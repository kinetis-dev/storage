<?php

declare(strict_types=1);

namespace Kinetis\Storage;

use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;

/**
 * A caller-supplied logical path admitted into AmpFileAdapter: proven to
 * name a location at or below the configured root, and split into the
 * components every check and every filesystem location in that class is
 * built from.
 *
 * AmpFileAdapter is a public, documented class a consumer can construct
 * and call with no League\Flysystem\Filesystem in front of it, so the
 * normalization League\Flysystem\WhitespacePathNormalizer performs for
 * FilesystemOperator callers is not the boundary — this is. Every
 * operand of every operation passes through from() before a prefix is
 * applied, both sides of move() and copy() included, each validated on
 * its own.
 *
 * The rules, and what each one refuses:
 *
 * - A `..` segment anywhere throws
 *   League\Flysystem\PathTraversalDetected: `../etc/passwd`, and equally
 *   `a/../../etc/passwd`, whose traversal only leaves the root on its
 *   second `..`. Refused rather than resolved — a resolved path is a
 *   different path from the one the caller wrote, and confinement that
 *   rests on a rewrite is only as sound as the rewrite.
 * - A control byte (NUL through 0x1F, and 0x7F) throws
 *   League\Flysystem\CorruptedPathDetected. A NUL ends the string at the
 *   C boundary every filesystem call crosses, so the location a check
 *   inspects and the one the kernel acts on can be two different paths.
 * - A backslash throws CorruptedPathDetected too. The segment split
 *   below reads it as an ordinary filename byte while a caller, Windows
 *   and WhitespacePathNormalizer all read it as a separator, and one
 *   path with two readings is what a confinement check cannot carry.
 *
 * A `.` segment and a repeated, leading or trailing separator name no
 * location of their own and are dropped, so `a//b/` and `a/./b` both
 * name `a/b`. A path left with no segment at all names the root itself,
 * which is what listContents('') and directoryExists('') ask about and
 * what namesTheRoot() reports.
 *
 * @internal to kinetis/storage
 */
final readonly class ConfinedPath
{
    /**
     * @param list<string> $segments
     */
    private function __construct(public string $path, public array $segments)
    {
    }

    /**
     * @throws PathTraversalDetected when a segment is `..`
     * @throws CorruptedPathDetected when the path carries a control byte
     *   or a backslash
     */
    public static function from(string $path): self
    {
        if (\preg_match('#[\x00-\x1F\x7F\\\\]#', $path) === 1) {
            throw CorruptedPathDetected::forPath($path);
        }

        $segments = [];

        foreach (\explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw PathTraversalDetected::forPath($path);
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return new self(\implode('/', $segments), $segments);
    }

    /**
     * Whether this path names the root itself rather than anything
     * below it — true for every spelling that leaves no segment behind:
     * `''`, `.`, `/`, `//`, `/.`, `./`, `/./`. A listing or an
     * existence check asks a legitimate question of that location; a
     * publication has no file to name there, so AmpFileAdapter refuses
     * one.
     */
    public function namesTheRoot(): bool
    {
        return $this->segments === [];
    }
}
