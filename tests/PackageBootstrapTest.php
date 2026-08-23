<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Storage\PackageBootstrap;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;

final class PackageBootstrapTest extends TestCase
{
    public function test_no_driver_configured_binds_nothing(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([]));

        self::assertFalse($app->has(FilesystemOperator::class));
    }

    public function test_local_driver_binds_a_filesystem(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config([
            'FILESYSTEM_DRIVER' => 'local',
            'FILESYSTEM_ROOT' => \sys_get_temp_dir(),
        ]));
        $app->boot();

        self::assertInstanceOf(FilesystemOperator::class, $app->get(FilesystemOperator::class));
    }

    /**
     * The binding is a factory: with the driver named but its own
     * configuration missing, registration still succeeds and the error
     * surfaces at first use, naming the key that is missing.
     */
    public function test_a_misconfigured_driver_fails_at_first_use_not_at_boot(): void
    {
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['FILESYSTEM_DRIVER' => 'local']));
        $app->boot();

        $this->expectExceptionMessageMatches('/FILESYSTEM_ROOT/');

        $app->get(FilesystemOperator::class);
    }
}
