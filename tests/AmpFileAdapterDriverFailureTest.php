<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Tests\Fixtures\SelectivelyFailingFilesystemDriver;
use League\Flysystem\Config;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The exception boundary itself, independent of which driver amphp/file
 * selects: an ordinary Exception from a driver call becomes the
 * UnableTo* type FilesystemOperator declares for the operation, with the
 * original chained, and an Error passes through as itself.
 */
final class AmpFileAdapterDriverFailureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-driver-failure-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove("{$path}/{$entry}");
            }
        }

        rmdir($path);
    }

    /**
     * @return array{0: AmpFileAdapter, 1: SelectivelyFailingFilesystemDriver}
     */
    private function instrumentedAdapter(): array
    {
        $driver = new SelectivelyFailingFilesystemDriver(new BlockingFilesystemDriver());

        return [new AmpFileAdapter(new AmpFilesystem($driver), $this->root), $driver];
    }

    /**
     * The names directly inside $this->root, sorted.
     *
     * @return list<string>
     */
    private function rootEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    public function test_an_ordinary_exception_from_the_driver_becomes_the_operations_declared_failure(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('report.txt', 'the previous occupant', new Config());
        $driver->moveThrows = new RuntimeException('the driver raised something the boundary does not name');

        try {
            $adapter->write('report.txt', 'the replacement', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious(), 'The driver failure is chained, not discarded.');
        }

        self::assertSame('the previous occupant', $adapter->read('report.txt'), 'Nothing was published.');
        self::assertSame(['report.txt'], $this->rootEntries(), 'The staging directory is cleaned up.');
    }

    public function test_an_error_from_the_driver_reaches_the_caller_as_itself(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $adapter->write('report.txt', 'the previous occupant', new Config());
        $driver->moveThrows = new \Error('a programmer error inside the driver');

        try {
            $adapter->write('report.txt', 'the replacement', new Config());
            self::fail('Expected Error.');
        } catch (\Error $e) {
            self::assertSame('a programmer error inside the driver', $e->getMessage(), 'An Error is never relabelled as a write failure it is not.');
        }

        self::assertSame('the previous occupant', $adapter->read('report.txt'));
        self::assertSame(['report.txt'], $this->rootEntries(), 'The staged file is still cleaned up for a type nothing here catches.');
    }
}
