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

        $filesystem = FilesystemFactory::fromConfig($config);

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

            $default = FilesystemFactory::fromConfig($config);
            $backups = FilesystemFactory::fromConfig($config, 'backups');

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

    public function test_a_missing_root_throws_a_clear_error(): void
    {
        $config = new Config([]);

        $this->expectException(MissingConfigException::class);
        $this->expectExceptionMessage('FILESYSTEM_ROOT');
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

    private function adapterOf(Filesystem $filesystem): object
    {
        $property = new ReflectionProperty(Filesystem::class, 'adapter');

        /** @var object $adapter */
        $adapter = $property->getValue($filesystem);

        return $adapter;
    }
}
