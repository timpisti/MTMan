<?php

namespace MTMan\Storage;

use MTMan\Exceptions\MTManException;

class ResultStorage
{
    private string $tempDir;
    private string $prefix;
    private int $ownerPid;

    public function __construct(string $tempDir)
    {
        $this->tempDir = $tempDir;
        $this->ownerPid = getmypid();
        $this->prefix = 'mtman_' . $this->ownerPid . '_' . bin2hex(random_bytes(8));

        if (!is_dir($this->tempDir)) {
            if (!mkdir($this->tempDir, 0700, true)) {
                throw MTManException::storage("Cannot create directory: {$this->tempDir}");
            }
        }
    }

    public function store(int $taskId, mixed $result): void
    {
        $file = $this->getFilePath($taskId);
        $data = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($file, $data, LOCK_EX) === false) {
            throw MTManException::storage("Write failed for task {$taskId}");
        }
        chmod($file, 0600);
    }

    public function get(int $taskId): mixed
    {
        $file = $this->getFilePath($taskId);

        if (!file_exists($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        try {
            return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function cleanup(): void
    {
        if (getmypid() !== $this->ownerPid) {
            return;
        }

        foreach (glob("{$this->tempDir}/{$this->prefix}_*.dat") as $file) {
            @unlink($file);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function getFilePath(int $taskId): string
    {
        return "{$this->tempDir}/{$this->prefix}_{$taskId}.dat";
    }
}
