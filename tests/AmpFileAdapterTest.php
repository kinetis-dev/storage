<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Kinetis\Storage\AmpFileAdapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

use function Amp\File\filesystem;

final class AmpFileAdapterTest extends TestCase
{
    private string $root;

    /**
     * A sibling directory, outside $root, that the symlink tests below
     * point a link at — the thing $root is supposed to be a boundary
     * against reaching.
     */
    private string $outside;

    private AmpFileAdapter $adapter;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-test-' . bin2hex(random_bytes(8));
        $this->outside = sys_get_temp_dir() . '/kinetis-storage-test-outside-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        mkdir($this->outside, 0777, true);
        $this->adapter = new AmpFileAdapter(filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        $this->removeDirectory($this->outside);
    }

    /**
     * Symlink-safe: a symlink entry is unlink()'d directly, never
     * followed via is_dir() (which, unlike this class's own
     * Filesystem::isSymlink(), does follow) — the exact distinction the
     * fix this file tests for is built around.
     */
    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = "{$path}/{$entry}";

            if (is_link($entryPath)) {
                unlink($entryPath);
            } elseif (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($path);
    }

    public function test_write_then_read_round_trips(): void
    {
        $this->adapter->write('greeting.txt', 'hello world', new Config());

        self::assertSame('hello world', $this->adapter->read('greeting.txt'));
    }

    public function test_file_exists_reflects_real_state(): void
    {
        self::assertFalse($this->adapter->fileExists('nothing.txt'));

        $this->adapter->write('nothing.txt', 'now it exists', new Config());

        self::assertTrue($this->adapter->fileExists('nothing.txt'));
    }

    public function test_write_creates_missing_parent_directories(): void
    {
        $this->adapter->write('nested/deep/file.txt', 'contents', new Config());

        self::assertTrue($this->adapter->fileExists('nested/deep/file.txt'));
        self::assertTrue($this->adapter->directoryExists('nested/deep'));
    }

    public function test_delete_removes_the_file(): void
    {
        $this->adapter->write('to-delete.txt', 'x', new Config());
        $this->adapter->delete('to-delete.txt');

        self::assertFalse($this->adapter->fileExists('to-delete.txt'));
    }

    public function test_reading_a_missing_file_throws(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->adapter->read('missing.txt');
    }

    public function test_create_directory_then_delete_directory_recursively(): void
    {
        $this->adapter->createDirectory('a/b/c', new Config());
        $this->adapter->write('a/b/c/file.txt', 'x', new Config());

        self::assertTrue($this->adapter->directoryExists('a/b/c'));

        $this->adapter->deleteDirectory('a');

        self::assertFalse($this->adapter->directoryExists('a'));
        self::assertFalse($this->adapter->fileExists('a/b/c/file.txt'));
    }

    public function test_move_relocates_the_file(): void
    {
        $this->adapter->write('source.txt', 'moved contents', new Config());
        $this->adapter->move('source.txt', 'destination.txt', new Config());

        self::assertFalse($this->adapter->fileExists('source.txt'));
        self::assertSame('moved contents', $this->adapter->read('destination.txt'));
    }

    public function test_copy_duplicates_the_file_leaving_the_source_intact(): void
    {
        $this->adapter->write('original.txt', 'copied contents', new Config());
        $this->adapter->copy('original.txt', 'duplicate.txt', new Config());

        self::assertSame('copied contents', $this->adapter->read('original.txt'));
        self::assertSame('copied contents', $this->adapter->read('duplicate.txt'));
    }

    public function test_write_stream_then_read_stream_round_trips(): void
    {
        $source = fopen('php://temp', 'r+b');
        fwrite($source, 'streamed contents');
        rewind($source);

        $this->adapter->writeStream('streamed.txt', $source, new Config());

        $result = $this->adapter->readStream('streamed.txt');

        self::assertSame('streamed contents', stream_get_contents($result));
    }

    public function test_file_size_and_last_modified_reflect_real_metadata(): void
    {
        $this->adapter->write('sized.txt', '12345', new Config());

        self::assertSame(5, $this->adapter->fileSize('sized.txt')->fileSize());
        self::assertIsInt($this->adapter->lastModified('sized.txt')->lastModified());
    }

    public function test_mime_type_is_detected_from_content(): void
    {
        $this->adapter->write('document.json', '{"key": "value"}', new Config());

        self::assertSame('application/json', $this->adapter->mimeType('document.json')->mimeType());
    }

