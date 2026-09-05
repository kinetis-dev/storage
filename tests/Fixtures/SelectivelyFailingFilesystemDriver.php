<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Closure;
use Throwable;

/**
 * A real Amp\File\FilesystemDriver decorator delegating every call to a
 * real driver (Amp\File\createDefaultDriver()) — the smallest available
 * seam for forcing a deterministic failure at a specific call:
 * Amp\File\Filesystem itself is `final`, but its constructor takes an
 * injectable FilesystemDriver interface, and building a real Filesystem
 * around this decorator needs no changes to AmpFileAdapter, which
 * already accepts a Filesystem instance directly. Every operation this
 * is not asked to fail runs against the real filesystem unmodified.
 *
 * The seams, grouped by what they reproduce:
 *
 * - Plain failures: $failChangePermissions, $failGetStatus, $failMove,
 *   $failCreateDirectory, $failDeleteDirectory each raise a real
 *   FilesystemException from the call they name; $failRead and
 *   $failCloseForModes raise a real StreamException from the wrapped
 *   handle.
 * - Failures outside every catch list this adapter names: $moveThrows
 *   and $writeThrows raise an arbitrary Throwable from the rename and
 *   from the wrapped handle's write().
 * - The two amphp/file paths that translate nothing:
 *   $openFileThrowsForModes and $closeThrowsForModes raise an arbitrary
 *   Throwable from an open and from a close, which is how a real
 *   Amp\Parallel worker or task failure surfaces from
 *   ParallelFilesystemDriver::openFile() and ParallelFile::close().
 * - Writes that lose bytes: $failWriteAfterBytes truncates and throws,
 *   $dropWritesAfterBytes truncates and returns normally.
 * - Rename outcomes a failed rename cannot distinguish on its own:
 *   $renameThenThrow performs the real rename and then raises, and
 *   $afterMove runs arbitrary test code between the two.
 * - Staged-file status faults: $stagedStatusFault makes getLinkStatus()
 *   throw, return null, or return a status with its size or identity
 *   removed, for the staged file only.
 * - A mkdir whose reply is lost: $createDirectoryThenThrow creates the
 *   directory and then raises.
 *
 * openFile(), deleteFile(), changePermissions() and every close() record
 * observations (see the properties below) without altering behavior, and
 * $beforeOpenFile/$beforeChangePermissions run arbitrary test code in the
 * window before the call they name reaches the real driver.
 *
 * @internal test fixture only
 */
final class SelectivelyFailingFilesystemDriver implements FilesystemDriver
{
    public bool $failChangePermissions = false;

    public bool $failGetStatus = false;

    public bool $failRead = false;

    /**
     * The open modes ('x' for a staged file, 'r' for a source) whose
     * handles fail to close. Per mode rather than global, so a test can
     * fail one close and still assert the other was attempted.
     *
     * @var list<string>
     */
    public array $failCloseForModes = [];

    /**
     * Makes every wrapped handle reject a second close with a real
     * Amp\ByteStream\ClosedException. Amp\Closable does not promise
     * close() is idempotent, and a File is free to behave this way; with
     * this set, any code that closes the same handle twice on a path
     * that succeeded fails the operation.
     */
    public bool $rejectSecondClose = false;

    /**
     * Thrown from openFile() instead of reaching the real driver, keyed
     * by the open mode ('x' for a staged file, 'r' for a source or a
     * mime-type sample). This is where a worker acquisition failure
     * lands: ParallelFilesystemDriver::openFile() pulls a worker from
     * the pool before the try that wraps the open task, so the pool's
     * own failure arrives with no Amp\File type around it.
     *
     * @var array<string, Throwable>
     */
    public array $openFileThrowsForModes = [];

    /**
     * Thrown from a wrapped handle's close(), keyed by the same open
     * mode — $failCloseForModes with an arbitrary type instead of a
     * StreamException. ParallelFile::close() submits its fclose task
     * without wrapping anything, so a real worker or task failure
     * reaches a caller exactly like this.
     *
     * @var array<string, Throwable>
     */
    public array $closeThrowsForModes = [];



    /**
     * Breaks the staged file's status from the first move() onward, so
     * the length check still sees a clean status and the classification
     * after a failed rename does not. A flag rather than a hook, so a
     * test using it leaves no closure in the resulting exception's
     * stack trace and can serialize it.
     */
    public bool $stagedStatusFaultAfterMove = false;

    public bool $failMove = false;

    public bool $failCreateDirectory = false;

    /**
     * Creates the directory and then raises, reproducing a
     * driver whose worker completed mkdir(2) and lost the reply.
     */
    public bool $createDirectoryThenThrow = false;

    public bool $failDeleteDirectory = false;

    /**
     * Renames and then raises, reproducing a driver whose
     * worker completed rename(2) and lost the reply — the case a rename
     * failure cannot distinguish from one that never happened.
     */
    public bool $renameThenThrow = false;

    /**
     * Invoked as ($from, $to) after a $renameThenThrow rename has landed
     * and before it raises, so a test can change the destination again
     * inside that window.
     *
     * @var Closure(string, string): void
     */
    public Closure $afterMove;

    /**
     * How getLinkStatus() misbehaves for the staged file, and only for
     * it: 'throw', 'null', 'no-size', 'bad-size', or 'no-identity'.
     * Null leaves it alone.
     */
    public ?string $stagedStatusFault = null;

    /**
     * Fails the wrapped handle's write() only once $failWriteAfterBytes
     * have already reached the real file — a partial write, the shape a
     * body that stops halfway actually has, rather than one that never
     * started. Null leaves write() alone.
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
     * Thrown from move() instead of the FilesystemException
     * $failMove produces — the seam for a failure type outside every
     * catch list this adapter names, raised at the last step before the
     * destination would have been published.
     */
    public ?Throwable $moveThrows = null;

