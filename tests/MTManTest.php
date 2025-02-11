<?php

namespace Tests;

use MTMan\MTMan;
use MTMan\Exceptions\MTManException;
use PHPUnit\Framework\TestCase;

class MTManTest extends TestCase
{
    private string $tempDir;
    private MTMan $mtman;

    protected function setUp(): void
    {
        $this->tempDir = dirname(__DIR__) . '/temp/test_' . uniqid();
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        $this->mtman = new MTMan([
            'threads_count' => 2,
            'time_limit' => 30,
            'log_level' => 'DEBUG',
            'temp_dir' => $this->tempDir
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->mtman)) {
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

    public function testBasicTask(): void
    {
        $value = uniqid('test_');
        
        $this->mtman->addTask(function() use ($value) {
            return $value;
        });

        $results = $this->mtman->run();

        $this->assertCount(1, $results);
        $this->assertEquals($value, reset($results));
    }

    public function testMultipleTasks(): void
    {
        // Add three tasks
        for ($i = 0; $i < 3; $i++) {
            $this->mtman->addTask(function() {
                return getmypid();
            });
        }

        $results = $this->mtman->run();
        $uniquePids = array_unique($results);

        $this->assertCount(3, $results);
        // Since threads_count = 2, we should have at least 2 different PIDs
        $this->assertGreaterThanOrEqual(2, count($uniquePids));
    }

    public function testTaskWithParameters(): void
    {
        $this->mtman->addTask(function($a, $b) {
            return $a + $b;
        }, [5, 3]);

        $results = $this->mtman->run();

        $this->assertCount(1, $results);
        $this->assertEquals(8, reset($results));
    }

    public function testTaskFailureAndRetry(): void
    {
        $attempts = 0;
        $testFile = $this->tempDir . '/attempts.txt';
        file_put_contents($testFile, '0');
        
        $this->mtman->addTask(function() use ($testFile) {
            $attempts = (int)file_get_contents($testFile);
            $attempts++;
            file_put_contents($testFile, $attempts);
            
            if ($attempts === 1) {
                throw new \Exception("First attempt fails");
            }
            return "Success on attempt $attempts";
        });

        $results = $this->mtman->run();
        
        $this->assertCount(1, $results, "Should have one successful result after retry");
        $this->assertEquals(
            "Success on attempt 2", 
            reset($results), 
            "Should succeed on second attempt"
        );
        $this->assertEquals(
            2, 
            (int)file_get_contents($testFile), 
            "Should have attempted exactly twice"
        );
    }

    public function testTimeout(): void
    {
        $mtman = new MTMan([
            'threads_count' => 1,
            'time_limit' => 1,
            'temp_dir' => $this->tempDir
        ]);

        $this->expectException(MTManException::class);

        $mtman->addTask(function() {
            sleep(2);
            return true;
        });

        $mtman->run();
    }

    public function testParallelExecution(): void
    {
        $startTime = microtime(true);

        // Add two tasks that each sleep for 1 second
        for ($i = 0; $i < 2; $i++) {
            $this->mtman->addTask(function() {
                sleep(1);
                return microtime(true);
            });
        }

        $results = $this->mtman->run();
        $executionTime = microtime(true) - $startTime;

        // With parallel execution, this should take ~1 second, not ~2 seconds
        $this->assertLessThan(1.5, $executionTime);
        $this->assertCount(2, $results);
    }

    public function testLargeResult(): void
    {
        $largeArray = array_fill(0, 1000, str_repeat('a', 1000));
        
        $this->mtman->addTask(function() use ($largeArray) {
            return $largeArray;
        });

        $results = $this->mtman->run();
        
        $this->assertCount(1, $results);
        $this->assertEquals($largeArray, reset($results));
    }

    public function testThreadStatus(): void
    {
        $this->mtman->addTask(function() {
            sleep(1);
            return true;
        });

        $results = $this->mtman->run();
        $status = $this->mtman->getThreadStatus();
        
        $this->assertNotEmpty($status);
        $this->assertContains('COMPLETED', $status);
    }
}