    public function test_set_visibility_then_visibility_round_trips(): void
    {
        $this->adapter->write('secret.txt', 'x', new Config());

        $this->adapter->setVisibility('secret.txt', Visibility::PRIVATE);
        self::assertSame(Visibility::PRIVATE, $this->adapter->visibility('secret.txt')->visibility());

        $this->adapter->setVisibility('secret.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $this->adapter->visibility('secret.txt')->visibility());
    }

    public function test_list_contents_shallow_does_not_descend_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', false)));

        self::assertContains('top.txt', $paths);
        self::assertContains('sub', $paths);
        self::assertNotContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_deep_descends_into_subdirectories(): void
    {
        $this->adapter->write('top.txt', 'x', new Config());
        $this->adapter->write('sub/nested.txt', 'x', new Config());

        $paths = array_map(static fn ($attrs) => $attrs->path(), iterator_to_array($this->adapter->listContents('', true)));

        self::assertContains('sub/nested.txt', $paths);
    }

    public function test_list_contents_distinguishes_files_from_directories(): void
    {
        $this->adapter->write('file.txt', 'x', new Config());
        $this->adapter->createDirectory('directory', new Config());

        $entries = iterator_to_array($this->adapter->listContents('', false));
        $byPath = [];

        foreach ($entries as $entry) {
            $byPath[$entry->path()] = $entry;
        }

        self::assertTrue($byPath['file.txt']->isFile());
        self::assertInstanceOf(DirectoryAttributes::class, $byPath['directory']);
    }

    // --- Symlink policy: no path is ever allowed to resolve through a
    // symlink, whether the symlink is the requested path's own leaf, an
    // intermediate directory component, or an entry discovered while
    // listing/recursively deleting. ---

    public function test_reading_through_a_symlinked_directory_is_rejected(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        $this->expectException(SymbolicLinkEncountered::class);
        $this->adapter->read('link/secret.txt');
    }

    public function test_writing_through_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");

        try {
            $this->adapter->write('link/new.txt', 'should not land outside', new Config());
            self::fail('write() through a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/new.txt", 'the write must never have reached outside root');
    }

    public function test_reading_a_symlinked_file_directly_is_rejected(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink("{$this->outside}/secret.txt", "{$this->root}/shortcut.txt");

        $this->expectException(SymbolicLinkEncountered::class);
        $this->adapter->read('shortcut.txt');
    }

    public function test_deleting_a_symlinked_directory_does_not_touch_its_target(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        try {
            $this->adapter->deleteDirectory('link');
            self::fail('deleteDirectory() on a symlink should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    public function test_deleting_a_directory_containing_a_nested_symlink_does_not_touch_the_links_target(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        mkdir("{$this->root}/safe");
        symlink($this->outside, "{$this->root}/safe/evil-link");

        try {
            $this->adapter->deleteDirectory('safe');
            self::fail('deleteDirectory() should have thrown on the nested symlink.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    /**
     * A symlink discovered partway through a directory's real entries
     * must not leave the entries visited earlier already deleted —
     * deleteDirectory() plans the whole subtree before deleting anything,
     * specifically so this doesn't depend on which order the filesystem
     * happens to list entries in.
     */
    public function test_deleting_a_directory_with_a_symlink_leaves_every_other_entry_intact(): void
    {
        // Amp\File's blocking driver lists entries via scandir(), which
        // sorts alphabetically by default — the safe entries are named to
        // sort *before* the symlink specifically so this test exercises
        // the real hazard (entries a combined walk-and-delete pass would
        // have already deleted before reaching the symlink), rather than
        // happening to pass merely because the symlink was listed first.
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        mkdir("{$this->root}/safe");
        file_put_contents("{$this->root}/safe/a-one.txt", 'one');
        file_put_contents("{$this->root}/safe/a-two.txt", 'two');
        mkdir("{$this->root}/safe/a-nested");
        file_put_contents("{$this->root}/safe/a-nested/a-three.txt", 'three');
        symlink($this->outside, "{$this->root}/safe/z-evil-link");

        try {
            $this->adapter->deleteDirectory('safe');
            self::fail('deleteDirectory() should have thrown on the nested symlink.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileExists("{$this->root}/safe/a-one.txt", 'a sibling entry must survive a symlink found elsewhere in the same directory');
        self::assertFileExists("{$this->root}/safe/a-two.txt", 'a sibling entry must survive a symlink found elsewhere in the same directory');
        self::assertFileExists("{$this->root}/safe/a-nested/a-three.txt", 'a nested file below a safe subdirectory must survive');
        self::assertDirectoryExists("{$this->root}/safe/a-nested");
        self::assertDirectoryExists("{$this->root}/safe");
        self::assertFileExists("{$this->outside}/secret.txt", 'the outside file must survive');
    }

    public function test_moving_into_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");
        $this->adapter->write('source.txt', 'contents', new Config());

        try {
            $this->adapter->move('source.txt', 'link/destination.txt', new Config());
            self::fail('move() into a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/destination.txt");
        self::assertTrue($this->adapter->fileExists('source.txt'), 'the source must be untouched on rejection');
    }

    public function test_copying_into_a_symlinked_directory_is_rejected(): void
    {
        symlink($this->outside, "{$this->root}/link");
        $this->adapter->write('source.txt', 'contents', new Config());

        try {
            $this->adapter->copy('source.txt', 'link/destination.txt', new Config());
            self::fail('copy() into a symlinked directory should have thrown.');
        } catch (SymbolicLinkEncountered) {
            // Expected.
        }

        self::assertFileDoesNotExist("{$this->outside}/destination.txt");
    }

    public function test_deep_listing_throws_on_a_symlinked_directory_instead_of_descending_into_it(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        $this->expectException(SymbolicLinkEncountered::class);
        iterator_to_array($this->adapter->listContents('', true));
    }

    public function test_deep_listing_does_not_loop_forever_on_a_symlink_cycle(): void
    {
        symlink($this->root, "{$this->root}/loop");

        $this->expectException(SymbolicLinkEncountered::class);
        iterator_to_array($this->adapter->listContents('', true));
    }

    public function test_file_exists_reports_false_through_a_symlink_rather_than_throwing(): void
    {
        file_put_contents("{$this->outside}/secret.txt", 'top secret');
        symlink($this->outside, "{$this->root}/link");

        self::assertFalse($this->adapter->fileExists('link/secret.txt'));
    }

    public function test_directory_exists_reports_false_for_a_symlink_itself(): void
    {
        symlink($this->outside, "{$this->root}/link");

        self::assertFalse($this->adapter->directoryExists('link'));
    }
}
