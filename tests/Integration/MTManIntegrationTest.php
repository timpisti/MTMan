<?php

namespace Tests\Integration;

use MTMan\MTMan;
use PHPUnit\Framework\TestCase;

class MTManIntegrationTest extends TestCase
{
    private string $tempDir;
    private MTMan $mtman;

    protected function setUp(): void
    {
        $this->tempDir = dirname(__DIR__, 2) . '/temp/integration_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->mtman = new MTMan([
            'threads_count' => 2,
            'temp_dir' => $this->tempDir
        ]);
    }

    protected function tearDown(): void
    {
        $this->mtman->cleanup();
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        array_map(function($item) use ($dir) {
            if ($item === '.' || $item === '..') return;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }, scandir($dir));
        rmdir($dir);
    }

    public function testCompleteWorkflow(): void
    {
        // Add multiple tasks
        for ($i = 0; $i < 3; $i++) {
            $this->mtman->addTask(function() use ($i) {
                sleep(1); // Simulate work
                return "Result $i from PID " . getmypid();
            });
        }

        $startTime = microtime(true);
        $results = $this->mtman->run();
        $executionTime = microtime(true) - $startTime;

        // Verify results
        $this->assertCount(3, $results);
        $this->assertLessThan(3, $executionTime, 'Should run in parallel');
        
        // Verify thread status
        $status = $this->mtman->getThreadStatus();
        $this->assertContains('COMPLETED', $status);
    }
}