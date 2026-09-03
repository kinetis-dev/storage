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
 * copy()'s retained-visibility resolution carries the identical
 * check-then-use structure, for the identical reason — see
 * verifiedSourceStatus()'s own docblock for what it actually verifies
 * and doesn't.
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
     * implementation, not something specific to this project. Confirmed
     * directly against real Amp\File\Filesystem::getStatus() output
     * before relying on it, not assumed from the constant's own name: a
     * plain file reports mode 0100644, a directory 0040755, a followed
     * symlink the mode of whatever it points to (getStatus() follows
     * symlinks; getLinkStatus() is the lstat()-equivalent that doesn't).
     * Used by sourceModeUnchanged() below.
     */
    private const int TYPE_MASK = 0170000;

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
        $existedBefore = true;
        $modeFailed = false;

        try {
            // isFile() delegates to getStatus(), which can itself throw
            // FilesystemException — kept inside this try, not before
            // it, so that failure surfaces as UnableToWriteFile too,
            // not raw.
            if ($mode !== null) {
                $existedBefore = $this->filesystem->isFile($location);
            }

            $this->ensureParentDirectoryExists($location, $config);
            $handle = $this->filesystem->openFile($location, 'w');

            try {
                // Applied before the body is written, not after — see
                // resolveExplicitFileMode()'s own docblock for why this
                // ordering closes the confidentiality gap a
                // mode-after-body sequence would leave open.
                if ($mode !== null) {
                    try {
                        $this->filesystem->changePermissions($location, $mode);
                    } catch (FilesystemException $e) {
                        $modeFailed = true;

                        throw $e;
                    }
                }

                $handle->write($contents);
            } finally {
                $handle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            // Deferred until $handle is guaranteed closed by the
            // finally above: unlinking an open file works on POSIX but
            // is not portable — Windows commonly refuses to unlink a
            // file with an open handle.
            $this->deleteIfNewAfterModeFailure($location, $modeFailed, $existedBefore);

            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->assertNoSymlinkBelowRoot($location);

        $mode = $this->resolveExplicitFileMode($config);
        $existedBefore = true;
        $modeFailed = false;

        try {
            if ($mode !== null) {
                $existedBefore = $this->filesystem->isFile($location);
            }

            $this->ensureParentDirectoryExists($location, $config);
            $handle = $this->filesystem->openFile($location, 'w');

            try {
                if ($mode !== null) {
                    try {
                        $this->filesystem->changePermissions($location, $mode);
                    } catch (FilesystemException $e) {
                        $modeFailed = true;

                        throw $e;
                    }
                }

                pipe(new ReadableResourceStream($contents), $handle);
            } finally {
                $handle->close();
            }
        } catch (FilesystemException|StreamException $e) {
            $this->deleteIfNewAfterModeFailure($location, $modeFailed, $existedBefore);

            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Resolves the concrete file mode an explicit visibility maps to —
     * before openFile('w') below ever touches $location, not after.
     * VisibilityConverter::forFile() is a pure, side-effect-free
     * string-to-int mapping, so calling it here means a garbage
     * explicit value's InvalidVisibilityProvided escapes before
     * openFile('w') truncates whatever content $location might already
     * hold, rather than after — the smallest possible blast radius for
     * an invalid request: zero disk mutation at all, not merely "no
     * secret bytes written." Returns null when no visibility was
     * requested, so write()/writeStream() skip applying a mode
     * entirely — pure overhead on the overwhelming majority of writes
     * that never set one.
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
        // Deliberately never deletes $to on that failure, unlike copy()'s
        // own equivalent catch block below: $to is the *only* remaining
        // copy of the data after a successful rename — the source is
        // gone — so removing it here would trade a wrong-mode file for
        // real data loss, a strictly worse outcome. copy() has no such
        // constraint: its source is untouched, so cleaning up its
        // destination just means the copy didn't happen, not that
        // anything was lost.
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

    #[\Override]
    public function copy(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->assertNoSymlinkBelowRoot($from);
        $this->assertNoSymlinkBelowRoot($to);

        $explicitVisibility = $config->get(Config::OPTION_VISIBILITY);
        $retainVisibility = (bool) $config->get(Config::OPTION_RETAIN_VISIBILITY, true);
        $needsSourceMode = $explicitVisibility === null && $retainVisibility;

        // Filesystem::copy()'s default identical-path resolution
        // (ResolveIdenticalPathConflict::TRY) still delegates all the
        // way here — FAIL/IGNORE are both resolved entirely by the
        // Filesystem facade before it ever calls this method, so this
        // adapter never sees either. Below, openFile($to, 'w') would
        // truncate $to before pipe() ever reads a single byte from
        // $from — for a same-path "copy" that's the identical inode,
        // silent, destructive data loss for what's promised to be a
        // no-op. The byte copy is skipped entirely, matching
        // League\Flysystem\Local\LocalFilesystemAdapter's own copy(),
        // which guards the same case (`$sourcePath !== $destinationPath`)
        // before ever calling PHP's own copy() — only the visibility
        // step still runs, and only for an explicit override: see
        // reapplySameOriginVisibility()'s own docblock for why
        // retain_visibility is deliberately never consulted here at all.
        if ($from === $to) {
            $this->reapplySameOriginVisibility($source, $destination, $to, $explicitVisibility);

            return;
        }

        // A single pre-open stat narrows the original after-the-copy
        // race but doesn't close it: nothing bound that captured mode to
        // the bytes actually read afterward — a source replaced with
        // different content between the stat and openFile() would still
        // have its *old* mode applied to the *new* bytes. Amp\File\File
        // (the handle openFile() returns) exposes no fstat-on-handle at
        // all to bind metadata to what's actually opened instead
        // (confirmed via reflection: getMode() returns the *open* mode,
        // 'r'/'w', never Unix permission bits) — so the best available
        // protocol is to stat as close to the open as possible, then
        // stat again after the byte copy and refuse to apply anything
        // unless both agree. See verifiedSourceStatus()'s own docblock
        // for the one further limitation even that leaves — a real,
        // disclosed one, not a claim this closes the race outright.
        /** @var array<string, mixed>|null $beforeStatus */
        $beforeStatus = null;

        try {
            $this->ensureParentDirectoryExists($to, $config);
            $readHandle = $this->filesystem->openFile($from, 'r');

            try {
                if ($needsSourceMode) {
                    $beforeStatus = $this->filesystem->getStatus($from);
                }

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

        // The bytes above are a legitimate copy regardless of what
        // follows: a file descriptor is bound to the inode it opened,
        // not the path string, so they genuinely reflect whatever that
        // handle actually read no matter what happened to the path
        // afterward. What's still in question is only the *visibility*
        // about to be applied — but openFile($to, 'w') already created
        // $to at whatever the adapter/umask default happens to be
        // (public-leaning on most real deployments), so simply skipping
        // the chmod on a verification failure and leaving that file in
        // place would be exactly the "a possibly-private source ends up
        // more exposed than intended" outcome this fix exists to close,
        // just via the default creation mode instead of an explicitly
        // wrong one. Explicit cleanup, not left ambiguous: the catch
        // below deletes $to before rethrowing, best-effort — a failure
        // deleting it is a distinct, secondary problem that must never
        // mask or replace the real one being reported.
        try {
            $sourceStatus = $needsSourceMode ? $this->verifiedSourceStatus($from, $beforeStatus) : null;
            $visibilityToApply = $this->resolveCopyVisibility(
                $source,
                $explicitVisibility !== null ? (string) $explicitVisibility : null,
                $retainVisibility,
                $sourceStatus,
            );

            if ($visibilityToApply !== null) {
                // See move()'s own identical comment: forFile() can
                // throw League\Flysystem\InvalidVisibilityProvided for a
                // garbage explicit value, deliberately left uncaught
                // here to match League\Flysystem\Local\LocalFilesystemAdapter's
                // own copy(), which doesn't wrap this call either.
                $this->filesystem->changePermissions($to, $this->visibility->forFile($visibilityToApply));
            }
        } catch (FilesystemException|UnableToRetrieveMetadata $e) {
            try {
                $this->filesystem->deleteFile($to);
            } catch (FilesystemException) {
                // Best-effort; the original failure below is what's reported.
            }

            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Re-stats $from after the byte copy and returns $before only when
     * the two observations agree on mode — never $after itself, and
     * never a best-of-both merge, so resolveCopyVisibility() always
     * reasons about one single, internally-consistent observation.
     * Disagreement, a vanished path, or unresolvable metadata on either
     * side all collapse to the same null resolveCopyVisibility() already
     * treats as a genuine failure — this method only ever decides
     * whether the two observations are trustworthy together, never
     * whether that's an acceptable outcome.
     *
     * Best-effort, not a closed race — a real, disclosed limit, the same
     * kind this class's own "Not a security boundary against a
     * concurrent actor" docblock section already states for the symlink
     * checks, for the identical structural reason: a check and the
     * operation it protects are always separated by at least one more
     * syscall, and closing that fully needs the same kernel-level,
     * handle-bound primitive (openat()/a real fstat-on-handle) that
     * section already explains PHP exposes no binding for here either.
     * One further limitation specific to this pair of calls, found by
     * reading Amp\File's real source rather than assumed: both go
     * through Amp\File\Driver\StatusCachingFilesystemDriver, which
     * caches a getStatus() result per path for one second, invalidated
     * only by an operation *this same process* performs against that
     * path afterward (changePermissions()/write()/deleteFile()/etc.) —
     * reading $from, all copy() itself ever does to it, never
     * invalidates the entry. So these two calls reliably detect a
     * same-process race (a second Fiber/request handled by the same
     * persistent worker changing the source in between — a real,
     * meaningful scenario under this framework's own primary FrankenPHP
     * worker model, where many requests share one process), but not a
     * source modified by a genuinely separate process/worker within that
     * window, since nothing invalidates this process's own cache for a
     * change it never made.
     *
     * @param array<string, mixed> $before
     * @return array<string, mixed>|null
     */
    private function verifiedSourceStatus(string $from, ?array $before): ?array
    {
        if ($before === null) {
            return null;
        }

        $after = $this->filesystem->getStatus($from);

        return self::sourceModeUnchanged($before, $after) ? $before : null;
    }

    /**
     * True only when both $before and $after report the identical,
     * genuinely-known file type *and* permission bits — the pure
     * comparison verifiedSourceStatus() defers to, kept filesystem-free
     * so it's directly, deterministically testable with fabricated
     * status arrays rather than needing a real race to exercise. Type
     * and permission are checked as two separate, explicit comparisons
     * rather than one combined bitmask specifically so a path that
     * became a directory or a symlink between the two stats — while
     * coincidentally sharing the same low 9 permission bits as the
     * original regular file — is correctly treated as changed, not
     * masked away by only ever comparing `& 0777`. $after being null
     * (the path no longer resolves), or either side missing a mode or
     * reporting a non-int one, all count as "changed" too — unknown is
     * never treated as unchanged.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    private static function sourceModeUnchanged(array $before, ?array $after): bool
    {
        if ($after === null) {
            return false;
        }

        $beforeMode = $before['mode'] ?? null;
        $afterMode = $after['mode'] ?? null;

        if (!\is_int($beforeMode) || !\is_int($afterMode)) {
            return false;
        }

        return ($beforeMode & self::TYPE_MASK) === ($afterMode & self::TYPE_MASK)
            && ($beforeMode & 0777) === ($afterMode & 0777);
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
     * A visibility failure here never deletes the file — unlike the
     * normal path's catch-and-delete, $to *is* the source, the only
     * existing copy of the data, so deleting it on a permission-change
     * failure would be real data loss. The same reasoning move()'s own
     * no-rollback catch already documents for the identical structural
     * reason.
     */
    private function reapplySameOriginVisibility(
        string $source,
        string $destination,
        string $to,
        mixed $explicitVisibility,
    ): void {
        if ($explicitVisibility === null) {
            return;
        }

        try {
            // See copy()'s own identical comment on its main path:
            // forFile() can throw League\Flysystem\InvalidVisibilityProvided
            // for a garbage explicit value, deliberately left uncaught
            // here too, matching LocalFilesystemAdapter's own copy(),
            // which doesn't wrap this call either.
            $this->filesystem->changePermissions($to, $this->visibility->forFile((string) $explicitVisibility));
        } catch (FilesystemException $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Decides which visibility (if any) copy() should apply to the
     * destination — a small, pure decision with no filesystem access of
     * its own, deliberately kept separate from the getStatus() call
     * itself. That separation is what makes the one invariant that
     * actually matters — unresolvable source metadata must never
     * silently become Visibility::PUBLIC — directly and deterministically
     * testable: Amp\File\Filesystem is `final`, offering no seam to fake
     * a real getStatus() failure through, but this method needs no real
     * filesystem at all to prove its own null-handling.
     *
     * $sourceStatus is expected to already be verifiedSourceStatus()'s
     * own output (or null), so a mismatched/vanished/unresolvable source
     * has already collapsed to null by the time this runs — this method
     * never itself distinguishes "never resolvable" from "resolvable but
     * untrustworthy," both are the identical failure from here.
     *
     * @param array<string, mixed>|null $sourceStatus
     * @throws UnableToRetrieveMetadata when retention is needed but
     *   $sourceStatus is null, or its mode is missing or not an int —
     *   unknown metadata is a genuine failure in every one of those
     *   shapes, never mode 0, which inverseForFile() would otherwise map
     *   to Visibility::PUBLIC same as a real 0644/0600 match. Not
     *   assumed safe just because every Amp\File driver installed today
     *   happens to always populate an int mode — checked explicitly
     *   regardless, since nothing in the driver's own contract actually
     *   guarantees that.
     */
    private function resolveCopyVisibility(
        string $source,
        ?string $explicitVisibility,
        bool $retainVisibility,
        ?array $sourceStatus,
    ): ?string {
        if ($explicitVisibility !== null) {
            return $explicitVisibility;
        }

        if (!$retainVisibility) {
            return null;
        }

        $mode = $sourceStatus['mode'] ?? null;

        if (!\is_int($mode)) {
            throw UnableToRetrieveMetadata::visibility($source, 'source status could not be retrieved');
        }

        return $this->visibility->inverseForFile($mode & 0777);
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

    /**
     * Called by write()/writeStream() only from their outer catch —
     * after their own finally has already closed the Amp\File\File
     * handle openFile('w') returned, deliberately: unlinking a file
     * with a still-open handle works on POSIX, but this is not a
     * portable guarantee to lean on, since Windows commonly refuses to
     * unlink one. $modeFailed distinguishes a changePermissions()
     * failure from any other failure in the same try (a body-write I/O
     * error, a directory-creation failure) — only a mode failure gets
     * this cleanup; a body-write failure leaves whatever partial
     * content already landed, unchanged from write()/writeStream()'s
     * behavior with no visibility requested at all, since a partial
     * write under the *correct*, already-applied mode is a data-
     * integrity concern, not the confidentiality one this exists for.
     * $existedBefore then decides whether deleting is even safe: never
     * for what was an overwrite (its old content is already gone to
     * openFile('w')'s own truncation regardless of whether the mode
     * change ever ran, so deleting the now-empty file would only
     * remove the last trace a path existed there at all — the same
     * "the destination is the only remaining copy" reasoning move()
     * already applies), but always for what was genuinely new, where
     * deleting just undoes the call and leaves nothing where nothing
     * existed before it started. Best-effort: a failure deleting is
     * silently absorbed, since the original mode failure is what
     * write()/writeStream() actually report.
     */
    private function deleteIfNewAfterModeFailure(string $location, bool $modeFailed, bool $existedBefore): void
    {
        if (!$modeFailed || $existedBefore) {
            return;
        }

        try {
            $this->filesystem->deleteFile($location);
        } catch (FilesystemException) {
            // Best-effort; the original failure is what's reported.
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
