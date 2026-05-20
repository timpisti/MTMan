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
        $this->tempDir = dirname(__DIR__, 2) . '/temp/integration_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0700, true);

        $this->mtman = new MTMan([
            'max_processes' => 2,
            'temp_dir' => $this->tempDir,
        ]);
    }

    protected function tearDown(): void
    {
        $this->mtman->cleanup();
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        array_map(function ($item) use ($dir) {
            if ($item === '.' || $item === '..') {
                return;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }, scandir($dir));
        rmdir($dir);
    }

    public function testCompleteWorkflow(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mtman->addTask(function () use ($i) {
                usleep(200_000); // 200ms simulated work
                return "Result {$i} from PID " . getmypid();
            });
        }

        $startTime = microtime(true);
        $output = $this->mtman->run();
        $executionTime = microtime(true) - $startTime;

        $this->assertCount(3, $output['results']);
        $this->assertEmpty($output['errors']);
        $this->assertLessThan(2.0, $executionTime, 'Should run in parallel');

        $status = $this->mtman->getProcessStatus();
        $this->assertContains('COMPLETED', $status);
    }

    public function testMixedSuccessAndFailure(): void
    {
        $mtman = new MTMan([
            'max_processes' => 2,
            'max_retries' => 0,
            'temp_dir' => $this->tempDir,
        ]);

        $mtman->addTask(function () {
            return 'ok';
        });

        $mtman->addTask(function () {
            throw new \RuntimeException('boom');
        });

        $mtman->addTask(function () {
            return 'also ok';
        });

        $output = $mtman->run();

        $this->assertCount(2, $output['results']);
        $this->assertCount(1, $output['errors']);
        $this->assertArrayHasKey(1, $output['errors']);
    }
}
