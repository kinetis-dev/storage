<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;

/**
 * A real Amp\File\FilesystemDriver decorator delegating every call to a
 * real driver (Amp\File\createDefaultDriver()) — the smallest available
 * seam for forcing a deterministic failure at a specific call:
 * Amp\File\Filesystem itself is `final`, but its constructor takes an
 * injectable FilesystemDriver interface, and building a real Filesystem
 * around this decorator needs zero changes to AmpFileAdapter, which
 * already accepts a Filesystem instance directly. changePermissions()
 * and getStatus() each throw a real FilesystemException on demand
 * ($failChangePermissions/$failGetStatus); openFile()'s own returned
 * handle is wrapped in SelectivelyFailingFile, whose read()/close() each
 * throw a real Amp\ByteStream\StreamException on demand
 * ($failRead/$failClose) — every other operation, on both this driver
 * and the wrapped handle, runs against the real filesystem unmodified.
 * openFile()/deleteFile() additionally record observations (see their
 * own properties below) without altering behavior.
 *
 * @internal test fixture only
 */
final class SelectivelyFailingFilesystemDriver implements FilesystemDriver
{
    public bool $failChangePermissions = false;

    public bool $failGetStatus = false;

    public bool $failRead = false;

    public bool $failClose = false;

    /**
     * The real file's own byte length at the exact moment
     * changePermissions() was called, captured on every call
     * regardless of $failChangePermissions — the direct proof that a
     * caller applies a mode before writing any body bytes: this can
     * only be non-zero if body content already landed first.
     */
    public ?int $fileSizeWhenChangePermissionsWasCalled = null;

    /**
     * Whether the most recently opened File handle had already been
     * closed at the exact moment deleteFile() was called — the direct
     * proof that a caller only cleans up a file once its own handle is
     * closed, since unlinking a file with a still-open handle works on
     * POSIX but is not a portable guarantee to lean on.
     */
    public ?bool $handleWasClosedWhenDeleteFileWasCalled = null;

    private ?File $lastOpenedHandle = null;

    public function __construct(private readonly FilesystemDriver $real)
    {
    }

    #[\Override]
    public function changePermissions(string $path, int $mode): void
    {
        $this->fileSizeWhenChangePermissionsWasCalled = \strlen($this->real->read($path));

        if ($this->failChangePermissions) {
            throw new FilesystemException('simulated permission-change failure');
        }

        $this->real->changePermissions($path, $mode);
    }

    #[\Override]
    public function openFile(string $path, string $mode): File
    {
        return $this->lastOpenedHandle = new SelectivelyFailingFile($this->real->openFile($path, $mode), $this);
    }

    #[\Override]
    public function getStatus(string $path): ?array
    {
        if ($this->failGetStatus) {
            throw new FilesystemException('simulated status-check failure');
        }

        return $this->real->getStatus($path);
    }

    #[\Override]
    public function getLinkStatus(string $path): ?array
    {
        return $this->real->getLinkStatus($path);
    }

    #[\Override]
    public function createSymlink(string $target, string $link): void
    {
        $this->real->createSymlink($target, $link);
    }

    #[\Override]
    public function createHardlink(string $target, string $link): void
    {
        $this->real->createHardlink($target, $link);
    }

    #[\Override]
    public function resolveSymlink(string $target): string
    {
        return $this->real->resolveSymlink($target);
    }

    #[\Override]
    public function move(string $from, string $to): void
    {
        $this->real->move($from, $to);
    }

    #[\Override]
    public function deleteFile(string $path): void
    {
        $this->handleWasClosedWhenDeleteFileWasCalled = $this->lastOpenedHandle?->isClosed();

        $this->real->deleteFile($path);
    }

    #[\Override]
    public function createDirectory(string $path, int $mode = 511): void
    {
        $this->real->createDirectory($path, $mode);
    }

    #[\Override]
    public function createDirectoryRecursively(string $path, int $mode = 511): void
    {
        $this->real->createDirectoryRecursively($path, $mode);
    }

    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $this->real->deleteDirectory($path);
    }

    #[\Override]
    public function listFiles(string $path): array
    {
        return $this->real->listFiles($path);
    }

    #[\Override]
    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        $this->real->changeOwner($path, $uid, $gid);
    }

    #[\Override]
    public function touch(string $path, ?int $modificationTime, ?int $accessTime): void
    {
        $this->real->touch($path, $modificationTime, $accessTime);
    }

    #[\Override]
    public function read(string $path): string
    {
        return $this->real->read($path);
    }

    #[\Override]
    public function write(string $path, string $contents): void
    {
        $this->real->write($path, $contents);
    }
}
