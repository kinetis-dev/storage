<?php

declare(strict_types=1);

namespace Kinetis\Storage\Tests;

use Amp\ByteStream\StreamException;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\Filesystem as AmpFilesystem;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\WorkerException;
use Kinetis\Storage\AmpFileAdapter;
use Kinetis\Storage\Tests\Fixtures\SelectivelyFailingFilesystemDriver;
use League\Flysystem\Config;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;

use function Amp\File\filesystem;

/**
 * The two paths amphp/file's default driver leaves untranslated —
 * worker acquisition inside openFile(), and ParallelFile::close() — and
 * what each operation that reaches one of them reports. AmpFileAdapter's
 * own docblock holds the contract; these tests exercise it.
 *
 * The exception instances are the real amphp/parallel types, built the
 * way that library builds them: a test on a substitute type would pass
 * against a catch list naming the substitute and nothing a real driver
 * throws. The failures are injected at the seam rather than by breaking
 * a real worker pool, which no test can do deterministically, but they
 * are injected at exactly the two calls a real pool failure comes out
 * of.
 */
final class AmpFileAdapterDriverFailureTest extends TestCase
{
    private string $root;

    private AmpFileAdapter $adapter;

    /**
     * The pool instrumentedAdapter() builds its driver on, held so
     * tearDown() can shut its subprocesses down rather than leaving
     * them to be force-killed at process exit.
     */
    private ?ContextWorkerPool $pool = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kinetis-storage-driver-failure-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0777, true);
        $this->adapter = new AmpFileAdapter(filesystem(), $this->root);
    }

    protected function tearDown(): void
    {
        $this->pool?->shutdown();

        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove("{$this->root}/{$entry}");
            }
        }

        rmdir($this->root);
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
        $this->pool = new ContextWorkerPool();
        $driver = new SelectivelyFailingFilesystemDriver(new ParallelFilesystemDriver($this->pool));

        return [new AmpFileAdapter(new AmpFilesystem($driver), $this->root), $driver];
    }

    /**
     * A TaskFailureException as amphp/parallel builds one: the class,
     * message, code, file, line and flattened trace of the throwable
     * the task raised on the other side of the worker boundary.
     */
    private static function taskFailure(string $message): TaskFailureException
    {
        return new TaskFailureException(StreamException::class, $message, 0, __FILE__, __LINE__, []);
    }

    private static function taskError(string $message): TaskFailureError
    {
        return new TaskFailureError(\Error::class, $message, 0, __FILE__, __LINE__, []);
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

    // --- Worker acquisition, which happens before the open task exists
    // to wrap a failure in. ---

    public function test_a_worker_failure_opening_the_staged_file_fails_the_write_as_its_own_type(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('report.txt', 'the previous occupant', new Config());
        $driver->openFileThrowsForModes = ['x' => new WorkerException('the pool could not supply a worker')];

        try {
            $adapter->write('report.txt', 'the replacement', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(WorkerException::class, $e->getPrevious(), 'The worker failure is chained, not discarded.');
        }

        self::assertSame('the previous occupant', $this->adapter->read('report.txt'), 'Nothing was published.');
        self::assertSame(['report.txt'], $this->rootEntries(), 'The staging directory is cleaned up.');
    }

    public function test_a_context_failure_opening_the_source_fails_the_copy_as_its_own_type(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config());
        $driver->openFileThrowsForModes = ['r' => new ContextException('the worker context could not be started')];

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            self::assertInstanceOf(ContextException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    public function test_a_worker_failure_opening_the_sample_fails_the_mime_type_lookup_as_its_own_type(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('sample.txt', 'plain text', new Config());
        $driver->openFileThrowsForModes = ['r' => new WorkerException('the pool could not supply a worker')];

        try {
            $adapter->mimeType('sample.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertInstanceOf(WorkerException::class, $e->getPrevious());
        }
    }

    // --- ParallelFile::close(), which submits its fclose task with no
    // wrapping at all. ---

    public function test_a_task_failure_closing_the_staged_file_fails_the_write_as_its_own_type(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('report.txt', 'the previous occupant', new Config());
        $driver->closeThrowsForModes = ['x' => self::taskFailure('fclose failed in the worker')];

        try {
            $adapter->write('report.txt', 'the replacement', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $e) {
            self::assertInstanceOf(TaskFailureException::class, $e->getPrevious());
        }

        self::assertSame('the previous occupant', $this->adapter->read('report.txt'), 'Nothing was published.');
        self::assertSame(['report.txt'], $this->rootEntries(), 'The staging directory is cleaned up.');
    }

    public function test_a_task_failure_closing_the_sample_fails_the_mime_type_lookup_as_its_own_type(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('sample.txt', 'plain text', new Config());
        $driver->closeThrowsForModes = ['r' => self::taskFailure('fclose failed in the worker')];

        try {
            $adapter->mimeType('sample.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertInstanceOf(TaskFailureException::class, $e->getPrevious(), 'With nothing already failing, the close failure is the failure.');
        }
    }

    // --- Cleanup never reports in place of the failure that prompted
    // it, whatever type the cleanup fails with. ---

    public function test_a_worker_failure_closing_the_sample_never_replaces_the_read_failure_already_in_flight(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('sample.txt', 'plain text', new Config());
        $driver->failRead = true;
        $driver->closeThrowsForModes = ['r' => new WorkerException('the worker died while closing')];

        try {
            $adapter->mimeType('sample.txt');
            self::fail('Expected UnableToRetrieveMetadata.');
        } catch (UnableToRetrieveMetadata $e) {
            $cause = $e->getPrevious();

            self::assertInstanceOf(StreamException::class, $cause);
            self::assertSame(
                'simulated stream read failure',
                $cause->getMessage(),
                'The close failure must not take the place of the read failure being reported.',
            );
        }
    }

    public function test_a_worker_failure_closing_either_copy_handle_never_replaces_the_failure_already_in_flight(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('source.txt', 'the new content', new Config());
        $this->adapter->write('destination.txt', 'the previous occupant', new Config());
        $driver->writeThrows = new StreamException('the primary failure');
        $driver->closeThrowsForModes = [
            'x' => new WorkerException('the worker died closing the staged file'),
            'r' => self::taskFailure('fclose failed for the source'),
        ];

        try {
            $adapter->copy('source.txt', 'destination.txt', new Config());
            self::fail('Expected UnableToCopyFile.');
        } catch (UnableToCopyFile $e) {
            $cause = $e->getPrevious();

            self::assertInstanceOf(StreamException::class, $cause);
            self::assertSame('the primary failure', $cause->getMessage());
        }

        self::assertSame(
            ['r', 'x'],
            array_map(static fn (string $attempt): string => explode(':', $attempt, 2)[0], $driver->closeAttempts),
            'Both owned handles are still closed — the source by the copy, then the staged one by the primitive — even though the first close threw.',
        );
        self::assertSame('the previous occupant', $this->adapter->read('destination.txt'));
        self::assertSame(['destination.txt', 'source.txt'], $this->rootEntries());
    }

    // --- Programmer errors keep their own identity. ---

    public function test_a_task_failure_error_from_an_open_stays_a_programmer_error(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('report.txt', 'the previous occupant', new Config());
        $driver->openFileThrowsForModes = ['x' => self::taskError('the task raised an Error')];

        try {
            $adapter->write('report.txt', 'the replacement', new Config());
            self::fail('Expected TaskFailureError.');
        } catch (TaskFailureError $e) {
            self::assertSame('Error', $e->getOriginalClassName(), 'It escapes as itself, not relabeled as a write failure it is not.');
        }

        self::assertSame('the previous occupant', $this->adapter->read('report.txt'));
        self::assertSame(['report.txt'], $this->rootEntries(), 'The staging directory is cleaned up for an error type nothing here catches.');
    }

    public function test_a_task_failure_error_from_a_close_stays_a_programmer_error(): void
    {
        [$adapter, $driver] = $this->instrumentedAdapter();
        $this->adapter->write('sample.txt', 'plain text', new Config());
        $driver->closeThrowsForModes = ['r' => self::taskError('the task raised an Error')];

        $this->expectException(TaskFailureError::class);
        $adapter->mimeType('sample.txt');
    }
}
