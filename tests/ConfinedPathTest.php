<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Kinetis\Storage\ConfinedPath;
use Kinetis\Storage\Exception\ReservedPathDetected;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfinedPathTest extends TestCase
{
    /** 32 lowercase hexadecimal digits: the tail a staging directory name carries. */
    private const string HEX = '0123456789abcdef0123456789abcdef';

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function acceptedPaths(): iterable
    {
        yield 'a plain file' => ['avatar.png', 'avatar.png', ['avatar.png']];
        yield 'a nested file' => ['a/b/c.txt', 'a/b/c.txt', ['a', 'b', 'c.txt']];
        yield 'the root itself' => ['', '', []];
        yield 'a leading separator' => ['/a/b', 'a/b', ['a', 'b']];
        yield 'a trailing separator' => ['a/b/', 'a/b', ['a', 'b']];
        yield 'repeated separators' => ['a//b', 'a/b', ['a', 'b']];
        yield 'a separator alone' => ['/', '', []];
        yield 'a current-directory segment' => ['a/./b', 'a/b', ['a', 'b']];
        yield 'a leading dot in a name' => ['.hidden/file', '.hidden/file', ['.hidden', 'file']];
        yield 'a name that starts with two dots' => ['..hidden', '..hidden', ['..hidden']];
        yield 'a space, which is an ordinary byte' => ['my file.txt', 'my file.txt', ['my file.txt']];

        // A raw multibyte name reaches the filesystem as the bytes the
        // caller wrote; only control bytes are refused, so a non-ASCII
        // filename stays usable.
        yield 'a multibyte name' => ['ünïcode/名前.txt', 'ünïcode/名前.txt', ['ünïcode', '名前.txt']];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('acceptedPaths')]
    public function test_an_accepted_path_keeps_its_segments(string $input, string $expected, array $segments): void
    {
        $confined = ConfinedPath::from($input);

        self::assertSame($expected, $confined->path);
        self::assertSame($segments, $confined->segments);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversingPaths(): iterable
    {
        yield 'a bare parent segment' => ['..'];
        yield 'a leading traversal' => ['../etc/passwd'];
        yield 'a traversal in the middle' => ['a/../../etc/passwd'];
        yield 'a traversal that lands back inside' => ['a/../b'];
        yield 'a traversal at the end' => ['a/b/..'];
        yield 'a traversal behind a leading separator' => ['/../etc/passwd'];
        yield 'a traversal behind a current-directory segment' => ['./../etc/passwd'];
        yield 'a repeated traversal' => ['../../../../../../etc/passwd'];
    }

    #[DataProvider('traversingPaths')]
    public function test_a_traversing_path_is_refused(string $path): void
    {
        $this->expectException(PathTraversalDetected::class);
        ConfinedPath::from($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptPaths(): iterable
    {
        yield 'a NUL byte' => ["a\0/../etc/passwd"];
        yield 'a NUL byte at the end' => ["avatar.png\0"];
        yield 'a newline' => ["a\nb"];
        yield 'a tab' => ["a\tb"];
        yield 'an escape byte' => ["a\x1Bb"];
        yield 'a delete byte' => ["a\x7Fb"];
        yield 'a backslash separator' => ['a\\b'];
        yield 'a backslash traversal' => ['..\\..\\etc\\passwd'];
    }

    #[DataProvider('corruptPaths')]
    public function test_a_corrupt_path_is_refused(string $path): void
    {
        $this->expectException(CorruptedPathDetected::class);
        ConfinedPath::from($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedPaths(): iterable
    {
        yield 'a staging directory itself' => ['.kinetis-stage.' . self::HEX];
        yield 'the staged file inside one' => ['.kinetis-stage.' . self::HEX . '/staged'];
        yield 'one nested below an ordinary directory' => ['uploads/.kinetis-stage.' . self::HEX];
        yield 'one in the middle of a deeper path' => ['a/b/.kinetis-stage.' . self::HEX . '/c/d.txt'];
        yield 'a tail of a different value' => ['.kinetis-stage.ffffffffffffffffffffffffffffffff'];
    }

    /**
     * The name AmpFileAdapter publishes through is reserved, and the
     * refusal lives here rather than in each operation, since every
     * operand of every operation is admitted through from().
     */
    #[DataProvider('reservedPaths')]
    public function test_a_reserved_staging_path_is_refused(string $path): void
    {
        $this->expectException(ReservedPathDetected::class);
        ConfinedPath::from($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nearMissNames(): iterable
    {
        yield 'the prefix alone' => ['.kinetis-stage'];
        yield 'the prefix with no tail' => ['.kinetis-stage.'];
        yield 'a tail one digit short' => ['.kinetis-stage.' . substr(self::HEX, 1)];
        yield 'a tail one digit long' => ['.kinetis-stage.' . self::HEX . 'a'];
        yield 'an uppercase tail' => ['.kinetis-stage.' . strtoupper(self::HEX)];
        yield 'a non-hexadecimal tail' => ['.kinetis-stage.' . substr(self::HEX, 1) . 'z'];
        yield 'an extension after the name' => ['.kinetis-stage.' . self::HEX . '.txt'];
        yield 'the name without its leading dot' => ['kinetis-stage.' . self::HEX];
        yield 'the name as a substring of a longer one' => ['pre.kinetis-stage.' . self::HEX];
    }

    /**
     * The grammar boundary: the reserved name is the whole name, so
     * everything one character away from it is an ordinary path a caller
     * owns, admitted unchanged as a single segment.
     */
    #[DataProvider('nearMissNames')]
    public function test_a_name_the_staging_grammar_does_not_match_whole_is_admitted(string $name): void
    {
        $confined = ConfinedPath::from($name);

        self::assertSame($name, $confined->path);
        self::assertSame([$name], $confined->segments);
    }

    /**
     * A NUL ends the string at the C boundary, so a check that read the
     * path as PHP sees it and a kernel that reads it as C does would
     * disagree about which file the call names. The refusal happens
     * before the traversal scan, which is why this asserts the type
     * rather than only that something threw.
     */
    public function test_a_corrupt_path_is_refused_before_its_traversal_is_even_examined(): void
    {
        $this->expectException(CorruptedPathDetected::class);
        ConfinedPath::from("safe\0/../../etc/passwd");
    }
}
