<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\File\Filesystem;
use Amp\File\FilesystemException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;

use function Amp\ByteStream\pipe;

/**
 * A League\Flysystem\FilesystemAdapter for local disk backed by
 * Amp\File\Filesystem instead of Flysystem's own (blocking) local adapter
 * — every method here delegates to amphp/file, whose calls suspend the
 * calling Fiber via Revolt rather than blocking the whole worker process,
 * the same non-blocking idiom Amp\Mysql/Amp\Redis already use throughout
 * Kinetis\Persistence. Flysystem's own FilesystemAdapter interface has
 * plain synchronous-looking method signatures throughout (no Future/
 * promise return types anywhere) — that's not a mismatch, since
 * Amp\File\Filesystem's methods look synchronous too while suspending
 * internally; the Fiber suspends, not the method signature.
 *
 * readStream() is the one real, disclosed exception to "genuinely
 * non-blocking throughout": PHP resources are an engine-level type that
 * can't be backed by arbitrary userland code without a registered stream
 * wrapper, so there's no way to hand back a resource that lazily pulls
 * from Amp\File\File on demand. It reads the whole file via the
 * non-blocking read() below, then buffers that into an in-memory
 * `php://temp` resource for the caller — the disk read itself never
 * blocks, but the whole file is loaded into memory up front rather than
 * streamed incrementally. write()/writeStream()/copy() are genuinely
 * streaming via Amp\ByteStream\pipe() between real Amp\File\File handles,
 * with no such compromise.
 *
 * Symlink checks — rejects a symlink observed at check time; this is not
 * the same claim as "a symlink can never be followed", and the
 * distinction matters (see "Not a security boundary" below). Every
 * method that touches a path checks each path component from directly
 * under $root down to the target with Amp\File\Filesystem::isSymlink()
 * (lstat — it inspects the component itself, never what it points to)
 * via assertNoSymlinkBelowRoot(), and refuses with
 * League\Flysystem\SymbolicLinkEncountered the moment any component is
 * one; listContentsRecursively()/deleteDirectoryRecursively() (the
 * latter via planRecursiveDeletion() — see its own docblock) apply the
 * identical check to each entry they discover, which is what also stops
 * a symlink cycle — a rejected entry is never descended into, so there's
 * nothing left to loop on. $root is not a hard boundary on its own;
 * PathPrefixer only ever concatenates strings, it does not confine where
 * the filesystem actually resolves them — this is what these checks
 * exist to compensate for.
 *
 * Not a security boundary against a concurrent actor — stated plainly,
 * not as a footnote, because "Symlinks are never followed" is exactly
 * the kind of headline claim this limitation contradicts:
 *
 * - What the checks above catch: a symlink that already exists below
 *   $root at the moment a component is checked, however it got there
 *   (an unpacked archive containing one, a link left over from an
 *   earlier operation, one planted moments before this request and left
 *   in place). This is genuinely useful — it's the entire static-exploit
 *   case — but it is a check-then-use guard, not a race-free primitive.
 * - What they cannot catch, structurally, not as a matter of degree: a
 *   symlink swapped into place between a component's own isSymlink()
 *   check and the real filesystem operation that follows it a few
 *   instructions later. Checking a deeper component doesn't help —
 *   resolving root/swapped/child already follows whatever swapped has
 *   become by the time the real operation runs, regardless of what an
 *   earlier lstat() found; the check and the use are always two separate
 *   syscalls with an unavoidable gap between them. Closing this for real
 *   needs a directory-relative, no-follow open (openat()/O_NOFOLLOW,
 *   walked one component at a time from a held parent directory
 *   descriptor) — nothing in Amp\File, or PHP itself without a native
 *   extension binding that syscall, exposes one. ext-ffi was checked,
 *   not assumed absent, as a route to it directly: not compiled into
 *   this project's own standard `php:8.4-cli-alpine` toolchain image,
 *   and even where available, a native extension dependency to reach one
 *   syscall is a heavier, more fragile commitment than this closes.
 *   Not pursued.
 * - The supported threat model, narrowed accordingly rather than
 *   left open-ended: $root is a real boundary only when this adapter is
 *   the sole writer to it — an application-exclusive directory nothing
 *   else, trusted or not, creates, renames, or replaces entries in
 *   concurrently. Outside that model — shared storage, a process
 *   unpacking untrusted uploads directly into $root, any other actor
 *   with concurrent write access to the tree — these checks provide no
 *   protection at all, not merely weaker protection, since winning the
 *   race needs nothing beyond ordinary filesystem access to $root, not
 *   an already-compromised environment. A deployment that can't
 *   guarantee application-exclusive access needs an OS-level control
 *   this adapter cannot provide from PHP userland instead: Linux's
 *   `nosymfollow` mount option (5.10+), a dedicated bind-mount/mount
 *   namespace with no symlink-creation rights for any other writer, or
 *   restricting symlink() for every other writer via seccomp/an LSM
 *   profile.
 *
 * See {doc}`storage`.
 */
