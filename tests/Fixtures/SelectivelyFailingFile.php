<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\ByteStream\StreamException;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\File\File;
use Amp\File\Whence;
use Closure;
use IteratorAggregate;

/**
 * A real Amp\File\File decorator delegating every call to a real handle
 * except write(), which carries the enclosing driver's two write seams —
 * File itself has no injectable constructor the way
 * Amp\File\Filesystem does. Both are read through the driver rather than
 * copied at construction, so a test can set either after the handle is
 * already open.
 *
 * - $failWriteAfterBytes passes bytes through to the real handle until
 *   that many have landed on disk, then throws, so the file really does
 *   hold a truncated body when the failure surfaces.
 * - $dropWritesAfterBytes does the same truncation and then returns
 *   normally, reporting nothing. This is the real shape of the hazard
 *   Amp\File\File::write()'s void return leaves open: the driver behind
 *   a local file calls fwrite() once and only rejects an outright
 *   false, so an ordinary short write against a full disk or a quota
 *   looks exactly like a complete one to every caller.
 *
 * File extends Amp\ByteStream\ReadableStream, which extends Traversable
 * — a bare interface with no methods of its own, but PHP still requires
 * any concrete class satisfying it to directly implement Iterator or
 * IteratorAggregate. \IteratorAggregate + ReadableStreamIteratorAggregate
 * is the same mechanism every real amphp/file File implementation uses
 * to satisfy this.
 *
 * @internal test fixture only
 */
final class SelectivelyFailingFile implements File, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private int $bytesWritten = 0;

    private int $bytesDropped = 0;

    public function __construct(
        private readonly File $real,
        private readonly SelectivelyFailingFilesystemDriver $driver,
    ) {
    }

    #[\Override]
    public function read(?Cancellation $cancellation = null, int $length = 8192): ?string
    {
        return $this->real->read($cancellation, $length);
    }

    #[\Override]
    public function close(): void
    {
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
