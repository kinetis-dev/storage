<?php

declare(strict_types=1);

namespace Kinetis\Storage\Exception;

use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * A write, writeStream or copy whose rename failed in a way that leaves
 * the destination's state unknown to the adapter.
 *
 * A driver that runs rename(2) in a worker can lose the reply after the
 * kernel has already renamed, so a rename failure alone does not say
 * whether the destination changed. AmpFileAdapter reconciles what it can
 * from the staged file's device and inode and throws this for the cases
 * it cannot decide, rather than reporting a failed write that would
 * claim the old destination survived.
 *
 * The whole contract is $path — the caller's own logical path, never a
 * prefixed filesystem location — and one of the REASON_* constants. It
 * chains nothing, and __serialize() carries those two fields and no
 * more: an exception's inherited file, line and trace state reach a log
 * or a queue along with it, and with zend.exception_ignore_args=0 the
 * trace holds the arguments of every frame below it, which for this
 * class are FILESYSTEM_ROOT, the staging path and the driver's own
 * diagnostics. A trace can also hold a closure, which serialize()
 * refuses outright.
 *
 * Implements League\Flysystem\FilesystemException, which every write and
 * copy on FilesystemOperator already declares. See {doc}`storage`.
 */
final class IndeterminatePublicationException extends RuntimeException implements FilesystemException
{
    /** The staged file's status could not be read back after the rename failed. */
    public const string REASON_UNREADABLE = 'the staged file could not be read back';

    /** Something other than the staged object is at the staging path. */
    public const string REASON_FOREIGN_STAGED_OBJECT = 'the staged path holds an object this call did not stage';

    /** The staged object is gone and the destination is not it. */
    public const string REASON_DESTINATION_NOT_STAGED = 'the staged file is gone and the destination is not the object it staged';

    public readonly string $path;

    public readonly string $reason;

    private function __construct(string $path, string $reason)
    {
        $this->path = $path;
        $this->reason = $reason;

        parent::__construct(self::describe($path, $reason));
    }

    /**
     * @param string $path the caller's own logical path
     * @param string $reason one of this class's REASON_* constants
     */
    public static function atLocation(string $path, string $reason): self
    {
        return new self($path, $reason);
    }

    /**
     * @return array{path: string, reason: string}
     */
    public function __serialize(): array
    {
        return ['path' => $this->path, 'reason' => $this->reason];
    }

    /**
     * Rebuilds the pair __serialize() kept, and the message derived from
     * it. Everything Exception would otherwise restore — file, line,
     * trace, previous — is left at its default, so a restored instance
     * carries exactly what was serialized.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $path = $data['path'] ?? null;
        $reason = $data['reason'] ?? null;

        $this->path = \is_string($path) ? $path : '';
        $this->reason = \is_string($reason) ? $reason : self::REASON_UNREADABLE;
        $this->message = self::describe($this->path, $this->reason);
        $this->code = 0;
    }

    private static function describe(string $path, string $reason): string
    {
        return "Unable to establish whether {$path} was replaced: {$reason}. Inspect it before retrying.";
    }
}
