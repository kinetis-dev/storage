<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\ByteStream\StreamException;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Amp\File\FilesystemException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Error;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Exception\IndeterminatePublicationException;
use Kinetis\Storage\Tests\Fixtures\FailingStreamWrapper;
use Kinetis\Storage\Tests\Fixtures\SelectivelyFailingFilesystemDriver;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\ResolveIdenticalPathConflict;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function Amp\File\filesystem;

final class AmpFileAdapterTest extends TestCase
{
    private string $root;

    /**
     * A sibling directory, outside $root, that the symlink tests below
     * point a link at — the thing $root is supposed to be a boundary
     * against reaching.
     */
    private string $outside;

    private AmpFileAdapter $adapter;

    /**
     * Set only by instrumentedAdapter() — a real
     * ContextWorkerPool spawns its own OS subprocesses, and
     * Amp\File\createDefaultDriver() has no way to hand one back out
     * once created, so it's built explicitly here (matching exactly
     * what createDefaultDriver() itself does in this environment,
     * confirmed directly: neither ext-uv nor ext-eio is present, so it
     * always falls through to `new ParallelFilesystemDriver()`) purely
     * so tearDown() can shut it down instead of leaving it to be
     * force-killed when the process exits.
     */
    private ?ContextWorkerPool $failingPool = null;

    public static function setUpBeforeClass(): void
    {
        FailingStreamWrapper::register();
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-test-' . bin2hex(random_bytes(8));
        $this->outside = sys_get_temp_dir() . '/kinetis-storage-test-outside-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
        $this->adapter = new AmpFileAdapter(filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->failingPool?->shutdown();
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->outside);
    }

    /**
     * Symlink-safe: a symlink entry is unlink()'d directly, never
     * followed via is_dir() (which, unlike this class's own
     * Filesystem::isSymlink(), does follow) — the exact distinction the
     * fix this file tests for is built around.
     */
    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = "{$path}/{$entry}";

            if (is_link($entryPath)) {
                unlink($entryPath);
            } elseif (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($path);
    }

    /** Non-empty and genuinely binary — null bytes and non-ASCII bytes included, not a trivial "x". */
    private static function binaryContent(): string
    {
        return "\x00\x01\xFF\xFEbinary payload\xDE\xAD\xBE\xEF" . str_repeat('y', 500);
    }

    /** The staging directory name prefix AmpFileAdapter publishes through. */
    private const string STAGING_PREFIX = '.kinetis-stage.';

    /** The single entry a staging directory holds. */
    private const string STAGED_FILE_NAME = 'staged';

    /**
     * The mode a brand-new file lands on under this test run's own
     * umask, observed rather than computed — umask() can only be read
     * in PHP by setting it and setting it back, and the value that
     * matters here is the one the filesystem actually produces.
     * Written outside $root so it never shows up in rootEntries().
     */
    private function defaultNewFileMode(): int
    {
        $reference = "{$this->outside}/umask-reference-" . bin2hex(random_bytes(4));
        file_put_contents($reference, 'x');

        return fileperms($reference) & 0777;
    }

    /**
     * The staging sequence every publishing call shares: the staged
     * file is made private while still empty, the mode it is published
     * under is applied only once the body is complete, both land on a
     * file inside a 0700 directory, and neither touches the destination
     * path.
     */
    private static function assertStagedThenPublished(
        SelectivelyFailingFilesystemDriver $driver,
        int $publishedMode,
        int $bodyLength,
    ): void {
        $changes = $driver->permissionChanges;

        self::assertCount(2, $changes, 'A publication applies exactly two modes: private while it stages, then the one it publishes under.');
        self::assertSame(0600, $changes[0]['mode'], 'The staged file is private before a single body byte can reach it.');
        self::assertSame(0, $changes[0]['size'], 'And it is empty at that moment — direct proof the mode came first.');
        self::assertSame($publishedMode, $changes[1]['mode'], 'The requested mode is the last thing applied before the rename.');
        self::assertSame($bodyLength, $changes[1]['size'], 'Applied to a complete body, not to an empty file.');

        foreach ($changes as $change) {
            self::assertSame(0700, $change['directoryMode'], 'Every mode is applied while the file is still inside the private staging directory.');
            self::assertStringEndsWith('/' . self::STAGED_FILE_NAME, $change['path'], 'No mode is ever applied to the destination itself.');
            self::assertStringContainsString('/' . self::STAGING_PREFIX, $change['path']);
        }
    }

    /**
     * Every path the driver was asked to rename something onto, in
     * order.
     *
     * @return list<string>
     */
    private static function renameDestinations(SelectivelyFailingFilesystemDriver $driver): array
    {
        $destinations = [];

        foreach ($driver->calls as $call) {
            if (str_starts_with($call, 'move:')) {
                $destinations[] = explode(':', $call)[2];
            }
        }

        return $destinations;
    }

    /**
     * The mode each staging directory was created with, in order.
     *
     * @return list<int>
     */
    private static function stagingDirectoryModes(SelectivelyFailingFilesystemDriver $driver): array
    {
        $modes = [];

        foreach ($driver->calls as $call) {
            if (str_starts_with($call, 'createDirectory:') && str_contains($call, '/' . self::STAGING_PREFIX)) {
                $modes[] = (int) octdec(explode(':', $call)[2]);
            }
        }

        return $modes;
    }

    public function test_write_then_read_round_trips(): void
    {
        $this->adapter->write('greeting.txt', 'hello world', new Config());

        self::assertSame('hello world', $this->adapter->read('greeting.txt'));
    }

    public function test_file_exists_reflects_real_state(): void
    {
        self::assertFalse($this->adapter->fileExists('nothing.txt'));

        $this->adapter->write('nothing.txt', 'now it exists', new Config());

        self::assertTrue($this->adapter->fileExists('nothing.txt'));
    }

    public function test_write_creates_missing_parent_directories(): void
    {
        $this->adapter->write('nested/deep/file.txt', 'contents', new Config());

        self::assertTrue($this->adapter->fileExists('nested/deep/file.txt'));
        self::assertTrue($this->adapter->directoryExists('nested/deep'));
    }

    // --- The staging sequence docs/storage.md specifies, read off the
    // SelectivelyFailingFilesystemDriver seam rather than inferred from
    // outcomes. A failure anywhere in it surfaces as UnableToWriteFile,
    // never a raw Amp\File\FilesystemException. ---

    public function test_write_stages_privately_and_publishes_the_requested_mode_at_the_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $body = 'this is the real body content';

        $adapter->write('ordering.txt', $body, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertStagedThenPublished($driver, 0644, \strlen($body));
        self::assertSame([0700], self::stagingDirectoryModes($driver), 'The staging directory is created private, never created wide and narrowed afterward.');
        self::assertSame(["{$this->root}/ordering.txt"], self::renameDestinations($driver), 'The destination is reached by exactly one rename.');
        self::assertSame($body, $adapter->read('ordering.txt'), 'The body itself must still land correctly.');
        self::assertSame(0644, fileperms("{$this->root}/ordering.txt") & 0777);
        self::assertSame(['ordering.txt'], $this->rootEntries(), 'The staging directory is gone once the copy is published.');
    }

    public function test_write_stream_stages_privately_and_publishes_the_requested_mode_at_the_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $body = 'this is the real body content';

        $adapter->writeStream('ordering-stream.txt', self::streamOf($body), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertStagedThenPublished($driver, 0644, \strlen($body));
        self::assertSame([0700], self::stagingDirectoryModes($driver));
        self::assertSame($body, $adapter->read('ordering-stream.txt'));
        self::assertSame(['ordering-stream.txt'], $this->rootEntries());
    }

