<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

/**
 * A stand-in for PHP's own `php` stream wrapper, installed over it for
 * the length of one test so that AmpFileAdapter::readStream()'s
 * `php://temp` resource is this class instead. Its write raises a
 * warning, which is the shape a real spill failure has: `php://temp`
 * spills past 2 MiB to a temporary file, and a full or unwritable spill
 * disk is reported by fwrite() as a warning rather than a return value.
 *
 * Registering the protocol is what makes that reachable without
 * exhausting a real disk. Every other operation behaves like a working
 * stream, and stream_close() records that it ran, so a test can tell
 * whether the resource was closed on the way out.
 *
 * @internal test fixture only
 */
final class WarningRaisingTempStreamWrapper
{
    public const string PROTOCOL = 'php';

    /** Whether the stream opened through this wrapper was closed. */
    public static bool $closed = false;

    /**
     * Set by PHP itself when a stream is opened with a context.
     *
     * @var ?resource
     */
    public $context;

    public static function install(): void
    {
        self::$closed = false;
        \stream_wrapper_unregister(self::PROTOCOL);
        \stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function uninstall(): void
    {
        \stream_wrapper_restore(self::PROTOCOL);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        \trigger_error('simulated temporary-stream spill failure', \E_USER_WARNING);

        return \strlen($data);
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}
