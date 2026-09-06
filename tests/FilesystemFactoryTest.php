<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Exception\StorageUnavailableException;
use Kinetis\Storage\FilesystemFactory;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FilesystemFactoryTest extends TestCase
{
    /**
     * Every filesystem this class builds, held for the run. Each one
     * owns its driver and, without ext-uv or ext-eio, that driver's pool
     * of worker processes — dropping one mid-run kills its workers
     * outright, which a deployment building one per process or thread
     * never does.
     *
     * @var list<Filesystem>
     */
    private static array $built = [];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-factory-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        foreach (glob("{$path}/*") ?: [] as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        rmdir($path);
    }

    public function test_the_default_connection_builds_a_local_ampfile_backed_filesystem(): void
    {
        $config = new Config(['FILESYSTEM_ROOT' => $this->root]);

        $filesystem = self::build($config);

        self::assertInstanceOf(Filesystem::class, $filesystem);
        self::assertInstanceOf(AmpFileAdapter::class, $this->adapterOf($filesystem));
    }

    public function test_a_named_connection_reads_its_own_root_not_the_defaults(): void
    {
        $namedRoot = sys_get_temp_dir() . '/kinetis-storage-factory-named-' . bin2hex(random_bytes(8));
        mkdir($namedRoot, 0777, true);

        try {
            $config = new Config([
                'FILESYSTEM_ROOT' => $this->root,
                'FILESYSTEM_BACKUPS_ROOT' => $namedRoot,
            ]);

            $default = self::build($config);
            $backups = self::build($config, 'backups');

            $default->write('only-in-default.txt', 'x');
            $backups->write('only-in-backups.txt', 'x');

            self::assertFileExists("{$this->root}/only-in-default.txt");
            self::assertFileDoesNotExist("{$namedRoot}/only-in-default.txt");
            self::assertFileExists("{$namedRoot}/only-in-backups.txt");
            self::assertFileDoesNotExist("{$this->root}/only-in-backups.txt");
        } finally {
            $this->removeDirectory($namedRoot);
        }
    }

    /**
     * Two filesystems over one root, which is what a persistent worker
     * holds across threads, processes and any external writer: what one
     * changes, the other reports. Amp\File\filesystem() would answer
     * from a per-instance status cache for up to 1000 seconds instead.
     */
    public function test_a_change_made_through_one_filesystem_is_visible_to_another_over_the_same_root(): void
    {
        $config = new Config(['FILESYSTEM_ROOT' => $this->root]);

        $writer = self::build($config);
        $reader = self::build($config);

        $writer->write('shared.txt', 'the original');

        self::assertTrue($reader->fileExists('shared.txt'));
        self::assertSame(12, $reader->fileSize('shared.txt'));

        $writer->write('shared.txt', 'a longer replacement');

        self::assertSame(20, $reader->fileSize('shared.txt'), 'A rewrite through another instance must not be answered from a cached status.');

        $writer->delete('shared.txt');

        self::assertFalse($reader->fileExists('shared.txt'), 'And neither must a deletion.');
    }

    public function test_a_missing_root_throws_a_clear_error(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('FILESYSTEM_ROOT');
        FilesystemFactory::fromConfig($config);
    }

    /**
     * A key that is present but empty is a different failure from a
     * missing one, and it is the dangerous one: an empty root leaves
     * every path relative to whatever working directory the worker holds.
     * AmpFileAdapter refuses it, so the factory never builds one.
     */
    public function test_an_empty_root_throws_rather_than_resolving_against_the_working_directory(): void
    {
        $config = new Config(['FILESYSTEM_ROOT' => '']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root');
        FilesystemFactory::fromConfig($config);
    }

    public function test_an_unknown_driver_throws_a_clear_error(): void
    {
        $config = new Config(['FILESYSTEM_DRIVER' => 'gcs']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FILESYSTEM_DRIVER="gcs" is not supported by kinetis/storage');
        FilesystemFactory::fromConfig($config);
    }

    public function test_a_named_connections_unknown_driver_names_its_own_scoped_key_in_the_error(): void
    {
        $config = new Config(['FILESYSTEM_BACKUPS_DRIVER' => 'gcs']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FILESYSTEM_BACKUPS_DRIVER="gcs"');
        FilesystemFactory::fromConfig($config, 'backups');
    }

    public function test_s3_driver_without_the_package_installed_throws_a_clear_install_error(): void
    {
        $config = new Config(['FILESYSTEM_DRIVER' => 's3']);

        $this->expectException(StorageUnavailableException::class);
        $this->expectExceptionMessage('install "kinetis/storage-s3"');
        FilesystemFactory::fromConfig($config);
    }

    private static function build(Config $config, string $connection = 'default'): Filesystem
    {
        $filesystem = FilesystemFactory::fromConfig($config, $connection);
        self::$built[] = $filesystem;

        return $filesystem;
    }

    private function adapterOf(Filesystem $filesystem): object
    {
        $property = new ReflectionProperty(Filesystem::class, 'adapter');

        /** @var object $adapter */
        $adapter = $property->getValue($filesystem);

        return $adapter;
    }
}
