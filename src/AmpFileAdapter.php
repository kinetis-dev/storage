<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\File\FilesystemException;
use Closure;
use Kinetis\Storage\Exception\IndeterminatePublicationException;
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
use Throwable;

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
 * streamed incrementally. writeStream() and copy() stream via
 * Amp\ByteStream\pipe() between real Amp\File\File handles, with no such
 * compromise; write() hands its string to the staged handle directly.
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
 * copy()'s post-copy source check carries the identical check-then-use
 * structure, for the identical reason — see sourceStillMatches() for
 * what it verifies and what it cannot.
 *
 * write(), writeStream() and copy() publish through one primitive,
 * publishThroughStagingDirectory(). {doc}`storage`'s "Writes are staged
 * privately and published atomically" section is the contract: the
 * privacy boundary and its same-UID limitation, what a failed call
 * guarantees, what it can leave behind, and the three outcomes of a
 * rename that fails without saying whether it happened.
 *
 * See {doc}`storage`.
 */
final readonly class AmpFileAdapter implements FilesystemAdapter
{
    private const int MIME_TYPE_SAMPLE_BYTES = 4096;

    /**
     * The POSIX S_IFMT mask, isolating a stat mode's file-type bits
     * (regular file/directory/symlink/etc.) from its permission bits —
     * a stable, portable value across every real Unix stat(2)
     * implementation. Amp\File\Filesystem::getStatus() reports a plain
     * file as 0100644, a directory as 0040755, and a symlink as the mode
     * of whatever it points to, since it follows symlinks;
     * getLinkStatus() is the lstat()-equivalent that does not. Used by
     * sourceUnchanged() below.
     */
    private const int TYPE_MASK = 0170000;

    /**
     * The mode every staging directory is created with, closing it to
     * every user but the one this process runs as. mkdir(2) applies the
     * umask to its argument and a umask only clears bits, so the
     * directory is never broader than this and needs no chmod afterward.
     * {doc}`storage` states what this boundary does and does not cover.
     */
    private const int STAGING_DIRECTORY_MODE = 0700;

    /**
     * The mode a staged file is moved to while it is still empty, and
     * keeps until the instant before it is renamed into place — a second
     * layer under the directory's own, so a staged file is private on
     * its own terms.
     */
    private const int STAGED_FILE_MODE = 0600;

    /**
     * The single entry a staging directory ever holds. Fixed rather than
     * random: the directory's own name already carries the per-call
     * randomness.
     */
    private const string STAGED_FILE_NAME = 'staged';

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

        // Resolved — and, for a garbage value, thrown — before anything
        // on disk is touched. See resolveExplicitFileMode()'s own
        // docblock.
        $mode = $this->resolveExplicitFileMode($config);

        try {
            $this->publishThroughStagingDirectory(
                $path,
                $location,
                $mode,
                $config,
                static function (File $staged) use ($contents): int {
                    $staged->write($contents);

                    // The whole body in one call, so the count checked
                    // against the staged length is its own length.
                    return \strlen($contents);
                },
            );
        } catch (FilesystemException|StreamException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        $mode = $this->resolveExplicitFileMode($config);

        try {
            $this->publishThroughStagingDirectory(
                $path,
                $location,
                $mode,
                $config,
                static function (File $staged) use ($contents): int {
                    // pipe() counts what it hands over chunk by chunk,
                    // so the delivered count needs no copy of the body.
                    return pipe(new ReadableResourceStream($contents), $staged);
                },
            );
        } catch (FilesystemException|StreamException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Resolves the concrete file mode an explicit visibility maps to,
     * before write()/writeStream() reach the filesystem at all.
     * VisibilityConverter::forFile() is a pure, side-effect-free
     * string-to-int mapping, so calling it here means a garbage
     * explicit value's InvalidVisibilityProvided escapes with nothing on
     * disk touched — no staging directory created, no parent directory
     * built for a call that was never going to publish anything.
     * Returns null when no visibility was requested, which
     * publishThroughStagingDirectory() reads as "publish at the mode
     * this path would have had anyway" rather than as a mode of its
     * own.
     */
    private function resolveExplicitFileMode(Config $config): ?int
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        return $visibility !== null ? $this->visibility->forFile((string) $visibility) : null;
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

        \error_clear_last();
        $stream = @\fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, $this->describeLastWarning('unable to open a temporary stream'));
        }

        try {
            $this->populateTempStream($stream, $path, $contents);
        } catch (UnableToReadFile $e) {
            \fclose($stream);

            throw $e;
        }

        return $stream;
    }

    /**
     * Writes $contents to $stream in a progress-checked loop, then
     * rewinds it to byte zero — construction/population/rewind of the
     * temporary stream readStream() hands back are all treated as part
     * of the read operation, so a failure at any of these stages
     * surfaces as UnableToReadFile for $path, never a bare PHP warning
     * as the only signal (every native call here is @-suppressed, with
     * the real warning text captured via describeLastWarning() instead
     * of discarded).
     *
     * fwrite() is not guaranteed to consume its entire argument in one
     * call — PHP's own streams layer retries a short stream_write()
     * automatically, but only up to the first zero-progress attempt;
     * confirmed directly, not assumed, since a userspace stream wrapper
     * can force exactly that combination deterministically. A single
     * unchecked fwrite() can therefore silently truncate $contents at
     * whatever a caller's resource happened to accept in one attempt.
     * false or zero progress on any individual call here is treated as
     * a hard failure; a lesser positive count just continues the loop.
     *
     * $stream is left open at whatever position a failure occurred —
     * this method only ever throws, never closes it; readStream() is
     * the one responsible for closing it, since only readStream() knows
     * whether $stream is one it opened itself versus one a caller
     * handed in some other way.
     *
     * A resource parameter rather than the URL to open, deliberately:
     * this is the seam a test uses to force a deterministic fwrite()/
     * rewind() failure via a real, custom stream wrapper, without
     * needing to exhaust a real resource (memory, disk, file
     * descriptors) to trigger one.
     *
     * @param resource $stream
     */
    private function populateTempStream($stream, string $path, string $contents): void
    {
        $length = \strlen($contents);
        $written = 0;

        while ($written < $length) {
            \error_clear_last();
            $result = @\fwrite($stream, \substr($contents, $written));

            if ($result === false || $result === 0) {
                throw UnableToReadFile::fromLocation($path, $this->describeLastWarning('unable to write to the temporary stream'));
            }

            $written += $result;
        }

        \error_clear_last();

        if (@\rewind($stream) === false) {
            throw UnableToReadFile::fromLocation($path, $this->describeLastWarning('unable to rewind the temporary stream'));
        }
    }

    /**
     * The real PHP warning message for the @-suppressed call
     * immediately before this, when one fired — genuinely more useful
     * than a fixed string for whoever debugs a real failure — or
     * $fallback when none did (a controlled test double, for instance,
     * can fail without ever triggering a native warning at all).
     */
    private function describeLastWarning(string $fallback): string
    {
        $error = \error_get_last();

        return $error !== null ? $error['message'] : $fallback;
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

    /**
     * File-only by contract, not merely by this class's own choice —
     * League\Flysystem\FilesystemAdapter::visibility() is declared to
     * return FileAttributes specifically, never DirectoryAttributes, and
     * League\Flysystem\Local\LocalFilesystemAdapter (Flysystem's own
     * reference local adapter) implements this identically: always
     * inverseForFile(), unconditionally, with no directory branch at
     * all. Nothing upstream ever calls this with a directory path
     * expecting directory-visibility semantics back.
     */
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
            $sample = $this->readMimeTypeSample($location);
        } catch (FilesystemException|StreamException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        $mimeType = $this->mimeTypeDetector->detectMimeType($path, $sample);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'unable to determine mime type');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    /**
     * Reads up to MIME_TYPE_SAMPLE_BYTES from $location for mimeType()'s
     * own detection. File::read() is a ReadableStream operation — the
     * concrete driver actually in play here (ParallelFile, confirmed
     * directly against its own source) only ever raises
     * Amp\ByteStream\StreamException (or its ClosedException subtype)
     * from read() itself, never Amp\File\FilesystemException, so only
     * StreamException is caught around it; mimeType()'s own catch still
     * lists both, since close() below genuinely can raise either.
     *
     * A close() failure while a read failure is already propagating is
     * absorbed here rather than allowed to take its place: PHP does
     * not discard an exception a try was already propagating when its
     * own finally throws a different one — it makes the finally's
     * exception the new outer exception and chains the original one
     * beneath it as previous. Left unhandled, that means the catch
     * below would see the close failure directly, with the real read
     * failure only reachable one level deeper via
     * getPrevious()->getPrevious() — not what
     * UnableToRetrieveMetadata::mimeType()'s own reason/previous should
     * report. Absorbing the close failure here keeps the read failure
     * as the one mimeType() directly reports. A close() failure with no
     * read failure in flight is not absorbed — it propagates normally,
     * the same "closing is part of the operation" precedent
     * write()/writeStream() already establish for their own handles.
     */
    private function readMimeTypeSample(string $location): string
    {
        $handle = $this->filesystem->openFile($location, 'r');
        $primaryFailure = null;

        try {
            return $handle->read(length: self::MIME_TYPE_SAMPLE_BYTES) ?? '';
        } catch (StreamException $e) {
            $primaryFailure = $e;

            throw $e;
        } finally {
            try {
                $handle->close();
            } catch (FilesystemException|StreamException $closeFailure) {
                if ($primaryFailure === null) {
                    throw $closeFailure;
                }
            }
        }
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

        // move() renames the same inode, so the destination already
        // carries the source's own mode with nothing to do by default —
        // only an explicit override needs applying. Kept a distinct
        // catch, rather than folding into the block above, so a failure
        // here still surfaces as UnableToMoveFile (the relocation itself
        // already succeeded; only the requested permission change didn't).
        // Never rolls $to back on that failure: after a successful
        // rename $to is the only remaining copy of the data, so removing
        // it would trade a wrong-mode file for real data loss. copy()
        // has no such constraint — it builds its result inside a
        // staging directory and never touches $to until that result is
        // complete.
        $explicitVisibility = $config->get(Config::OPTION_VISIBILITY);

        if ($explicitVisibility !== null) {
            try {
                // forFile() validates its own argument and can itself
                // throw League\Flysystem\InvalidVisibilityProvided for a
                // garbage explicit value — deliberately left uncaught
                // here (it isn't a League\Flysystem\FilesystemException
                // subtype Amp\File's own catch below matches, and PHP
                // evaluates it before changePermissions() is even
                // called): confirmed directly against
                // League\Flysystem\Local\LocalFilesystemAdapter's own
                // move(), which doesn't wrap this call either. Letting
                // it escape as itself, not relabeled as an
                // UnableToMoveFile it isn't, is the real, documented
                // Flysystem contract here, not a gap.
                $this->filesystem->changePermissions($to, $this->visibility->forFile((string) $explicitVisibility));
            } catch (FilesystemException $e) {
                throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
            }
        }
    }

    /**
     * Streams $source into a staged file inside a private directory
     * beside the destination and renames it over $to only once the
     * whole copy has succeeded — see publishThroughStagingDirectory()
     * for the publication guarantee and the failure guarantee, which
     * copy() shares with write() and writeStream() rather than
     * restating.
     *
     * What is copy()'s own: the retained mode is read from $from before
     * the source handle is opened, and $from is read again after the
     * byte copy. A disagreement between the two readings fails the copy
     * with UnableToRetrieveMetadata as the cause, before anything is
     * published. sourceStillMatches() states what that pair does and
     * does not detect.
     */
    #[\Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->assertNoSymlinkBelowRoot($from);
        $this->assertNoSymlinkBelowRoot($to);

        $explicitVisibility = $config->get(Config::OPTION_VISIBILITY);
        $retainVisibility = (bool) $config->get(Config::OPTION_RETAIN_VISIBILITY, true);

        // forFile() is a pure string-to-int mapping, so a garbage
        // explicit visibility raises InvalidVisibilityProvided with
        // nothing on disk touched yet. It escapes as itself rather than
        // being relabeled as an UnableToCopyFile it isn't.
        $explicitMode = $explicitVisibility !== null
            ? $this->visibility->forFile((string) $explicitVisibility)
            : null;

        // Filesystem::copy()'s default identical-path resolution
        // (ResolveIdenticalPathConflict::TRY) delegates all the way
        // here; FAIL and IGNORE are resolved by the Filesystem facade
        // and never reach this adapter. $to is $from, so there is no
        // second file to produce and nothing to replace — only an
        // explicit override touches the file. See
        // reapplySameOriginVisibility().
        if ($from === $to) {
            $this->reapplySameOriginVisibility($source, $destination, $to, $explicitMode);

            return;
        }

        try {
            // Observed before the source handle is opened, whatever the
            // destination's mode is going to come from. Observed after
            // the open instead, a source replaced in between would be
            // described by both observations while the handle still
            // streams the file that was there first, and the check after
            // the copy would agree with itself.
            $beforeStatus = $this->filesystem->getLinkStatus($from);
            $mode = $this->resolveCopyMode($source, $explicitMode, $retainVisibility, $beforeStatus);

            $this->publishThroughStagingDirectory(
                $destination,
                $to,
                $mode,
                $config,
                function (File $staged) use ($from, $source, $beforeStatus): int {
                    // The source handle is this closure's to close; the
                    // staged one belongs to the primitive.
                    $readHandle = $this->filesystem->openFile($from, 'r');

                    return self::closingAfter([$readHandle], function () use ($staged, $readHandle, $from, $source, $beforeStatus): int {
                        $copied = pipe($readHandle, $staged);

                        // Checked before the commit, so a source that
                        // changed under the copy publishes nothing
                        // rather than one file's bytes as another's.
                        if (!$this->sourceStillMatches($from, $beforeStatus)) {
                            throw UnableToRetrieveMetadata::create($source, 'identity', 'the source changed while it was being copied');
                        }

                        return $copied;
                    });
                },
            );
        } catch (FilesystemException|StreamException|UnableToRetrieveMetadata $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Re-reads $from after the byte copy and reports whether it still
     * describes the file the copy started from — same type, same
     * permission bits, same device and inode. Run for every copy, not
     * only for one retaining the source's visibility, so a source
     * replaced under an explicit-mode copy is rejected too.
     *
     * Both observations go through getLinkStatus(): lstat semantics, so
     * a symlink swapped into $from's place reads as a file-type change
     * rather than being followed, and, under Amp\File's default driver
     * composition, the uncached path. StatusCachingFilesystemDriver
     * caches a getStatus() result per path for 1000 seconds and
     * invalidates an entry only when this same process mutates that
     * path, so a second getStatus() would usually replay the first
     * observation verbatim.
     *
     * What this pair does not catch, and no reading of a path can: a
     * file mutated in place, or replaced and then reverted, between the
     * two observations — the path's identity is unchanged because the
     * inode is the same one, or is the same one again. Amp\File\File
     * exposes no fstat-on-handle (getMode() returns the open mode,
     * 'r'/'w', never permission bits), so neither observation is bound
     * to the inode the read handle actually streamed. Same check-then-use
     * structure, same missing kernel primitive, as this class's symlink
     * checks: it narrows the window, it does not make copy() atomic.
     *
     * @param array<string, mixed>|null $before
     */
    private function sourceStillMatches(string $from, ?array $before): bool
    {
        return $before !== null && self::sourceUnchanged($before, $this->filesystem->getLinkStatus($from));
    }

    /**
     * True only when both $before and $after report the identical,
     * known file type, permission bits and stable identity — the pure
     * comparison sourceStillMatches() defers to, kept filesystem-free
     * so it is directly testable with fabricated status arrays rather
     * than needing a real race to exercise. Type and permission are two
     * separate comparisons rather than one combined bitmask, so a path
     * that became a directory or a symlink between the two stats while
     * coincidentally sharing the original file's low 9 permission bits
     * counts as changed rather than being masked away by an `& 0777`
     * comparison alone. $after being null (the path no longer
     * resolves), or either side missing a mode or reporting a non-int
     * one, all count as changed too — unknown is never treated as
     * unchanged.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    private static function sourceUnchanged(array $before, ?array $after): bool
    {
        if ($after === null) {
            return false;
        }

        $beforeMode = $before['mode'] ?? null;
        $afterMode = $after['mode'] ?? null;

        if (!\is_int($beforeMode) || !\is_int($afterMode)) {
            return false;
        }

        if (($beforeMode & self::TYPE_MASK) !== ($afterMode & self::TYPE_MASK)) {
            return false;
        }

        if (($beforeMode & 0777) !== ($afterMode & 0777)) {
            return false;
        }

        // Device and inode are the only fields a stat offers that tell
        // one file from a different file at the same path, which mode
        // bits cannot: a replacement created with the original's own
        // permissions matches on type and mode exactly. An identity
        // neither observation supplies counts as changed — unknown is
        // never treated as unchanged.
        $beforeIdentity = self::identityOf($before);

        return $beforeIdentity !== null && $beforeIdentity === self::identityOf($after);
    }

    /**
     * copy()'s identical-source-and-destination branch. There is no
     * separate destination here to retain anything *onto* — $to is
     * $from — so retain_visibility (whether true or false) has nothing
     * to do and is never consulted: only an explicit override touches
     * the file's mode. Reading the file's own current mode back through
     * inverseForFile() and reapplying it, the way the normal copy path
     * does for genuine retention, would silently canonicalize a real
     * but non-canonical mode (0640, say) to Visibility::PUBLIC's own
     * 0644 the moment inverseForFile() fails to recognize it as one of
     * the two values it knows — broadening a file's permissions with no
     * explicit request to do so at all. Skipping the read-and-reapply
     * entirely for the no-explicit-visibility case is what avoids that.
     *
     * A visibility failure here never deletes the file: $to *is* the
     * source, the only existing copy of the data, so removing it on a
     * permission-change failure would be real data loss — the same
     * reasoning move()'s own no-rollback catch documents.
     */
    private function reapplySameOriginVisibility(
        string $source,
        string $destination,
        string $to,
        ?int $explicitMode,
    ): void {
        if ($explicitMode === null) {
            return;
        }

        try {
            $this->filesystem->changePermissions($to, $explicitMode);
        } catch (FilesystemException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Decides which mode (if any) copy() applies to the destination — a
     * pure decision with no filesystem access of its own, kept separate
     * from the stat call so the invariant that matters (unresolvable
     * source metadata must never silently become Visibility::PUBLIC) is
     * testable without a real filesystem.
     *
     * $sourceStatus is copy()'s pre-copy observation of the source,
     * taken before the source handle is opened, so the mode this returns
     * is decided from a reading nothing the copy itself does could have
     * influenced. Whether that observation still describes the source
     * afterward is sourceStillMatches()'s question, never this
     * method's.
     *
     * @param array<string, mixed>|null $sourceStatus
     * @throws UnableToRetrieveMetadata when retention is needed but
     *   $sourceStatus is null, or its mode is missing or not an int.
     *   Unknown metadata is a failure in each of those shapes, never
     *   mode 0, which inverseForFile() would map to Visibility::PUBLIC
     *   the same as a real 0644/0600 match. Nothing in the driver
     *   contract guarantees an int mode, so it is checked rather than
     *   assumed.
     */
    private function resolveCopyMode(
        string $source,
        ?int $explicitMode,
        bool $retainVisibility,
        ?array $sourceStatus,
    ): ?int {
        if ($explicitMode !== null) {
            return $explicitMode;
        }

        if (!$retainVisibility) {
            return null;
        }

        $mode = $sourceStatus['mode'] ?? null;

        if (!\is_int($mode)) {
            throw UnableToRetrieveMetadata::visibility($source, 'source status could not be retrieved');
        }

        // Round-tripped through the converter rather than carried over
        // raw, so the destination lands on the canonical mode for the
        // source's own visibility — the same value setVisibility() would
        // produce — not an arbitrary source mode this adapter never
        // promises to reproduce.
        return $this->visibility->forFile($this->visibility->inverseForFile($mode & 0777));
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

    /**
     * Builds $to's new content inside a private staging directory and
     * renames it into place — the single publication path write(),
     * writeStream() and copy() share.
     *
     * $fill receives an open handle on the staged file, writes the body
     * into it, and returns the byte count it handed over. This method is
     * the staged handle's sole normal owner and closes it; $fill must
     * not. $fill owns only the handles it opens itself — copy()'s source
     * handle, which it closes through closingAfter() — while write() and
     * writeStream() open none and close nothing.
     *
     * The staged file is created inside a 0700 directory, moved to 0600
     * while empty, checked for a stored length matching what was written
     * to it, given the mode it is to be published under, and renamed
     * over $to. The rename is the commit point, atomic because the
     * staging directory is a child of $to's own parent and both paths
     * are therefore on one filesystem.
     *
     * {doc}`storage` states the privacy boundary, the publication
     * guarantee and the three outcomes of a failed rename; this method
     * implements them and does not restate them.
     *
     * @param ?int $mode the mode to publish with, or null for the mode
     *   this path would have had anyway — see defaultPublicationModeFor()
     * @param Closure(File): int $fill returns the byte count it wrote
     * @throws IndeterminatePublicationException when a rename failure
     *   cannot be classified
     * @throws Throwable anything else the filesystem, $fill or the
     *   rename raises — write(), writeStream() and copy() relabel the
     *   types they name and let the rest reach the caller as itself
     */
    private function publishThroughStagingDirectory(
        string $path,
        string $to,
        ?int $mode,
        Config $config,
        Closure $fill,
    ): void {
        $this->ensureParentDirectoryExists($to, $config);

        $staging = $this->createStagingDirectory($to);
        $staged = $staging . '/' . self::STAGED_FILE_NAME;

        $handle = null;
        $closed = false;

        try {
            $handle = $this->filesystem->openFile($staged, 'x');

            // Read while the staged file still carries the mode its
            // creation gave it, before the chmod below replaces that:
            // that is the umask default for a new file here.
            $publishedMode = $mode ?? $this->defaultPublicationModeFor($to, $staged);

            $this->filesystem->changePermissions($staged, self::STAGED_FILE_MODE);

            $written = $fill($handle);

            // The staged handle is closed here and nowhere else on a
            // path that reaches this line. Amp\Closable does not promise
            // close() is idempotent, so a $fill that closed it too would
            // make a conforming File that rejects a second close fail a
            // write that had already succeeded. The close has to happen
            // before the status read below, or that read could miss
            // bytes still buffered behind the handle.
            self::closeAll([$handle], null);
            $closed = true;

            $stagedIdentity = $this->verifiedStagedIdentity($staged, $written);

            $this->filesystem->changePermissions($staged, $publishedMode);
        } catch (Throwable $e) {
            // Throwable: a producer or a third-party Amp\File
            // implementation can raise anything, and an unfamiliar type
            // must not be the one case that leaves a staged file behind.
            // Rethrown unchanged, so a programming error stays one.
            //
            // Closed here only when the close above did not already
            // succeed, so no path closes a handle twice except one whose
            // own close failed — and the unlink below needs that attempt.
            if ($handle !== null && !$closed && !$handle->isClosed()) {
                self::closeAll([$handle], $e);
            }

            $this->deleteBestEffort($staged);
            $this->deleteDirectoryBestEffort($staging);

            throw $e;
        }

        $this->commitStagedFile($path, $staged, $to, $staging, $stagedIdentity);
    }

    /**
     * Renames the staged file over $to and cleans the staging directory
     * up afterward.
     *
     * A rename failure does not by itself say whether the rename
     * happened: a driver running rename(2) in a worker can lose the
     * reply once the kernel has already committed. $stagedIdentity is
     * the device and inode the staged file carried a moment earlier,
     * which is what makes the three outcomes distinguishable — see
     * classifyFailedRename().
     *
     * @param array{dev: int, ino: int} $stagedIdentity
     */
    private function commitStagedFile(
        string $path,
        string $staged,
        string $to,
        string $staging,
        array $stagedIdentity,
    ): void {
        try {
            $this->filesystem->move($staged, $to);
        } catch (Throwable $renameFailure) {
            try {
                $committed = $this->classifyFailedRename($path, $staged, $to, $stagedIdentity);
            } catch (Throwable $indeterminate) {
                // rmdir(2) refuses a directory that still holds
                // anything, so this removes the staging directory only
                // where nothing unaccounted for is left in it.
                $this->deleteDirectoryBestEffort($staging);

                throw $indeterminate;
            }

            if (!$committed) {
                $this->deleteBestEffort($staged);
                $this->deleteDirectoryBestEffort($staging);

                throw $renameFailure;
            }
        }

        $this->deleteDirectoryBestEffort($staging);
    }

    /**
     * Decides what a failed rename actually did, from the staged file's
     * own identity.
     *
     * Returns true when the staged inode is gone from the staging
     * directory and is the one now at $to: the rename committed and the
     * only thing left is cleanup. Returns false when that same inode is
     * still staged: it did not commit, so the staged file is this call's
     * to remove and the original failure is what the caller gets.
     *
     * Everything else is unproven and throws
     * IndeterminatePublicationException: a status that cannot be read,
     * an identity the filesystem does not supply, a staged path holding
     * some other inode, or a $to holding neither the old file nor the
     * staged one. Nothing is deleted on that path — an object whose
     * ownership is not established is not this call's to remove — beyond
     * the staging directory, which rmdir(2) refuses while anything is
     * still in it.
     *
     * @param array{dev: int, ino: int} $stagedIdentity
     */
    private function classifyFailedRename(string $path, string $staged, string $to, array $stagedIdentity): bool
    {
        try {
            $stagedNow = $this->filesystem->getLinkStatus($staged);
            $destinationNow = $stagedNow === null ? $this->filesystem->getLinkStatus($to) : null;
        } catch (Throwable) {
            throw IndeterminatePublicationException::atLocation($path, IndeterminatePublicationException::REASON_UNREADABLE);
        }

        if ($stagedNow !== null) {
            if (self::identityOf($stagedNow) === $stagedIdentity) {
                return false;
            }

            throw IndeterminatePublicationException::atLocation($path, IndeterminatePublicationException::REASON_FOREIGN_STAGED_OBJECT);
        }

        if ($destinationNow !== null && self::identityOf($destinationNow) === $stagedIdentity) {
            return true;
        }

        throw IndeterminatePublicationException::atLocation($path, IndeterminatePublicationException::REASON_DESTINATION_NOT_STAGED);
    }

    /**
     * The staged file's device and inode, once its stored length has
     * been checked against the $written bytes handed to it.
     *
     * Amp\File\File::write() returns void and is not required to have
     * stored what it accepted, so the length has to be read back;
     * {doc}`storage` states what that promises and what it does not.
     * The identity comes from the same status, which commitStagedFile()
     * needs to classify a rename that fails without saying whether it
     * happened. A length or an identity the filesystem cannot report
     * fails the publication: unknown is never treated as correct.
     *
     * @return array{dev: int, ino: int}
     * @throws FilesystemException when the length or the identity
     *   disagrees with what was staged, or cannot be read
     */
    private function verifiedStagedIdentity(string $staged, int $written): array
    {
        $status = $this->filesystem->getLinkStatus($staged);
        $size = $status !== null ? ($status['size'] ?? null) : null;

        if ($size !== $written) {
            throw new FilesystemException(\sprintf(
                'the staged file holds %s, not the %d byte(s) written to it',
                \is_int($size) ? $size . ' byte(s)' : 'an unreportable length',
                $written,
            ));
        }

        // A status that could not be read reports no length either, so
        // the check above has already thrown for it.
        $identity = self::identityOf($status);

        if ($identity === null) {
            throw new FilesystemException('the staged file reports no device and inode to publish it by');
        }

        return $identity;
    }

    /**
     * A status's device and inode, or null when the filesystem supplies
     * neither as a usable value (PHP reports an unavailable field as 0).
     * Two statuses describe the same object when this returns the same
     * pair for both.
     *
     * @param array<string, mixed> $status
     * @return array{dev: int, ino: int}|null
     */
    private static function identityOf(array $status): ?array
    {
        $device = $status['dev'] ?? null;
        $inode = $status['ino'] ?? null;

        if (!\is_int($device) || !\is_int($inode) || $inode === 0) {
            return null;
        }

        return ['dev' => $device, 'ino' => $inode];
    }

    /**
     * Creates, and returns the path of, a staging directory in $to's own
     * directory. mkdir(2) is atomic and the name is random per call, so
     * anything already at that path — a symlink included — fails the
     * creation rather than being followed or reused. Every component
     * above it is the destination's own, already checked by
     * assertNoSymlinkBelowRoot().
     *
     * A creation failure removes nothing: a driver running mkdir(2) in a
     * worker can lose the reply after the directory exists, and neither
     * that nor a directory found at this path shows whether it is this
     * call's own. What the failure can leave is the empty directory
     * {doc}`storage` discloses; nothing was written into it and no
     * destination was touched.
     */
    private function createStagingDirectory(string $to): string
    {
        $staging = \dirname($to) . '/.kinetis-stage.' . \bin2hex(\random_bytes(16));

        $this->filesystem->createDirectory($staging, self::STAGING_DIRECTORY_MODE);

        return $staging;
    }

    /**
     * The mode to publish with when the caller asked for none: the one
     * $to already carries if it exists, so a replacement never widens or
     * narrows what it replaced, and otherwise the mode $staged's own
     * creation produced, which is this deployment's umask default for a
     * new file. Read from the filesystem rather than from umask(), which
     * PHP can only report by setting it and setting it back.
     *
     * A mode neither path can supply falls back to STAGED_FILE_MODE:
     * unknown permissions are published private, never guessed public.
     */
    private function defaultPublicationModeFor(string $to, string $staged): int
    {
        foreach ([$to, $staged] as $path) {
            $status = $this->filesystem->getLinkStatus($path);
            $mode = $status !== null ? ($status['mode'] ?? null) : null;

            if (\is_int($mode)) {
                return $mode & 0777;
            }
        }

        return self::STAGED_FILE_MODE;
    }

    /**
     * Runs $body, then closes $handles, without letting the closing
     * rewrite what went wrong.
     *
     * A finally that throws replaces the exception its try was already
     * propagating, chaining the original beneath it — so an unguarded
     * close() failure on the way out of a failed pipe() becomes the
     * failure the caller reports. Capturing the primary first and
     * handing it to closeAll() keeps the original the reported one. The
     * same mechanism as readMimeTypeSample().
     *
     * With nothing already failing, a close() failure is the failure:
     * closing is part of the operation.
     *
     * @param list<File> $handles
     * @param Closure(): int $body
     * @return int whatever $body reported writing
     */
    private static function closingAfter(array $handles, Closure $body): int
    {
        $primaryFailure = null;

        try {
            return $body();
        } catch (Throwable $e) {
            $primaryFailure = $e;

            throw $e;
        } finally {
            self::closeAll($handles, $primaryFailure);
        }
    }

    /**
     * Closes every handle, attempting each whatever the ones before it
     * did: a first close that throws must not leave a second handle
     * open, which is what closing them one statement after another would
     * do.
     *
     * With $primaryFailure in flight every close failure is absorbed,
     * since that failure is what the operation reports. With none, the
     * first close failure in $handles order is thrown, so the reported
     * failure is deterministic rather than whichever handle happened to
     * be closed last.
     *
     * @param list<File> $handles
     */
    private static function closeAll(array $handles, ?Throwable $primaryFailure): void
    {
        $cleanupFailure = null;

        foreach ($handles as $handle) {
            try {
                $handle->close();
            } catch (Throwable $e) {
                $cleanupFailure ??= $e;
            }
        }

        if ($primaryFailure === null && $cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }

    /**
     * Creates the directories $location needs to exist under, if they do
     * not already. Never undone by a caller that later fails: a
     * directory is shared state, and a concurrent call may already be
     * publishing into one this call happened to create — which is why
     * publishThroughStagingDirectory()'s failure guarantee excludes
     * these and only these.
     */
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

    /**
     * Removes a staged file that will never be published, whether the
     * publication failed or already succeeded. Best-effort by design: on
     * the failure path the failure that prompted the cleanup is what the
     * caller reports and a second failure here must never mask it, and
     * on the success path the destination is already committed, so
     * nothing here may turn a completed operation into a reported
     * failure. That is a real trade, not a free one — a cleanup that
     * fails leaves the staged file where it is, which is why
     * publishThroughStagingDirectory() promises the attempt rather than
     * the outcome.
     *
     * Called only once the staged file's handle is closed: unlinking a
     * file with a still-open handle works on POSIX, but Windows commonly
     * refuses to — so a handle that could not be closed is itself a
     * reason this may not remove anything.
     */
    private function deleteBestEffort(string $location): void
    {
        try {
            $this->filesystem->deleteFile($location);
        } catch (Throwable) {
            // Best-effort; the original failure is what's reported.
            // Swallowing every type, not only the expected ones, is the
            // whole point: this runs while a failure is already being
            // reported, and must not become that failure.
        }
    }

    /**
     * The staging directory's counterpart to deleteBestEffort(), for the
     * same reasons — and rmdir(2) refuses a directory that still holds
     * anything, so a staged file that could not be removed leaves its
     * directory in place rather than taking a still-present file down
     * with it silently.
     */
    private function deleteDirectoryBestEffort(string $location): void
    {
        try {
            $this->filesystem->deleteDirectory($location);
        } catch (Throwable) {
            // Best-effort; see deleteBestEffort().
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
