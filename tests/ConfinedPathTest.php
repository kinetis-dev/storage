<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Kinetis\Storage\ConfinedPath;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\PathTraversalDetected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfinedPathTest extends TestCase
{
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
