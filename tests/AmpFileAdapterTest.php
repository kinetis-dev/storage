<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\ByteStream\StreamException;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Amp\File\FilesystemException;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Tests\Fixtures\SelectivelyFailingFilesystemDriver;
use Kinetis\Storage\Tests\Fixtures\WarningRaisingTempStreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\ResolveIdenticalPathConflict;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\File\createDefaultDriver;

/**
 * The behaviour AmpFileAdapter promises against a real filesystem:
 * round trips, the visibility matrix, atomic complete publication and
 * what a failed call leaves behind, self copy/move, the symlink policy,
 * and the resource methods.
 */
final class AmpFileAdapterTest extends TestCase
{
    /** The staging directory name prefix AmpFileAdapter publishes through. */
    private const string STAGING_PREFIX = '.kinetis-stage.';

    /** The single entry a staging directory holds. */
    private const string STAGED_FILE_NAME = 'staged';

    /**
     * One driver for the whole class, so the worker pool behind
     * Amp\File\createDefaultDriver() is started once rather than per
     * test. Built directly rather than through Amp\File\filesystem(),
     * which caches a positive status per path — see FilesystemFactory.
     */
    private static ?AmpFilesystem $filesystem = null;

    private string $root;

    /**
     * A sibling directory, outside $root, that the symlink tests below
     * point a link at — the thing $root is supposed to be a boundary
     * against reaching.
     */
    private string $outside;

