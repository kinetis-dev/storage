<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\Whence;
use Closure;
use IteratorAggregate;

/**
 * A real Amp\File\File decorator delegating every call to a real handle
 * except read()/close(), which can each throw a real
 * Amp\ByteStream\StreamException on demand — the smallest available seam
 * for forcing a deterministic stream-level failure while a file is open,
 * since File itself has no injectable constructor the way
 * Amp\File\Filesystem does. Read via the enclosing driver's own
 * $failRead/$failClose properties rather than a copy taken at
 * construction time, so a test can flip either flag after the handle has
 * already been opened.
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

    public function __construct(
        private readonly File $real,
        private readonly SelectivelyFailingFilesystemDriver $driver,
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
        if ($this->driver->failClose) {
            throw new StreamException('simulated stream close failure');
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
        $this->real->write($bytes);
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
