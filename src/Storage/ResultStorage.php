<?php

namespace MTMan\Storage;

use MTMan\Exceptions\MTManException;

class ResultStorage
{
    private string $tempDir;
    private string $prefix;
    private static string $sharedPrefix;

    public function __construct(string $tempDir)
    {
        $this->tempDir = $tempDir;
        if (!isset(self::$sharedPrefix)) {
            self::$sharedPrefix = uniqid('mtman_', true);
        }
        $this->prefix = self::$sharedPrefix;
        
        if (!is_dir($this->tempDir)) {
            if (!mkdir($this->tempDir, 0777, true)) {
                throw new MTManException("Cannot create storage directory");
            }
        }
    }

    public function store(int $taskId, mixed $result): void
    {
        $file = $this->getFilePath($taskId);
        $data = serialize($result);
        
        if (file_put_contents($file, $data, LOCK_EX) === false) {
            throw new MTManException("Failed to store result for task {$taskId}");
        }
        chmod($file, 0666);
    }

    public function get(int $taskId): mixed
    {
        $file = $this->getFilePath($taskId);
        if (!file_exists($file)) {
            return null;
        }
        return unserialize(file_get_contents($file));
    }

    public function cleanup(): void
    {
        foreach (glob("{$this->tempDir}/{$this->prefix}_*.dat") as $file) {
            @unlink($file);
        }
    }

    private function getFilePath(int $taskId): string
    {
        return "{$this->tempDir}/{$this->prefix}_{$taskId}.dat";
    }
}