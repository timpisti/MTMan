<?php

namespace Tests;

use MTMan\MTMan;
use MTMan\Exceptions\MTManException;
use PHPUnit\Framework\TestCase;

class MTManTest extends TestCase
{
    private string $tempDir;
    private ?MTMan $mtman = null;

    protected function setUp(): void
    {
        $this->tempDir = dirname(__DIR__) . '/temp/test_' . bin2hex(random_bytes(4));

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }

        $this->mtman = new MTMan([
            'max_processes' => 2,
            'time_limit' => 30,
            'log_level' => 'DEBUG',
            'temp_dir' => $this->tempDir,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->mtman !== null) {
            $this->mtman->cleanup();
        }
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ──────────────────────────────────────────────
    // Basic functionality
    // ──────────────────────────────────────────────

    public function testBasicTask(): void
    {
        $value = uniqid('test_');

        $this->mtman->addTask(function () use ($value) {
            return $value;
        });

        $output = $this->mtman->run();

        $this->assertCount(1, $output['results']);
        $this->assertEmpty($output['errors']);
        $this->assertEquals($value, reset($output['results']));
    }

    public function testMultipleTasks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mtman->addTask(function () {
                return getmypid();
            });
        }

        $output = $this->mtman->run();
        $uniquePids = array_unique($output['results']);

