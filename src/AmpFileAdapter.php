<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\File\File;
use Amp\File\Filesystem;
use Closure;
use Exception;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\StorageAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use RuntimeException;
use Throwable;

use function Amp\ByteStream\pipe;

/**
 * A League\Flysystem\FilesystemAdapter for local disk backed by
 * Amp\File\Filesystem rather than Flysystem's own local adapter, so a
 * driver call suspends the calling Fiber instead of blocking the worker
 * process.
 *
 * readStream() and writeStream() are the disclosed exception: a PHP
 * resource cannot be backed by userland code without a stream wrapper,
 * so readStream() buffers the whole object into a `php://temp` resource
 * and writeStream() reads the caller's resource with PHP's own stream
 * functions on the calling thread. Both block this thread wherever they
 * reach a disk. {doc}`storage` states the boundaries in full.
 *
 * $root must be non-empty; an empty one would leave every location
 * relative to the worker's working directory. A root of '/' is valid,
 * and so is the empty logical path, which names $root itself.
 *
 * Every operand of every operation — both sides of move() and copy()
 * included — is admitted through ConfinedPath::from() before a location
 * is built from it, so a `..` segment, a control byte or a backslash
 * never reaches a filesystem call. The check lives here rather than in
 * front of the adapter because this class is public and documented for
 * direct use. A publication additionally refuses a destination naming
 * the root itself, from the confined path alone.
 *
 * Every method that touches a path then walks the confined path one
 * component at a time, from directly under $root down to the target,
 * with Amp\File\Filesystem::isSymlink() (lstat: it inspects the
 * component itself, never what it points to), and refuses a component
 * that is a symlink. Listing and recursive deletion apply the same
 * check to each entry they discover, which is also what stops a symlink
 * cycle. A link created while an operation runs is not detected;
 * {doc}`storage` states the threat model that follows.
 *
 * Confinement, the root-destination refusal, the symlink preflight and
 * every Amp\File call an operation makes sit inside that operation's
 * own try. A League\Flysystem\FilesystemException keeps its own type, so
 * a policy outcome stays what it is; any other Exception becomes the
 * UnableTo* type FilesystemOperator declares for the operation, with the
 * original chained. An \Error is never caught.
 *
 * write(), writeStream() and copy() publish through publish(), which
 * builds the new content in a private directory beside the destination
 * and renames it into place. That directory's name is the adapter's own
 * and StagingName is the one grammar it is read by: listContents()
 * reports no entry carrying it at any depth or in either mode,
 * ConfinedPath refuses a caller's path naming one, and recursive
 * deletion still walks and removes them.
 */
