<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

/**
 * A registered PHP stream wrapper (protocol self::PROTOCOL) whose
 * write()/seek() behavior is fully controlled via stream context
 * options — the deterministic seam AmpFileAdapterTest uses to simulate a
 * short write, a failed write, or a failed rewind() against a real PHP
 * resource, without needing to exhaust a real one (memory, disk, file
 * descriptors) to trigger a failure.
 *
 * Backed by a plain in-memory buffer plus a read/write position, the
 * same semantics a real php://temp stream has for the subset of
 * operations AmpFileAdapter's readStream()/populateTempStream() actually
 * exercise (write, seek/rewind, close) — everything not explicitly
 * configured to fail behaves like a genuinely working stream.
 *
 * Context options, all under the self::PROTOCOL key, all optional:
 * - writeReturns: list<int|false> — one forced return value per
 *   fwrite() call, consumed in order; false fails that call outright
 *   with nothing written, an int less than the requested length
 *   simulates a short write (only that many bytes are actually
 *   buffered). Once the list is exhausted, further writes succeed
 *   normally.
 * - failSeek: bool — when true, every stream_seek() call (what
 *   rewind() uses) fails.
 *
 * PHP instantiates this class itself the moment fopen() is called
 * against the registered protocol — there is no constructor to pass
 * configuration through directly, which is why it travels via
 * stream_context_create() instead.
 *
 * @internal test fixture only
 */
final class FailingStreamWrapper
{
    public const string PROTOCOL = 'kinetis-test-failing-stream';

    /** @var resource */
    public $context;

    private string $buffer = '';

    private int $position = 0;

    /** @var list<int|false> */
    private array $writeReturns = [];

    private bool $failSeek = false;

    public static function register(): void
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $contextOptions = \stream_context_get_options($this->context);
        $config = $contextOptions[self::PROTOCOL] ?? [];

        $this->writeReturns = $config['writeReturns'] ?? [];
        $this->failSeek = $config['failSeek'] ?? false;

        return true;
    }

    public function stream_write(string $data): int|false
    {
        if ($this->writeReturns !== []) {
            $forced = \array_shift($this->writeReturns);

            if ($forced === false) {
                return false;
            }

            $consumed = \min($forced, \strlen($data));
            $this->buffer = \substr_replace($this->buffer, \substr($data, 0, $consumed), $this->position, $consumed);
            $this->position += $consumed;

            return $consumed;
        }

        $length = \strlen($data);
        $this->buffer = \substr_replace($this->buffer, $data, $this->position, $length);
        $this->position += $length;

        return $length;
    }

    public function stream_read(int $count): string
    {
        $chunk = \substr($this->buffer, $this->position, $count);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen($this->buffer);
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        if ($this->failSeek) {
            return false;
        }

        if ($whence !== \SEEK_SET || $offset < 0) {
            return false;
        }

        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