        $this->assertCount(3, $output['results']);
        // With max_processes=2 we should see at least 2 distinct PIDs
        $this->assertGreaterThanOrEqual(2, count($uniquePids));
    }

    public function testTaskWithParameters(): void
    {
        $this->mtman->addTask(function ($a, $b) {
            return $a + $b;
        }, [5, 3]);

        $output = $this->mtman->run();

        $this->assertCount(1, $output['results']);
        $this->assertEquals(8, reset($output['results']));
    }

    // ──────────────────────────────────────────────
    // Retry and failure
    // ──────────────────────────────────────────────

    public function testTaskFailureAndRetry(): void
    {
        $testFile = $this->tempDir . '/attempts.txt';
        file_put_contents($testFile, '0', LOCK_EX);

        $this->mtman->addTask(function () use ($testFile) {
            // Use LOCK_EX for atomic read-modify-write across processes
            $fp = fopen($testFile, 'c+');
            flock($fp, LOCK_EX);
            $attempts = (int) stream_get_contents($fp);
            $attempts++;
            fseek($fp, 0);
            ftruncate($fp, 0);
            fwrite($fp, (string) $attempts);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($attempts === 1) {
                throw new \Exception('First attempt fails');
            }
            return "Success on attempt {$attempts}";
        });

        $output = $this->mtman->run();

        $this->assertCount(1, $output['results'], 'Should have one successful result after retry');
        $this->assertEquals('Success on attempt 2', reset($output['results']));
        $this->assertEquals(2, (int) file_get_contents($testFile));
    }

    public function testPermanentFailureReportedInErrors(): void
    {
        $mtman = new MTMan([
            'max_processes' => 1,
            'max_retries' => 1,
            'time_limit' => 10,
            'temp_dir' => $this->tempDir,
        ]);

        $mtman->addTask(function () {
            throw new \RuntimeException('Always fails');
        });

        $output = $mtman->run();

        $this->assertEmpty($output['results'], 'No results for permanently failed tasks');
        $this->assertCount(1, $output['errors'], 'Errors should contain permanently failed task');
        $this->assertArrayHasKey(0, $output['errors']);
    }

    // ──────────────────────────────────────────────
    // Timeout
    // ──────────────────────────────────────────────

    public function testTimeout(): void
    {
        $mtman = new MTMan([
            'max_processes' => 1,
            'time_limit' => 1,
            'temp_dir' => $this->tempDir,
        ]);

        $this->expectException(MTManException::class);
        $this->expectExceptionCode(MTManException::ERROR_TIMEOUT);

        $mtman->addTask(function () {
            sleep(5);
            return true;
        });

        $mtman->run();
    }

    // ──────────────────────────────────────────────
    // Parallel execution
    // ──────────────────────────────────────────────

    public function testParallelExecution(): void
    {
        $startTime = microtime(true);

        for ($i = 0; $i < 2; $i++) {
            $this->mtman->addTask(function () {
                sleep(1);
                return microtime(true);
            });
        }

        $output = $this->mtman->run();
        $executionTime = microtime(true) - $startTime;

        // Parallel: ~1s not ~2s
        $this->assertLessThan(1.8, $executionTime);
        $this->assertCount(2, $output['results']);
    }

    // ──────────────────────────────────────────────
    // Concurrency limit enforcement
    // ──────────────────────────────────────────────

    public function testConcurrencyLimitRespected(): void
    {
        $maxProcs = 2;
        $mtman = new MTMan([
            'max_processes' => $maxProcs,
            'time_limit' => 30,
            'temp_dir' => $this->tempDir,
        ]);

        // Launch 4 tasks that each record how many siblings are alive
        $concurrencyFile = $this->tempDir . '/concurrency.txt';
        file_put_contents($concurrencyFile, '', LOCK_EX);

        for ($i = 0; $i < 4; $i++) {
            $mtman->addTask(function () use ($concurrencyFile) {
                // Atomically increment active counter
                $fp = fopen($concurrencyFile, 'c+');
                flock($fp, LOCK_EX);
                $lines = file($concurrencyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $active = 0;
                foreach ($lines as $line) {
                    [$pid, $event] = explode(':', $line);
                    if ($event === 'start') {
                        $active++;
                    } else {
                        $active--;
                    }
                }
                $myPid = getmypid();
                fwrite($fp, "{$myPid}:start\n");
                flock($fp, LOCK_UN);
                fclose($fp);

                $peakSeen = $active + 1; // +1 for self

                usleep(100_000); // 100ms of work

                // Decrement
                file_put_contents($concurrencyFile, "{$myPid}:end\n", FILE_APPEND | LOCK_EX);

                return $peakSeen;
            });
        }

        $output = $mtman->run();

        foreach ($output['results'] as $peakSeen) {
            $this->assertLessThanOrEqual(
                $maxProcs,
                $peakSeen,
                'Observed concurrency should never exceed max_processes'
            );
        }
    }

    // ──────────────────────────────────────────────
    // Large results
    // ──────────────────────────────────────────────

    public function testLargeResult(): void
    {
        $largeArray = array_fill(0, 1000, str_repeat('a', 1000));

        $this->mtman->addTask(function () use ($largeArray) {
            return $largeArray;
        });

        $output = $this->mtman->run();

        $this->assertCount(1, $output['results']);
        $this->assertEquals($largeArray, reset($output['results']));
    }

    // ──────────────────────────────────────────────
    // Process status tracking
    // ──────────────────────────────────────────────

    public function testProcessStatus(): void
    {
        $this->mtman->addTask(function () {
            return true;
        });

        $this->mtman->run();
        $status = $this->mtman->getProcessStatus();

        $this->assertNotEmpty($status);
        $this->assertContains('COMPLETED', $status);
    }

    // ──────────────────────────────────────────────
    // Lifecycle callbacks
    // ──────────────────────────────────────────────

    public function testLifecycleCallbacks(): void
    {
        $started = [];
        $completed = [];
        $failed = [];

        // threads_count=1 to guarantee deterministic ordering
        $mtman = new MTMan([
            'max_processes' => 1,
            'max_retries' => 0,
            'temp_dir' => $this->tempDir,
            'on_task_start' => function ($taskId) use (&$started) {
                $started[] = $taskId;
            },
            'on_task_complete' => function ($taskId, $result) use (&$completed) {
                $completed[] = [$taskId, $result];
            },
            'on_task_error' => function ($taskId, $error) use (&$failed) {
                $failed[] = [$taskId, $error];
            },
        ]);

        $mtman->addTask(function () {
            return 'success_val';
        });

        $mtman->addTask(function () {
            throw new \Exception('failure_val');
        });

        $mtman->run();

        $this->assertCount(2, $started);
        $this->assertCount(1, $completed);
        $this->assertCount(1, $failed);

        $this->assertEquals(0, $completed[0][0]);
        $this->assertEquals('success_val', $completed[0][1]);

        $this->assertEquals(1, $failed[0][0]);
    }

    // ──────────────────────────────────────────────
    // Config validation
    // ──────────────────────────────────────────────

    public function testUnknownConfigKeyThrows(): void
    {
        $this->expectException(MTManException::class);
        $this->expectExceptionCode(MTManException::ERROR_INVALID_CONFIG);

        new MTMan([
            'temp_dir' => $this->tempDir,
            'thread_count' => 4,  // typo — should be max_processes
        ]);
    }

    public function testInvalidMaxProcessesThrows(): void
    {
        $this->expectException(MTManException::class);
        $this->expectExceptionCode(MTManException::ERROR_INVALID_CONFIG);

        new MTMan([
            'temp_dir' => $this->tempDir,
            'max_processes' => 0,
        ]);
    }

    public function testInvalidLogLevelThrows(): void
    {
        $this->expectException(MTManException::class);
        $this->expectExceptionCode(MTManException::ERROR_INVALID_CONFIG);

        new MTMan([
            'temp_dir' => $this->tempDir,
            'log_level' => 'TRACE',
        ]);
    }

    // ──────────────────────────────────────────────
    // Storage owner-PID guard
    // ──────────────────────────────────────────────

    public function testResultStorageOwnerPidGuard(): void
    {
        $storage = new \MTMan\Storage\ResultStorage($this->tempDir);
        $storage->store(123, 'test_data');

        $reflection = new \ReflectionClass($storage);
        $property = $reflection->getProperty('ownerPid');
        $property->setAccessible(true);
        $property->setValue($storage, getmypid() + 9999);

        // Cleanup should be a no-op from a "foreign" PID
        $storage->cleanup();

        $refMethod = $reflection->getMethod('getFilePath');
        $refMethod->setAccessible(true);
        $filePath = $refMethod->invoke($storage, 123);

        $this->assertFileExists($filePath);

        // Restore real PID so destructor can clean up
        $property->setValue($storage, getmypid());
        $storage->cleanup();
        $this->assertFileDoesNotExist($filePath);
    }

    // ──────────────────────────────────────────────
    // Cleanup verification
    // ──────────────────────────────────────────────

    public function testCleanupRemovesTempFiles(): void
    {
        $this->mtman->addTask(function () {
            return 'data';
        });

        $this->mtman->run();

        // Before cleanup: temp dir should have .dat and .log files
        $datFiles = glob($this->tempDir . '/mtman_*.dat');
        $this->assertNotEmpty($datFiles, 'Result files should exist before cleanup');

        $this->mtman->cleanup();

        $datFiles = glob($this->tempDir . '/mtman_*.dat');
        $this->assertEmpty($datFiles, 'Result files should be removed after cleanup');
    }

    // ──────────────────────────────────────────────
    // addTask returns task ID
    // ──────────────────────────────────────────────

    public function testAddTaskReturnsSequentialIds(): void
    {
        $id0 = $this->mtman->addTask(function () {
            return 'a';
        });
        $id1 = $this->mtman->addTask(function () {
            return 'b';
        });

        $this->assertSame(0, $id0);
        $this->assertSame(1, $id1);
    }
}