    /**
     * The staged file lives inside the staging directory, never beside
     * the destination — the window this whole structure exists to
     * close, since a file created in a public directory can be opened
     * by another process before any chmod reaches it, and that
     * descriptor survives every later permission change.
     */
    public function test_write_never_creates_the_staged_file_in_the_destination_directory(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();

        $adapter->write('staged-elsewhere.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $creations = array_values(array_filter($driver->calls, static fn (string $call): bool => str_starts_with($call, 'openFile:x:')));
        self::assertCount(1, $creations);

        $staged = substr($creations[0], strlen('openFile:x:'));
        self::assertSame(self::STAGED_FILE_NAME, basename($staged));
        self::assertStringStartsWith(self::STAGING_PREFIX, basename(dirname($staged)));
        self::assertSame($this->root, dirname(dirname($staged)), 'The staging directory sits beside the destination, so the rename stays on one filesystem.');
    }

    /**
     * With no visibility requested there is no mode to apply, and a
     * replacement must not invent one: the destination keeps exactly the
     * permissions it already had. A rename-based replacement that
     * published the staged file's own mode would silently widen a
     * private file to whatever the umask produced.
     */
    public function test_write_replacing_a_file_without_a_requested_visibility_keeps_its_existing_mode(): void
    {
        $this->adapter->write('kept-mode.txt', 'original', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->write('kept-mode.txt', 'replacement', new Config());

        self::assertSame('replacement', $this->adapter->read('kept-mode.txt'));
        self::assertSame(0600, fileperms("{$this->root}/kept-mode.txt") & 0777);
    }

    public function test_write_stream_replacing_a_file_without_a_requested_visibility_keeps_its_existing_mode(): void
    {
        $this->adapter->write('kept-mode-stream.txt', 'original', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->writeStream('kept-mode-stream.txt', self::streamOf('replacement'), new Config());

        self::assertSame('replacement', $this->adapter->read('kept-mode-stream.txt'));
        self::assertSame(0600, fileperms("{$this->root}/kept-mode-stream.txt") & 0777);
    }

    /** A brand-new file with no visibility requested lands on the umask default, as it always has. */
    public function test_write_without_a_requested_visibility_publishes_a_new_file_at_the_umask_default(): void
    {
        $this->adapter->write('default-mode.txt', 'x', new Config());

        self::assertSame($this->defaultNewFileMode(), fileperms("{$this->root}/default-mode.txt") & 0777);
    }

    public function test_write_with_explicit_public_visibility_applies_the_correct_mode(): void
    {
        $this->adapter->write('public.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0644, fileperms("{$this->root}/public.txt") & 0777);
    }

    public function test_write_with_explicit_private_visibility_applies_the_correct_mode(): void
    {
        $this->adapter->write('private.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0600, fileperms("{$this->root}/private.txt") & 0777);
    }

    public function test_write_stream_with_explicit_public_visibility_applies_the_correct_mode(): void
    {
        $this->adapter->writeStream('public-stream.txt', self::streamOf('x'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0644, fileperms("{$this->root}/public-stream.txt") & 0777);
    }

    public function test_write_stream_with_explicit_private_visibility_applies_the_correct_mode(): void
    {
        $this->adapter->writeStream('private-stream.txt', self::streamOf('x'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0600, fileperms("{$this->root}/private-stream.txt") & 0777);
    }

    public function test_write_wraps_a_visibility_failure_as_unable_to_write_file_and_publishes_nothing(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->failChangePermissions = true;

        try {
            $adapter->write('new-write.txt', 'secret', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('new-write.txt'), 'A visibility failure must not leave the file behind.');
        self::assertSame([], $this->rootEntries(), 'And must not leave a staging directory behind either.');
        self::assertTrue(
            $driver->handleWasClosedWhenDeleteFileWasCalled,
            'Cleanup must observe the file handle already closed — unlinking a still-open handle works on POSIX but is not a portable guarantee.',
        );
    }

    public function test_write_stream_wraps_a_visibility_failure_as_unable_to_write_file_and_publishes_nothing(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->failChangePermissions = true;

        try {
            $adapter->writeStream('new-write-stream.txt', self::streamOf('secret'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('new-write-stream.txt'));
        self::assertSame([], $this->rootEntries());
        self::assertTrue($driver->handleWasClosedWhenDeleteFileWasCalled);
    }

    /**
     * The other visibility failure: the staged file was made private
     * fine, the body landed fine, and applying the mode the file is to
     * be *published* under is what failed — the last step before the
     * rename. Nothing is published, so the destination that was already
     * there is untouched, down to its bytes and its own mode.
     */
    public function test_write_wraps_a_publication_visibility_failure_and_preserves_the_existing_destination(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('published.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        // Everything up to the staging mode succeeds; only the mode the
        // file would be published under, which is a different value,
        // fails.
        $driver->beforeChangePermissions = static function (string $path, int $mode) use ($driver): void {
            $driver->failChangePermissions = $mode !== 0600;
        };

        try {
            $adapter->write('published.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('published.txt'));
        self::assertSame(0644, fileperms("{$this->root}/published.txt") & 0777);
        self::assertSame(['published.txt'], $this->rootEntries());
    }

    /**
     * The parent-directory check that precedes every publication
     * delegates to Filesystem::getStatus(), which can throw
     * FilesystemException. It has to surface as UnableToWriteFile like
     * any other failure in this operation, not escape raw past what
     * looks like the operation's own try/catch.
     */
    public function test_write_wraps_an_existence_check_failure_as_unable_to_write_file(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->failGetStatus = true;

        try {
            $adapter->write('existence-check.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }
    }

    public function test_write_stream_wraps_an_existence_check_failure_as_unable_to_write_file(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->failGetStatus = true;

        try {
            $adapter->writeStream('existence-check-stream.txt', self::streamOf('x'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }
    }

    /**
     * The destination is never opened for writing at all — the
     * replacement is assembled elsewhere and renamed over it — so a
     * failure part-way through leaves the file that was already there
     * byte-for-byte intact, not emptied. A caller that reads it while
     * this call is failing sees the old object, whole.
     */
    public function test_write_overwriting_an_existing_file_leaves_it_byte_for_byte_intact_on_a_visibility_failure(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('overwrite.txt', 'original content', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $driver->failChangePermissions = true;

        try {
            $adapter->write('overwrite.txt', 'replacement content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile) {
            // Expected.
        }

        self::assertSame('original content', $adapter->read('overwrite.txt'), 'A failed replacement never destroys the object it was going to replace.');
        self::assertSame(0644, fileperms("{$this->root}/overwrite.txt") & 0777, 'Nor its mode.');
        self::assertSame(['overwrite.txt'], $this->rootEntries());
    }

    public function test_write_stream_overwriting_an_existing_file_leaves_it_byte_for_byte_intact_on_a_visibility_failure(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->writeStream('overwrite-stream.txt', self::streamOf('original content'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $driver->failChangePermissions = true;

        try {
            $adapter->writeStream('overwrite-stream.txt', self::streamOf('replacement content'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile) {
            // Expected.
        }

        self::assertSame('original content', $adapter->read('overwrite-stream.txt'));
        self::assertSame(0644, fileperms("{$this->root}/overwrite-stream.txt") & 0777);
        self::assertSame(['overwrite-stream.txt'], $this->rootEntries());
    }

    /**
     * A body that stops half-way — the fixture lets real bytes land in
     * the staged file first, so the staged body really is truncated
     * rather than never started. The old destination is
     * still whole, and the truncated bytes are gone with the staging
     * directory rather than published.
     */
    public function test_write_leaves_the_existing_destination_intact_when_the_body_write_fails_part_way(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('partial.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failWriteAfterBytes = 8;

        try {
            $adapter->write('partial.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('partial.txt'));
        self::assertSame(0644, fileperms("{$this->root}/partial.txt") & 0777);
        self::assertSame(['partial.txt'], $this->rootEntries());
    }

    public function test_write_stream_leaves_the_existing_destination_intact_when_the_body_write_fails_part_way(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('partial-stream.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failWriteAfterBytes = 8;

        try {
            $adapter->writeStream('partial-stream.txt', self::streamOf(self::binaryContent()), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('partial-stream.txt'));
        self::assertSame(['partial-stream.txt'], $this->rootEntries());
    }

    /**
     * The source stream itself dies after real bytes have already been
     * piped across — the failure a caller's own resource can produce,
     * as opposed to one this adapter's filesystem raises. Same outcome:
     * nothing published, nothing left behind, old destination whole.
     */
    public function test_write_stream_leaves_the_existing_destination_intact_when_the_source_stream_fails(): void
    {
        $this->adapter->write('dying-source.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        try {
            $this->adapter->writeStream('dying-source.txt', self::failingSourceStream(str_repeat('n', 100_000), 8192), new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('dying-source.txt'));
        self::assertSame(0644, fileperms("{$this->root}/dying-source.txt") & 0777);
        self::assertSame(['dying-source.txt'], $this->rootEntries());
    }

    /** Closing the staged file is part of the write, so a close failure fails the write and publishes nothing. */
    public function test_write_leaves_the_existing_destination_intact_when_the_staged_close_fails(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('close-fail.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failCloseForModes = ['x'];

        try {
            $adapter->write('close-fail.txt', 'the replacement', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('close-fail.txt'));
        self::assertSame(['close-fail.txt'], $this->rootEntries());
    }

    /** And a rename that fails is a publication that never happened. */

    /** A staging directory that cannot even be created fails the write before anything else is touched. */
    public function test_write_leaves_the_existing_destination_intact_when_the_staging_directory_cannot_be_created(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('no-staging.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failCreateDirectory = true;

        try {
            $adapter->write('no-staging.txt', 'the replacement', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('no-staging.txt'));
        self::assertSame(['no-staging.txt'], $this->rootEntries());
    }

    /**
     * The successful counterpart to every failure above: a real
     * replacement lands whole, with the requested mode, and leaves the
     * directory holding nothing but the destination.
     */
    public function test_write_replaces_an_existing_destination_whole(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('replaced.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->write('replaced.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('replaced.txt'));
        self::assertSame(0600, fileperms("{$this->root}/replaced.txt") & 0777);
        self::assertSame(['replaced.txt'], $this->rootEntries());
    }

    public function test_write_stream_replaces_an_existing_destination_whole(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('replaced-stream.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->writeStream('replaced-stream.txt', self::streamOf($content), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('replaced-stream.txt'));
        self::assertSame(0600, fileperms("{$this->root}/replaced-stream.txt") & 0777);
        self::assertSame(['replaced-stream.txt'], $this->rootEntries());
    }

    /**
     * Cleanup after a successful rename is not part of the publication,
     * so its failure is not a copy failure. The empty directory left
     * behind is what docs/storage.md discloses.
     */
    // --- Silent short writes: a real prefix on disk, a discarded
    // suffix, and a write() that reports nothing. The staged length
    // check is what rejects them; see docs/storage.md. ---

    public function test_write_fails_and_preserves_the_destination_when_the_staged_write_silently_drops_bytes(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('silent.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->dropWritesAfterBytes = 8;

        try {
            $adapter->write('silent.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
            self::assertStringContainsString('8 byte(s)', (string) $e->getPrevious()?->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('silent.txt'), 'A body that lost its tail must never replace a good destination.');
        self::assertSame(0644, fileperms("{$this->root}/silent.txt") & 0777);
        self::assertSame(['silent.txt'], $this->rootEntries(), 'Cleanup succeeds here, so nothing staged is left.');
    }

    public function test_write_stream_fails_and_preserves_the_destination_when_the_staged_write_silently_drops_bytes(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('silent-stream.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->dropWritesAfterBytes = 8;

        try {
            $adapter->writeStream('silent-stream.txt', self::streamOf(self::binaryContent()), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('silent-stream.txt'));
        self::assertSame(0644, fileperms("{$this->root}/silent-stream.txt") & 0777);
        self::assertSame(['silent-stream.txt'], $this->rootEntries());
    }

    public function test_copy_fails_and_preserves_the_destination_when_the_staged_write_silently_drops_bytes(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->dropWritesAfterBytes = 8;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * The check is on length, so a body that lands whole passes it —
     * proving the check is a real comparison rather than a constant
     * rejection, across a body large enough to reach the staged handle
     * in several chunks.
     */
    public function test_write_stream_publishes_a_multi_chunk_body_whose_length_matches(): void
    {
        $content = str_repeat(self::binaryContent(), 200);

        $this->adapter->writeStream('large.txt', self::streamOf($content), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('large.txt'));
        self::assertSame(0600, fileperms("{$this->root}/large.txt") & 0777);
        self::assertSame(['large.txt'], $this->rootEntries());
    }

    /**
     * A staged status that cannot be read, or that reports no usable
     * length or identity, fails the publication exactly like a wrong
     * length: unknown is never read as correct. Driven end to end
     * through a seam that misbehaves for the staged file alone, so the
     * destination, the exception classification, the closes and the
     * residual staging state are all observed on the real path.
     *
     * @param 'throw'|'null'|'no-size'|'bad-size'|'no-identity' $fault
     */
    #[DataProvider('stagedStatusFaults')]
    public function test_write_fails_closed_and_preserves_the_destination_for_a_bad_staged_status(string $fault): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('bad-status.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->stagedStatusFault = $fault;

        try {
            $adapter->write('bad-status.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('bad-status.txt'));
        self::assertSame(0644, fileperms("{$this->root}/bad-status.txt") & 0777);
        self::assertSame(['bad-status.txt'], $this->rootEntries());
        self::assertSame(
            ['x'],
            self::closeModes($driver),
            'The staged handle is closed once, by the primitive, before the status is read back.',
        );
    }

    #[DataProvider('stagedStatusFaults')]
    public function test_write_stream_fails_closed_and_preserves_the_destination_for_a_bad_staged_status(string $fault): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('bad-status-stream.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->stagedStatusFault = $fault;

        try {
            $adapter->writeStream('bad-status-stream.txt', self::streamOf('the replacement'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('bad-status-stream.txt'));
        self::assertSame(0644, fileperms("{$this->root}/bad-status-stream.txt") & 0777);
        self::assertSame(['bad-status-stream.txt'], $this->rootEntries());
    }

    #[DataProvider('stagedStatusFaults')]
    public function test_copy_fails_closed_and_preserves_the_destination_for_a_bad_staged_status(string $fault): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->stagedStatusFault = $fault;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
        self::assertSame(['r', 'x'], self::closeModes($driver), 'The copy closes its source handle, then the primitive closes the staged one before reading the status back.');
    }

    /** @return iterable<string, array{string}> */
    public static function stagedStatusFaults(): iterable
    {
        yield 'status throws' => ['throw'];
        yield 'status missing' => ['null'];
        yield 'no size reported' => ['no-size'];
        yield 'size is not an integer' => ['bad-size'];
        yield 'no device and inode reported' => ['no-identity'];
    }

    // --- Failures the adapter's own catch lists never name. A producer,
    // or a third-party Amp\File implementation, can raise anything at
    // all; the staged file has to be cleaned up regardless, and the
    // throwable has to reach the caller as itself rather than relabeled
    // as a Flysystem failure it isn't. ---

    public function test_write_cleans_up_and_rethrows_a_runtime_exception_from_the_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('runtime.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->moveThrows = new RuntimeException('a failure no catch list here names');

        try {
            $adapter->write('runtime.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('a failure no catch list here names', $e->getMessage(), 'It must escape as itself, not relabeled as an UnableToWriteFile it is not.');
        }

        self::assertSame('the previous occupant', $this->adapter->read('runtime.txt'));
        self::assertSame(0644, fileperms("{$this->root}/runtime.txt") & 0777);
        self::assertSame(['runtime.txt'], $this->rootEntries(), 'The staged file and its directory are cleaned up even for a failure type nothing here anticipates.');
    }

    /** An Error, not an Exception — the same requirement, one level further out. */
    public function test_copy_cleans_up_and_rethrows_an_error_from_the_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->moveThrows = new Error('a programming error, not a filesystem one');

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected Error.');
        } catch (Error $e) {
            self::assertSame('a programming error, not a filesystem one', $e->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * The two together: an unfamiliar throwable raised inside the
     * producer, before the handle is closed, and a close that then fails
     * on the way out. The close must not displace the failure already in
     * flight, the throwable must still reach the caller as itself, and
     * the staged file must still be cleaned up.
     */
    public function test_write_preserves_an_unfamiliar_throwable_when_the_staged_close_also_fails(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('both.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->writeThrows = new RuntimeException('the primary failure');
        $driver->failCloseForModes = ['x'];

        try {
            $adapter->write('both.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame(
                'the primary failure',
                $e->getMessage(),
                'The close failure must not take the place of the failure already being reported.',
            );
        }

        self::assertSame('the previous occupant', $this->adapter->read('both.txt'), 'Nothing is published either way.');
        self::assertSame(
            ['both.txt'],
            $this->rootEntries(),
            'A close that fails still leaves the staged file unlinkable in principle; here the unlink succeeds, so nothing is left.',
        );
    }

    /**
     * The same competition, one level further out: an Error from the
     * producer, with the close failing behind it. Also the copy path,
     * where two handles are owned rather than one — both are still
     * attempted.
     */
    public function test_copy_preserves_an_error_when_the_closes_also_fail(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->writeThrows = new Error('the primary error');
        $driver->failCloseForModes = ['x', 'r'];

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected Error.');
        } catch (Error $e) {
            self::assertSame('the primary error', $e->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
    }

    // --- Rename outcomes. A driver that runs rename(2) in a worker can
    // lose the reply after the kernel has already renamed, so a rename
    // failure alone does not say whether the destination changed. The
    // staged file's device and inode, taken a moment earlier, are what
    // separate the three outcomes: not committed, committed, or
    // unprovable. ---

    /** Throw before the rename: the staged inode is still staged, so nothing committed. */
    public function test_a_rename_that_never_ran_leaves_the_destination_and_cleans_up(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('not-committed.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failMove = true;

        try {
            $adapter->write('not-committed.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
            self::assertSame('simulated rename failure', $e->getPrevious()?->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('not-committed.txt'));
        self::assertSame(0644, fileperms("{$this->root}/not-committed.txt") & 0777);
        self::assertSame(['not-committed.txt'], $this->rootEntries());
    }

    /**
     * Rename, then throw: the destination already holds the staged
     * inode. Reporting a failure here would contradict the disk, so the
     * call succeeds and only the staging directory is cleaned up.
     */
    public function test_a_rename_that_committed_before_failing_is_reported_as_success(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('committed.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->renameThenThrow = true;

        $adapter->write('committed.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame('the replacement', $this->adapter->read('committed.txt'), 'The destination really was replaced, so the call must not claim otherwise.');
        self::assertSame(0600, fileperms("{$this->root}/committed.txt") & 0777);
        self::assertSame(['committed.txt'], $this->rootEntries());
    }

    public function test_a_copy_whose_rename_committed_before_failing_is_reported_as_success(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->renameThenThrow = true;

        $adapter->copy('source.txt', 'destination.txt', new Config());

        self::assertSame('the new content', $this->adapter->read('destination.txt'));
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * Rename, then something else replaces the destination, then throw.
     * The staged inode is neither staged nor at the destination, so
     * neither outcome can be shown and the caller is told to look.
     */
    public function test_a_destination_replaced_again_after_the_rename_is_reported_as_indeterminate(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('changed-again.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->renameThenThrow = true;
        $driver->afterMove = static function (string $from, string $to): void {
            $usurper = "{$to}.usurper";
            file_put_contents($usurper, 'a third party');
            rename($usurper, $to);
        };

        try {
            $adapter->write('changed-again.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected IndeterminatePublicationException.');
        } catch (IndeterminatePublicationException $e) {
            self::assertStringContainsString('changed-again.txt', $e->getMessage());
            self::assertStringContainsString(IndeterminatePublicationException::REASON_DESTINATION_NOT_STAGED, $e->getMessage());
            $this->assertNoInternalDetailLeaks($e, 'simulated lost reply after a completed rename');
        }

        self::assertSame('a third party', $this->adapter->read('changed-again.txt'), 'The destination is whatever it is; the call reports that it cannot say.');
        self::assertSame(['changed-again.txt'], $this->rootEntries());
    }

    /**
     * A staged status that cannot be read after a failed rename leaves
     * both outcomes unprovable, so nothing is deleted: an object whose
     * ownership is not established is not this call's to remove.
     */
    public function test_an_unreadable_staged_status_after_a_failed_rename_is_indeterminate_and_deletes_nothing(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('unprovable.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        // Clean until the length check has run, then broken for the
        // classification that follows the rename failure.
        $driver->failMove = true;
        $driver->stagedStatusFaultAfterMove = true;

        try {
            $adapter->write('unprovable.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected IndeterminatePublicationException.');
        } catch (IndeterminatePublicationException $e) {
            self::assertStringContainsString('unprovable.txt', $e->getMessage());
            self::assertStringContainsString(IndeterminatePublicationException::REASON_UNREADABLE, $e->getMessage());
            $this->assertNoInternalDetailLeaks($e, 'simulated status failure for the staged file');
        }

        self::assertSame('the previous occupant', $this->adapter->read('unprovable.txt'));

        $left = array_values(array_filter($this->rootEntries(), static fn (string $entry): bool => str_starts_with($entry, self::STAGING_PREFIX)));
        self::assertCount(1, $left, 'The staged file could not be shown to be this call\'s, so it is left in place rather than removed.');
    }

    /**
     * A cleanup failure on the not-committed path does not change what
     * the caller is told: the rename failure is still the reported one,
     * and the destination is still intact.
     */
    public function test_a_cleanup_failure_after_a_failed_rename_does_not_replace_the_reported_failure(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('cleanup-fails.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failMove = true;
        $driver->failDeleteDirectory = true;

        try {
            $adapter->write('cleanup-fails.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertSame('simulated rename failure', $e->getPrevious()?->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('cleanup-fails.txt'));
    }

    /**
     * A mkdir whose reply is lost leaves a directory this call cannot
     * show is its own rather than a path that was already there, so it
     * is left alone. No destination has been touched.
     */
    public function test_a_staging_directory_whose_creation_reply_is_lost_is_not_deleted(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('mkdir-lost.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->createDirectoryThenThrow = true;

        try {
            $adapter->write('mkdir-lost.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('mkdir-lost.txt'));
        self::assertSame(0644, fileperms("{$this->root}/mkdir-lost.txt") & 0777);

        $left = array_values(array_filter($this->rootEntries(), static fn (string $entry): bool => str_starts_with($entry, self::STAGING_PREFIX)));
        self::assertCount(1, $left, 'An empty staging directory is left rather than a possibly unowned path deleted.');
        self::assertSame([], array_values(array_diff(scandir("{$this->root}/{$left[0]}") ?: [], ['.', '..'])), 'Nothing was written into it.');
    }

    /**
     * forFile() can throw League\Flysystem\InvalidVisibilityProvided for
     * a garbage explicit value — must escape write() as itself, never
     * relabeled as an UnableToWriteFile it isn't, matching
     * League\Flysystem\Local\LocalFilesystemAdapter's own write() (via
     * setVisibility()).
     */
    public function test_write_lets_an_invalid_explicit_visibility_escape_as_itself(): void
    {
        $this->expectException(InvalidVisibilityProvided::class);

        $this->adapter->write('invalid-visibility.txt', 'x', new Config([Config::OPTION_VISIBILITY => 'not-a-real-visibility']));
    }

    /**
     * resolveExplicitFileMode() resolves (and, here, throws for) an
     * explicit visibility before write()/writeStream() ever call
     * openFile('w') — so a garbage value must reject the whole call
     * with zero destructive mutation, not just zero body bytes written.
     * Proven directly: writing to a path that already holds real
     * content with an invalid visibility must leave that content
     * completely untouched, not truncated to empty the way a genuine
     * changePermissions() failure (see the overwrite tests above)
     * leaves it.
     */
    public function test_write_with_an_invalid_explicit_visibility_never_touches_a_preexisting_file(): void
    {
        $this->adapter->write('untouched.txt', 'original content', new Config());

        try {
            $this->adapter->write('untouched.txt', 'replacement content', new Config([Config::OPTION_VISIBILITY => 'not-a-real-visibility']));
            self::fail('Expected InvalidVisibilityProvided.');
        } catch (InvalidVisibilityProvided) {
            // Expected.
        }

        self::assertSame('original content', $this->adapter->read('untouched.txt'));
    }

    public function test_write_stream_with_an_invalid_explicit_visibility_never_touches_a_preexisting_file(): void
    {
        $this->adapter->write('untouched-stream.txt', 'original content', new Config());

        try {
            $this->adapter->writeStream('untouched-stream.txt', self::streamOf('replacement content'), new Config([Config::OPTION_VISIBILITY => 'not-a-real-visibility']));
            self::fail('Expected InvalidVisibilityProvided.');
        } catch (InvalidVisibilityProvided) {
            // Expected.
        }

        self::assertSame('original content', $this->adapter->read('untouched-stream.txt'));
    }

    /**
     * @return array{0: AmpFileAdapter, 1: SelectivelyFailingFilesystemDriver}
     */
    private function instrumentedAdapter(): array
    {
        $this->failingPool = new ContextWorkerPool();
        $driver = new SelectivelyFailingFilesystemDriver(new ParallelFilesystemDriver($this->failingPool));
        $adapter = new AmpFileAdapter(new AmpFilesystem($driver), $this->root);

        return [$adapter, $driver];
    }

    /**
     * Every entry directly under $root, dot entries excluded but
     * dotfiles included — copy()'s temporaries are dotfiles, so this is
     * what proves one did not survive a failed copy.
     *
     * @return list<string>
     */
    private function rootEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * The open mode of every close the wrapped handles saw, in order:
     * 'x' for a staged file, 'r' for a source.
     *
     * @return list<string>
     */
    private static function closeModes(SelectivelyFailingFilesystemDriver $driver): array
    {
        return array_map(
            static fn (string $attempt): string => explode(':', $attempt)[0],
            $driver->closeAttempts,
        );
    }

    /** @param list<string> $calls */
    private static function countCalls(array $calls, string $call): int
    {
        return count(array_keys($calls, $call, true));
    }

    /** @return resource */
    private static function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * A real PHP resource holding $contents that fails hard once
     * $failAfterBytes of it have been read out — a caller's source
     * dying mid-transfer, which no ordinary resource can be made to do
     * on demand. See FailingStreamWrapper's own docblock for why an
     * exception thrown inside a wrapper is a genuine read failure
     * rather than an early EOF.
     *
     * @return resource
     */
    private static function failingSourceStream(string $contents, int $failAfterBytes)
    {
        $context = stream_context_create([
            FailingStreamWrapper::PROTOCOL => [
                'contents' => $contents,
                'throwOnReadAfterBytes' => $failAfterBytes,
            ],
        ]);

        return fopen(FailingStreamWrapper::PROTOCOL . '://source', 'rb', context: $context);
    }

    public function test_delete_removes_the_file(): void
    {
        $this->adapter->write('to-delete.txt', 'x', new Config());
        $this->adapter->delete('to-delete.txt');

        self::assertFalse($this->adapter->fileExists('to-delete.txt'));
    }

    public function test_reading_a_missing_file_throws(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->adapter->read('missing.txt');
    }

    public function test_create_directory_then_delete_directory_recursively(): void
    {
        $this->adapter->createDirectory('a/b/c', new Config());
        $this->adapter->write('a/b/c/file.txt', 'x', new Config());

        self::assertTrue($this->adapter->directoryExists('a/b/c'));

        $this->adapter->deleteDirectory('a');

        self::assertFalse($this->adapter->directoryExists('a'));
        self::assertFalse($this->adapter->fileExists('a/b/c/file.txt'));
    }

    public function test_move_relocates_the_file(): void
    {
        $this->adapter->write('source.txt', 'moved contents', new Config());
        $this->adapter->move('source.txt', 'destination.txt', new Config());

        self::assertFalse($this->adapter->fileExists('source.txt'));
        self::assertSame('moved contents', $this->adapter->read('destination.txt'));
    }

    public function test_copy_duplicates_the_file_leaving_the_source_intact(): void
    {
        $this->adapter->write('original.txt', 'copied contents', new Config());
        $this->adapter->copy('original.txt', 'duplicate.txt', new Config());

        self::assertSame('copied contents', $this->adapter->read('original.txt'));
        self::assertSame('copied contents', $this->adapter->read('duplicate.txt'));
    }

    // --- copy()/move() visibility semantics: real filesystem modes, not
    // mocked calls — asserted both through the adapter's own visibility()
    // and directly against the raw fileperms() on disk.
    //
    // The ordering group further below adds the timing half of the same
    // guarantee — the destination's mode is applied to a still-empty
    // file, never to one already holding the source's bytes — through
    // SelectivelyFailingFilesystemDriver, the same seam the write()/
    // writeStream() ordering tests use. A real chmod() failure can't be
    // forced any other way here: the php:8.4-cli-alpine toolchain these
    // tests run under is root, which bypasses ordinary POSIX permission
    // checks entirely. ---

    public function test_copy_by_default_retains_the_sources_visibility(): void
    {
        $this->adapter->write('source-public.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $this->adapter->write('source-private.txt', 'y', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('source-public.txt', 'copy-public.txt', new Config());
        $this->adapter->copy('source-private.txt', 'copy-private.txt', new Config());

        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('copy-public.txt')->visibility());
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('copy-private.txt')->visibility());
        self::assertSame(0644, fileperms("{$this->root}/copy-public.txt") & 0777);
        self::assertSame(0600, fileperms("{$this->root}/copy-private.txt") & 0777);
    }

    public function test_copy_with_explicit_visibility_overrides_the_source(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('destination.txt')->visibility());
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    /**
     * With no mode requested, the destination lands on the mode a new
     * file gets here anyway — not the source's own. Asserting the
     * destination's mode differs from the source's unusual 0600 is what
     * proves retention was skipped, without pinning a "default" that
     * depends on the umask this test runs under.
     */
    public function test_copy_with_retain_visibility_false_and_no_explicit_visibility_does_not_retain(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        $mode = fileperms("{$this->root}/destination.txt") & 0777;
        self::assertNotSame(0600, $mode, "retain_visibility=false must not have carried the source's own mode over.");
    }

    // --- copy() takes the same staging sequence. The source is always
    // written through the plain adapter, so the only
    // changePermissions() calls the fixture sees are the copy's own. ---

    /**
     * The published mode may be retained or explicit, private or
     * public; the staging sequence is the same one either way, and a
     * public destination is public only from the last step.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: int}>
     */
    public static function copyPublicationModes(): iterable
    {
        yield 'retained private' => [Visibility::PRIVATE, [], 0600];
        yield 'retained public' => [Visibility::PUBLIC, [], 0644];
        yield 'explicit private over a public source' => [Visibility::PUBLIC, [Config::OPTION_VISIBILITY => Visibility::PRIVATE], 0600];
        yield 'explicit public over a private source' => [Visibility::PRIVATE, [Config::OPTION_VISIBILITY => Visibility::PUBLIC], 0644];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('copyPublicationModes')]
    public function test_copy_stages_privately_and_publishes_the_mode_at_the_rename(string $sourceVisibility, array $config, int $published): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $content = self::binaryContent();
        $this->adapter->write('source.txt', $content, new Config([Config::OPTION_VISIBILITY => $sourceVisibility]));

        $adapter->copy('source.txt', 'copy.txt', new Config($config));

        self::assertStagedThenPublished($driver, $published, \strlen($content));
        self::assertSame([0700], self::stagingDirectoryModes($driver));
        self::assertSame($content, $adapter->read('copy.txt'), 'The body itself must still land correctly.');
        self::assertSame($published, fileperms("{$this->root}/copy.txt") & 0777);
    }

    /**
     * An overwrite takes the identical route: the bytes and the mode
     * both land on a staged file, and the existing destination is
     * replaced by the rename in one step.
     */
    public function test_copy_over_an_existing_destination_stages_privately_and_publishes_at_the_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $content = self::binaryContent();
        $this->adapter->write('secret.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('existing.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $adapter->copy('secret.txt', 'existing.txt', new Config());

        self::assertStagedThenPublished($driver, 0600, \strlen($content));
        self::assertSame(["{$this->root}/existing.txt"], self::renameDestinations($driver), 'The destination is reached by exactly one rename.');
        self::assertSame($content, $adapter->read('existing.txt'));
        self::assertSame(0600, fileperms("{$this->root}/existing.txt") & 0777);
    }

    /**
     * retain_visibility=false asks for no mode of its own, so the copy
     * publishes at the mode a brand-new file would have had here
     * anyway. The staging mode is still applied — a staged file is
     * private whatever the caller asked for — so what this pins down is
     * the *published* mode, read off the seam rather than inferred.
     */
    public function test_copy_with_retain_visibility_false_publishes_a_new_destination_at_the_umask_default(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('secret.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $adapter->copy('secret.txt', 'unretained.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        $default = $this->defaultNewFileMode();
        self::assertStagedThenPublished($driver, $default, 1);
        self::assertSame($default, fileperms("{$this->root}/unretained.txt") & 0777);
    }

    /**
     * The same request over a destination that already exists keeps that
     * destination's own mode rather than resetting it: no mode was
     * requested, so none is invented, and a replacement never silently
     * widens what it replaced.
     */
    public function test_copy_with_retain_visibility_false_keeps_an_existing_destinations_mode(): void
    {
        $this->adapter->write('secret.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('kept.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('secret.txt', 'kept.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        self::assertSame('x', $this->adapter->read('kept.txt'));
        self::assertSame(0600, fileperms("{$this->root}/kept.txt") & 0777);
    }

    /**
     * The mode lands on the temporary before any body byte, so a chmod
     * failure is a copy that never happened: no new destination, no
     * temporary left behind, and a source still intact.
     */
    public function test_copy_leaves_no_destination_when_the_mode_cannot_be_applied(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $content = self::binaryContent();
        $this->adapter->write('secret.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $driver->failChangePermissions = true;

        try {
            $adapter->copy('secret.txt', 'leaked.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('leaked.txt'), 'A destination whose requested mode could not be applied must never be left on disk.');
        self::assertSame($content, $this->adapter->read('secret.txt'), 'The source is never touched by a failed copy.');
        self::assertSame(['secret.txt'], $this->rootEntries(), 'The temporary must be gone too, not merely unrenamed.');
        self::assertTrue(
            $driver->handleWasClosedWhenDeleteFileWasCalled,
            'Cleanup must observe the file handle already closed — unlinking a still-open handle works on POSIX but is not a portable guarantee.',
        );
    }

    /**
     * The same failure against a destination that already exists. The
     * copy is assembled in a temporary that is never renamed, so the
     * previous occupant keeps both its content and its own mode — there
     * is no truncation to undo and nothing to delete.
     */
    public function test_copy_preserves_an_existing_destination_when_the_mode_cannot_be_applied(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('secret.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('existing.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failChangePermissions = true;

        try {
            $adapter->copy('secret.txt', 'existing.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('existing.txt'));
        self::assertSame(0644, fileperms("{$this->root}/existing.txt") & 0777);
        self::assertSame(['existing.txt', 'secret.txt'], $this->rootEntries());
    }

    /**
     * A read failure partway through the body reaches the same outcome
     * by a different route: the bytes were going into a temporary that
     * is never renamed, so no partial content is ever published under
     * the mode derived from the source.
     */
    public function test_copy_wraps_a_stream_read_failure_and_leaves_the_destination_untouched(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failRead = true;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * The rename is the commit point, so a rename that fails publishes
     * nothing: the destination that was already there keeps its content
     * and mode, and the temporary is removed on the way out.
     */
    public function test_copy_wraps_a_rename_failure_and_leaves_the_existing_destination_intact(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failMove = true;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /** The successful counterpart: an existing destination is replaced whole, content and mode together. */
    public function test_copy_over_an_existing_destination_replaces_its_content_and_its_mode(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('secret.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('existing.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('secret.txt', 'existing.txt', new Config());

        self::assertSame($content, $this->adapter->read('existing.txt'));
        self::assertSame(0600, fileperms("{$this->root}/existing.txt") & 0777);
        self::assertSame(['existing.txt', 'secret.txt'], $this->rootEntries(), 'A completed copy leaves no temporary behind either.');
    }

    /** And to a destination that does not exist yet, with nothing else left in the directory. */
    public function test_copy_to_a_new_destination_publishes_content_and_mode_and_no_temporary(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('secret.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('secret.txt', 'new-copy.txt', new Config());

        self::assertSame($content, $this->adapter->read('new-copy.txt'));
        self::assertSame(0600, fileperms("{$this->root}/new-copy.txt") & 0777);
        self::assertSame(['new-copy.txt', 'secret.txt'], $this->rootEntries());
    }

    // --- copy()'s retained-mode observation against the source
    // handle's own open: the observation comes first. Taken after the
    // open instead, a source replaced in between would be described by
    // both observations while the handle still streams the file that
    // was there first, and the post-copy check would agree with
    // itself. ---

    /**
     * Read straight off the driver seam. Exactly two getLinkStatus()
     * calls reach the source before its handle opens — one from
     * assertNoSymlinkBelowRoot()'s component walk, one for the retained
     * mode — and exactly one after it, the post-copy verification. The
     * reverse split is the ordering this pins down.
     */
    public function test_copy_observes_the_retained_source_mode_before_opening_the_source(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('ordered.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $adapter->copy('ordered.txt', 'ordered-copy.txt', new Config());

        $open = array_search("openFile:r:{$this->root}/ordered.txt", $driver->calls, true);
        self::assertIsInt($open, 'The source handle must have been opened.');

        $stat = "getLinkStatus:{$this->root}/ordered.txt";
        self::assertSame(2, self::countCalls(array_slice($driver->calls, 0, $open), $stat), 'The symlink check and the retained-mode observation both precede the open.');
        self::assertSame(1, self::countCalls(array_slice($driver->calls, $open + 1), $stat), 'Only the post-copy verification follows it.');
    }

    /**
     * The window the pre-copy observation exists for, driven
     * deterministically rather than by OS scheduling: the source is
     * replaced at the instant its handle opens, by a file carrying the
     * original's own mode, so the two observations agree on type and
     * permissions and disagree only on which inode the path resolves
     * to. Run for all three ways a copy can decide the destination's
     * mode, because the check is not a by-product of retaining the
     * source's visibility — it is what says the bytes being published
     * came from the file the copy started on.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('copyVisibilityModes')]
    public function test_copy_rejects_a_source_replaced_during_the_copy(array $config): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $source = "{$this->root}/swapped.txt";
        $this->adapter->write('swapped.txt', 'the original', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        // Built elsewhere and renamed over $source rather than unlinked
        // and rewritten in place: a filesystem may hand the inode it
        // just freed straight back to the next file created in that
        // directory, which would test the allocator rather than this
        // adapter.
        $driver->beforeOpenFile = static function (string $path, string $mode) use ($source): void {
            if ($path !== $source || $mode !== 'r') {
                return;
            }

            $replacement = "{$source}.replacement";
            file_put_contents($replacement, 'the replacement');
            chmod($replacement, 0600);
            rename($replacement, $source);
        };

        try {
            $adapter->copy('swapped.txt', 'destination.txt', new Config($config));
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(UnableToRetrieveMetadata::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'swapped.txt'], $this->rootEntries());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function copyVisibilityModes(): iterable
    {
        yield 'retaining the source visibility' => [[]];
        yield 'an explicit visibility' => [[Config::OPTION_VISIBILITY => Visibility::PUBLIC]];
        yield 'retain_visibility disabled' => [[Config::OPTION_RETAIN_VISIBILITY => false]];
    }

    /**
     * A replacement whose mode differs too is rejected by the same
     * check, one comparison earlier.
     */
    public function test_copy_rejects_a_source_replaced_by_a_file_with_a_different_mode(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $source = "{$this->root}/swapped-mode.txt";
        $this->adapter->write('swapped-mode.txt', 'the original', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $driver->beforeOpenFile = static function (string $path, string $mode) use ($source): void {
            if ($path !== $source || $mode !== 'r') {
                return;
            }

            unlink($source);
            file_put_contents($source, 'the replacement');
            chmod($source, 0644);
        };

        try {
            $adapter->copy('swapped-mode.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(UnableToRetrieveMetadata::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('destination.txt'));
        self::assertSame(['swapped-mode.txt'], $this->rootEntries());
    }

    /**
     * A staging directory that cannot be created fails the copy before
     * the source is even opened, with the destination untouched.
     */
    public function test_copy_leaves_the_existing_destination_intact_when_the_staging_directory_cannot_be_created(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->failCreateDirectory = true;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * Removing the staging directory after a successful rename is
     * cleanup, not part of the publication: the destination is already
     * committed at that point, so a cleanup failure must never be
     * reported as a failed copy. The abandoned directory is what the
     * caller is left with instead — disclosed, and empty.
     */
    public function test_copy_succeeds_when_only_the_staging_cleanup_fails(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $driver->failDeleteDirectory = true;

        $adapter->copy('source.txt', 'destination.txt', new Config());

        self::assertSame('the new content', $this->adapter->read('destination.txt'));
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    // --- Handle lifecycle. copy() owns two handles, the staged file's
    // ('x') and the source's ('r'); write()/writeStream() own one. Every
    // owned handle must be closed whatever the others do, and the
    // failure reported must be the first in that fixed order, not
    // whichever handle happened to be closed last. The fixture records
    // each close against its handle's own open mode, so these read the
    // attempts rather than inferring them from an outcome. ---

    /**
     * @return array{0: list<string>, 1: ?Throwable}
     * @param list<string> $failing
     */
    private function runCopyWithFailingCloses(array $failing): array
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'x', new Config());
        $driver->failCloseForModes = $failing;

        $reported = null;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
        } catch (UnableToCopyFile $e) {
            $reported = $e->getPrevious();
        }

        self::assertFalse($adapter->fileExists('destination.txt'), 'A close that fails publishes nothing.');
        self::assertSame(['source.txt'], $this->rootEntries());

        return [self::closeModes($driver), $reported];
    }

    /**
     * The staged close is the primitive's and fails after the source
     * handle is already closed, so the cleanup that follows retries it —
     * the one place a handle is closed twice, and only ever one whose
     * close did not succeed.
     */
    public function test_copy_retries_only_the_staged_close_that_failed(): void
    {
        [$modes, $reported] = $this->runCopyWithFailingCloses(['x']);

        self::assertSame(['r', 'x', 'x'], $modes);
        self::assertSame("simulated close failure for the 'x' handle", $reported?->getMessage());
    }

    public function test_copy_closes_the_staged_handle_when_the_source_close_fails(): void
    {
        [$modes, $reported] = $this->runCopyWithFailingCloses(['r']);

        self::assertSame(['r', 'x'], $modes);
        self::assertSame("simulated close failure for the 'r' handle", $reported?->getMessage());
    }

    public function test_copy_attempts_both_closes_when_both_fail_and_reports_the_first_failure(): void
    {
        [$modes, $reported] = $this->runCopyWithFailingCloses(['x', 'r']);

        self::assertSame(['r', 'x'], $modes, 'Neither close is skipped because the other failed.');
        self::assertSame(
            "simulated close failure for the 'r' handle",
            $reported?->getMessage(),
            'The source close fails first, and the staged close that follows it is cleanup rather than a replacement for it.',
        );
    }

    /**
     * Amp\Closable does not promise close() is idempotent, so a
     * successful publication closes the staged handle exactly once. This
     * runs against a File that rejects a second close outright.
     */
    #[DataProvider('publicationEntryPoints')]
    public function test_a_successful_publication_closes_the_staged_handle_once(string $entryPoint): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->rejectSecondClose = true;
        $expected = self::runEntryPoint($adapter, $this->adapter, $entryPoint, 'once.txt');

        self::assertSame($expected, $adapter->read('once.txt'));
        self::assertSame(0600, fileperms("{$this->root}/once.txt") & 0777);
        self::assertSame(1, self::countCalls(self::closeModes($driver), 'x'), 'The staged handle is closed once and only once.');
        self::assertSame(['once.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function publicationEntryPoints(): iterable
    {
        yield 'write' => ['write'];
        yield 'writeStream' => ['writeStream'];
        yield 'copy' => ['copy'];
    }

    /**
     * Publishes 'the body' to $destination through $entryPoint and
     * returns what should end up there. 'source.txt' exists either way,
     * so every case leaves the same directory behind.
     */
    private static function runEntryPoint(AmpFileAdapter $adapter, AmpFileAdapter $plain, string $entryPoint, string $destination): string
    {
        $body = 'the body';
        $private = new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]);
        $plain->write('source.txt', $body, $private);

        match ($entryPoint) {
            'write' => $adapter->write($destination, $body, $private),
            'writeStream' => $adapter->writeStream($destination, self::streamOf($body), $private),
            'copy' => $adapter->copy('source.txt', $destination, new Config()),
        };

        return $body;
    }

    /**
     * A File that rejects a second close must not turn a failed transfer
     * into a different failure either: the staged handle was never
     * closed, so the cleanup path closes it once.
     */
    public function test_a_failed_transfer_closes_the_staged_handle_once(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('kept.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->rejectSecondClose = true;
        $driver->writeThrows = new RuntimeException('the primary failure');

        try {
            $adapter->write('kept.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('the primary failure', $e->getMessage());
        }

        self::assertSame(['x'], self::closeModes($driver));
        self::assertSame('the previous occupant', $this->adapter->read('kept.txt'));
        self::assertSame(['kept.txt'], $this->rootEntries());
    }

    /** And a source close that fails leaves the staged handle closed exactly once. */
    public function test_a_failed_source_close_closes_the_staged_handle_once(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'x', new Config());
        $driver->rejectSecondClose = true;
        $driver->failCloseForModes = ['r'];

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertSame("simulated close failure for the 'r' handle", $e->getPrevious()?->getMessage());
        }

        self::assertSame(['r', 'x'], self::closeModes($driver));
        self::assertSame(['source.txt'], $this->rootEntries());
    }

    /**
     * The typed outcome survives a queue or a log store and arrives
     * carrying its public contract and nothing else.
     *
     * Native exception serialization would take the inherited file, line
     * and trace with it, and zend.exception_ignore_args is off here so
     * that trace holds every argument below the throw: FILESYSTEM_ROOT,
     * the staging path, the physical destination, the driver's own
     * sentinels, and the fill closure that serialize() refuses outright.
     * The assertions on the live trace establish that those are present
     * before the serialized and restored forms are checked for them.
     */
    public function test_the_indeterminate_outcome_serializes_to_its_public_contract_alone(): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            [$adapter, $driver] = $this->instrumentedAdapter();
            $this->adapter->write('sentinel.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            $driver->failMove = true;
            $driver->stagedStatusFaultAfterMove = true;

            $thrown = null;

            try {
                $adapter->write('sentinel.txt', 'the replacement', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            } catch (IndeterminatePublicationException $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(IndeterminatePublicationException::class, $thrown);

            $trace = print_r($thrown->getTrace(), true);
            self::assertStringContainsString($this->root, $trace, 'The trace holds the internals the whitelist has to keep out.');
            self::assertStringContainsString('Closure', $trace, 'And a closure, which native serialization refuses.');

            $serialized = serialize($thrown);
            $restored = unserialize($serialized);

            self::assertInstanceOf(IndeterminatePublicationException::class, $restored);
            self::assertSame('sentinel.txt', $restored->path, 'The logical path survives the round trip.');
            self::assertSame(IndeterminatePublicationException::REASON_UNREADABLE, $restored->reason);
            self::assertSame($thrown->getMessage(), $restored->getMessage());
            self::assertSame($thrown->getCode(), $restored->getCode());
            self::assertNull($restored->getPrevious());

            $carried = [
                'serialized form' => $serialized,
                'restored path' => $restored->path,
                'restored reason' => $restored->reason,
                'restored message' => $restored->getMessage(),
                're-serialized form' => serialize($restored),
            ];

            foreach ($carried as $label => $form) {
                foreach ($this->publicationSentinels() as $name => $sentinel) {
                    self::assertStringNotContainsString($sentinel, $form, "The {$name} must not reach the {$label}.");
                }
            }

            // A restored exception's file, line and trace describe the
            // unserialize() call, not the throw — PHP fills them in
            // wherever an exception object comes into being. What must
            // not survive is anything from the original raise, so the
            // sentinels unique to it are checked there too.
            $restoredTrace = print_r([$restored->getFile(), $restored->getLine(), $restored->getTrace()], true);

            foreach (['staging path', 'rename sentinel', 'status sentinel'] as $name) {
                self::assertStringNotContainsString($this->publicationSentinels()[$name], $restoredTrace, "The {$name} must not survive the round trip.");
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '1' : $ignoreArgs);
        }
    }

    /**
     * Every value the exception is raised amongst and must not carry.
     *
     * @return array<string, string>
     */
    private function publicationSentinels(): array
    {
        return [
            'physical root' => $this->root,
            'physical destination' => "{$this->root}/sentinel.txt",
            'staging path' => self::STAGING_PREFIX,
            'rename sentinel' => 'simulated rename failure',
            'status sentinel' => 'simulated status failure for the staged file',
            'closure' => 'Closure',
        ];
    }

    /**
     * The typed outcome stays actionable while carrying nothing a log or
     * a serialized copy should not: no FILESYSTEM_ROOT, no staging path,
     * no driver diagnostics, and no chained cause holding them.
     */
    private function assertNoInternalDetailLeaks(IndeterminatePublicationException $e, string $driverMessage): void
    {
        self::assertNull($e->getPrevious(), 'Nothing is chained, so nothing chained can carry internals.');

        foreach (['message' => $e->getMessage(), 'string form' => (string) $e] as $label => $form) {
            $this->assertCarriesNoInternals($form, $driverMessage, $label);
        }
    }

    private function assertCarriesNoInternals(string $form, string $driverMessage, string $label): void
    {
        self::assertStringNotContainsString($this->root, $form, "The physical root must not reach the {$label}.");
        self::assertStringNotContainsString(self::STAGING_PREFIX, $form, "The staging path must not reach the {$label}.");
        self::assertStringNotContainsString($driverMessage, $form, "The driver's own diagnostics must not reach the {$label}.");
    }

    /**
     * A close failure on the way out of a failed transfer must not
     * become the failure reported: the source verification is what
     * actually went wrong, and a caller reading getPrevious() has to
     * see that, not the cleanup that followed it. Both closes are still
     * attempted.
     */
    public function test_copy_reports_the_source_change_rather_than_the_close_failure_that_followed_it(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $source = "{$this->root}/swapped-then-close-fails.txt";
        $this->adapter->write('swapped-then-close-fails.txt', 'the original', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $driver->beforeOpenFile = static function (string $path, string $mode) use ($source, $driver): void {
            if ($path !== $source || $mode !== 'r') {
                return;
            }

            unlink($source);
            file_put_contents($source, 'the replacement');
            chmod($source, 0644);
            $driver->failCloseForModes = ['x', 'r'];
        };

        try {
            $adapter->copy('swapped-then-close-fails.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(
                UnableToRetrieveMetadata::class,
                $e->getPrevious(),
                'The primary failure must survive the close failures that follow it, not be replaced by one.',
            );
        }

        self::assertFalse($adapter->fileExists('destination.txt'));
    }

    public function test_copy_with_an_invalid_explicit_visibility_never_touches_a_preexisting_destination(): void
    {
        $this->adapter->write('source.txt', 'x', new Config());
        $this->adapter->write('destination.txt', 'original content', new Config());

        try {
            $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => 'not-a-visibility']));
            self::fail('Expected InvalidVisibilityProvided.');
        } catch (InvalidVisibilityProvided) {
            // Expected — escapes as itself, never relabeled as an UnableToCopyFile.
        }

        self::assertSame('original content', $this->adapter->read('destination.txt'));
    }

    /** rename() moves the same inode, so the destination already carries the source's own mode with nothing to apply. */
    public function test_move_preserves_the_sources_visibility_through_the_rename(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->move('source.txt', 'destination.txt', new Config());

        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('destination.txt')->visibility());
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    public function test_move_with_explicit_visibility_overrides_the_source(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->move('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('destination.txt')->visibility());
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    /**
     * visibility() is file-only by contract, not merely by this class's
     * own choice — see its own docblock in AmpFileAdapter. This exercises
     * the identical inverseForFile() path League\Flysystem\Local\LocalFilesystemAdapter
     * (Flysystem's own reference local adapter) uses for a directory
     * path too, proving parity with the upstream contract rather than a
     * latent bug unique to this class.
     */
    public function test_visibility_on_a_directory_uses_the_same_file_only_contract_as_upstream(): void
    {
        $this->adapter->createDirectory('a-directory', new Config());
        // 0755 is not a *file* mode under PortableVisibilityConverter's
        // defaults (0644 public / 0600 private) — inverseForFile() falls
        // back to its own documented PUBLIC default for anything it
        // doesn't recognise, which is exactly what this exercises.
        chmod("{$this->root}/a-directory", 0755);

        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('a-directory')->visibility());
    }

    // --- copy()'s retained-visibility resolution: unresolvable or
    // changed source metadata must never silently become
    // Visibility::PUBLIC. resolveCopyMode() and sourceModeUnchanged()
    // are the pure decision logic behind that guarantee, deliberately
    // filesystem-free so both are directly, deterministically testable
    // via reflection — matching this project's own established
    // precedent (Kinetis\CacheRedis\Tests\Cluster\ClusterTopologyTest's
    // identical ReflectionMethod-on-a-private-method pattern) — rather
    // than needing a real OS-scheduling race to exercise them. ---

    public function test_resolve_copy_mode_never_falls_back_to_public_for_unresolvable_source_metadata(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyMode')
            ->invoke($this->adapter, 'source.txt', null, true, null);
    }

    /**
     * A genuinely resolvable status, by contrast, must produce the real
     * mapped mode — not the exception the test above proves for the
     * unresolvable case — pinning both sides of the same branch directly
     * against the extracted resolver, independent of the
     * filesystem-level round trip test_copy_by_default_retains_the_sources_visibility()
     * already covers end-to-end.
     */
    public function test_resolve_copy_mode_maps_a_resolvable_status_to_the_real_mode(): void
    {
        $method = new ReflectionMethod($this->adapter, 'resolveCopyMode');

        self::assertSame(0600, $method->invoke($this->adapter, 'source.txt', null, true, ['mode' => 0600]));
        self::assertSame(0644, $method->invoke($this->adapter, 'source.txt', null, true, ['mode' => 0644]));
    }

    /**
     * A status array missing the 'mode' key entirely (not the same
     * shape as a null status, but equally unknown metadata) must throw
     * exactly like the null case — never coerced to 0 and mapped to
     * Visibility::PUBLIC.
     */
    public function test_resolve_copy_mode_throws_on_a_status_missing_its_mode_key(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyMode')
            ->invoke($this->adapter, 'source.txt', null, true, ['size' => 123]);
    }

    /**
     * A non-int mode is equally unresolvable — not assumed impossible
     * just because every Amp\File driver installed today always
     * populates an int; nothing in the driver's own contract guarantees
     * that, so this is checked explicitly rather than trusted.
     */
    public function test_resolve_copy_mode_throws_on_a_non_integer_mode(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyMode')
            ->invoke($this->adapter, 'source.txt', null, true, ['mode' => '0644']);
    }

    /** An explicit mode or retain_visibility=false never even needs the source's status. */
    public function test_resolve_copy_mode_skips_source_status_when_not_needed(): void
    {
        $method = new ReflectionMethod($this->adapter, 'resolveCopyMode');

        self::assertSame(0600, $method->invoke($this->adapter, 'source.txt', 0600, true, null));
        self::assertNull($method->invoke($this->adapter, 'source.txt', null, false, null));
    }

    // --- sourceUnchanged(): the pure comparison
    // sourceStillMatches() defers to, closing the specific gap the
    // reflection tests above can't reach on their own — a source whose
    // mode genuinely *changed* between the two real stats copy() takes,
    // as opposed to one that was never resolvable in the first place.
    // Tested with fabricated status arrays, deterministically, rather
    // than a real race — the same "extract the pure decision, test it
    // directly" approach resolveCopyMode() itself already uses,
    // for the identical reason: Amp\File\Filesystem is `final`, and
    // reliably forcing a real mode change to land in the exact window
    // between two real stats isn't achievable without a seam this
    // project's own conventions rule out adding solely for a test. ---

    public function test_source_mode_unchanged_is_true_for_two_identical_observations(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertTrue($method->invoke(
            null,
            ['mode' => 0600, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0600, 'dev' => 88, 'ino' => 1000],
        ));
    }

    /** The mode a real chmod would actually produce mid-copy — the exact scenario this method exists to catch. */
    public function test_source_mode_unchanged_is_false_when_the_mode_genuinely_changed(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0600], ['mode' => 0644]));
    }

    /** The source vanished entirely between the two stats — at least as unresolvable as a mode change. */
    public function test_source_mode_unchanged_is_false_when_the_source_vanished(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0600], null));
    }

    /** A real, full mode (file type plus permissions, exactly what getStatus() actually returns) matching itself. */
    public function test_source_mode_unchanged_is_true_for_a_realistic_full_mode_matching_itself(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertTrue($method->invoke(
            null,
            ['mode' => 0100644, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0100644, 'dev' => 88, 'ino' => 1000],
        ));
    }

    /**
     * The exact scenario raised directly: a path changing from a
     * regular file to a directory or a symlink, while coincidentally
     * sharing the same low 9 permission bits as the original file, must
     * never be reported as unchanged — the file type is as much a part
     * of "the source is still what it was" as its permissions are.
     * 0040644/0120644 are real getStatus() shapes (S_IFDIR/S_IFLNK
     * combined with the identical 0644 permission bits 0100644 already
     * uses above), not synthetic values.
     */
    public function test_source_mode_unchanged_is_false_when_the_file_type_changed_despite_identical_permission_bits(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0100644], ['mode' => 0040644]), 'regular file -> directory');
        self::assertFalse($method->invoke(null, ['mode' => 0100644], ['mode' => 0120644]), 'regular file -> symlink');
    }

    /**
     * A bare permission-only value (no file-type bits set at all) is
     * itself a different file-type observation from a real regular
     * file's mode — proving the type bits are genuinely part of the
     * comparison, not masked away.
     */
    public function test_source_mode_unchanged_is_false_when_one_side_carries_no_type_bits_at_all(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0100644], ['mode' => 0644]));
    }

    public function test_source_mode_unchanged_is_false_when_either_side_is_missing_or_not_an_integer_mode(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(null, ['size' => 1], ['mode' => 0644]));
        self::assertFalse($method->invoke(null, ['mode' => 0644], ['size' => 1]));
        self::assertFalse($method->invoke(null, ['mode' => '0644'], ['mode' => 0644]));
    }

    /**
     * The gap mode bits alone cannot close: a replacement created with
     * the original's own permissions is identical on type and mode and
     * is still a different file. The device and inode a stat carries are
     * the only fields that say so.
     */
    public function test_source_unchanged_is_false_when_the_inode_changed_despite_an_identical_mode(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(
            null,
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1001],
        ));
    }

    /** The same file name resolving onto a different filesystem entirely. */
    public function test_source_unchanged_is_false_when_the_device_changed(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(
            null,
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0100600, 'dev' => 89, 'ino' => 1000],
        ));
    }

    public function test_source_unchanged_is_true_for_the_same_identity_observed_twice(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertTrue($method->invoke(
            null,
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
        ));
    }

    /**
     * An identity the filesystem does not report (PHP gives an
     * unavailable field as 0, not as a missing key) makes the source
     * unverifiable, and unverifiable fails closed rather than falling
     * back to type and mode, which cannot tell one file from another at
     * the same path. Every driver this adapter supports reports both
     * fields.
     */
    public function test_source_unchanged_fails_closed_when_identity_is_unavailable(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceUnchanged');

        self::assertFalse($method->invoke(
            null,
            ['mode' => 0100600, 'dev' => 0, 'ino' => 0],
            ['mode' => 0100600, 'dev' => 0, 'ino' => 0],
        ));
        self::assertFalse($method->invoke(
            null,
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
            ['mode' => 0100600, 'dev' => 0, 'ino' => 0],
        ));
        self::assertFalse($method->invoke(
            null,
            ['mode' => 0100600],
            ['mode' => 0100600, 'dev' => 88, 'ino' => 1000],
        ));
    }

    // These reflection tests cover every input shape a real race can
    // produce (identical, changed, vanished, replaced under the same
    // mode, or malformed observations) without depending on OS
    // scheduling. The two
    // test_copy_rejects_a_source_replaced_* cases drive the change
    // itself, at an exact point, through the driver seam.

    /**
     * The documented taxonomy, end to end: a source whose mode cannot
     * be resolved fails as UnableToCopyFile with the
     * UnableToRetrieveMetadata that caused it as its previous — never
     * that metadata exception escaping raw — and leaves no destination
     * behind.
     */
    public function test_copy_of_a_nonexistent_source_wraps_the_metadata_failure_as_unable_to_copy_file(): void
    {
        try {
            $this->adapter->copy('never-existed.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(UnableToRetrieveMetadata::class, $e->getPrevious());
        }

        self::assertFalse($this->adapter->fileExists('destination.txt'));
        self::assertSame([], $this->rootEntries());
    }

    /**
     * With retention switched off there is no metadata step to fail
     * first, so the same missing source surfaces as the open failure —
     * still wrapped as UnableToCopyFile.
     */
    public function test_copy_of_a_nonexistent_source_without_retention_wraps_the_open_failure(): void
    {
        try {
            $this->adapter->copy('never-existed.txt', 'destination.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($this->adapter->fileExists('destination.txt'));
        self::assertSame([], $this->rootEntries());
    }

    // --- copy()/move() with source === destination: the identical-path
    // TRY resolution Filesystem::copy()/move() default to still
    // delegates all the way to the adapter, so a naive open($to, 'w')
    // would truncate the very file being "copied" before ever reading
    // it. Every case below writes real, non-empty, binary content
    // first and asserts both the bytes and the real on-disk mode
    // afterward — never just "no exception was thrown." ---

    public function test_copying_a_file_onto_itself_via_the_public_filesystem_default_try_is_non_destructive(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('same-fs-try.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $filesystem->copy('same-fs-try.txt', 'same-fs-try.txt');

        self::assertSame($content, $this->adapter->read('same-fs-try.txt'));
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('same-fs-try.txt')->visibility());
        self::assertSame(0600, fileperms("{$this->root}/same-fs-try.txt") & 0777);
    }

    public function test_copying_a_file_onto_itself_via_the_adapter_directly_is_non_destructive(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-direct.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('same-direct.txt', 'same-direct.txt', new Config());

        self::assertSame($content, $this->adapter->read('same-direct.txt'));
        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('same-direct.txt')->visibility());
        self::assertSame(0644, fileperms("{$this->root}/same-direct.txt") & 0777);
    }

    public function test_copying_a_file_onto_itself_with_an_explicit_visibility_applies_it_in_place(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-explicit.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('same-explicit.txt', 'same-explicit.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('same-explicit.txt'));
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('same-explicit.txt')->visibility());
        self::assertSame(0600, fileperms("{$this->root}/same-explicit.txt") & 0777);
    }

    /**
     * The real gap a canonical PUBLIC/PRIVATE mode can't expose: 0640
     * is a real, restrictive, non-canonical mode — neither
     * PortableVisibilityConverter::filePublic (0644) nor filePrivate
     * (0600) — so reading it back through inverseForFile() and
     * reapplying the result would silently canonicalize it to PUBLIC's
     * 0644, broadening the file's permissions with no explicit request
     * to do so. No visibility step must run at all here; the raw mode
     * has to stay exactly 0640, not merely "still restrictive."
     */
    public function test_copying_a_file_onto_itself_via_the_public_filesystem_default_try_does_not_broaden_a_noncanonical_mode(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('same-noncanonical-fs.txt', $content, new Config());
        chmod("{$this->root}/same-noncanonical-fs.txt", 0640);

        $filesystem->copy('same-noncanonical-fs.txt', 'same-noncanonical-fs.txt');

        self::assertSame($content, $this->adapter->read('same-noncanonical-fs.txt'));
        self::assertSame(0640, fileperms("{$this->root}/same-noncanonical-fs.txt") & 0777);
    }

    public function test_copying_a_file_onto_itself_via_the_adapter_directly_does_not_broaden_a_noncanonical_mode(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-noncanonical-direct.txt', $content, new Config());
        chmod("{$this->root}/same-noncanonical-direct.txt", 0640);

        $this->adapter->copy('same-noncanonical-direct.txt', 'same-noncanonical-direct.txt', new Config());

        self::assertSame($content, $this->adapter->read('same-noncanonical-direct.txt'));
        self::assertSame(0640, fileperms("{$this->root}/same-noncanonical-direct.txt") & 0777);
    }

    /**
     * retain_visibility is never consulted at all for an identical path
     * — there's no separate destination to retain the source's
     * visibility *onto*. Explicitly setting it false must make no
     * difference, proven against the same noncanonical mode: if
     * retain_visibility were still being read here, both true and false
     * would already leave 0640 untouched for an unrelated reason (no
     * explicit visibility given), so this specifically confirms the
     * option is never even inspected, not merely that its default
     * happens to be harmless.
     */
    public function test_copying_a_file_onto_itself_with_retain_visibility_false_leaves_content_and_mode_unchanged(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-no-retain.txt', $content, new Config());
        chmod("{$this->root}/same-no-retain.txt", 0640);

        $this->adapter->copy('same-no-retain.txt', 'same-no-retain.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        self::assertSame($content, $this->adapter->read('same-no-retain.txt'));
        self::assertSame(0640, fileperms("{$this->root}/same-no-retain.txt") & 0777);
    }

    /**
     * FAIL is resolved entirely by Filesystem::copy() itself — the
     * adapter is never called at all, so this is really proving the
     * outer strategy still works through the public API, not a new
     * behavior of this adapter's own copy().
     */
    public function test_copying_a_file_onto_itself_with_the_fail_strategy_throws_and_leaves_it_untouched(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('same-fail.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        try {
            $filesystem->copy('same-fail.txt', 'same-fail.txt', [
                Config::OPTION_COPY_IDENTICAL_PATH => ResolveIdenticalPathConflict::FAIL,
            ]);
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile) {
            // Expected.
        }

        self::assertSame($content, $this->adapter->read('same-fail.txt'));
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('same-fail.txt')->visibility());
    }

    /**
     * IGNORE returns before Filesystem::copy() ever calls the adapter —
     * so even an explicit visibility request in the same call must have
     * no effect at all, not just "no exception."
     */
    public function test_copying_a_file_onto_itself_with_the_ignore_strategy_does_nothing_at_all(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('same-ignore.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $filesystem->copy('same-ignore.txt', 'same-ignore.txt', [
            Config::OPTION_COPY_IDENTICAL_PATH => ResolveIdenticalPathConflict::IGNORE,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]);

        self::assertSame($content, $this->adapter->read('same-ignore.txt'));
        self::assertSame(
            Visibility::PRIVATE,
            $this->adapter->visibility('same-ignore.txt')->visibility(),
            'IGNORE must never reach the adapter, so an explicit visibility request in the same call must have no effect.',
        );
    }

    /**
     * move() needed no production change: Amp\File\Filesystem::move()
     * (a real rename()) to the same path is POSIX-guaranteed to be a
     * no-op, confirmed directly against this real backend, not assumed
     * from the spec alone — this proves it stays that way.
     */
    public function test_moving_a_file_onto_itself_via_the_adapter_directly_is_non_destructive(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('move-same.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->move('move-same.txt', 'move-same.txt', new Config());

        self::assertSame($content, $this->adapter->read('move-same.txt'));
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('move-same.txt')->visibility());
    }

    public function test_moving_a_file_onto_itself_via_the_public_filesystem_default_try_is_non_destructive(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('move-same-fs.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $filesystem->move('move-same-fs.txt', 'move-same-fs.txt');

        self::assertSame($content, $this->adapter->read('move-same-fs.txt'));
        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('move-same-fs.txt')->visibility());
    }

    public function test_moving_a_file_onto_itself_with_an_explicit_visibility_applies_it_in_place(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('move-same-explicit.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->move('move-same-explicit.txt', 'move-same-explicit.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('move-same-explicit.txt'));
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('move-same-explicit.txt')->visibility());
    }

    public function test_write_stream_then_read_stream_round_trips(): void
    {
        $contents = self::binaryContent();
        $source = fopen('php://temp', 'r+b');
        fwrite($source, $contents);
        rewind($source);

        $this->adapter->writeStream('streamed.txt', $source, new Config());

        $result = $this->adapter->readStream('streamed.txt');

        self::assertSame(0, ftell($result), 'readStream() must hand back a resource already positioned at byte zero.');
        self::assertSame($contents, stream_get_contents($result));
    }

    // --- populateTempStream() (readStream()'s own temp-stream
    // population, deliberately filesystem-free once it has a resource
    // to work with) is tested directly here via
    // Kinetis\Storage\Tests\Fixtures\FailingStreamWrapper — a real,
    // registered PHP stream wrapper whose write()/seek() behavior is
    // forced deterministically through stream context options, rather
    // than needing to genuinely exhaust memory/disk/file descriptors
    // to trigger a failure. ---

    /**
     * Confirmed directly, not assumed: PHP's own streams layer already
     * retries a short stream_write() internally, but only up to the
     * first zero-progress attempt — a single userspace fwrite() call
     * genuinely can return less than the full length requested, with
     * no error, the moment the underlying stream stalls after some
     * partial progress. writeReturns [10, 0] forces exactly that
     * combination: the first adapter-level fwrite() call observes 10
     * (not the full 30), proving populateTempStream()'s own loop must
     * continue rather than assume completion — the second adapter-level
     * call (writeReturns now exhausted) then succeeds normally,
     * finishing the transfer.
     */
    public function test_populate_temp_stream_continues_after_a_short_write(): void
    {
        $context = stream_context_create([FailingStreamWrapper::PROTOCOL => ['writeReturns' => [10, 0]]]);
        $stream = fopen(FailingStreamWrapper::PROTOCOL . '://test', 'r+b', context: $context);
        $contents = str_repeat('X', 30);

        new ReflectionMethod($this->adapter, 'populateTempStream')
            ->invoke($this->adapter, $stream, 'probe.txt', $contents);

        self::assertSame(0, ftell($stream), 'A successful population must leave the stream rewound to byte zero.');
        self::assertSame($contents, stream_get_contents($stream));

        fclose($stream);
    }

    /**
     * A write that fails outright (false) or makes zero progress on
     * its very first attempt — neither preceded by any partial success
     * in the same populateTempStream() call — must throw
     * UnableToReadFile immediately, not loop forever or silently
     * return a truncated stream.
     */
    public function test_populate_temp_stream_wraps_a_hard_write_failure_as_unable_to_read_file(): void
    {
        $context = stream_context_create([FailingStreamWrapper::PROTOCOL => ['writeReturns' => [false]]]);
        $stream = fopen(FailingStreamWrapper::PROTOCOL . '://test', 'r+b', context: $context);

        try {
            new ReflectionMethod($this->adapter, 'populateTempStream')
                ->invoke($this->adapter, $stream, 'probe.txt', 'some content');
            self::fail('Expected UnableToReadFile.');
        } catch (UnableToReadFile $e) {
            self::assertStringContainsString('probe.txt', $e->getMessage());
        }

        fclose($stream);
    }

    /**
     * A distinct case from the hard-failure (false) test above, not
     * covered by it: fwrite() returning a plain 0 is a genuinely
     * different observable outcome than false, and the short-write test
     * elsewhere in this file does not exercise it either — PHP's own
     * streams layer already retries a short stream_write() internally
     * up to the first zero-progress attempt, so a writeReturns
     * [10, 0] configuration lets the adapter's own fwrite() call see
     * only the already-completed 10, never the internal 0 that stopped
     * PHP's retry. A bare writeReturns [0], with nothing written yet in
     * this call, is what makes the adapter's own fwrite() observe 0
     * directly — confirmed to return immediately, not hang, via a
     * standalone timed probe before writing this test.
     */
    public function test_populate_temp_stream_wraps_a_zero_progress_write_as_unable_to_read_file(): void
    {
        $context = stream_context_create([FailingStreamWrapper::PROTOCOL => ['writeReturns' => [0]]]);
        $stream = fopen(FailingStreamWrapper::PROTOCOL . '://test', 'r+b', context: $context);

        try {
            new ReflectionMethod($this->adapter, 'populateTempStream')
                ->invoke($this->adapter, $stream, 'probe.txt', 'some content');
            self::fail('Expected UnableToReadFile.');
        } catch (UnableToReadFile $e) {
            self::assertStringContainsString('probe.txt', $e->getMessage());
        }

        fclose($stream);
    }

    /**
     * A rewind() failure after the write itself succeeded must also
     * surface as UnableToReadFile — construction/population/rewind are
     * all part of the same read operation.
     */
    public function test_populate_temp_stream_wraps_a_rewind_failure_as_unable_to_read_file(): void
    {
        $context = stream_context_create([FailingStreamWrapper::PROTOCOL => ['failSeek' => true]]);
        $stream = fopen(FailingStreamWrapper::PROTOCOL . '://test', 'r+b', context: $context);

        try {
            new ReflectionMethod($this->adapter, 'populateTempStream')
                ->invoke($this->adapter, $stream, 'probe.txt', 'some content');
            self::fail('Expected UnableToReadFile.');
        } catch (UnableToReadFile $e) {
            self::assertStringContainsString('probe.txt', $e->getMessage());
        }

        fclose($stream);
    }

    public function test_file_size_and_last_modified_reflect_real_metadata(): void
    {
        $this->adapter->write('sized.txt', '12345', new Config());

        self::assertSame(5, $this->adapter->fileSize('sized.txt')->fileSize());
        self::assertIsInt($this->adapter->lastModified('sized.txt')->lastModified());
    }

    public function test_mime_type_is_detected_from_content(): void
    {
        $this->adapter->write('document.json', '{"key": "value"}', new Config());

        self::assertSame('application/json', $this->adapter->mimeType('document.json')->mimeType());
    }

    /**
     * File::read() is a ReadableStream operation and can throw
     * Amp\ByteStream\StreamException, not just Amp\File\FilesystemException
     * — a failure here must surface as UnableToRetrieveMetadata::mimeType(),
     * never a raw stream-level exception escaping the metadata contract.
     */
    public function test_mime_type_wraps_a_stream_read_failure_as_unable_to_retrieve_metadata(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('mime-probe.txt', 'plain text content', new Config());
        $driver->failRead = true;

        try {
            $adapter->mimeType('mime-probe.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertSame('mime_type', $e->metadataType());
            self::assertSame('mime-probe.txt', $e->location());
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }
    }

    /**
     * If close() also fails while a read failure is already
     * propagating, PHP would otherwise make the close failure the new
     * outer exception and chain the read failure beneath it as
     * previous — not discarded, but pushed one level deeper than
     * mimeType()'s own catch can see directly, which is the masking
     * readMimeTypeSample() exists to prevent. Both failures are forced
     * simultaneously; the reported exception's previous cause must
     * still be the read failure, not the close failure.
     */
    public function test_mime_type_preserves_the_read_failure_when_close_also_fails(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('mime-probe-both-fail.txt', 'plain text content', new Config());
        $driver->failRead = true;
        $driver->failCloseForModes = ['r'];

        try {
            $adapter->mimeType('mime-probe-both-fail.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
            self::assertSame('simulated stream read failure', $e->getPrevious()?->getMessage());
            self::assertSame(1, self::countCalls(self::closeModes($driver), 'r'), 'The handle is closed once, by readMimeTypeSample() itself.');
        }
    }

    /**
     * A close() failure with no read failure in flight is not
     * absorbed — it must still surface as UnableToRetrieveMetadata, the
     * same "closing is part of the operation" precedent
     * write()/writeStream() already establish for their own handles.
     */
    public function test_mime_type_wraps_a_close_only_failure_as_unable_to_retrieve_metadata(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('mime-probe-close-only.txt', 'plain text content', new Config());
        $driver->failCloseForModes = ['r'];

        try {
            $adapter->mimeType('mime-probe-close-only.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
        }
    }

    public function test_set_visibility_then_visibility_round_trips(): void
    {
        $this->adapter->write('secret.txt', 'x', new Config());

        $this->adapter->setVisibility('secret.txt', Visibility::PRIVATE);
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('secret.txt')->visibility());

        $this->adapter->setVisibility('secret.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('secret.txt')->visibility());
    }

    public function test_list_contents_shallow_does_not_descend_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', false)));

        self::assertContains('top.txt', $paths);
        self::assertContains('sub', $paths);
        self::assertNotContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_deep_descends_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', true)));

        self::assertContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_distinguishes_files_from_directories(): void
    {
        $this->adapter->write('file.txt', 'x', new Config());
        $this->adapter->createDirectory('directory', new Config());

        $entries = iterator_to_array($this->adapter->listContents('', false));
        $byPath = [];

        foreach ($entries as $entry) {
            $byPath[$entry->path()] = $entry;
        }

        self::assertTrue($byPath['file.txt']->isFile());
        self::assertInstanceOf(DirectoryAttributes::class, $byPath['directory']);
    }

    // --- Symlink policy: no path is ever allowed to resolve through a
    // symlink, whether the symlink is the requested path's own leaf, an
    // intermediate directory component, or an entry discovered while
    // listing/recursively deleting. ---

    public function test_reading_through_a_symlinked_directory_is_rejected(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        $this->expectException(SymbolicLinkEncountered::class);
        $this->adapter->read('link/secret.txt');
    }

    public function test_writing_through_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");

        try {
            $this->adapter->write('link/new.txt', 'should not land outside', new Config());
            self::fail('write() through a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/new.txt", 'the write must never have reached outside root');
    }

    public function test_reading_a_symlinked_file_directly_is_rejected(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink("{$this->outside}/secret.txt", "{$this->root}/shortcut.txt");

        $this->expectException(SymbolicLinkEncountered::class);
        $this->adapter->read('shortcut.txt');
    }

    public function test_deleting_a_symlinked_directory_does_not_touch_its_target(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        try {
            $this->adapter->deleteDirectory('link');
            self::fail('deleteDirectory() on a symlink should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    public function test_deleting_a_directory_containing_a_nested_symlink_does_not_touch_the_links_target(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        mkdir("{$this->root}/safe");
        symlink($this->outside, "{$this->root}/safe/evil-link");

        try {
            $this->adapter->deleteDirectory('safe');
            self::fail('deleteDirectory() should have thrown on the nested symlink.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    /**
     * A symlink discovered partway through a directory's real entries
     * must not leave the entries visited earlier already deleted —
     * deleteDirectory() plans the whole subtree before deleting anything,
     * specifically so this doesn't depend on which order the filesystem
     * happens to list entries in.
     */
    public function test_deleting_a_directory_with_a_symlink_leaves_every_other_entry_intact(): void
    {
        // Amp\File's blocking driver lists entries via scandir(), which
        // sorts alphabetically by default — the safe entries are named to
        // sort *before* the symlink specifically so this test exercises
        // the real hazard (entries a combined walk-and-delete pass would
        // have already deleted before reaching the symlink), rather than
        // happening to pass merely because the symlink was listed first.
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        mkdir("{$this->root}/safe");
        file_put_contents("{$this->root}/safe/a-one.txt", 'one');
        file_put_contents("{$this->root}/safe/a-two.txt", 'two');
        mkdir("{$this->root}/safe/a-nested");
        file_put_contents("{$this->root}/safe/a-nested/a-three.txt", 'three');
        symlink($this->outside, "{$this->root}/safe/z-evil-link");

        try {
            $this->adapter->deleteDirectory('safe');
            self::fail('deleteDirectory() should have thrown on the nested symlink.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->root}/safe/a-one.txt", 'a sibling entry must survive a symlink found elsewhere in the same directory');
        self::assertFileExists("{$this->root}/safe/a-two.txt", 'a sibling entry must survive a symlink found elsewhere in the same directory');
        self::assertFileExists("{$this->root}/safe/a-nested/a-three.txt", 'a nested file below a safe subdirectory must survive');
        self::assertDirectoryExists("{$this->root}/safe/a-nested");
        self::assertDirectoryExists("{$this->root}/safe");
        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    public function test_moving_into_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");
        $this->adapter->write('source.txt', 'contents', new Config());

        try {
            $this->adapter->move('source.txt', 'link/destination.txt', new Config());
            self::fail('move() into a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/destination.txt");
        self::assertTrue($this->adapter->fileExists('source.txt'), 'the source must be untouched on rejection');
    }

    public function test_copying_into_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");
        $this->adapter->write('source.txt', 'contents', new Config());

        try {
            $this->adapter->copy('source.txt', 'link/destination.txt', new Config());
            self::fail('copy() into a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/destination.txt");
    }

    public function test_deep_listing_throws_on_a_symlinked_directory_instead_of_descending_into_it(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        $this->expectException(SymbolicLinkEncountered::class);
        iterator_to_array($this->adapter->listContents('', true));
    }

    public function test_deep_listing_does_not_loop_forever_on_a_symlink_cycle(): void
    {
        symlink($this->root, "{$this->root}/loop");

        $this->expectException(SymbolicLinkEncountered::class);
        iterator_to_array($this->adapter->listContents('', true));
    }

    public function test_file_exists_reports_false_through_a_symlink_rather_than_throwing(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        self::assertFalse($this->adapter->fileExists('link/secret.txt'));
    }

    public function test_directory_exists_reports_false_for_a_symlink_itself(): void
    {
        symlink($this->outside, "{$this->root}/link");

        self::assertFalse($this->adapter->directoryExists('link'));
    }
}