    /**
     * Thrown from the wrapped handle's write(), before anything has been
     * written and before the handle is closed — so a close failure
     * configured alongside it competes with a primary failure already in
     * flight, which is the case this exists to exercise.
     */
    public ?Throwable $writeThrows = null;

    /**
     * Every stat, open, chmod, rename, unlink, mkdir and rmdir this
     * driver sees, in order, as "<method>:<path>" (with 'r'/'x' included
     * for an open and the mode for a mkdir). The ordering tests read the
     * staged file's chmod and rename positions, and the retained-mode
     * observation's position against the source handle's own open,
     * straight out of this rather than inferring them from an outcome.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * One entry per changePermissions() call, in order: the path, the
     * mode requested, the real file's own byte length at that moment,
     * and the mode of the directory holding it. Together these are the
     * direct proof of what a caller applies when — that the staged file
     * is made private while still empty, and that the mode it will be
     * published under is applied while its directory is still 0700 and
     * before any rename.
     *
     * @var list<array{path: string, mode: int, size: int, directoryMode: int}>
     */
    public array $permissionChanges = [];

    /**
     * Every close() the wrapped handles saw, in order, as
     * "<open mode>:<path>" — including a second close of a handle
     * already closed, so a test can assert both that every owned handle
     * was attempted and that closing twice is harmless.
     *
     * @var list<string>
     */
    public array $closeAttempts = [];

    /**
     * Invoked as ($path, $mode) immediately before changePermissions()
     * reaches the real driver, so a test can observe the wider
     * filesystem at that exact instant.
     *
     * @var Closure(string, int): void
     */
    public Closure $beforeChangePermissions;

    /**
     * Invoked as ($path, $mode) immediately before openFile() reaches
     * the real driver, so a test can replace a path inside the exact
     * window between the stat that observed it and the open that
     * follows.
     *
     * @var Closure(string, string): void
     */
    public Closure $beforeOpenFile;

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
        $this->beforeOpenFile = static function (string $path, string $mode): void {
        };
        $this->beforeChangePermissions = static function (string $path, int $mode): void {
        };
        $this->afterMove = static function (string $from, string $to): void {
        };
    }

    #[\Override]
    public function changePermissions(string $path, int $mode): void
    {
        $this->calls[] = "changePermissions:{$path}";
        $this->permissionChanges[] = [
            'path' => $path,
            'mode' => $mode,
            'size' => \strlen($this->real->read($path)),
            'directoryMode' => (\fileperms(\dirname($path)) ?: 0) & 0777,
        ];
        ($this->beforeChangePermissions)($path, $mode);

        if ($this->failChangePermissions) {
            throw new FilesystemException('simulated permission-change failure');
        }

        $this->real->changePermissions($path, $mode);
    }

    #[\Override]
    public function openFile(string $path, string $mode): File
    {
        $this->calls[] = "openFile:{$mode}:{$path}";
        ($this->beforeOpenFile)($path, $mode);

        if (isset($this->openFileThrowsForModes[$mode])) {
            throw $this->openFileThrowsForModes[$mode];
        }

        return $this->lastOpenedHandle = new SelectivelyFailingFile($this->real->openFile($path, $mode), $this, $mode, $path);
    }

    #[\Override]
    public function getStatus(string $path): ?array
    {
        $this->calls[] = "getStatus:{$path}";

        if ($this->failGetStatus) {
            throw new FilesystemException('simulated status-check failure');
        }

        return $this->real->getStatus($path);
    }

    #[\Override]
    public function getLinkStatus(string $path): ?array
    {
        $this->calls[] = "getLinkStatus:{$path}";

        if ($this->stagedStatusFault === null || \basename($path) !== 'staged') {
            return $this->real->getLinkStatus($path);
        }

        $status = $this->real->getLinkStatus($path);

        return match ($this->stagedStatusFault) {
            'throw' => throw new FilesystemException('simulated status failure for the staged file'),
            'null' => null,
            'no-size' => $status === null ? null : \array_diff_key($status, ['size' => null]),
            'bad-size' => $status === null ? null : ['size' => 'not an int'] + $status,
            'no-identity' => $status === null ? null : ['dev' => 0, 'ino' => 0] + $status,
            default => $status,
        };
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
        $this->calls[] = "move:{$from}:{$to}";



        if ($this->stagedStatusFaultAfterMove) {
            $this->stagedStatusFault = 'throw';
        }

        if ($this->moveThrows !== null) {
            throw $this->moveThrows;
        }

        if ($this->failMove) {
            throw new FilesystemException('simulated rename failure');
        }

        if ($this->renameThenThrow) {
            $this->real->move($from, $to);
            ($this->afterMove)($from, $to);

            throw new FilesystemException('simulated lost reply after a completed rename');
        }

        $this->real->move($from, $to);
    }

    #[\Override]
    public function deleteFile(string $path): void
    {
        $this->calls[] = "deleteFile:{$path}";
        $this->handleWasClosedWhenDeleteFileWasCalled = $this->lastOpenedHandle?->isClosed();

        $this->real->deleteFile($path);
    }

    #[\Override]
    public function createDirectory(string $path, int $mode = 511): void
    {
        $this->calls[] = \sprintf('createDirectory:%s:%o', $path, $mode);

        if ($this->failCreateDirectory) {
            throw new FilesystemException('simulated directory-creation failure');
        }

        if ($this->createDirectoryThenThrow) {
            $this->real->createDirectory($path, $mode);

            throw new FilesystemException('simulated lost reply after a completed mkdir');
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
        $this->calls[] = "deleteDirectory:{$path}";

        if ($this->failDeleteDirectory) {
            throw new FilesystemException('simulated directory-removal failure');
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