final readonly class AmpFileAdapter implements FilesystemAdapter
{
    private const int MIME_TYPE_SAMPLE_BYTES = 4096;

    private PathPrefixer $prefixer;

    private VisibilityConverter $visibility;

    private MimeTypeDetector $mimeTypeDetector;

    /**
     * $root with any trailing separator stripped — the boundary every
     * symlink check walks down from. Never itself checked: it's
     * operator-configured (FILESYSTEM_ROOT), not attacker-reachable, the
     * same trust boundary every other configuration value in this
     * framework already has.
     */
    private string $root;

    public function __construct(
        private Filesystem $filesystem,
        string $root,
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
    ) {
        $this->prefixer = new PathPrefixer($root);
        $this->visibility = $visibility ?? new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->root = rtrim($this->prefixer->prefixPath(''), '/');
    }

    #[\Override]
    public function fileExists(string $path): bool
    {
        $location = $this->prefixer->prefixPath($path);

        return $this->firstSymlinkBelowRoot($location) === null && $this->filesystem->isFile($location);
    }

    #[\Override]
    public function directoryExists(string $path): bool
    {
        $location = $this->prefixer->prefixDirectoryPath($path);

        return $this->firstSymlinkBelowRoot($location) === null && $this->filesystem->isDirectory($location);
    }

    #[\Override]
    public function write(string $path, string $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $this->ensureParentDirectoryExists($location, $config);
            $this->filesystem->write($location, $contents);
        } catch (FilesystemException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        $this->applyFileVisibility($location, $config);
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $this->ensureParentDirectoryExists($location, $config);
            $handle = $this->filesystem->openFile($location, 'w');

            try {
                pipe(new ReadableResourceStream($contents), $handle);
            } finally {
                $handle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        $this->applyFileVisibility($location, $config);
    }

    #[\Override]
    public function read(string $path): string
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            return $this->filesystem->read($location);
        } catch (FilesystemException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function readStream(string $path)
    {
        $contents = $this->read($path);

        /** @var resource $stream */
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    #[\Override]
    public function delete(string $path): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $this->filesystem->deleteFile($location);
        } catch (FilesystemException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $this->deleteDirectoryRecursively($location);
        } catch (SymbolicLinkEncountered $e) {
            // Not wrapped: a symlink discovered mid-walk is a policy
            // violation, not an ordinary I/O failure, and a caller
            // checking for it by type should still be able to.
            throw $e;
        } catch (FilesystemException $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function createDirectory(string $path, Config $config): void
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $this->filesystem->createDirectoryRecursively($location, $this->directoryModeFor($config));
        } catch (FilesystemException $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function setVisibility(string $path, string $visibility): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $mode = $this->filesystem->isDirectory($location)
                ? $this->visibility->forDirectory($visibility)
                : $this->visibility->forFile($visibility);
            $this->filesystem->changePermissions($location, $mode);
        } catch (FilesystemException $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function visibility(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);
        $status = $this->filesystem->getStatus($location);

        if ($status === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'path does not exist');
        }

        return new FileAttributes($path, visibility: $this->visibility->inverseForFile($status['mode'] & 0777));
    }

    #[\Override]
    public function mimeType(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $handle = $this->filesystem->openFile($location, 'r');

            try {
                $sample = $handle->read(length: self::MIME_TYPE_SAMPLE_BYTES) ?? '';
            } finally {
                $handle->close();
            }
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $sample);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'unable to determine mime type');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    #[\Override]
    public function lastModified(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $time = $this->filesystem->getModificationTime($location);
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, lastModified: $time);
    }

    #[\Override]
    public function fileSize(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        try {
            $size = $this->filesystem->getSize($location);
        } catch (FilesystemException $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, fileSize: $size);
    }

    #[\Override]
    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        if (!$this->filesystem->isDirectory($location)) {
            return;
        }

        yield from $this->listContentsRecursively($location, $deep);
    }

    #[\Override]
    public function move(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->assertNoSymlinkBelowRoot($from);
        $this->assertNoSymlinkBelowRoot($to);

        try {
            $this->ensureParentDirectoryExists($to, $config);
            $this->filesystem->move($from, $to);
        } catch (FilesystemException $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    #[\Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->assertNoSymlinkBelowRoot($from);
        $this->assertNoSymlinkBelowRoot($to);

        try {
            $this->ensureParentDirectoryExists($to, $config);
            $readHandle = $this->filesystem->openFile($from, 'r');

            try {
                $writeHandle = $this->filesystem->openFile($to, 'w');

                try {
                    pipe($readHandle, $writeHandle);
                } finally {
                    $writeHandle->close();
                }
            } finally {
                $readHandle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * @return iterable<StorageAttributes>
     */
    private function listContentsRecursively(string $location, bool $deep): iterable
    {
        foreach ($this->filesystem->listFiles($location) as $name) {
            $entryLocation = $location . '/' . $name;
            $publicPath = $this->prefixer->stripPrefix($entryLocation);

            // $location itself was already established non-symlink by the
            // caller (listContents()'s own check, or this same check one
            // level up) — checking only the entry, not the whole path
            // again, is what makes this cheap at any depth while still
            // catching a symlink introduced anywhere in the tree, and
            // never descending into or reporting on one, which is what
            // also rules out a symlink cycle.
            if ($this->filesystem->isSymlink($entryLocation)) {
                throw SymbolicLinkEncountered::atLocation($publicPath);
            }

            if ($this->filesystem->isDirectory($entryLocation)) {
                yield new DirectoryAttributes($publicPath);

                if ($deep) {
                    yield from $this->listContentsRecursively($entryLocation, true);
                }

                continue;
            }

            $status = $this->filesystem->getStatus($entryLocation);
            yield new FileAttributes(
                $publicPath,
                fileSize: $status['size'] ?? null,
                lastModified: $status['mtime'] ?? null,
            );
        }
    }

    /**
     * Deletion is split into two passes — plan, then execute — rather
     * than deleting each entry as the walk discovers it. A single
     * combined pass throws the moment it hits a symlink, but by then
     * every safe sibling visited earlier in iteration order is already
     * gone: "aborts entirely" would be false, since some of the tree was
     * already deleted. Planning first (throwing on a symlink with
     * nothing deleted yet, see planRecursiveDeletion()) is what makes a
     * symlink anywhere in the tree a genuine no-op instead of a partial
     * delete. This does widen the already-disclosed check-then-use race
     * window slightly (the whole tree is walked before any deletion,
     * rather than checking and deleting one entry at a time) — accepted,
     * since that race is fundamentally open regardless (see this class's
     * own "Threat model" docblock section) and a wider window there is a
     * smaller cost than a deterministic, always-reproducible partial
     * delete.
     *
     * A failure partway through the execute pass itself (deleteFile()/
     * deleteDirectory() failing on a permission error, for one) is a
     * different, unavoidable case this doesn't attempt to fix: nothing
     * short of a real filesystem transaction could make an I/O failure
     * mid-deletion atomic, so a caller catching an I/O-level
     * FilesystemException here (as opposed to the symlink-policy
     * SymbolicLinkEncountered above) should expect the tree to be
     * partially deleted, not intact.
     */
    private function deleteDirectoryRecursively(string $location): void
    {
        if (!$this->filesystem->isDirectory($location)) {
            return;
        }

        $plan = $this->planRecursiveDeletion($location);

        foreach ($plan['files'] as $file) {
            $this->filesystem->deleteFile($file);
        }

        // Deepest directories first — planRecursiveDeletion() appends a
        // directory to its own list only after every one of its children
        // (files and nested directories alike) has already been
        // appended, so this order is already correct for deleteDirectory()
        // to never be asked to remove a directory that still has
        // anything left inside it.
        foreach ($plan['directories'] as $directory) {
            $this->filesystem->deleteDirectory($directory);
        }
    }

    /**
     * Walks $location's whole subtree and returns every file and
     * directory it contains, throwing SymbolicLinkEncountered the moment
     * any entry anywhere in it is a symlink — before anything has been
     * deleted. $directories is ordered depth-first (a directory's own
     * entry is appended only after all of its children), which is what
     * lets deleteDirectoryRecursively() delete every returned directory
     * in list order with no directory ever asked to be removed while
     * something inside it still exists.
     *
     * @return array{files: list<string>, directories: list<string>}
     */
    private function planRecursiveDeletion(string $location): array
    {
        $files = [];
        $directories = [];

        foreach ($this->filesystem->listFiles($location) as $name) {
            $entryLocation = $location . '/' . $name;

            // Same reasoning as listContentsRecursively()'s identical
            // check: a symlink anywhere in the tree, whether it points to
            // a file or a directory, stops the whole plan — never
            // descended into or included, which is what also rules out a
            // symlink cycle.
            if ($this->filesystem->isSymlink($entryLocation)) {
                throw SymbolicLinkEncountered::atLocation($this->prefixer->stripPrefix($entryLocation));
            }

            if ($this->filesystem->isDirectory($entryLocation)) {
                $nested = $this->planRecursiveDeletion($entryLocation);
                array_push($files, ...$nested['files']);
                array_push($directories, ...$nested['directories']);
            } else {
                $files[] = $entryLocation;
            }
        }

        $directories[] = $location;

        return ['files' => $files, 'directories' => $directories];
    }

    private function ensureParentDirectoryExists(string $location, Config $config): void
    {
        $directory = dirname($location);

        if ($directory === '.' || $this->filesystem->isDirectory($directory)) {
            return;
        }

        $this->filesystem->createDirectoryRecursively($directory, $this->directoryModeFor($config));
    }

    private function directoryModeFor(Config $config): int
    {
        $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY));

        return $visibility !== null
            ? $this->visibility->forDirectory($visibility)
            : $this->visibility->defaultForDirectories();
    }

    private function applyFileVisibility(string $location, Config $config): void
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        if ($visibility !== null) {
            $this->filesystem->changePermissions($location, $this->visibility->forFile($visibility));
        }
    }

    /**
     * Walks $location one path component at a time, from directly under
     * $root down to $location itself, and returns the first one that is a
     * symlink — checked with Filesystem::isSymlink() (lstat semantics: it
     * reports the component itself, never what it resolves to), or null
     * if none of them are. A component that does not exist yet is not a
     * symlink either, so this never rejects a path that is merely new —
     * only one that already passes through a link somewhere.
     */
    private function firstSymlinkBelowRoot(string $location): ?string
    {
        $relative = substr($location, strlen($this->root));
        $current = $this->root;

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }

            $current .= '/' . $segment;

            if ($this->filesystem->isSymlink($current)) {
                return $current;
            }
        }

        return null;
    }

    /**
     * @throws SymbolicLinkEncountered when any component of $location is a
     *   symlink — see this class's own docblock for the policy and its one
     *   disclosed limitation.
     */
    private function assertNoSymlinkBelowRoot(string $location): void
    {
        $offender = $this->firstSymlinkBelowRoot($location);

        if ($offender !== null) {
            throw SymbolicLinkEncountered::atLocation($this->prefixer->stripPrefix($offender));
        }
    }
}