    private AmpFileAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-test-' . bin2hex(random_bytes(8));
        $this->outside = sys_get_temp_dir() . '/kinetis-storage-test-outside-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
        $this->adapter = new AmpFileAdapter(self::filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->outside);
    }

    private static function filesystem(): AmpFilesystem
    {
        return self::$filesystem ??= new AmpFilesystem(createDefaultDriver());
    }

    /**
     * An adapter over a driver that can be made to fail one named call.
     * The blocking driver underneath keeps the failure injection in this
     * process: the seam is the decorator, and what is under test is the
     * adapter's own sequence, not which driver runs it.
     *
     * @return array{0: AmpFileAdapter, 1: SelectivelyFailingFilesystemDriver}
     */
    private function instrumentedAdapter(): array
    {
        $driver = new SelectivelyFailingFilesystemDriver(new BlockingFilesystemDriver());

        return [new AmpFileAdapter(new AmpFilesystem($driver), $this->root), $driver];
    }

    /**
     * Symlink-safe: a symlink entry is unlink()'d directly, never
     * followed via is_dir() (which, unlike this class's own
     * Filesystem::isSymlink(), does follow) — the exact distinction the
     * policy this file tests is built around.
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

    /** Non-empty and binary — null bytes and non-ASCII bytes included, not a trivial "x". */
    private static function binaryContent(): string
    {
        return "\x00\x01\xFF\xFEbinary payload\xDE\xAD\xBE\xEF" . str_repeat('y', 500);
    }

    /**
     * Every entry directly under $root, dot entries excluded but
     * dotfiles included — a staging directory is a dotfile, so this is
     * what proves one did not survive a failed publication.
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

    /** @return resource */
    private static function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    // --- Round trips and the ordinary operations. ---

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

        $byPath = [];

        foreach ($this->adapter->listContents('', false) as $entry) {
            $byPath[$entry->path()] = $entry;
        }

        self::assertTrue($byPath['file.txt']->isFile());
        self::assertInstanceOf(DirectoryAttributes::class, $byPath['directory']);
    }

    // --- The visibility matrix, against real on-disk modes. ---

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

    public function test_write_stream_with_explicit_private_visibility_applies_the_correct_mode(): void
    {
        $this->adapter->writeStream('private-stream.txt', self::streamOf('x'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0600, fileperms("{$this->root}/private-stream.txt") & 0777);
    }

    /** A brand-new file with no visibility requested lands on the umask default. */
    public function test_write_without_a_requested_visibility_publishes_a_new_file_at_the_umask_default(): void
    {
        $this->adapter->write('default-mode.txt', 'x', new Config());

        self::assertSame($this->defaultNewFileMode(), fileperms("{$this->root}/default-mode.txt") & 0777);
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

    public function test_copy_by_default_retains_the_sources_visibility(): void
    {
        $this->adapter->write('source-public.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $this->adapter->write('source-private.txt', 'y', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('source-public.txt', 'copy-public.txt', new Config());
        $this->adapter->copy('source-private.txt', 'copy-private.txt', new Config());

        self::assertSame(0644, fileperms("{$this->root}/copy-public.txt") & 0777);
        self::assertSame(0600, fileperms("{$this->root}/copy-private.txt") & 0777);
    }

    public function test_copy_with_explicit_visibility_overrides_the_source(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

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

        self::assertNotSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    public function test_copy_with_retain_visibility_false_keeps_an_existing_destinations_mode(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'y', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        self::assertSame('x', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
    }

    public function test_move_preserves_the_sources_visibility_through_the_rename(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->move('source.txt', 'destination.txt', new Config());

        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
    }

    public function test_move_with_explicit_visibility_overrides_the_source(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->move('source.txt', 'destination.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
    }

    public function test_move_applies_an_explicit_directory_visibility_through_the_directory_converter(): void
    {
        $this->adapter->createDirectory('source-dir', new Config());

        $this->adapter->move('source-dir', 'destination-dir', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0700, fileperms("{$this->root}/destination-dir") & 0777, 'A directory must never land on a file mode, which would make its contents unreachable.');
    }

    /**
     * `visibility` names the file a write publishes; a parent built on
     * the way to it reads `directory_visibility` alone, so a private
     * file never cuts off the siblings already published beside it.
     */
    public function test_an_implicitly_created_parent_reads_directory_visibility_not_visibility(): void
    {
        $this->adapter->write('one/public.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $this->adapter->write('two/public.txt', 'x', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(0644, fileperms("{$this->root}/one/public.txt") & 0777);
        self::assertSame(0700, fileperms("{$this->root}/one") & 0777, "The file's own visibility never reaches the parent, which stays at the converter's default.");
        self::assertSame(0755, fileperms("{$this->root}/two") & 0777, 'Only directory_visibility moves it.');
    }

    /**
     * createDirectory() reads `visibility` first and falls back to
     * `directory_visibility` — a call naming one directory means that
     * directory, whichever key it reached for.
     */
    public function test_create_directory_reads_visibility_first_and_directory_visibility_second(): void
    {
        $this->adapter->createDirectory('named-private', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->createDirectory('named-via-directory-key', new Config([Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0700, fileperms("{$this->root}/named-private") & 0777);
        self::assertSame(0700, fileperms("{$this->root}/named-via-directory-key") & 0777);
    }

    /**
     * A garbage visibility is a pure string-to-int conversion failure,
     * so it escapes as itself and the tree is left exactly as it was —
     * no staging directory, no truncated destination.
     */
    public function test_write_lets_an_invalid_explicit_visibility_escape_as_itself(): void
    {
        $this->adapter->write('untouched.txt', 'original content', new Config());

        try {
            $this->adapter->write('untouched.txt', 'replacement content', new Config([Config::OPTION_VISIBILITY => 'not-a-real-visibility']));
            self::fail('Expected InvalidVisibilityProvided.');
        } catch (InvalidVisibilityProvided) {
            // Expected.
        }

        self::assertSame('original content', $this->adapter->read('untouched.txt'));
        self::assertSame(['untouched.txt'], $this->rootEntries());
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
        self::assertSame(['untouched-stream.txt'], $this->rootEntries());
    }

    // --- Publication: staged beside the destination in a private
    // directory, published by one rename, and never partially. ---

    /**
     * The staged file lives inside a 0700 directory beside the
     * destination, never at the destination itself — the window this
     * structure exists to close, since a file created in a directory
     * others can read can be opened before any chmod reaches it, and
     * that descriptor survives every later permission change.
     */
    public function test_a_publication_stages_in_a_private_directory_and_reaches_the_destination_by_one_rename(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $body = 'this is the real body content';

        $adapter->write('ordering.txt', $body, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame([0700], $driver->stagingDirectoryModes, 'The staging directory is created private, never created wide and narrowed afterward.');
        self::assertCount(1, $driver->renames, 'The destination is reached by exactly one rename.');
        self::assertStringEndsWith(":{$this->root}/ordering.txt", $driver->renames[0]);

        $staged = explode(':', $driver->renames[0])[0];
        self::assertSame(self::STAGED_FILE_NAME, basename($staged));
        self::assertStringStartsWith(self::STAGING_PREFIX, basename(dirname($staged)));
        self::assertSame($this->root, dirname(dirname($staged)), 'The staging directory sits beside the destination, so the rename stays on one filesystem.');

        self::assertCount(1, $driver->permissionChanges, 'One mode is applied: the one the file is published under.');
        self::assertSame(0644, $driver->permissionChanges[0]['mode']);
        self::assertSame(\strlen($body), $driver->permissionChanges[0]['size'], 'Applied to a complete body, not to an empty file.');
        self::assertSame(0700, $driver->permissionChanges[0]['directoryMode'], 'And applied while the file is still inside the private staging directory.');
        self::assertStringEndsWith('/' . self::STAGED_FILE_NAME, $driver->permissionChanges[0]['path'], 'No mode is ever applied to the destination itself.');

        self::assertSame($body, $adapter->read('ordering.txt'));
        self::assertSame(0644, fileperms("{$this->root}/ordering.txt") & 0777);
        self::assertSame(['ordering.txt'], $this->rootEntries(), 'The staging directory is gone once the file is published.');
    }

    /**
     * The successful counterpart to every failure below: a replacement
     * lands whole, with the requested mode, and leaves the directory
     * holding nothing but the destination.
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

    public function test_copy_replaces_an_existing_destination_whole(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('source.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config());

        self::assertSame($content, $this->adapter->read('destination.txt'));
        self::assertSame(0600, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * The destination is never opened for writing, so a failure part-way
     * through leaves the file that was already there byte-for-byte
     * intact rather than emptied. Each case below fails at a different
     * step of the publication; the outcome has to be the same one.
     *
     * @param Closure(SelectivelyFailingFilesystemDriver): void $fault
     */
    #[DataProvider('publicationFaults')]
    public function test_a_failed_publication_preserves_the_destination_and_leaves_nothing_staged(
        \Closure $fault,
        string $cause,
    ): void {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $fault($driver);

        try {
            $adapter->write('destination.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf($cause, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777, 'Nor its mode.');
        self::assertSame(['destination.txt'], $this->rootEntries(), 'And nothing staged is left behind.');
    }

    /** @return iterable<string, array{\Closure(SelectivelyFailingFilesystemDriver): void, string}> */
    public static function publicationFaults(): iterable
    {
        yield 'the staging directory cannot be created' => [
            static function (SelectivelyFailingFilesystemDriver $driver): void {
                $driver->failCreateDirectory = true;
            },
            FilesystemException::class,
        ];

        yield 'the body write fails part way' => [
            static function (SelectivelyFailingFilesystemDriver $driver): void {
                $driver->failWriteAfterBytes = 8;
            },
            StreamException::class,
        ];

        yield 'the body write silently drops its tail' => [
            static function (SelectivelyFailingFilesystemDriver $driver): void {
                $driver->dropWritesAfterBytes = 8;
            },
            \RuntimeException::class,
        ];

        yield 'the published mode cannot be applied' => [
            static function (SelectivelyFailingFilesystemDriver $driver): void {
                $driver->failChangePermissions = true;
            },
            FilesystemException::class,
        ];

        yield 'the rename fails' => [
            static function (SelectivelyFailingFilesystemDriver $driver): void {
                $driver->failMove = true;
            },
            FilesystemException::class,
        ];
    }

    /**
     * A body that lost its tail without reporting it is the hazard
     * Amp\File\File::write()'s void return leaves open, and the staged
     * length check is what rejects it. Proven for each of the three
     * publishing operations, since each one counts the bytes it wrote
     * differently.
     */
    public function test_write_stream_rejects_a_body_that_silently_lost_its_tail(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('silent-stream.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->dropWritesAfterBytes = 8;

        $this->expectException(UnableToWriteFile::class);

        try {
            $adapter->writeStream('silent-stream.txt', self::streamOf(self::binaryContent()), new Config());
        } finally {
            self::assertSame('the previous occupant', $this->adapter->read('silent-stream.txt'));
            self::assertSame(['silent-stream.txt'], $this->rootEntries());
        }
    }

    public function test_copy_rejects_a_body_that_silently_lost_its_tail(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', self::binaryContent(), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $driver->dropWritesAfterBytes = 8;

        $this->expectException(UnableToCopyFile::class);

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
        } finally {
            self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
            self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
        }
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
     * A rename that fails before the kernel is ever asked — which is
     * what this driver injects — is the operation's failure, whatever
     * type it failed with, and leaves the destination the caller
     * already had. A rename the kernel did perform but did not
     * acknowledge is indistinguishable from this one to the adapter;
     * {doc}`storage` states what that leaves a caller able to conclude.
     */
    public function test_a_failed_rename_fails_the_copy_and_leaves_the_destination(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config());
        $driver->failMove = true;

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * A retaining copy reads the mode it will publish at off the source
     * pathname, and opens the source handle after that. Another writer
     * replacing a public file with a private one in between would
     * otherwise publish the private file's bytes at the public file's
     * mode; the copy fails instead, with the destination untouched.
     */
    public function test_a_retaining_copy_fails_when_the_source_pathname_is_replaced_before_it_is_opened(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the public source', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $this->adapter->write('destination.txt', 'the previous occupant', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        // A different file at the same pathname — a new inode, private
        // — landing in the window the check covers.
        $driver->onOpenFile = function (string $path): void {
            if (!str_ends_with($path, '/source.txt')) {
                return;
            }

            unlink("{$this->root}/source.txt");
            file_put_contents("{$this->root}/source.txt", 'the private secret');
            chmod("{$this->root}/source.txt", 0600);
        };

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertStringContainsString('the source was replaced', $e->getMessage());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(0644, fileperms("{$this->root}/destination.txt") & 0777);
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    /**
     * copy() reads the source in bounded chunks, so a body spanning
     * several of them arrives whole and is counted whole — the staged
     * length check would reject it otherwise.
     */
    public function test_copy_publishes_a_body_spanning_several_chunks(): void
    {
        $contents = str_repeat(self::binaryContent(), 3000);
        $this->adapter->write('big-source.bin', $contents, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('big-source.bin', 'big-copy.bin', new Config());

        self::assertSame($contents, $this->adapter->read('big-copy.bin'));
        self::assertSame(0600, fileperms("{$this->root}/big-copy.bin") & 0777);
    }

    /**
     * A staging cleanup that fails after the rename has committed is not
     * the publication's failure: the file is published, and what remains
     * is the empty directory {doc}`storage` discloses.
     */
    public function test_a_failed_staging_cleanup_after_a_committed_rename_does_not_fail_the_write(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $driver->failDeleteDirectory = true;

        $adapter->write('published.txt', 'the body', new Config());

        self::assertSame('the body', $this->adapter->read('published.txt'));
    }

    // --- copy()/move() with source === destination: the identical-path
    // TRY resolution Filesystem::copy()/move() default to still
    // delegates all the way to the adapter, so a naive open($to, 'w')
    // would truncate the very file being "copied" before ever reading
    // it. Every case writes real, non-empty, binary content first and
    // asserts both the bytes and the real on-disk mode afterward. ---

    public function test_copying_a_file_onto_itself_via_the_public_filesystem_default_try_is_non_destructive(): void
    {
        $filesystem = new Filesystem($this->adapter);
        $content = self::binaryContent();
        $this->adapter->write('same-fs-try.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $filesystem->copy('same-fs-try.txt', 'same-fs-try.txt');

        self::assertSame($content, $this->adapter->read('same-fs-try.txt'));
        self::assertSame(0600, fileperms("{$this->root}/same-fs-try.txt") & 0777);
    }

    public function test_copying_a_file_onto_itself_via_the_adapter_directly_is_non_destructive(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-direct.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('same-direct.txt', 'same-direct.txt', new Config());

        self::assertSame($content, $this->adapter->read('same-direct.txt'));
        self::assertSame(0644, fileperms("{$this->root}/same-direct.txt") & 0777);
    }

    public function test_copying_a_file_onto_itself_with_an_explicit_visibility_applies_it_in_place(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-explicit.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->copy('same-explicit.txt', 'same-explicit.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('same-explicit.txt'));
        self::assertSame(0600, fileperms("{$this->root}/same-explicit.txt") & 0777);
    }

    /**
     * 0640 is a real, restrictive, non-canonical mode — neither
     * PortableVisibilityConverter::filePublic (0644) nor filePrivate
     * (0600) — so reading it back and reapplying the result would
     * canonicalize it to 0644, broadening the file with no request to
     * do so. No visibility step runs for an identical path, and
     * retain_visibility is never consulted there either.
     */
    public function test_copying_a_file_onto_itself_does_not_broaden_a_noncanonical_mode(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('same-noncanonical.txt', $content, new Config());
        chmod("{$this->root}/same-noncanonical.txt", 0640);

        $this->adapter->copy('same-noncanonical.txt', 'same-noncanonical.txt', new Config());
        $this->adapter->copy('same-noncanonical.txt', 'same-noncanonical.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        self::assertSame($content, $this->adapter->read('same-noncanonical.txt'));
        self::assertSame(0640, fileperms("{$this->root}/same-noncanonical.txt") & 0777);
    }

    /**
     * FAIL and IGNORE are resolved by Filesystem::copy() itself and
     * never reach the adapter — so even an explicit visibility in the
     * same call must have no effect.
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
        self::assertSame(0600, fileperms("{$this->root}/same-fail.txt") & 0777);
    }

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
        self::assertSame(0600, fileperms("{$this->root}/same-ignore.txt") & 0777);
    }

    public function test_moving_a_file_onto_itself_is_non_destructive(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('move-same.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->move('move-same.txt', 'move-same.txt', new Config());
        new Filesystem($this->adapter)->move('move-same.txt', 'move-same.txt');

        self::assertSame($content, $this->adapter->read('move-same.txt'));
        self::assertSame(0600, fileperms("{$this->root}/move-same.txt") & 0777);
    }

    public function test_moving_a_file_onto_itself_with_an_explicit_visibility_applies_it_in_place(): void
    {
        $content = self::binaryContent();
        $this->adapter->write('move-same-explicit.txt', $content, new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $this->adapter->move('move-same-explicit.txt', 'move-same-explicit.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame($content, $this->adapter->read('move-same-explicit.txt'));
        self::assertSame(0600, fileperms("{$this->root}/move-same-explicit.txt") & 0777);
    }

    // --- The resource methods, whose boundaries docs/storage.md
    // states: readStream() buffers into php://temp, writeStream() reads
    // the caller's resource on the calling thread. ---

    public function test_write_stream_then_read_stream_round_trips(): void
    {
        $contents = self::binaryContent();

        $this->adapter->writeStream('streamed.txt', self::streamOf($contents), new Config());
        $result = $this->adapter->readStream('streamed.txt');

        self::assertSame(0, ftell($result), 'readStream() must hand back a resource already positioned at byte zero.');
        self::assertSame($contents, stream_get_contents($result));
        fclose($result);
    }

    /**
     * `php://temp` holds 2 MiB in memory and spills the rest to a
     * temporary file. A body past that boundary round-trips whole, which
     * is what proves the spill path is real rather than an untested
     * branch of the documented behaviour.
     */
    public function test_read_stream_round_trips_a_body_past_the_php_temp_memory_boundary(): void
    {
        $contents = str_repeat('s', 3 * 1024 * 1024);
        $this->adapter->write('spilled.txt', $contents, new Config());

        $result = $this->adapter->readStream('spilled.txt');

        self::assertSame($contents, stream_get_contents($result));
        fclose($result);
    }

    public function test_read_stream_of_a_missing_file_throws(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->adapter->readStream('missing.txt');
    }

    /**
     * A spill-disk failure reaches readStream() as a warning from
     * inside fwrite(), and an application error handler that converts
     * warnings turns that into a throw of its own. It stays this
     * operation's own UnableToReadFile, carrying that throw, and the
     * temporary resource is closed rather than abandoned open.
     */
    public function test_read_stream_reports_a_converted_temporary_stream_warning_as_unable_to_read(): void
    {
        [$adapter] = $this->instrumentedAdapter();
        $adapter->write('buffered.txt', 'the body', new Config());

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });
        WarningRaisingTempStreamWrapper::install();

        try {
            $adapter->readStream('buffered.txt');
            self::fail('Expected UnableToReadFile.');
        } catch (UnableToReadFile $e) {
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
        } finally {
            WarningRaisingTempStreamWrapper::uninstall();
            restore_error_handler();
        }

        self::assertTrue(WarningRaisingTempStreamWrapper::$closed, 'The temporary resource is closed on the way out.');
    }

    /**
     * writeStream() neither closes the caller's resource nor keeps the
     * non-blocking mode Amp\ByteStream\ReadableResourceStream sets on
     * it to install its readability watcher. A regular file is what
     * proves the restoration: php://temp reports no blocking mode at
     * all, and a mode that was never observed is not one to restore.
     */
    public function test_write_stream_returns_the_callers_resource_open_and_in_its_original_mode(): void
    {
        $contents = self::binaryContent();
        $path = "{$this->outside}/upload-source.bin";
        file_put_contents($path, $contents);
        $source = fopen($path, 'rb');

        $this->adapter->writeStream('from-file.bin', $source, new Config());

        self::assertSame($contents, $this->adapter->read('from-file.bin'));
        self::assertIsResource($source);
        self::assertTrue(stream_get_meta_data($source)['blocked'], "The caller's resource comes back in the mode they handed it over in.");
        fclose($source);
    }

    public function test_write_stream_leaves_a_memory_resource_open(): void
    {
        $source = self::streamOf(self::binaryContent());

        $this->adapter->writeStream('from-memory.bin', $source, new Config());

        self::assertIsResource($source);
        fclose($source);
    }

    // --- Symlink policy: no path is ever allowed to resolve through a
    // symlink, whether the symlink is the requested path's own leaf, an
    // intermediate directory component, or an entry discovered while
    // listing or recursively deleting. ---

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
     * deleteDirectory() plans the whole subtree before deleting
     * anything, so this does not depend on which order the filesystem
     * happens to list entries in.
     */
    public function test_deleting_a_directory_with_a_symlink_leaves_every_other_entry_intact(): void
    {
        // Amp\File's blocking driver lists entries via scandir(), which
        // sorts alphabetically by default — the safe entries are named
        // to sort *before* the symlink specifically so this exercises
        // the real hazard (entries a combined walk-and-delete pass would
        // have already deleted before reaching the symlink), rather than
        // passing because the symlink happened to be listed first.
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

        self::assertFileExists("{$this->root}/safe/a-one.txt");
        self::assertFileExists("{$this->root}/safe/a-two.txt");
        self::assertFileExists("{$this->root}/safe/a-nested/a-three.txt");
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
