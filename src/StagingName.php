<?php

declare(strict_types=1);

namespace Kinetis\Storage;

/**
 * The name AmpFileAdapter gives the private directory it publishes
 * through: PREFIX followed by RANDOM_BYTES * 2 lowercase hexadecimal
 * digits and nothing else.
 *
 * Generation and matching sit together, so a name this adapter produces
 * is exactly the name listContents() hides and ConfinedPath refuses.
 * The match is the whole name, which leaves every other one a caller's
 * own — `.htaccess`, `.kinetis-stage`, the prefix with a shorter,
 * longer, uppercase or non-hexadecimal tail.
 *
 * @internal to kinetis/storage
 */
final class StagingName
{
    private const string PREFIX = '.kinetis-stage.';

    /**
     * 128 bits, hex-encoded into the name, so two publications into one
     * directory never collide.
     */
    private const int RANDOM_BYTES = 16;

    public static function generate(): string
    {
        return self::PREFIX . \bin2hex(\random_bytes(self::RANDOM_BYTES));
    }

    /**
     * Whether $name is, in full, a name generate() could have produced.
     * Anchored at both ends with `D`, so a trailing newline is not a
     * match either.
     */
    public static function matches(string $name): bool
    {
        $pattern = '/^' . \preg_quote(self::PREFIX, '/') . '[0-9a-f]{' . self::RANDOM_BYTES * 2 . '}$/D';

        return \preg_match($pattern, $name) === 1;
    }
}
