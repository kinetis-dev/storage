<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\ByteStream\StreamException;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Amp\File\FilesystemException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Kinetis\Storage\AmpFileAdapter;
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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
     * Set only by adapterWithFailingChangePermissions() — a real
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

    // --- write()/writeStream() apply an explicit visibility as part of
    // what the operation promises, not an optional afterthought, and
    // apply it *before* any body byte is written — openFile('w')
    // truncates the destination at open time (confirmed directly: a
    // real Filesystem::read() call between openFile() returning and
    // the first write() saw 0 bytes), so a mode applied ahead of the
    // body means a changePermissions() failure never leaves new
    // content readable under a stale or broader mode, for either a
    // brand-new file or an overwrite. A failure applying it must
    // surface as UnableToWriteFile, never a raw
    // Amp\File\FilesystemException. Failure cases use
    // SelectivelyFailingFilesystemDriver — a real FilesystemDriver
    // decorator delegating everything except changePermissions() to
    // the real filesystem — the smallest available seam, since
    // Amp\File\Filesystem is `final` but its own constructor takes an
    // injectable driver interface. ---

    public function test_write_applies_the_mode_before_any_body_bytes_are_written(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();

        $adapter->write('ordering.txt', 'this is the real body content', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(
            0,
            $driver->fileSizeWhenChangePermissionsWasCalled,
            'changePermissions() must observe an empty file — direct proof it ran before the body was written, not after.',
        );
        self::assertSame('this is the real body content', $adapter->read('ordering.txt'), 'The body itself must still land correctly once the mode has been applied.');
    }

    public function test_write_stream_applies_the_mode_before_any_body_bytes_are_written(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();

        $adapter->writeStream('ordering-stream.txt', self::streamOf('this is the real body content'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0, $driver->fileSizeWhenChangePermissionsWasCalled);
        self::assertSame('this is the real body content', $adapter->read('ordering-stream.txt'));
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

    public function test_write_wraps_a_visibility_failure_as_unable_to_write_file_and_deletes_the_new_file(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $driver->failChangePermissions = true;

        try {
            $adapter->write('new-write.txt', 'secret', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('new-write.txt'), 'A visibility failure on a brand-new write must not leave the file behind.');
        self::assertTrue(
            $driver->handleWasClosedWhenDeleteFileWasCalled,
            'Cleanup must observe the file handle already closed — unlinking a still-open handle works on POSIX but is not a portable guarantee.',
        );
    }

    public function test_write_stream_wraps_a_visibility_failure_as_unable_to_write_file_and_deletes_the_new_file(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $driver->failChangePermissions = true;

        try {
            $adapter->writeStream('new-write-stream.txt', self::streamOf('secret'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertFalse($adapter->fileExists('new-write-stream.txt'));
        self::assertTrue($driver->handleWasClosedWhenDeleteFileWasCalled);
    }

    /**
     * isFile() (used to decide whether $location is an overwrite before
     * the write even begins) delegates to Filesystem::getStatus(),
     * which can itself throw FilesystemException — a failure here must
     * surface as UnableToWriteFile like any other failure in this
     * operation, not escape raw past what looks like the operation's
     * own try/catch.
     */
    public function test_write_wraps_an_existence_check_failure_as_unable_to_write_file(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
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
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $driver->failGetStatus = true;

        try {
            $adapter->writeStream('existence-check-stream.txt', self::streamOf('x'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }
    }

    /**
     * openFile('w') truncates $location immediately at open time,
     * before write()/writeStream() ever write a body byte or apply a
     * mode — so by the time applyModeBeforeBody() runs, the old
     * content is already gone regardless of whether the mode change
     * that follows succeeds or fails. What matters is that the *new*
     * content never lands readable under a stale or broader mode: if
     * changePermissions() fails, no body bytes — old or new — have
     * ever been written, proven below by asserting the file is empty,
     * not merely present. The file is left in place rather than
     * deleted, matching move()'s own "the destination is the only
     * remaining copy" reasoning — deleting an already-emptied overwrite
     * target would remove the one remaining trace a path existed
     * there, for no confidentiality benefit an empty file doesn't
     * already provide.
     */
    public function test_write_overwriting_an_existing_file_never_deletes_it_on_a_visibility_failure(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $adapter->write('overwrite.txt', 'original content', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $driver->failChangePermissions = true;

        try {
            $adapter->write('overwrite.txt', 'replacement content', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile) {
            // Expected.
        }

        self::assertTrue($adapter->fileExists('overwrite.txt'), 'A visibility failure overwriting an existing file must not delete it.');
        self::assertSame(
            '',
            $adapter->read('overwrite.txt'),
            'Neither the old content nor the new replacement content may survive readable: the mode is applied before any body bytes are written, so a failure here leaves the file empty, never populated at a stale or broader mode.',
        );
    }

    public function test_write_stream_overwriting_an_existing_file_never_deletes_it_on_a_visibility_failure(): void
    {
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $adapter->writeStream('overwrite-stream.txt', self::streamOf('original content'), new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        $driver->failChangePermissions = true;

        try {
            $adapter->writeStream('overwrite-stream.txt', self::streamOf('replacement content'), new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile) {
            // Expected.
        }

        self::assertTrue($adapter->fileExists('overwrite-stream.txt'));
        self::assertSame('', $adapter->read('overwrite-stream.txt'));
    }

    /**
     * forFile() can throw League\Flysystem\InvalidVisibilityProvided for
     * a garbage explicit value — must escape write() as itself, never
     * relabeled as an UnableToWriteFile it isn't, matching
     * League\Flysystem\Local\LocalFilesystemAdapter's own write() (via
     * setVisibility()), confirmed directly by reading its real source.
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
    private function adapterWithFailingChangePermissions(): array
    {
        $this->failingPool = new ContextWorkerPool();
        $driver = new SelectivelyFailingFilesystemDriver(new ParallelFilesystemDriver($this->failingPool));
        $adapter = new AmpFileAdapter(new AmpFilesystem($driver), $this->root);

        return [$adapter, $driver];
    }

    /** @return resource */
    private static function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
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
    // No dedicated failure-path test for the visibility-application step
    // itself (changePermissions() throwing after an otherwise-successful
    // copy/move): investigated and found genuinely impractical to force
    // deterministically here, not skipped for convenience. The standard
    // php:8.4-cli-alpine toolchain this project's own tests run under is
    // root, which bypasses ordinary POSIX permission checks entirely, so
    // a real chmod() failure can't be forced the way a permission-denied
    // write can for another user. Amp\File\Filesystem is itself `final`,
    // so there's no decorator/subclass seam the way kinetis/session's
    // FailingWriteStreamWrapper provides for stream-wrapped paths —
    // building one would mean widening AmpFileAdapter's own constructor
    // to accept an interface instead of the concrete final class, a
    // larger change than this issue's actual scope (visibility
    // correctness, not a new extension point). The existing symlink/
    // partial-write failure tests elsewhere in this file already prove
    // the established try/catch → UnableToCopyFile/UnableToMoveFile
    // pattern works for the operations that *can* be forced to fail in
    // this environment; the new visibility-apply try/catch below reuses
    // that identical pattern verbatim. ---

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
     * openFile($to, 'w') creates the destination fresh, at whatever mode
     * the runtime's umask happens to produce — genuinely not the
     * source's own mode. That's exactly what proves retention was
     * skipped: the destination's real mode must differ from the
     * source's deliberately unusual 0600, rather than asserting one
     * specific "default" value that would itself depend on the umask
     * this test runs under.
     */
    public function test_copy_with_retain_visibility_false_and_no_explicit_visibility_does_not_retain(): void
    {
        $this->adapter->write('source.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        $this->adapter->copy('source.txt', 'destination.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));

        $mode = fileperms("{$this->root}/destination.txt") & 0777;
        self::assertNotSame(0600, $mode, "retain_visibility=false must not have carried the source's own mode over.");
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
    // Visibility::PUBLIC. resolveCopyVisibility() and sourceModeUnchanged()
    // are the pure decision logic behind that guarantee, deliberately
    // filesystem-free so both are directly, deterministically testable
    // via reflection — matching this project's own established
    // precedent (Kinetis\CacheRedis\Tests\Cluster\ClusterTopologyTest's
    // identical ReflectionMethod-on-a-private-method pattern) — rather
    // than needing a real OS-scheduling race to exercise them. ---

    public function test_resolve_copy_visibility_never_falls_back_to_public_for_unresolvable_source_metadata(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyVisibility')
            ->invoke($this->adapter, 'source.txt', null, true, null);
    }

    /**
     * A genuinely resolvable status, by contrast, must produce the real
     * mapped visibility — not the exception the test above proves for
     * the unresolvable case — pinning both sides of the same branch
     * directly against the extracted resolver, independent of the
     * filesystem-level round trip test_copy_by_default_retains_the_sources_visibility()
     * already covers end-to-end.
     */
    public function test_resolve_copy_visibility_maps_a_resolvable_status_to_the_real_visibility(): void
    {
        $method = new ReflectionMethod($this->adapter, 'resolveCopyVisibility');

        self::assertSame(Visibility::PRIVATE, $method->invoke($this->adapter, 'source.txt', null, true, ['mode' => 0600]));
        self::assertSame(Visibility::PUBLIC, $method->invoke($this->adapter, 'source.txt', null, true, ['mode' => 0644]));
    }

    /**
     * A status array missing the 'mode' key entirely (not the same
     * shape as a null status, but equally unknown metadata) must throw
     * exactly like the null case — never coerced to 0 and mapped to
     * Visibility::PUBLIC.
     */
    public function test_resolve_copy_visibility_throws_on_a_status_missing_its_mode_key(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyVisibility')
            ->invoke($this->adapter, 'source.txt', null, true, ['size' => 123]);
    }

    /**
     * A non-int mode is equally unresolvable — not assumed impossible
     * just because every Amp\File driver installed today always
     * populates an int; nothing in the driver's own contract guarantees
     * that, so this is checked explicitly rather than trusted.
     */
    public function test_resolve_copy_visibility_throws_on_a_non_integer_mode(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        new ReflectionMethod($this->adapter, 'resolveCopyVisibility')
            ->invoke($this->adapter, 'source.txt', null, true, ['mode' => '0644']);
    }

    /** An explicit visibility or retain_visibility=false never even needs the source's status. */
    public function test_resolve_copy_visibility_skips_source_status_when_not_needed(): void
    {
        $method = new ReflectionMethod($this->adapter, 'resolveCopyVisibility');

        self::assertSame(Visibility::PRIVATE, $method->invoke($this->adapter, 'source.txt', Visibility::PRIVATE, true, null));
        self::assertNull($method->invoke($this->adapter, 'source.txt', null, false, null));
    }

    // --- sourceModeUnchanged(): the pure comparison
    // verifiedSourceStatus() defers to, closing the specific gap the
    // reflection tests above can't reach on their own — a source whose
    // mode genuinely *changed* between the two real stats copy() takes,
    // as opposed to one that was never resolvable in the first place.
    // Tested with fabricated status arrays, deterministically, rather
    // than a real race — the same "extract the pure decision, test it
    // directly" approach resolveCopyVisibility() itself already uses,
    // for the identical reason: Amp\File\Filesystem is `final`, and
    // reliably forcing a real mode change to land in the exact window
    // between two real stats isn't achievable without a seam this
    // project's own conventions rule out adding solely for a test. ---

    public function test_source_mode_unchanged_is_true_for_two_identical_observations(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertTrue($method->invoke(null, ['mode' => 0600], ['mode' => 0600]));
    }

    /** The mode a real chmod would actually produce mid-copy — the exact scenario this method exists to catch. */
    public function test_source_mode_unchanged_is_false_when_the_mode_genuinely_changed(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0600], ['mode' => 0644]));
    }

    /** The source vanished entirely between the two stats — at least as unresolvable as a mode change. */
    public function test_source_mode_unchanged_is_false_when_the_source_vanished(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0600], null));
    }

    /** A real, full mode (file type plus permissions, exactly what getStatus() actually returns) matching itself. */
    public function test_source_mode_unchanged_is_true_for_a_realistic_full_mode_matching_itself(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertTrue($method->invoke(null, ['mode' => 0100644], ['mode' => 0100644]));
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
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

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
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertFalse($method->invoke(null, ['mode' => 0100644], ['mode' => 0644]));
    }

    public function test_source_mode_unchanged_is_false_when_either_side_is_missing_or_not_an_integer_mode(): void
    {
        $method = new ReflectionMethod($this->adapter, 'sourceModeUnchanged');

        self::assertFalse($method->invoke(null, ['size' => 1], ['mode' => 0644]));
        self::assertFalse($method->invoke(null, ['mode' => 0644], ['size' => 1]));
        self::assertFalse($method->invoke(null, ['mode' => '0644'], ['mode' => 0644]));
    }

    // No test here exercises the real race this class's own docblocks
    // describe (a concurrent visibility change landing inside the
    // window between copy()'s two internal stats): its pass/fail would
    // depend on real OS scheduling, which is never a valid basis for a
    // deterministic CI requirement, and there's no hook to pause copy()
    // at an exact point to remove that dependency — Amp\File\Filesystem
    // is `final`, with no seam to add one through short of widening this
    // class's own surface solely for a test. The fully deterministic
    // sourceModeUnchanged()/resolveCopyVisibility() reflection tests
    // above are what actually cover the decision logic that race
    // depends on, for every input shape a real race could produce
    // (identical, changed, vanished, or malformed observations),
    // without needing OS scheduling to cooperate.

    /**
     * The end-to-end proof this real filesystem can actually give: a
     * source that was never resolvable at all fails as UnableToCopyFile
     * — never a raw UnableToRetrieveMetadata escaping unwrapped — and
     * leaves no destination file behind. The reflection tests above
     * cover the null-handling invariant itself; this proves the whole
     * pipeline produces the documented exception type end to end.
     */
    public function test_copy_of_a_nonexistent_source_never_creates_the_destination(): void
    {
        try {
            $this->adapter->copy('never-existed.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile) {
            // Expected.
        }

        self::assertFalse($this->adapter->fileExists('destination.txt'));
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
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
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
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $adapter->write('mime-probe-both-fail.txt', 'plain text content', new Config());
        $driver->failRead = true;
        $driver->failClose = true;

        try {
            $adapter->mimeType('mime-probe-both-fail.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertInstanceOf(StreamException::class, $e->getPrevious());
            self::assertSame('simulated stream read failure', $e->getPrevious()?->getMessage());
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
        [$adapter, $driver] = $this->adapterWithFailingChangePermissions();
        $adapter->write('mime-probe-close-only.txt', 'plain text content', new Config());
        $driver->failClose = true;

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