final readonly class AmpFileAdapter implements FilesystemAdapter
{
    private const int MIME_TYPE_SAMPLE_BYTES = 4096;

    /**
     * The mode every staging directory is created with, closing it to
     * every user but the one this process runs as. mkdir(2) applies the
     * umask to its argument and a umask only clears bits, so the
     * directory is never broader than this. It is what makes a staged
     * file private from creation: Amp\File\Filesystem::openFile() takes
     * no mode, and the umask is process-global and cannot be changed
     * safely from a worker thread.
     */
    private const int STAGING_DIRECTORY_MODE = 0700;

    /**
     * The single entry a staging directory ever holds. Fixed rather
     * than random: the directory's own name already carries the
     * per-call randomness.
     */
    private const string STAGED_FILE_NAME = 'staged';

    /**
     * How much writeStream() reads from the caller's resource, and
     * copy() from the source handle, per chunk. Each chunk is one
     * driver write — and, for copy(), one driver read as well — which
     * under the worker-pool driver is an IPC round trip apiece, so the
     * 8 KiB Amp\ByteStream defaults to would cost thousands of them for
     * an ordinary upload.
     */
    private const int STREAM_CHUNK_BYTES = 524288;

    /**
     * Carried into each operation's own League exception, so the four
     * refusals cannot drift apart.
     */
    private const string ROOT_DESTINATION_REASON = 'the destination names the storage root itself';

    private VisibilityConverter $visibility;

    private MimeTypeDetector $mimeTypeDetector;

    /**
     * $root with any trailing separator stripped — the prefix every
     * location is built on and the point every symlink check walks down
     * from. Never itself checked: it is operator-configured
     * (FILESYSTEM_ROOT), the same trust boundary every other
     * configuration value in this framework has.
     */
    private string $root;

    /**
     * The location the empty logical path names: $root, or '/' when
     * that stripped to nothing.
     */
    private string $rootLocation;

    /**
     * @throws InvalidArgumentException when $root is empty
     */
    public function __construct(
        private Filesystem $filesystem,
        string $root,
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
    ) {
        if ($root === '') {
            throw new InvalidArgumentException('A storage root is required; an empty one confines nothing.');
        }

        $this->visibility = $visibility ?? new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->root = rtrim($root, '/');
        $this->rootLocation = $this->root === '' ? '/' : $this->root;
    }

    #[\Override]
    public function fileExists(string $path): bool
    {
        try {
            $confined = ConfinedPath::from($path);

            return $this->firstSymlinkBelowRoot($confined) === null
                && $this->filesystem->isFile($this->locate($confined));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    #[\Override]
    public function directoryExists(string $path): bool
    {
        try {
            $confined = ConfinedPath::from($path);

            return $this->firstSymlinkBelowRoot($confined) === null
                && $this->filesystem->isDirectory($this->locate($confined));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    #[\Override]
    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $location = $this->publicationLocation($path);

            if ($location === null) {
                throw UnableToWriteFile::atLocation($path, self::ROOT_DESTINATION_REASON);
            }

            // Converted before anything on disk is touched: forFile() is
            // a pure string-to-int mapping, so a garbage visibility
            // raises InvalidVisibilityProvided with nothing staged and
            // no parent built for a call that was never going to
            // publish.
            $mode = $this->explicitFileMode($config);

            $this->publish($location, $mode, $config, static function (File $staged) use ($contents): int {
                $staged->write($contents);

                // The whole body in one call, so the count checked
                // against the staged length is its own length.
                return \strlen($contents);
            });
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * $contents is the caller's resource: never closed here, and left
     * at whatever blocking mode it arrived with.
     * Amp\ByteStream\ReadableResourceStream switches it to non-blocking
     * to install its readability watcher, so the mode is captured
     * before and restored after.
     */
    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $blocking = null;

        try {
            // Ahead of the ReadableResourceStream below, so a refused
            // destination leaves the caller's own resource untouched at
            // the position they handed it over at.
            $location = $this->publicationLocation($path);

            if ($location === null) {
                throw UnableToWriteFile::atLocation($path, self::ROOT_DESTINATION_REASON);
            }

            $mode = $this->explicitFileMode($config);
            $blocking = self::blockingModeOf($contents);

            $this->publish($location, $mode, $config, static function (File $staged) use ($contents): int {
                // pipe() counts what it hands over chunk by chunk, so
                // the delivered count needs no copy of the body.
                return pipe(new ReadableResourceStream($contents, self::STREAM_CHUNK_BYTES), $staged);
            });
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        } finally {
            if ($blocking !== null && \is_resource($contents)) {
                \stream_set_blocking($contents, $blocking);
            }
        }
    }

    /**
     * The caller's resource's current blocking mode, or null when the
     * stream does not report one — a wrapper is free to omit the key,
     * and a mode that was never observed is not one to restore.
     * php://temp is such a wrapper, so the metadata is read as the
     * open-ended array it is.
     *
     * @param resource $contents
     */
    private static function blockingModeOf($contents): ?bool
    {
        /** @var array<string, mixed> $meta */
        $meta = \stream_get_meta_data($contents);
        $blocked = $meta['blocked'] ?? null;

        return \is_bool($blocked) ? $blocked : null;
    }

    /**
     * The concrete mode an explicit visibility maps to, or null when
     * the call requested none — which publish() reads as "publish at
     * the mode this path would have had anyway".
     */
    private function explicitFileMode(Config $config): ?int
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        return $visibility !== null ? $this->visibility->forFile((string) $visibility) : null;
    }

    #[\Override]
    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Reads the whole object through read() above and hands it back as
     * a `php://temp` resource. `php://temp` keeps up to 2 MiB in memory
     * and spills the rest to a temporary file, so memory and disk cost
     * the object's own size and the spill blocks this thread for as
     * long as that disk takes. Prefer read() unless a consumer requires
     * a resource.
     *
     * The three native calls sit inside a boundary of their own,
     * because a failure they report as a warning — a full or unwritable
     * spill disk above all — becomes a throw under an application error
     * handler that converts warnings, which would otherwise leave this
     * method as something other than the UnableToReadFile its interface
     * declares, with the temporary resource still open.
     */
    #[\Override]
    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $stream = null;

        try {
            $stream = @\fopen('php://temp', 'r+b');

            if ($stream === false) {
                throw UnableToReadFile::fromLocation($path, 'unable to open a temporary stream');
            }

            if (@\fwrite($stream, $contents) !== \strlen($contents) || \rewind($stream) === false) {
                throw UnableToReadFile::fromLocation($path, 'unable to buffer the file into a temporary stream');
            }

            return $stream;
        } catch (Throwable $e) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }

            if ($e instanceof Exception && !$e instanceof FilesystemException) {
                throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
            }

            throw $e;
        }
    }

    #[\Override]
    public function delete(string $path): void
    {
        try {
            $this->filesystem->deleteFile($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * A symlink found anywhere in the tree leaves the whole call a
     * no-op and keeps its own type. See deleteDirectoryRecursively()
     * for what an I/O failure partway through the deletion pass leaves
     * instead.
     */
    #[\Override]
    public function deleteDirectory(string $path): void
    {
        try {
            $confined = ConfinedPath::from($path);
            $this->assertNoSymlinkBelowRoot($confined);

            $this->deleteDirectoryRecursively($this->locate($confined), $confined->path);
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->filesystem->createDirectoryRecursively(
                $this->confinedLocation($path),
                $this->explicitDirectoryMode($config),
            );
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $location = $this->confinedLocation($path);
            $mode = $this->filesystem->isDirectory($location)
                ? $this->visibility->forDirectory($visibility)
                : $this->visibility->forFile($visibility);
            $this->filesystem->changePermissions($location, $mode);
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * File-only by contract, not by this class's choice —
     * League\Flysystem\FilesystemAdapter::visibility() is declared to
     * return FileAttributes.
     */
    #[\Override]
    public function visibility(string $path): FileAttributes
    {
        try {
            $status = $this->filesystem->getStatus($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }

        if ($status === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'path does not exist');
        }

        return new FileAttributes($path, visibility: $this->visibility->inverseForFile($status['mode'] & 0777));
    }

    #[\Override]
    public function mimeType(string $path): FileAttributes
    {
        try {
            $sample = $this->readMimeTypeSample($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $sample);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'unable to determine mime type');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    private function readMimeTypeSample(string $location): string
    {
        $handle = $this->filesystem->openFile($location, 'r');

        try {
            return $handle->read(length: self::MIME_TYPE_SAMPLE_BYTES) ?? '';
        } finally {
            $handle->close();
        }
    }

    #[\Override]
    public function lastModified(string $path): FileAttributes
    {
        try {
            $time = $this->filesystem->getModificationTime($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, lastModified: $time);
    }

    #[\Override]
    public function fileSize(string $path): FileAttributes
    {
        try {
            $size = $this->filesystem->getSize($this->confinedLocation($path));
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        return new FileAttributes($path, fileSize: $size);
    }

    /**
     * A generator, so nothing here runs until the caller iterates —
     * which is why the boundary is inside the method body. A driver
     * failure on the tenth directory reaches the caller as
     * UnableToListContents just as one on the first does.
     *
     * A symlink discovered mid-walk keeps its own type here. Behind a
     * League\Flysystem\FilesystemOperator the same walk arrives as
     * UnableToListContents with that SymbolicLinkEncountered as its
     * previous, since Filesystem::listContents() wraps every Throwable
     * its own iteration sees.
     *
     * @return iterable<StorageAttributes>
     */
    #[\Override]
    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $confined = ConfinedPath::from($path);
            $this->assertNoSymlinkBelowRoot($confined);
            $location = $this->locate($confined);

            if (!$this->filesystem->isDirectory($location)) {
                return;
            }

            yield from $this->listContentsRecursively($location, $confined->path, $deep);
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }
    }

    /**
     * Renames $source onto $destination, and applies an explicit
     * visibility to what arrives there.
     *
     * The source's kind is read, and an explicit visibility converted,
     * before a parent directory is created or anything is renamed:
     * after the rename the source is gone, and a directory built for a
     * call carrying a garbage visibility is a mutation for an operation
     * that publishes nothing. An InvalidVisibilityProvided therefore
     * leaves the tree exactly as it found it and escapes as itself.
     *
     * A rename keeps the same inode, so the destination already carries
     * the source's own mode and a call requesting no visibility applies
     * none. An explicit one goes through the converter the source's
     * kind calls for, so moving a directory private does not land it on
     * a file's 0600 with its own contents unreachable.
     *
     * $to is never rolled back on a failure after the rename: it is by
     * then the only remaining copy of the data.
     */
    #[\Override]
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            [$from, $to] = $this->confinedLocationPair($source, $destination);

            if ($to === null) {
                throw UnableToMoveFile::because(self::ROOT_DESTINATION_REASON, $source, $destination);
            }

            $mode = $this->explicitMoveMode($config, $this->filesystem->isDirectory($from));

            $this->ensureParentDirectoryExists($to, $config);
            $this->filesystem->move($from, $to);

            if ($mode !== null) {
                $this->filesystem->changePermissions($to, $mode);
            }
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * The mode move() applies after the rename, or null when the call
     * requested no visibility. Pure: the converters map a string to an
     * int and touch nothing, so a garbage value throws
     * League\Flysystem\InvalidVisibilityProvided while the tree is
     * still untouched.
     */
    private function explicitMoveMode(Config $config, bool $sourceIsDirectory): ?int
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        if ($visibility === null) {
            return null;
        }

        return $sourceIsDirectory
            ? $this->visibility->forDirectory((string) $visibility)
            : $this->visibility->forFile((string) $visibility);
    }

    /**
     * Streams $source into a staged file inside a private directory
     * beside the destination and renames it over $to only once the
     * whole copy has succeeded — publish() holds the publication and
     * failure guarantees copy() shares with write() and writeStream().
     *
     * What the destination is published at: an explicit visibility
     * first, then the source's own visibility when `retain_visibility`
     * is left at its default.
     */
    #[\Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            [$from, $to] = $this->confinedLocationPair($source, $destination);

            if ($to === null) {
                throw UnableToCopyFile::because(self::ROOT_DESTINATION_REASON, $source, $destination);
            }

            $mode = $this->explicitFileMode($config);

            // Filesystem::copy()'s default identical-path resolution
            // (ResolveIdenticalPathConflict::TRY) delegates all the way
            // here; FAIL and IGNORE are resolved by the Filesystem
            // facade and never reach this adapter. $to is $from, so
            // there is no second file to produce and nothing to
            // replace: only an explicit override touches the file, and
            // it is never deleted on a failure, being the only
            // remaining copy of the data. Reading the mode back and
            // reapplying it, the way genuine retention does, would
            // canonicalize a real but non-canonical mode into a
            // broader one with no request to do so.
            if ($from === $to) {
                if ($mode !== null) {
                    $this->filesystem->changePermissions($to, $mode);
                }

                return;
            }

            if ($mode === null && (bool) $config->get(Config::OPTION_RETAIN_VISIBILITY, true)) {
                $mode = $this->retainedMode($from, $source, $destination);
            }

            $this->publish($to, $mode, $config, function (File $staged) use ($from): int {
                // The source handle is this closure's to close; the
                // staged one belongs to publish().
                $readHandle = $this->filesystem->openFile($from, 'r');

                try {
                    return self::transfer($readHandle, $staged);
                } finally {
                    $readHandle->close();
                }
            });
        } catch (FilesystemException $e) {
            throw $e;
        } catch (Exception $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * The mode a retaining copy publishes: the source's own visibility,
     * round-tripped through the converter so the destination lands on
     * the canonical mode for that visibility — the same value
     * setVisibility() would produce — rather than an arbitrary source
     * mode this adapter never promises to reproduce.
     *
     * A source whose mode the filesystem cannot report fails the copy.
     * Mode 0 is what a missing field would otherwise read as, and
     * inverseForFile() maps it to Visibility::PUBLIC, so unknown is
     * never allowed to become public.
     */
    private function retainedMode(string $from, string $source, string $destination): int
    {
        $mode = $this->filesystem->getLinkStatus($from)['mode'] ?? null;

        if (!\is_int($mode)) {
            throw UnableToCopyFile::because('the source visibility could not be read', $source, $destination);
        }

        return $this->visibility->forFile($this->visibility->inverseForFile($mode & 0777));
    }

    /**
     * Reads $source to its end and writes it to $staged, in chunks of
     * self::STREAM_CHUNK_BYTES, returning the byte count handed over.
     * Amp\ByteStream\pipe() would do the same at its own 8 KiB default
     * read length, which under the worker-pool driver is a read and a
     * write round trip per 8 KiB. Neither handle is closed here; each
     * belongs to whoever opened it.
     */
    private static function transfer(File $source, File $staged): int
    {
        $copied = 0;

        while (($chunk = $source->read(length: self::STREAM_CHUNK_BYTES)) !== null) {
            $staged->write($chunk);
            $copied += \strlen($chunk);
        }

        return $copied;
    }

    /**
     * $logical is $location's own confined path, carried down alongside
     * it so each entry's reported path is built from the segments that
     * reached it rather than cut back out of the prefixed location.
     *
     * @return iterable<StorageAttributes>
     */
    private function listContentsRecursively(string $location, string $logical, bool $deep): iterable
    {
        foreach ($this->filesystem->listFiles($location) as $name) {
            // A staging directory is neither reported nor descended
            // into, which is what keeps the partially written file
            // inside one out of a deep listing too.
            if (StagingName::matches($name)) {
                continue;
            }

            $entryLocation = $location . '/' . $name;
            $publicPath = $logical === '' ? $name : $logical . '/' . $name;

            // $location itself was already established non-symlink by
            // the caller — checking only the entry is what makes this
            // cheap at any depth while still catching a symlink
            // introduced anywhere in the tree, and never descending
            // into or reporting on one, which also rules out a cycle.
            if ($this->filesystem->isSymlink($entryLocation)) {
                throw SymbolicLinkEncountered::atLocation($publicPath);
            }

            if ($this->filesystem->isDirectory($entryLocation)) {
                yield new DirectoryAttributes($publicPath);

                if ($deep) {
                    yield from $this->listContentsRecursively($entryLocation, $publicPath, true);
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
     * Deletion is split into two passes — plan, then execute — so that
     * a symlink anywhere in the tree is a no-op rather than a partial
     * delete: a single combined pass throws the moment it hits one, by
     * which time every safe sibling visited earlier is already gone.
     *
     * A failure partway through the execute pass is a different case
     * this does not attempt to fix: nothing short of a real filesystem
     * transaction could make an I/O failure mid-deletion atomic, so a
     * caller catching an I/O-level failure here should expect the tree
     * to be partially deleted, not intact.
     */
    private function deleteDirectoryRecursively(string $location, string $logical): void
    {
        if (!$this->filesystem->isDirectory($location)) {
            return;
        }

        $plan = $this->planRecursiveDeletion($location, $logical);

        foreach ($plan['files'] as $file) {
            $this->filesystem->deleteFile($file);
        }

        // Deepest directories first — planRecursiveDeletion() appends a
        // directory only after every one of its children, so this order
        // never asks deleteDirectory() to remove a directory that still
        // holds something.
        foreach ($plan['directories'] as $directory) {
            $this->filesystem->deleteDirectory($directory);
        }
    }

    /**
     * Walks $location's whole subtree and returns every file and
     * directory it holds, throwing SymbolicLinkEncountered the moment
     * any entry anywhere in it is a symlink — before anything has been
     * deleted.
     *
     * Every entry, a staging directory hidden from listContents()
     * included: rmdir(2) refuses a directory that still holds anything,
     * so a skipped leftover would leave its parent undeletable.
     *
     * @return array{files: list<string>, directories: list<string>}
     */
    private function planRecursiveDeletion(string $location, string $logical): array
    {
        $files = [];
        $directories = [];

        foreach ($this->filesystem->listFiles($location) as $name) {
            $entryLocation = $location . '/' . $name;
            $publicPath = $logical === '' ? $name : $logical . '/' . $name;

            if ($this->filesystem->isSymlink($entryLocation)) {
                throw SymbolicLinkEncountered::atLocation($publicPath);
            }

            if ($this->filesystem->isDirectory($entryLocation)) {
                $nested = $this->planRecursiveDeletion($entryLocation, $publicPath);
                array_push($files, ...$nested['files']);
                array_push($directories, ...$nested['directories']);
            } else {
                $files[] = $entryLocation;
            }
        }

        $directories[] = $location;

        return ['files' => $files, 'directories' => $directories];
    }

    /**
     * Builds $to's new content inside a private staging directory and
     * renames it into place — the single publication path write(),
     * writeStream() and copy() share. {doc}`storage` states the sequence
     * and the guarantees that follow from it; two things hold it
     * together here.
     *
     * The staging directory is a child of $to's own parent, so both
     * paths are on one filesystem and the rename is the commit point.
     * mkdir(2) creates it atomically, so anything already at that name
     * fails the creation rather than being followed or reused.
     *
     * Nothing before the rename touches $to, so a publication that
     * fails before it leaves $to as it was. Every failure reports the
     * operation's declared Flysystem failure; writing and copying are
     * idempotent replacements of $to, so a caller's action on one is to
     * run the same call again.
     *
     * $fill receives the open staged handle, writes the body into it,
     * and returns the byte count it handed over. This method is that
     * handle's sole owner and closes it; $fill must not. $fill owns
     * only the handles it opens itself.
     *
     * @param ?int $mode the mode to publish with, or null for the mode
     *   this path would have had anyway — see publicationMode()
     * @param Closure(File): int $fill returns the byte count it wrote
     */
    private function publish(string $to, ?int $mode, Config $config, Closure $fill): void
    {
        $this->ensureParentDirectoryExists($to, $config);

        $staging = \dirname($to) . '/' . StagingName::generate();
        $this->filesystem->createDirectory($staging, self::STAGING_DIRECTORY_MODE);
        $staged = $staging . '/' . self::STAGED_FILE_NAME;

        try {
            $handle = $this->filesystem->openFile($staged, 'x');

            try {
                $written = $fill($handle);
            } finally {
                // Closed before the length is read, or bytes still
                // behind the handle would not be counted.
                $handle->close();
            }

            $this->assertStagedLength($staged, $written);

            $published = $mode ?? $this->publicationMode($to);

            if ($published !== null) {
                $this->filesystem->changePermissions($staged, $published);
            }

            $this->filesystem->move($staged, $to);
        } catch (Throwable $e) {
            // Throwable: a producer or a third-party Amp\File
            // implementation can raise anything, and an unfamiliar type
            // must not be the one case that leaves a staged file
            // behind. Rethrown unchanged, so a programmer error stays
            // one.
            $this->discardStaging($staging, $staged);

            throw $e;
        }

        $this->deleteStagingDirectory($staging);
    }

    /**
     * Amp\File\File::write() returns nothing and is not required to
     * have stored what it accepted, so the closed file's length is read
     * back and compared. A length the filesystem cannot report fails the
     * publication too — unknown is never treated as correct.
     */
    private function assertStagedLength(string $staged, int $written): void
    {
        $status = $this->filesystem->getLinkStatus($staged);
        $size = $status['size'] ?? null;

        if ($size !== $written) {
            throw new RuntimeException(\sprintf(
                'the staged file holds %s, not the %d byte(s) written to it',
                \is_int($size) ? $size . ' byte(s)' : 'an unreportable length',
                $written,
            ));
        }
    }

    /**
     * The mode to publish with when the caller asked for none: the one
     * $to already carries if it exists, so a replacement neither widens
     * nor narrows what it replaced. Null when $to does not exist or
     * reports no mode, which leaves the staged file at the mode its own
     * creation produced — this deployment's umask default.
     */
    private function publicationMode(string $to): ?int
    {
        $mode = $this->filesystem->getLinkStatus($to)['mode'] ?? null;

        return \is_int($mode) ? $mode & 0777 : null;
    }

    /**
     * Creates the directories $location needs to exist under, if they
     * do not already. Never undone by a caller that later fails: a
     * directory is shared state, and a concurrent call may already be
     * publishing into one this call happened to create — which is why
     * publish()'s failure guarantee excludes these and only these.
     */
    private function ensureParentDirectoryExists(string $location, Config $config): void
    {
        $directory = dirname($location);

        if ($directory === '.' || $this->filesystem->isDirectory($directory)) {
            return;
        }

        $this->filesystem->createDirectoryRecursively($directory, $this->implicitDirectoryMode($config));
    }

    /**
     * The mode createDirectory() applies to the directory a caller
     * named: `visibility` first, `directory_visibility` second — the
     * precedence Flysystem's own local adapter uses for the same call,
     * since a caller naming one directory means that directory
     * whichever of the two keys they reached for.
     */
    private function explicitDirectoryMode(Config $config): int
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY, $config->get(Config::OPTION_DIRECTORY_VISIBILITY));

        return $visibility !== null
            ? $this->visibility->forDirectory((string) $visibility)
            : $this->visibility->defaultForDirectories();
    }

    /**
     * The mode a parent directory built on the way to a file lands on:
     * `directory_visibility` only, never `visibility`. A `visibility`
     * on a write, copy or move names the file that call publishes, and
     * a private file does not ask for a private directory above it — a
     * 0700 parent created that way would also cut off every sibling
     * already published there under a different call's options.
     */
    private function implicitDirectoryMode(Config $config): int
    {
        $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY);

        return $visibility !== null
            ? $this->visibility->forDirectory((string) $visibility)
            : $this->visibility->defaultForDirectories();
    }

    /**
     * Removes a staged file that will never be published, and the
     * directory holding it. Best-effort: the failure that prompted the
     * cleanup is what the caller reports and a second failure here must
     * never mask it, so a cleanup that fails leaves the staged file
     * inside its private directory.
     */
    private function discardStaging(string $staging, string $staged): void
    {
        try {
            $this->filesystem->deleteFile($staged);
        } catch (Throwable) {
            // Best-effort; the original failure is what's reported.
        }

        $this->deleteStagingDirectory($staging);
    }

    /**
     * Removes an emptied staging directory, best-effort for the same
     * reason — and rmdir(2) refuses a directory that still holds
     * anything, so a staged file that could not be removed leaves its
     * directory in place rather than taking a still-present file down
     * with it silently.
     */
    private function deleteStagingDirectory(string $staging): void
    {
        try {
            $this->filesystem->deleteDirectory($staging);
        } catch (Throwable) {
            // Best-effort; see discardStaging().
        }
    }

    /**
     * The filesystem location $path names: $root followed by the
     * confined path's own segments. The empty path names $root itself,
     * which is $rootLocation rather than $root so a configured root of
     * '/' still produces '/' and not the empty string.
     */
    private function locate(ConfinedPath $path): string
    {
        return $path->path === '' ? $this->rootLocation : $this->root . '/' . $path->path;
    }

    /**
     * The one preamble every operation shares: confine $path, prove no
     * component of it resolves through a symlink, and hand back the
     * location to act on.
     *
     * @throws PathTraversalDetected|CorruptedPathDetected when $path is
     *   not confined — see ConfinedPath
     * @throws SymbolicLinkEncountered when a component is a symlink
     */
    private function confinedLocation(string $path): string
    {
        $confined = ConfinedPath::from($path);
        $this->assertNoSymlinkBelowRoot($confined);

        return $this->locate($confined);
    }

    /**
     * The preamble a single-operand publication runs instead, returning
     * null for a destination naming $root itself — the one outcome this
     * preamble cannot name, since write() reports it as an
     * UnableToWriteFile while move() and copy() report their own types.
     * The decision needs nothing but the confined path's own segments,
     * so it lands before the walk below reaches the driver.
     */
    private function publicationLocation(string $path): ?string
    {
        $confined = ConfinedPath::from($path);

        if ($confined->namesTheRoot()) {
            return null;
        }

        $this->assertNoSymlinkBelowRoot($confined);

        return $this->locate($confined);
    }

    /**
     * The same preamble for a two-operand operation, with both operands
     * confined before either is walked. Each is judged on its own, and
     * confinement is a purely lexical rule, so an unconfined operand on
     * either side costs no filesystem call at all. The destination is
     * null when it names $root itself.
     *
     * @return array{string, string|null}
     */
    private function confinedLocationPair(string $source, string $destination): array
    {
        $from = ConfinedPath::from($source);
        $to = ConfinedPath::from($destination);

        if ($to->namesTheRoot()) {
            return [$this->locate($from), null];
        }

        $this->assertNoSymlinkBelowRoot($from);
        $this->assertNoSymlinkBelowRoot($to);

        return [$this->locate($from), $this->locate($to)];
    }

    /**
     * Walks $path one confined segment at a time, from directly under
     * $root down to the target, and returns the logical path of the
     * first segment that is a symlink, or null if none of them are. A
     * component that does not exist yet is not a symlink either, so
     * this never rejects a path that is merely new.
     */
    private function firstSymlinkBelowRoot(ConfinedPath $path): ?string
    {
        $location = $this->root;
        $walked = '';

        foreach ($path->segments as $segment) {
            $location .= '/' . $segment;
            $walked = $walked === '' ? $segment : $walked . '/' . $segment;

            if ($this->filesystem->isSymlink($location)) {
                return $walked;
            }
        }

        return null;
    }

    /**
     * @throws SymbolicLinkEncountered when any component of $path is a
     *   symlink — see this class's own docblock for the policy and its
     *   one disclosed limitation.
     */
    private function assertNoSymlinkBelowRoot(ConfinedPath $path): void
    {
        $offender = $this->firstSymlinkBelowRoot($path);

        if ($offender !== null) {
            throw SymbolicLinkEncountered::atLocation($offender);
        }
    }
}
