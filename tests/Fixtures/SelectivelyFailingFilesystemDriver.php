<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Throwable;

/**
 * A real Amp\File\FilesystemDriver decorator delegating every call to a
 * real driver, so a test can force a deterministic failure at one named
 * call while every other operation runs against the real filesystem
 * unmodified. Amp\File\Filesystem is `final` but takes an injectable
 * FilesystemDriver, and AmpFileAdapter accepts a Filesystem directly.
 *
 * The seams:
 *
 * - $failChangePermissions, $failMove, $failCreateDirectory and
 *   $failDeleteDirectory raise a real Amp\File\FilesystemException from
 *   the call they name; $moveThrows raises an arbitrary Throwable from
 *   the rename instead.
 * - $failWriteAfterBytes truncates the body and throws;
 *   $dropWritesAfterBytes truncates it and returns normally, which is
 *   the silent short write Amp\File\File::write()'s void return leaves
 *   open.
 *
 * changePermissions(), move() and createDirectory() also record what
 * they saw, so a test can read the staging sequence out of the driver
 * rather than inferring it from an outcome.
 *
 * @internal test fixture only
 */
final class SelectivelyFailingFilesystemDriver implements FilesystemDriver
{
    public bool $failChangePermissions = false;

    public bool $failMove = false;

    public bool $failCreateDirectory = false;

    public bool $failDeleteDirectory = false;

    /**
     * Fails the wrapped handle's write() only once this many bytes have
     * already reached the real file — a partial write, the shape a body
     * that stops halfway actually has. Null leaves write() alone.
     */
    public ?int $failWriteAfterBytes = null;

    /**
     * Silently truncates the wrapped handle's write() at this many
     * bytes: the prefix reaches the real file, the rest is discarded,
     * and write() returns as though all of it landed. Null leaves
     * write() alone.
     */
    public ?int $dropWritesAfterBytes = null;

    /**
     * Thrown from move() instead of the FilesystemException $failMove
     * produces — the seam for a failure type outside what the adapter's
     * boundary translates.
     */
    public ?Throwable $moveThrows = null;

    /**
     * Every rename this driver saw, in order, as "<from>:<to>".
     *
     * @var list<string>
     */
    public array $renames = [];

    /**
     * One entry per changePermissions() call, in order: the path, the
     * mode requested, the real file's own byte length at that moment,
     * and the mode of the directory holding it. Together these are the
     * direct proof of what a publication applies when.
     *
     * @var list<array{path: string, mode: int, size: int, directoryMode: int}>
     */
    public array $permissionChanges = [];

    /**
     * The mode each staging directory was created with, in order.
     *
     * @var list<int>
     */
    public array $stagingDirectoryModes = [];

    public function __construct(private readonly FilesystemDriver $real)
    {
    }

    #[\Override]
    public function changePermissions(string $path, int $mode): void
    {
        $this->permissionChanges[] = [
            'path' => $path,
            'mode' => $mode,
            'size' => \strlen($this->real->read($path)),
            'directoryMode' => (\fileperms(\dirname($path)) ?: 0) & 0777,
        ];

        if ($this->failChangePermissions) {
            throw new FilesystemException('simulated permission-change failure');
        }

        $this->real->changePermissions($path, $mode);
    }

    #[\Override]
    public function openFile(string $path, string $mode): File
    {
        return new SelectivelyFailingFile($this->real->openFile($path, $mode), $this);
    }

    #[\Override]
    public function getStatus(string $path): ?array
    {
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
        $this->renames[] = "{$from}:{$to}";

        if ($this->moveThrows !== null) {
            throw $this->moveThrows;
        }

        if ($this->failMove) {
            throw new FilesystemException('simulated rename failure');
        }

        $this->real->move($from, $to);
    }

    #[\Override]
    public function deleteFile(string $path): void
    {
        $this->real->deleteFile($path);
    }

    #[\Override]
    public function createDirectory(string $path, int $mode = 511): void
    {
        if (\str_contains(\basename($path), '.kinetis-stage.')) {
            $this->stagingDirectoryModes[] = $mode;
        }

        if ($this->failCreateDirectory) {
            throw new FilesystemException('simulated directory-creation failure');
        }

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
        if ($this->failDeleteDirectory) {
            throw new FilesystemException('simulated directory-deletion failure');
        }

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
