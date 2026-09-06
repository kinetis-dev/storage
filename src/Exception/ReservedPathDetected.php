<?php

declare(strict_types=1);

namespace Kinetis\Storage\Exception;

use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * A caller's path carried a segment matching Kinetis\Storage\StagingName's
 * grammar — the private directory AmpFileAdapter publishes through, the
 * one name kinetis/storage keeps for itself. Every operation refuses such
 * a path, from ConfinedPath, before any filesystem call is made.
 *
 * A League\Flysystem\FilesystemException, like every other policy outcome
 * this adapter reports, so a caller catching that alone catches this too.
 */
final class ReservedPathDetected extends RuntimeException implements FilesystemException
{
    public static function forPath(string $path, string $segment): self
    {
        return new self("Reserved path detected: {$path} — the segment \"{$segment}\" is a staging directory name kinetis/storage reserves.");
    }
}
