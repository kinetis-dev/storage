<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use League\Flysystem\FilesystemOperator;

/**
 * Declared via `extra.kinetis`: with `FILESYSTEM_DRIVER` set, binds
 * {@see FilesystemOperator} so a controller or job can constructor-inject
 * it with nothing else to register. Unset means inert — the same
 * convention as kinetis/persistence's DB_CONNECTION.
 *
 * FilesystemFactory keeps its own `local` default for a direct call;
 * the key still has to be named here, because a package binding a
 * service into every application that merely installed it would be
 * guessing at intent.
 *
 * The binding is a factory, resolved on first use rather than here: the
 * s3 driver reaches for kinetis/storage-s3, and nothing should be
 * constructed for an application that never injects a filesystem.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        if ($config->string('FILESYSTEM_DRIVER', '') === '') {
            return;
        }

        $app->bind(
            FilesystemOperator::class,
            static fn (): FilesystemOperator => FilesystemFactory::fromConfig($config),
        );
    }
}
