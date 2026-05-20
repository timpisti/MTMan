<?php

namespace MTMan\Logger;

class Logger
{
    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    private string $logDir;
    private int $minLevel;
    private string $execId;
    private int $ownerPid;

    public function __construct(string $logDir, string $logLevel = 'INFO')
    {
        $this->logDir = rtrim($logDir, '/\\');
        $this->minLevel = self::LEVELS[strtoupper($logLevel)] ?? self::LEVELS['INFO'];
        $this->execId = 'exec_' . bin2hex(random_bytes(8));
        $this->ownerPid = getmypid();

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0700, true);
        }
    }

    public function log(string $message, string $level = 'INFO'): void
    {
        $levelValue = self::LEVELS[strtoupper($level)] ?? null;
        if ($levelValue === null || $levelValue < $this->minLevel) {
            return;
        }

        $entry = json_encode([
            'timestamp' => microtime(true),
            'pid' => getmypid(),
            'level' => $level,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE) . "\n";

        // LOCK_EX prevents interleaved writes from concurrent forked processes
        file_put_contents("{$this->logDir}/{$this->execId}.log", $entry, FILE_APPEND | LOCK_EX);
        file_put_contents("{$this->logDir}/mtman.log", $entry, FILE_APPEND | LOCK_EX);
    }

    public function getExecId(): string
    {
        return $this->execId;
    }
}
