<?php

namespace MTMan\Logger;

class Logger
{
    private string $logDir;
    private string $logLevel;
    private string $execId;

    public function __construct(string $logDir, string $logLevel = 'INFO')
    {
        $this->logDir = rtrim($logDir, '/');
        $this->logLevel = strtoupper($logLevel);
        $this->execId = uniqid('exec_', true);
        
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public function log(string $message, string $level = 'INFO', ?int $pid = null): void
    {
        if ($this->shouldLog($level)) {
            $logEntry = [
                'timestamp' => microtime(true),
                'pid' => $pid ?? getmypid(),
                'level' => $level,
                'message' => $message
            ];

            // Per-execution log
            file_put_contents(
                "{$this->logDir}/{$this->execId}.log",
                json_encode($logEntry) . "\n",
                FILE_APPEND
            );

            // Overall log
            file_put_contents(
                "{$this->logDir}/mtman.log",
                json_encode($logEntry) . "\n",
                FILE_APPEND
            );
        }
    }

    private function shouldLog(string $level): bool
    {
        $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
        return $levels[$level] >= $levels[$this->logLevel];
    }
}