<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Storage\Exception\StorageUnavailableException;
use League\Flysystem\Filesystem;

use function Amp\File\filesystem;

/**
 * Builds a League\Flysystem\Filesystem from Config — FILESYSTEM_DRIVER
 * (default 'local', the only driver kinetis/storage itself implements;
 * 'local' needs nothing external to guess wrong against, unlike
 * DB_CONNECTION/QUEUE_CONNECTION, which is why this one has a default and
 * those don't) plus FILESYSTEM_ROOT (required — a wrong guessed root
 * could write files somewhere unintended). A key that is set but empty
 * reaches AmpFileAdapter, which refuses it; see that class for why.
 *
 * $connection selects a named connection via Config::scopedKey() — see
 * that class's own docblock. A named filesystem is never autowired by
 * type; retrieve it from the container explicitly, or construct it
 * directly, wherever it's needed.
 *
 * FILESYSTEM_DRIVER=s3 is class_exists()-gated against
 * Kinetis\StorageS3\S3FilesystemFactory rather than a direct reference,
 * the same pattern RuntimeDetector already uses for BrefLambdaAdapter —
 * kinetis/storage never depends on kinetis/storage-s3, so referencing its
 * class name as a plain string is always safe whether or not that
 * package is installed; only class_exists() or instantiation would ever
 * trigger autoloading.
 */
final class FilesystemFactory
{
    private const S3_FACTORY_CLASS = 'Kinetis\StorageS3\S3FilesystemFactory';

    public static function fromConfig(Config $config, string $connection = 'default'): Filesystem
    {
        $driverKey = Config::scopedKey('FILESYSTEM_DRIVER', $connection);
        $driver = $config->string($driverKey, 'local');

        return match ($driver) {
            'local' => new Filesystem(new AmpFileAdapter(
                filesystem(),
                $config->required(Config::scopedKey('FILESYSTEM_ROOT', $connection)),
            )),
            's3' => self::s3Filesystem($config, $connection),
            default => throw new InvalidArgumentException(
                "{$driverKey}=\"{$driver}\" is not supported by kinetis/storage — only \"local\" is built in.",
            ),
        };
    }

    private static function s3Filesystem(Config $config, string $connection): Filesystem
    {
        if (!class_exists(self::S3_FACTORY_CLASS)) {
            throw StorageUnavailableException::missingDriverPackage('s3', 'kinetis/storage-s3');
        }

        $factoryClass = self::S3_FACTORY_CLASS;

        /** @var Filesystem */
        return $factoryClass::fromConfig($config, $connection);
    }
}
