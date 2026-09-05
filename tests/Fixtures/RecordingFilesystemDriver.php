<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests\Fixtures;

use Amp\File\File;
use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;

/**
 * An Amp\File\FilesystemDriver that reaches no filesystem at all: it
 * records the method and path of every call it receives, answers from
 * the shape a test describes to it, and raises a real
 * Amp\File\FilesystemException from whichever methods $failing names.
 *
 * Two things need exactly this rather than
 * SelectivelyFailingFilesystemDriver's real-driver decoration. A
 * rejection test's claim is that *no* call was made — provable only
 * against a driver that would have recorded one. And the status/lstat/
 * list failure matrix needs each of those methods to fail on demand for
 * every operation in turn, which a real driver cannot be asked to do
 * without a real broken filesystem underneath it.
 *
 * @internal test fixture only
 */
final class RecordingFilesystemDriver implements FilesystemDriver
{
    private const int DIRECTORY_MODE = 0040755;

    private const int FILE_MODE = 0100644;

    /**
     * Every call this driver received, in order, as "<method>:<path>".
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * The methods that raise instead of answering. Named rather than
     * flagged one by one, so a data provider can walk the whole matrix.
     *
     * @var list<string>
     */
    public array $failing = [];

    /**
     * Paths this driver reports as directories. Everything else with a
     * status reads as a regular file.
     *
     * @var list<string>
     */
    public array $directories = [];

    /**
     * Paths that have no status at all — getStatus()/getLinkStatus()
     * report null for them, the shape a missing path has.
     *
     * @var list<string>
     */
    public array $missing = [];

    /**
     * The entry names listFiles() reports for a directory, keyed by its
     * path. A path absent from here lists as empty.
     *
     * @var array<string, list<string>>
     */
    public array $entries = [];

    /**
     * How many listFiles() calls succeed before the rest raise. Null
     * leaves listFiles() alone; 1 lets a deep walk report the first
     * directory's entries and fail on the one below it, which is the
     * only way a listing failure lands after the generator has already
     * yielded.
     */
    public ?int $listFilesSucceedsFor = null;

    private int $listFileCalls = 0;

    #[\Override]
    public function openFile(string $path, string $mode): File
    {
        $this->record('openFile', $path);

        throw new FilesystemException("Recording driver holds no file to open at '{$path}'");
    }

    #[\Override]
    public function getStatus(string $path): ?array
    {
        $this->record('getStatus', $path);

        return $this->statusFor($path);
    }

    #[\Override]
    public function getLinkStatus(string $path): ?array
    {
        $this->record('getLinkStatus', $path);

        return $this->statusFor($path);
    }

    #[\Override]
    public function createSymlink(string $target, string $link): void
    {
        $this->record('createSymlink', $link);
    }

    #[\Override]
    public function createHardlink(string $target, string $link): void
    {
        $this->record('createHardlink', $link);
    }

    #[\Override]
    public function resolveSymlink(string $target): string
    {
        $this->record('resolveSymlink', $target);

        return $target;
    }

    #[\Override]
    public function move(string $from, string $to): void
    {
        $this->record('move', $from);
    }

    #[\Override]
    public function deleteFile(string $path): void
    {
        $this->record('deleteFile', $path);
    }

    #[\Override]
    public function createDirectory(string $path, int $mode = 0777): void
    {
        $this->record('createDirectory', $path);
    }

    #[\Override]
    public function createDirectoryRecursively(string $path, int $mode = 0777): void
    {
        $this->record('createDirectoryRecursively', $path);
    }

    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $this->record('deleteDirectory', $path);
    }

    #[\Override]
    public function listFiles(string $path): array
    {
        $this->record('listFiles', $path);
        ++$this->listFileCalls;

        if ($this->listFilesSucceedsFor !== null && $this->listFileCalls > $this->listFilesSucceedsFor) {
            throw new FilesystemException("Simulated listing failure at '{$path}'");
        }

        return $this->entries[$path] ?? [];
    }

    #[\Override]
    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        $this->record('changeOwner', $path);
    }

    #[\Override]
    public function changePermissions(string $path, int $mode): void
    {
        $this->record('changePermissions', $path);
    }

    #[\Override]
    public function touch(string $path, ?int $modificationTime, ?int $accessTime): void
    {
        $this->record('touch', $path);
    }

    #[\Override]
    public function read(string $path): string
    {
        $this->record('read', $path);

        return '';
    }

    #[\Override]
    public function write(string $path, string $contents): void
    {
        $this->record('write', $path);
    }

    /**
     * @return array{mode: int, size: int, mtime: int, dev: int, ino: int}|null
     */
    private function statusFor(string $path): ?array
    {
        if (\in_array($path, $this->missing, true)) {
            return null;
        }

        return [
            'mode' => \in_array($path, $this->directories, true) ? self::DIRECTORY_MODE : self::FILE_MODE,
            'size' => 0,
            'mtime' => 1_700_000_000,
            'dev' => 1,
            'ino' => 2,
        ];
    }

    private function record(string $method, string $path): void
    {
        $this->calls[] = "{$method}:{$path}";

        if (\in_array($method, $this->failing, true)) {
            throw new FilesystemException("Simulated {$method} failure at '{$path}'");
        }
    }
}
