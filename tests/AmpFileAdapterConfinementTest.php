<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\File\Filesystem as AmpFilesystem;
use Amp\File\FilesystemException;
use Closure;
use InvalidArgumentException;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Tests\Fixtures\RecordingFilesystemDriver;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\Filesystem;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Amp\File\filesystem;

/**
 * Logical confinement, the exception taxonomy every public operation
 * reports through, and the two visibility options a directory can come
 * from.
 *
 * Two adapters appear here. One runs against a real temporary root, for
 * the claims that are about what lands on disk. The other runs against
 * RecordingFilesystemDriver, which reaches no filesystem — the only way
 * to prove that a refused path costs zero filesystem calls, and the only
 * way to make each of getStatus/getLinkStatus/listFiles fail on demand
 * for every operation in turn.
 */
final class AmpFileAdapterConfinementTest extends TestCase
{
    private string $root;

    /** A sibling directory outside $root, holding the sentinel a traversal would reach. */
    private string $outside;

    /** $outside's own name, so a traversal can be spelled relative to $root. */
    private string $outsideName;

    private AmpFileAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-confinement-' . bin2hex(random_bytes(8));
        $this->outsideName = 'kinetis-storage-confinement-outside-' . bin2hex(random_bytes(8));
        $this->outside = sys_get_temp_dir() . '/' . $this->outsideName;
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
        $this->adapter = new AmpFileAdapter(filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->outside);
    }

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

            if (is_link($entryPath) || !is_dir($entryPath)) {
                unlink($entryPath);
            } else {
                $this->removeDirectory($entryPath);
            }
        }

        rmdir($path);
    }

    /**
     * The mode a directory lands on once this deployment's umask has
     * been applied to $requested — mkdir(2) only ever clears bits, and
     * umask() called with no argument reports the current value without
     * changing it.
     */
    private static function underUmask(int $requested): int
    {
        return $requested & ~umask();
    }

    /**
     * An adapter over a driver that reaches nothing, plus the driver, so
     * a test can read back exactly which calls the operation made.
     *
     * @return array{AmpFileAdapter, RecordingFilesystemDriver}
     */
    private function fakeAdapter(string $root = '/storage'): array
    {
        $driver = new RecordingFilesystemDriver();
        $driver->directories = [$root, "{$root}/dir", "{$root}/dir/sub"];
        $driver->entries = ["{$root}/dir" => ['entry.txt'], "{$root}/dir/sub" => []];

        return [new AmpFileAdapter(new AmpFilesystem($driver), $root), $driver];
    }

    // --- The paths confinement refuses, and the operands it checks. ---

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'a leading traversal' => ['../escape.txt', PathTraversalDetected::class];
        yield 'a nested over-traversal' => ['a/../../escape.txt', PathTraversalDetected::class];
        yield 'a traversal that lands back inside' => ['a/../b.txt', PathTraversalDetected::class];
        yield 'a bare parent segment' => ['..', PathTraversalDetected::class];
        yield 'a backslash separator' => ['a\\b.txt', CorruptedPathDetected::class];
        yield 'a backslash traversal' => ['..\\escape.txt', CorruptedPathDetected::class];
        yield 'a NUL byte' => ["escape.txt\0", CorruptedPathDetected::class];
        yield 'a newline' => ["a\nb.txt", CorruptedPathDetected::class];
    }

    /**
     * Every single-operand operation, with the path it is given when the
     * call is meant to reach the driver, and the driver methods that
     * operation can fail at once confinement has admitted it.
     *
     * @return iterable<string, array{Closure(AmpFileAdapter, string): void, string, class-string<Throwable>, list<string>}>
     */
    public static function singleOperandOperations(): iterable
    {
        yield 'fileExists' => [
            static fn (AmpFileAdapter $a, string $p) => $a->fileExists($p),
            'dir/entry.txt',
            UnableToCheckFileExistence::class,
            ['getLinkStatus', 'getStatus'],
        ];

        yield 'directoryExists' => [
            static fn (AmpFileAdapter $a, string $p) => $a->directoryExists($p),
            'dir',
            UnableToCheckDirectoryExistence::class,
            ['getLinkStatus', 'getStatus'],
        ];

        yield 'read' => [
            static fn (AmpFileAdapter $a, string $p) => $a->read($p),
            'dir/entry.txt',
            UnableToReadFile::class,
            ['getLinkStatus', 'read'],
        ];

        yield 'readStream' => [
            static function (AmpFileAdapter $a, string $p): void {
                $stream = $a->readStream($p);
                fclose($stream);
            },
            'dir/entry.txt',
            UnableToReadFile::class,
            ['getLinkStatus', 'read'],
        ];

        yield 'write' => [
            static fn (AmpFileAdapter $a, string $p) => $a->write($p, 'body', new Config()),
            'dir/entry.txt',
            UnableToWriteFile::class,
            ['getLinkStatus', 'getStatus', 'createDirectory'],
        ];

        yield 'writeStream' => [
            static function (AmpFileAdapter $a, string $p): void {
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, 'body');
                rewind($stream);

                try {
                    $a->writeStream($p, $stream, new Config());
                } finally {
                    fclose($stream);
                }
            },
            'dir/entry.txt',
            UnableToWriteFile::class,
            ['getLinkStatus', 'getStatus', 'createDirectory'],
        ];

        yield 'delete' => [
            static fn (AmpFileAdapter $a, string $p) => $a->delete($p),
            'dir/entry.txt',
            UnableToDeleteFile::class,
            ['getLinkStatus', 'deleteFile'],
        ];

        yield 'deleteDirectory' => [
            static fn (AmpFileAdapter $a, string $p) => $a->deleteDirectory($p),
            'dir',
            UnableToDeleteDirectory::class,
            ['getLinkStatus', 'getStatus', 'listFiles'],
        ];

        yield 'createDirectory' => [
            static fn (AmpFileAdapter $a, string $p) => $a->createDirectory($p, new Config()),
            'dir/new',
            UnableToCreateDirectory::class,
            ['getLinkStatus', 'createDirectoryRecursively'],
        ];

        yield 'setVisibility' => [
            static fn (AmpFileAdapter $a, string $p) => $a->setVisibility($p, Visibility::PRIVATE),
            'dir/entry.txt',
            UnableToSetVisibility::class,
            ['getLinkStatus', 'getStatus', 'changePermissions'],
        ];

        yield 'visibility' => [
            static fn (AmpFileAdapter $a, string $p) => $a->visibility($p),
            'dir/entry.txt',
            UnableToRetrieveMetadata::class,
            ['getLinkStatus', 'getStatus'],
        ];

        yield 'mimeType' => [
            static fn (AmpFileAdapter $a, string $p) => $a->mimeType($p),
            'dir/entry.txt',
            UnableToRetrieveMetadata::class,
            ['getLinkStatus', 'openFile'],
        ];

        yield 'lastModified' => [
            static fn (AmpFileAdapter $a, string $p) => $a->lastModified($p),
            'dir/entry.txt',
            UnableToRetrieveMetadata::class,
            ['getLinkStatus', 'getStatus'],
        ];

        yield 'fileSize' => [
            static fn (AmpFileAdapter $a, string $p) => $a->fileSize($p),
            'dir/entry.txt',
            UnableToRetrieveMetadata::class,
            ['getLinkStatus', 'getStatus'],
        ];

        yield 'listContents' => [
            static function (AmpFileAdapter $a, string $p): void {
                foreach ($a->listContents($p, true) as $ignored) {
                    // Drained: a generator does nothing until it is.
                }
            },
            'dir',
            UnableToListContents::class,
            ['getLinkStatus', 'getStatus', 'listFiles'],
        ];
    }

    /**
     * Every two-operand operation, with the driver methods it can fail
     * at once both operands have been admitted.
     *
     * @return iterable<string, array{Closure(AmpFileAdapter, string, string): void, class-string<Throwable>, list<string>}>
     */
    public static function twoOperandOperations(): iterable
    {
        yield 'move' => [
            static fn (AmpFileAdapter $a, string $from, string $to) => $a->move($from, $to, new Config()),
            UnableToMoveFile::class,
            ['getLinkStatus', 'getStatus', 'move'],
        ];

        yield 'copy' => [
            static fn (AmpFileAdapter $a, string $from, string $to) => $a->copy($from, $to, new Config()),
            UnableToCopyFile::class,
            ['getLinkStatus', 'getStatus', 'openFile'],
        ];
    }

    /**
     * @param Closure(AmpFileAdapter, string): void $operation
     * @param list<string> $ignoredMethods
     */
    #[DataProvider('singleOperandOperations')]
    public function test_every_single_operand_operation_refuses_an_unconfined_path_without_touching_the_filesystem(
        Closure $operation,
        string $ignoredPath,
        string $ignoredFailure,
        array $ignoredMethods,
    ): void {
        foreach (self::refusedPaths() as $label => [$path, $refusal]) {
            [$adapter, $driver] = $this->fakeAdapter();

            try {
                $operation($adapter, $path);
                self::fail("{$label} should have been refused.");
            } catch (Throwable $e) {
                self::assertInstanceOf($refusal, $e, "{$label} must be refused as {$refusal}, not relabeled.");
            }

            self::assertSame([], $driver->calls, "{$label} must be refused before any filesystem call is made.");
        }
    }

    /**
     * @param Closure(AmpFileAdapter, string, string): void $operation
     * @param list<string> $ignoredMethods
     */
    #[DataProvider('twoOperandOperations')]
    public function test_every_two_operand_operation_refuses_an_unconfined_source_without_touching_the_filesystem(
        Closure $operation,
        string $ignoredFailure,
        array $ignoredMethods,
    ): void {
        foreach (self::refusedPaths() as $label => [$path, $refusal]) {
            [$adapter, $driver] = $this->fakeAdapter();

            try {
                $operation($adapter, $path, 'dir/destination.txt');
                self::fail("{$label} as a source should have been refused.");
            } catch (Throwable $e) {
                self::assertInstanceOf($refusal, $e);
            }

            self::assertSame([], $driver->calls);
        }
    }

    /**
     * The destination is confined on its own terms, before either
     * operand is walked — so a refused destination costs no filesystem
     * call either, even though the source beside it was admissible.
     *
     * @param Closure(AmpFileAdapter, string, string): void $operation
     * @param list<string> $ignoredMethods
     */
    #[DataProvider('twoOperandOperations')]
    public function test_every_two_operand_operation_refuses_an_unconfined_destination_without_touching_the_filesystem(
        Closure $operation,
        string $ignoredFailure,
        array $ignoredMethods,
    ): void {
        foreach (self::refusedPaths() as $label => [$path, $refusal]) {
            [$adapter, $driver] = $this->fakeAdapter();

            try {
                $operation($adapter, 'dir/entry.txt', $path);
                self::fail("{$label} as a destination should have been refused.");
            } catch (Throwable $e) {
                self::assertInstanceOf($refusal, $e);
            }

            self::assertSame([], $driver->calls);
        }
    }

    /**
     * The same refusal against a real root, with a real file outside it:
     * whatever the operation was, the sentinel is neither read, replaced
     * nor removed, and nothing is planted beside it.
     */
    public function test_a_traversal_reaches_nothing_outside_the_root(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        $escape = "../{$this->outsideName}/secret.txt";
        $planted = "../{$this->outsideName}/planted.txt";
        $plantedDirectory = "../{$this->outsideName}/planted";
        $outside = "../{$this->outsideName}";

        $refusals = [
            static fn (AmpFileAdapter $a) => $a->read($escape),
            static fn (AmpFileAdapter $a) => $a->fileExists($escape),
            static fn (AmpFileAdapter $a) => $a->delete($escape),
            static fn (AmpFileAdapter $a) => $a->write($planted, 'planted', new Config()),
            static fn (AmpFileAdapter $a) => $a->createDirectory($plantedDirectory, new Config()),
            static fn (AmpFileAdapter $a) => $a->setVisibility($escape, Visibility::PUBLIC),
            static fn (AmpFileAdapter $a) => $a->deleteDirectory($outside),
        ];

        foreach ($refusals as $index => $refusal) {
            try {
                $refusal($this->adapter);
                self::fail("Operation {$index} should have been refused.");
            } catch (PathTraversalDetected) {
                // Expected.
            }
        }

        self::assertSame('top secret', file_get_contents("{$this->outside}/secret.txt"));
        self::assertFileDoesNotExist("{$this->outside}/planted.txt");
        self::assertDirectoryDoesNotExist("{$this->outside}/planted");
        self::assertDirectoryExists($this->outside);
    }

    public function test_a_traversal_reaches_nothing_outside_the_root_through_either_move_operand(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        $this->adapter->write('inside.txt', 'inside', new Config());

        try {
            $this->adapter->move("../{$this->outsideName}/secret.txt", 'stolen.txt', new Config());
            self::fail('A traversing source should have been refused.');
        } catch (PathTraversalDetected) {
            // Expected.
        }

        try {
            $this->adapter->move('inside.txt', "../{$this->outsideName}/leaked.txt", new Config());
            self::fail('A traversing destination should have been refused.');
        } catch (PathTraversalDetected) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->root}/stolen.txt");
        self::assertFileDoesNotExist("{$this->outside}/leaked.txt");
        self::assertSame('inside', file_get_contents("{$this->root}/inside.txt"), 'The refused move left its source in place.');
        self::assertSame('top secret', file_get_contents("{$this->outside}/secret.txt"));
    }

    public function test_a_traversal_reaches_nothing_outside_the_root_through_either_copy_operand(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        $this->adapter->write('inside.txt', 'inside', new Config());

        try {
            $this->adapter->copy("../{$this->outsideName}/secret.txt", 'stolen.txt', new Config());
            self::fail('A traversing source should have been refused.');
        } catch (PathTraversalDetected) {
            // Expected.
        }

        try {
            $this->adapter->copy('inside.txt', "../{$this->outsideName}/leaked.txt", new Config());
            self::fail('A traversing destination should have been refused.');
        } catch (PathTraversalDetected) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->root}/stolen.txt");
        self::assertFileDoesNotExist("{$this->outside}/leaked.txt");
        self::assertSame('top secret', file_get_contents("{$this->outside}/secret.txt"));
    }

    // --- The root itself. ---

    public function test_an_empty_root_is_refused_rather_than_resolved_against_the_working_directory(): void
    {
        $driver = new RecordingFilesystemDriver();

        try {
            new AmpFileAdapter(new AmpFilesystem($driver), '');
            self::fail('An empty root should have been refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('root', $e->getMessage());
        }

        self::assertSame([], $driver->calls);
    }

    public function test_a_root_of_slash_stays_valid_and_names_absolute_locations(): void
    {
        [$adapter, $driver] = $this->fakeAdapter('/');

        $adapter->fileExists('etc/passwd');

        self::assertSame(
            ['getLinkStatus:/etc', 'getLinkStatus:/etc/passwd', 'getStatus:/etc/passwd'],
            $driver->calls,
            'A root of / must produce /etc/passwd, never //etc/passwd or a relative path.',
        );
    }

    public function test_the_empty_logical_path_names_the_root_itself(): void
    {
        $driver = new RecordingFilesystemDriver();
        $driver->directories = ['/storage'];
        $driver->entries = ['/storage' => ['top.txt']];
        $adapter = new AmpFileAdapter(new AmpFilesystem($driver), '/storage');

        $paths = [];

        foreach ($adapter->listContents('', false) as $entry) {
            $paths[] = $entry->path();
        }

        self::assertSame(['top.txt'], $paths);
        self::assertContains('listFiles:/storage', $driver->calls, 'The root is listed as itself, with no trailing separator.');
        self::assertTrue($adapter->directoryExists(''));
    }

    /**
     * Every spelling that leaves no segment behind, so every spelling
     * that names $root itself. `/.` and `./` are here because dropping
     * a `.` segment and dropping an empty one are two separate rules,
     * and a destination check that missed either would still pass on
     * the plain empty string.
     *
     * @return iterable<string, array{string}>
     */
    public static function rootSpellings(): iterable
    {
        yield 'the empty path' => [''];
        yield 'a lone dot' => ['.'];
        yield 'a lone separator' => ['/'];
        yield 'a repeated separator' => ['//'];
        yield 'a trailing separator' => ['./'];
        yield 'a leading separator' => ['/.'];
        yield 'both separators' => ['/./'];
        yield 'repeated dots and separators' => ['/.//./'];
    }

    /**
     * Every operation that publishes a file, with the exception its own
     * interface declares. The destination is the operand under test in
     * each; a source is supplied where one is needed and is always
     * admissible, so the refusal can only come from the destination.
     *
     * @return iterable<string, array{Closure(AmpFileAdapter, string): void, class-string<Throwable>}>
     */
    public static function publications(): iterable
    {
        yield 'write' => [
            static fn (AmpFileAdapter $a, string $p) => $a->write($p, 'body', new Config()),
            UnableToWriteFile::class,
        ];

        yield 'writeStream' => [
            static function (AmpFileAdapter $a, string $p): void {
                $stream = self::streamOf('body');

                try {
                    $a->writeStream($p, $stream, new Config());
                } finally {
                    fclose($stream);
                }
            },
            UnableToWriteFile::class,
        ];

        yield 'move' => [
            static fn (AmpFileAdapter $a, string $p) => $a->move('dir/entry.txt', $p, new Config()),
            UnableToMoveFile::class,
        ];

        yield 'copy' => [
            static fn (AmpFileAdapter $a, string $p) => $a->copy('dir/entry.txt', $p, new Config()),
            UnableToCopyFile::class,
        ];
    }

    /**
     * A readable resource holding $contents, positioned at byte zero.
     *
     * @return resource
     */
    private static function streamOf(string $contents)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * The root holds no file to publish over, and staging one there
     * would build the private directory in $root's own parent — outside
     * the tree. Every spelling of it is refused as the operation's own
     * failure, before any filesystem call: the source is never walked,
     * no parent is created, and no staging directory is made anywhere.
     *
     * @param Closure(AmpFileAdapter, string): void $operation
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('publications')]
    public function test_every_publication_refuses_a_root_destination_without_touching_the_filesystem(
        Closure $operation,
        string $expected,
    ): void {
        foreach (self::rootSpellings() as $label => [$path]) {
            [$adapter, $driver] = $this->fakeAdapter();

            try {
                $operation($adapter, $path);
                self::fail("{$label} as a destination should have been refused.");
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e, "{$label} must be refused as {$expected}.");
                self::assertStringContainsString('storage root', $e->getMessage(), 'The refusal says what it refused.');
            }

            self::assertSame([], $driver->calls, "{$label} must be refused before any filesystem call is made.");
        }
    }

    /**
     * The refusal lands before the body is read, so a caller's resource
     * is handed back exactly as it was given — nothing consumed, and
     * the position they set still theirs to reuse.
     */
    public function test_a_refused_root_destination_consumes_nothing_from_the_source_stream(): void
    {
        foreach (self::rootSpellings() as $label => [$path]) {
            [$adapter, $driver] = $this->fakeAdapter();
            $stream = self::streamOf('body');

            try {
                $adapter->writeStream($path, $stream, new Config());
                self::fail("{$label} as a destination should have been refused.");
            } catch (UnableToWriteFile) {
                // Expected.
            }

            self::assertSame(0, ftell($stream), "{$label} must be refused before the source is read from.");
            self::assertSame('body', stream_get_contents($stream), 'The whole body is still there to read.');
            self::assertSame([], $driver->calls);
            fclose($stream);
        }
    }

    /**
     * The same refusal for a caller holding a FilesystemOperator rather
     * than the adapter. League\Flysystem's normalizer maps every
     * spelling above onto the empty path before the adapter sees it, so
     * the check has to answer for the normalized form too.
     */
    #[DataProvider('rootSpellings')]
    public function test_a_root_destination_is_refused_through_a_filesystem_operator(string $path): void
    {
        $filesystem = new Filesystem($this->adapter);
        $filesystem->write('present.txt', 'body');

        try {
            $filesystem->write($path, 'body');
            self::fail('A root destination should have been refused.');
        } catch (UnableToWriteFile $e) {
            self::assertStringContainsString('storage root', $e->getMessage());
        }

        try {
            $filesystem->copy('present.txt', $path);
            self::fail('A root destination should have been refused.');
        } catch (UnableToCopyFile $e) {
            self::assertStringContainsString('storage root', $e->getMessage());
        }

        self::assertSame('body', file_get_contents("{$this->root}/present.txt"), 'The source is untouched.');
    }

    /**
     * The failure guarantee on a real filesystem: the adapter's root is
     * a directory this test owns the parent of, so a staging directory
     * built at the wrong level would land in a place nothing else
     * writes to. Every publication, in every spelling of the root, and
     * the parent still holds nothing but the root itself.
     */
    public function test_a_refused_root_destination_stages_nothing_outside_the_root(): void
    {
        $nested = "{$this->root}/nested";
        mkdir($nested, 0777, true);
        $adapter = new AmpFileAdapter(filesystem(), $nested);
        $adapter->write('dir/entry.txt', 'body', new Config());

        foreach (self::publications() as $operationLabel => [$operation, $expected]) {
            foreach (self::rootSpellings() as $pathLabel => [$path]) {
                try {
                    $operation($adapter, $path);
                    self::fail("{$operationLabel} to {$pathLabel} should have been refused.");
                } catch (Throwable $e) {
                    self::assertInstanceOf($expected, $e);
                }
            }
        }

        self::assertSame(['nested'], $this->entriesOf($this->root), 'Nothing was staged beside the root.');
        self::assertSame(['dir'], $this->entriesOf($nested), 'Nothing was staged inside it either.');
        self::assertSame('body', file_get_contents("{$nested}/dir/entry.txt"), 'The source is untouched.');
    }

    /**
     * The other side of the same rule: asking about the root is a
     * legitimate question, and every spelling of it still answers.
     */
    #[DataProvider('rootSpellings')]
    public function test_listing_and_existence_stay_valid_for_every_spelling_of_the_root(string $path): void
    {
        $this->adapter->write('top.txt', 'body', new Config());

        $paths = [];

        foreach ($this->adapter->listContents($path, false) as $entry) {
            $paths[] = $entry->path();
        }

        self::assertSame(['top.txt'], $paths);
        self::assertTrue($this->adapter->directoryExists($path));
        self::assertFalse($this->adapter->fileExists($path), 'The root is a directory, so it is not a file.');
    }

    /**
     * The names directly inside $path, sorted, without the dot entries.
     *
     * @return list<string>
     */
    private function entriesOf(string $path): array
    {
        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    public function test_a_listing_below_the_root_reports_single_separator_paths(): void
    {
        $this->adapter->write('sub/nested.txt', 'x', new Config());
        $this->adapter->write('sub/deeper/leaf.txt', 'x', new Config());

        $paths = [];

        foreach ($this->adapter->listContents('sub', true) as $entry) {
            $paths[] = $entry->path();
        }

        sort($paths);

        self::assertSame(['sub/deeper', 'sub/deeper/leaf.txt', 'sub/nested.txt'], $paths);
    }

    // --- The exception taxonomy. ---

    /**
     * @param Closure(AmpFileAdapter, string): void $operation
     * @param class-string<Throwable> $expected
     * @param list<string> $failableMethods
     */
    #[DataProvider('singleOperandOperations')]
    public function test_a_driver_failure_reaches_the_caller_as_the_operations_own_exception(
        Closure $operation,
        string $path,
        string $expected,
        array $failableMethods,
    ): void {
        foreach ($failableMethods as $method) {
            [$adapter, $driver] = $this->fakeAdapter();
            $driver->failing = [$method];

            try {
                $operation($adapter, $path);
                self::fail("A failing {$method} should have failed the operation.");
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e, "A failing {$method} must be reported as {$expected}.");
                self::assertInstanceOf(FilesystemException::class, self::rootCause($e), 'The driver failure is chained, not discarded.');
            }
        }
    }

    /**
     * @param Closure(AmpFileAdapter, string, string): void $operation
     * @param class-string<Throwable> $expected
     * @param list<string> $failableMethods
     */
    #[DataProvider('twoOperandOperations')]
    public function test_a_driver_failure_in_a_two_operand_operation_reaches_the_caller_as_its_own_exception(
        Closure $operation,
        string $expected,
        array $failableMethods,
    ): void {
        foreach ($failableMethods as $method) {
            [$adapter, $driver] = $this->fakeAdapter();
            $driver->failing = [$method];

            try {
                $operation($adapter, 'dir/entry.txt', 'dir/destination.txt');
                self::fail("A failing {$method} should have failed the operation.");
            } catch (Throwable $e) {
                self::assertInstanceOf($expected, $e, "A failing {$method} must be reported as {$expected}.");
            }
        }
    }

    private static function rootCause(Throwable $e): Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    /**
     * A listing is a generator, so its boundary has to be inside it:
     * building the iterator runs nothing, and the failure arrives when
     * the caller pulls from it.
     */
    public function test_a_listing_reports_nothing_until_it_is_iterated(): void
    {
        [$adapter, $driver] = $this->fakeAdapter();
        $driver->failing = ['getLinkStatus'];

        $listing = $adapter->listContents('dir', true);

        self::assertSame([], $driver->calls, 'Building the iterator must not reach the driver.');
        $this->expectException(UnableToListContents::class);
        iterator_to_array($listing);
    }

    /**
     * A failure that lands after entries have already been yielded is
     * still mapped — the catch runs inside the generator, so it is still
     * on the stack when the walk fails three directories in.
     */
    public function test_a_listing_that_fails_partway_through_iteration_is_still_mapped(): void
    {
        [$adapter, $driver] = $this->fakeAdapter();
        $driver->listFilesSucceedsFor = 1;
        $driver->entries['/storage/dir'] = ['sub'];

        $yielded = [];

        try {
            foreach ($adapter->listContents('dir', true) as $entry) {
                $yielded[] = $entry->path();
            }

            self::fail('The nested listing failure should have surfaced.');
        } catch (UnableToListContents $e) {
            self::assertInstanceOf(FilesystemException::class, $e->getPrevious());
        }

        self::assertSame(['dir/sub'], $yielded, 'The entries found before the failure were already delivered.');
    }

    /**
     * Called directly, the adapter reports the policy outcome itself.
     * Behind a League\Flysystem\FilesystemOperator the same walk arrives
     * as UnableToListContents, because Filesystem::listContents() wraps
     * every Throwable its own iteration sees — both are correct, and
     * which one a caller catches depends on which object they hold.
     */
    public function test_a_symlink_found_while_listing_keeps_its_type_for_a_direct_adapter_caller(): void
    {
        symlink($this->outside, "{$this->root}/link");

        $this->expectException(SymbolicLinkEncountered::class);
        iterator_to_array($this->adapter->listContents('', true));
    }

    public function test_the_same_symlink_arrives_wrapped_for_a_filesystem_operator_caller(): void
    {
        symlink($this->outside, "{$this->root}/link");

        try {
            new Filesystem($this->adapter)->listContents('', true)->toArray();
            self::fail('The symlink should have failed the listing.');
        } catch (UnableToListContents $e) {
            self::assertInstanceOf(SymbolicLinkEncountered::class, $e->getPrevious());
        }
    }

    // --- move(): what is resolved, and in which order. ---

    public function test_move_applies_the_file_mode_for_a_file(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());

        $this->adapter->move('source.txt', 'moved.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0600, fileperms("{$this->root}/moved.txt") & 0777);
        self::assertSame('body', file_get_contents("{$this->root}/moved.txt"));
    }

    /**
     * A directory moved private lands on 0700, not a file's 0600 —
     * which would leave its own contents unreachable, since a directory
     * needs its execute bit to be entered at all.
     */
    public function test_move_applies_the_directory_mode_for_a_directory(): void
    {
        $this->adapter->write('tree/leaf.txt', 'body', new Config());

        $this->adapter->move('tree', 'moved-tree', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));

        self::assertSame(0700, fileperms("{$this->root}/moved-tree") & 0777);
        self::assertSame('body', file_get_contents("{$this->root}/moved-tree/leaf.txt"));
    }

    public function test_move_applies_the_public_directory_mode_for_a_directory(): void
    {
        $this->adapter->createDirectory('tree', new Config());

        $this->adapter->move('tree', 'moved-tree', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0755, fileperms("{$this->root}/moved-tree") & 0777);
    }

    /**
     * The visibility is converted before a parent is created or anything
     * is renamed, so a garbage value leaves the tree exactly as it found
     * it — and escapes as InvalidVisibilityProvided rather than being
     * relabeled as a move failure it isn't.
     */
    public function test_move_with_an_invalid_visibility_mutates_nothing_at_all(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());
        $before = fileperms("{$this->root}/source.txt") & 0777;

        try {
            $this->adapter->move('source.txt', 'new-parent/moved.txt', new Config([Config::OPTION_VISIBILITY => 'nonsense']));
            self::fail('An invalid visibility should have been refused.');
        } catch (InvalidVisibilityProvided) {
            // Expected.
        }

        self::assertFileExists("{$this->root}/source.txt", 'The source is still where it was.');
        self::assertSame($before, fileperms("{$this->root}/source.txt") & 0777);
        self::assertDirectoryDoesNotExist("{$this->root}/new-parent", 'No parent is created for a move that publishes nothing.');
    }

    public function test_move_without_a_visibility_applies_no_mode_of_its_own(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());
        chmod("{$this->root}/source.txt", 0640);

        $this->adapter->move('source.txt', 'moved.txt', new Config());

        self::assertSame(0640, fileperms("{$this->root}/moved.txt") & 0777, 'A rename keeps the inode, and its mode with it.');
    }

    // --- Where a directory's mode comes from. ---

    public function test_create_directory_takes_visibility_first(): void
    {
        $this->adapter->createDirectory('explicit', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PRIVATE,
        ]));

        self::assertSame(self::underUmask(0755), fileperms("{$this->root}/explicit") & 0777);
    }

    public function test_create_directory_falls_back_to_directory_visibility(): void
    {
        $this->adapter->createDirectory('fallback', new Config([
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(self::underUmask(0755), fileperms("{$this->root}/fallback") & 0777);
    }

    public function test_create_directory_without_either_option_uses_the_converters_default(): void
    {
        $this->adapter->createDirectory('default', new Config());

        self::assertSame(self::underUmask(0700), fileperms("{$this->root}/default") & 0777);
    }

    /**
     * A `visibility` on a write names the file, not the tree above it —
     * a parent built on the way to it takes the converter's default, and
     * only `directory_visibility` moves it.
     */
    public function test_a_parent_created_by_a_write_never_inherits_the_file_visibility(): void
    {
        $this->adapter->write('inherited/file.txt', 'body', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(0644, fileperms("{$this->root}/inherited/file.txt") & 0777);
        self::assertSame(self::underUmask(0700), fileperms("{$this->root}/inherited") & 0777);
    }

    public function test_a_parent_created_by_a_write_takes_directory_visibility(): void
    {
        $this->adapter->write('explicit-parent/file.txt', 'body', new Config([
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(0600, fileperms("{$this->root}/explicit-parent/file.txt") & 0777);
        self::assertSame(self::underUmask(0755), fileperms("{$this->root}/explicit-parent") & 0777);
    }

    public function test_a_parent_created_by_a_copy_never_inherits_the_file_visibility(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());

        $this->adapter->copy('source.txt', 'copied-into/file.txt', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(self::underUmask(0700), fileperms("{$this->root}/copied-into") & 0777);
    }

    public function test_a_parent_created_by_a_move_never_inherits_the_file_visibility(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());

        $this->adapter->move('source.txt', 'moved-into/file.txt', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(0644, fileperms("{$this->root}/moved-into/file.txt") & 0777);
        self::assertSame(self::underUmask(0700), fileperms("{$this->root}/moved-into") & 0777);
    }

    public function test_a_parent_created_by_a_move_takes_directory_visibility(): void
    {
        $this->adapter->write('source.txt', 'body', new Config());

        $this->adapter->move('source.txt', 'moved-public/file.txt', new Config([
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(self::underUmask(0755), fileperms("{$this->root}/moved-public") & 0777);
    }
}
