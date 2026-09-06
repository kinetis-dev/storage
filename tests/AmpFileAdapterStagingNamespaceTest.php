<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Tests\Fixtures\SelectivelyFailingFilesystemDriver;
use League\Flysystem\Config;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\File\createDefaultDriver;

/**
 * The staging namespace through real adapter I/O: no listing reports the
 * private directory a publication builds in or the file inside it, a
 * recursive deletion still removes it, and an ordinary dotfile is
 * untouched by any of it. ConfinedPathTest holds the grammar boundary.
 *
 * A staging directory holding a partial `staged` file is put in place
 * directly rather than caught mid-publication: that is the shape a
 * listing concurrent with a publication finds, and the one a failed
 * cleanup leaves for good.
 */
final class AmpFileAdapterStagingNamespaceTest extends TestCase
{
    /** Written out rather than generated, to pin the grammar itself. */
    private const string STAGING = '.kinetis-stage.0123456789abcdef0123456789abcdef';

    /** One driver for the whole class — see AmpFileAdapterTest. */
    private static ?AmpFilesystem $filesystem = null;

    private string $root;

    private AmpFileAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-staging-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        $this->adapter = new AmpFileAdapter(self::filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private static function filesystem(): AmpFilesystem
    {
        return self::$filesystem ??= new AmpFilesystem(createDefaultDriver());
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $entryPath = "{$path}/{$entry}";
            is_dir($entryPath) ? $this->removeDirectory($entryPath) : unlink($entryPath);
        }

        rmdir($path);
    }

    /**
     * A staging directory under $directory in the shape a publication has
     * while it runs: mode 0700, holding a partially written `staged` file.
     */
    private function leaveStagingDirectory(string $directory, string $partialBody): void
    {
        $location = $directory === '' ? $this->root : "{$this->root}/{$directory}";
        mkdir("{$location}/" . self::STAGING, 0700, true);
        file_put_contents("{$location}/" . self::STAGING . '/staged', $partialBody);
    }

    /**
     * The paths $path's listing reports, sorted.
     *
     * @return list<string>
     */
    private function listing(string $path, bool $deep): array
    {
        $paths = array_map(
            static fn (StorageAttributes $entry): string => $entry->path(),
            iterator_to_array($this->adapter->listContents($path, $deep), false),
        );
        sort($paths);

        return $paths;
    }

    public function test_a_staging_directory_is_absent_from_a_shallow_listing(): void
    {
        $this->adapter->write('kept.txt', 'x', new Config());
        $this->leaveStagingDirectory('', 'half a body');

        self::assertSame(['kept.txt'], $this->listing('', false));
        self::assertDirectoryExists("{$this->root}/" . self::STAGING, 'The omitted directory is on disk, not merely missing.');
    }

    /**
     * A deep listing is where the partially written file inside one would
     * surface: the walk never descends, so no depth reaches it.
     */
    public function test_a_staging_directory_and_its_staged_file_are_absent_from_a_deep_listing_at_every_depth(): void
    {
        $this->adapter->write('kept.txt', 'x', new Config());
        $this->adapter->write('sub/kept.txt', 'x', new Config());
        $this->adapter->write('sub/deeper/kept.txt', 'x', new Config());
        $this->leaveStagingDirectory('', 'a body at the root');
        $this->leaveStagingDirectory('sub', 'a body one level down');
        $this->leaveStagingDirectory('sub/deeper', 'a body two levels down');

        self::assertSame(
            ['kept.txt', 'sub', 'sub/deeper', 'sub/deeper/kept.txt', 'sub/kept.txt'],
            $this->listing('', true),
        );
        self::assertSame(['sub/deeper', 'sub/deeper/kept.txt', 'sub/kept.txt'], $this->listing('sub', true));
        self::assertSame(['sub/deeper/kept.txt'], $this->listing('sub/deeper', true));
        self::assertDirectoryExists("{$this->root}/sub/deeper/" . self::STAGING);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryNames(): iterable
    {
        yield 'a plain dotfile' => ['.htaccess'];
        yield 'the staging prefix alone' => ['.kinetis-stage'];
    }

    /**
     * Only the whole grammar is reserved, so nothing here is filtered
     * for being a dotfile or for carrying the prefix: each writes, lists
     * at both depths and reads back like any other path.
     */
    #[DataProvider('ordinaryNames')]
    public function test_a_name_the_grammar_does_not_match_stays_an_ordinary_file(string $name): void
    {
        $this->adapter->write($name, 'body', new Config());
        $this->adapter->write("sub/{$name}", 'body', new Config());

        self::assertSame([$name, 'sub'], $this->listing('', false));
        self::assertSame([$name, 'sub', "sub/{$name}"], $this->listing('', true));
        self::assertSame('body', $this->adapter->read("sub/{$name}"));
    }

    /**
     * Hidden from a listing, taken by a deletion: rmdir(2) refuses a
     * directory that still holds anything, so a skipped leftover would
     * keep its parent undeletable forever.
     */
    public function test_delete_directory_removes_a_staging_directory_left_inside_it(): void
    {
        $this->adapter->write('dir/kept.txt', 'x', new Config());
        $this->leaveStagingDirectory('dir', 'a partial body');

        $this->adapter->deleteDirectory('dir');

        self::assertDirectoryDoesNotExist("{$this->root}/dir");
        self::assertSame([], $this->listing('', true));
    }

    /**
     * The same through the adapter's own publication path: a failing
     * rename takes the write with it, and a cleanup that cannot remove
     * the staging directory leaves it behind for good.
     */
    public function test_a_publication_whose_cleanup_failed_leaves_a_directory_no_listing_reports(): void
    {
        $driver = new SelectivelyFailingFilesystemDriver(new BlockingFilesystemDriver());
        $failing = new AmpFileAdapter(new AmpFilesystem($driver), $this->root);
        $failing->write('dir/kept.txt', 'x', new Config());

        $driver->failMove = true;
        $driver->failDeleteDirectory = true;

        try {
            $failing->write('dir/entry.txt', 'a body that never lands', new Config());
            self::fail('A failing rename should have failed the write.');
        } catch (UnableToWriteFile) {
            // Expected: nothing reached the destination.
        }

        $leftovers = array_values(array_filter(
            array_diff(scandir("{$this->root}/dir") ?: [], ['.', '..']),
            static fn (string $entry): bool => str_starts_with($entry, '.kinetis-stage.'),
        ));

        self::assertCount(1, $leftovers, 'The cleanup that failed left its staging directory behind.');
        self::assertFalse($this->adapter->fileExists('dir/entry.txt'));
        self::assertSame(['dir', 'dir/kept.txt'], $this->listing('', true));

        $this->adapter->deleteDirectory('dir');

        self::assertDirectoryDoesNotExist("{$this->root}/dir");
    }
}
