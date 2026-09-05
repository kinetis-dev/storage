<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\Whence;
use Closure;
use IteratorAggregate;

/**
 * A real Amp\File\File decorator delegating every call to a real handle
 * except read()/write()/close(), which can each throw a real
 * Amp\ByteStream\StreamException on demand — the smallest available seam
 * for forcing a deterministic stream-level failure while a file is open,
 * since File itself has no injectable constructor the way
 * Amp\File\Filesystem does. Read via the enclosing driver's own
 * $failRead/$failWriteAfterBytes/$dropWritesAfterBytes/
 * $failCloseForModes properties rather than a copy taken at construction
 * time, so a test can flip any of them after the handle has already been
 * opened. close() records every call against this handle's own open mode
 * and path first, so a test can assert which handles were attempted even
 * when one of them throws.
 *
 * write() is both write seams at once:
 *
 * - $failWriteAfterBytes passes bytes through to the real handle until
 *   that many have landed on disk, then throws — so the file really does
 *   hold a truncated body when the failure surfaces, which is what a
 *   staged write has to survive.
 * - $dropWritesAfterBytes does the same truncation and then *returns
 *   normally*, reporting nothing at all. This is the real shape of the
 *   hazard Amp\File\File::write()'s void return leaves open — the
 *   BlockingFile driver calls fwrite() once and only rejects an outright
 *   false, so an ordinary short write against a full disk or a quota
 *   looks exactly like a complete one to every caller.
 *
 * File extends Amp\ByteStream\ReadableStream, which extends Traversable
 * — a bare interface with no methods of its own, but PHP still requires
 * any concrete class satisfying it to directly implement Iterator or
 * IteratorAggregate. \IteratorAggregate + ReadableStreamIteratorAggregate
 * is the same mechanism every real amphp/file File implementation
 * (ParallelFile, BlockingFile, StatusCachingFile) already uses to satisfy
 * this, confirmed by reading their real source rather than assumed.
 *
 * @internal test fixture only
 */
final class SelectivelyFailingFile implements File, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private int $bytesWritten = 0;

    private int $bytesDropped = 0;

    private int $closes = 0;

    public function __construct(
        private readonly File $real,
        private readonly SelectivelyFailingFilesystemDriver $driver,
        private readonly string $mode,
        private readonly string $path,
    ) {
    }

    #[\Override]
    public function read(?Cancellation $cancellation = null, int $length = 8192): ?string
    {
        if ($this->driver->failRead) {
            throw new StreamException('simulated stream read failure');
        }

        return $this->real->read($cancellation, $length);
    }

    #[\Override]
    public function close(): void
    {
        $this->driver->closeAttempts[] = "{$this->mode}:{$this->path}";

        if ($this->driver->rejectSecondClose && $this->closes > 0) {
            throw new ClosedException("The '{$this->mode}' handle rejects a second close");
        }

        ++$this->closes;

        if (\in_array($this->mode, $this->driver->failCloseForModes, true)) {
            throw new StreamException("simulated close failure for the '{$this->mode}' handle");
        }

        $this->real->close();
    }

    #[\Override]
    public function seek(int $position, Whence $whence = Whence::Start): int
    {
        return $this->real->seek($position, $whence);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->real->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->real->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->real->isSeekable();
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->real->getPath();
    }

    #[\Override]
    public function getMode(): string
    {
        return $this->real->getMode();
    }

    #[\Override]
    public function truncate(int $size): void
    {
        $this->real->truncate($size);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->real->isReadable();
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->real->isClosed();
    }

    #[\Override]
    public function onClose(Closure $onClose): void
    {
        $this->real->onClose($onClose);
    }

    #[\Override]
    public function write(string $bytes): void
    {
        if ($this->driver->writeThrows !== null) {
            throw $this->driver->writeThrows;
        }

        $dropLimit = $this->driver->dropWritesAfterBytes;

        if ($dropLimit !== null) {
            $this->passThroughUpTo($bytes, $dropLimit, $this->bytesDropped);

            // Returns as though everything landed. No exception, no
            // return value to inspect — the caller has no way to know.
            return;
        }

        $failLimit = $this->driver->failWriteAfterBytes;

        if ($failLimit === null) {
            $this->real->write($bytes);

            return;
        }

        $this->passThroughUpTo($bytes, $failLimit, $this->bytesWritten);

        throw new StreamException('simulated stream write failure');
    }

    /**
     * Writes whatever part of $bytes still fits under $limit to the real
     * handle and discards the rest, advancing $accepted by what landed.
     */
    private function passThroughUpTo(string $bytes, int $limit, int &$accepted): void
    {
        $partial = \substr($bytes, 0, \max(0, $limit - $accepted));

        if ($partial === '') {
            return;
        }

        $this->real->write($partial);
        $accepted += \strlen($partial);
    }

    #[\Override]
    public function end(): void
    {
        $this->real->end();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->real->isWritable();
    }
}
