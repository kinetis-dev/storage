<?php

declare(strict_types=1);

namespace Kinetis\Storage\Exception;

use RuntimeException;

final class StorageUnavailableException extends RuntimeException
{
    public static function missingDriverPackage(string $driver, string $package): self
    {
        return new self("Cannot use FILESYSTEM_DRIVER=\"{$driver}\": install \"{$package}\" to enable it.");
    }
}
