<?php

namespace MTMan;

use MTMan\Exceptions\MTManException;
use MTMan\Storage\ResultStorage;
use MTMan\Logger\Logger;

class MTMan
{
    private const VALID_CONFIG_KEYS = [
        'max_processes', 'time_limit', 'log_level', 'max_retries',
        'temp_dir', 'on_task_start', 'on_task_complete', 'on_task_error',
        'poll_interval_us',
    ];

    private const DEFAULT_CONFIG = [
        'max_processes' => 4,
        'time_limit' => 60,
        'log_level' => 'INFO',
        'max_retries' => 3,
        'poll_interval_us' => 10_000, // 10ms — balanced between latency and CPU
    ];

    private array $config;
    private array $tasks = [];
    private ResultStorage $storage;
    private Logger $logger;
    private array $processStatus = [];
    private int $taskCounter = 0;
    private bool $shutdownRequested = false;

    public function __construct(array $config = [])
    {
        if (!extension_loaded('pcntl')) {
            throw MTManException::pcntlMissing();
        }

        $this->validateConfig($config);

        $projectRoot = dirname(__DIR__);
        $this->config = array_merge(
            self::DEFAULT_CONFIG,
            ['temp_dir' => $projectRoot . '/temp'],
            $config
        );

        if (!is_dir($this->config['temp_dir'])) {
            mkdir($this->config['temp_dir'], 0700, true);
        }

        $this->storage = new ResultStorage($this->config['temp_dir']);
        $this->logger = new Logger($this->config['temp_dir'], $this->config['log_level']);
    }

    public function addTask(callable $function, array $params = []): int
    {
        $taskId = $this->taskCounter++;
        $this->tasks[$taskId] = [
            'function' => $function,
            'params' => $params,
            'retries' => 0,
        ];
        return $taskId;
    }

    /**
     * Execute all queued tasks with process-level parallelism.
     *
     * @return array{results: array<int, mixed>, errors: array<int, string>}
     */
    public function run(): array
    {
        $startTime = time();
        $results = [];
        $errors = [];
        $running = []; // pid => taskId
        $pendingTasks = $this->tasks;
        $this->shutdownRequested = false;

        $this->installSignalHandlers();

        try {
            while (!empty($pendingTasks) || !empty($running)) {
                // Dispatch pending signals (async signals are enabled)
                pcntl_signal_dispatch();

                if ($this->shutdownRequested) {
                    $this->logger->log('Shutdown requested, draining running processes...', 'WARNING');
                    $pendingTasks = []; // Stop launching new work
                }

                $this->enforceTimeout($startTime, $running);

                // Fork new children up to the concurrency limit
                while (!$this->shutdownRequested
                    && count($running) < $this->config['max_processes']
                    && !empty($pendingTasks)
                ) {
                    $taskId = array_key_first($pendingTasks);
                    $task = $pendingTasks[$taskId];

                    $this->logger->log("Forking for task {$taskId}", 'DEBUG');
                    $pid = pcntl_fork();

                    if ($pid === -1) {
                        throw MTManException::forkFailed();
                    }

                    if ($pid === 0) {
                        // --- CHILD PROCESS ---
                        $this->executeChild($taskId, $task);
                        // executeChild never returns
                    }

                    // --- PARENT PROCESS ---
                    $running[$pid] = $taskId;
                    unset($pendingTasks[$taskId]);
                    $this->processStatus[$pid] = 'RUNNING';
                    $this->logger->log("Started task {$taskId} in PID {$pid}", 'DEBUG');
                    $this->fireCallback('on_task_start', $taskId);
                }

                // Reap finished children (non-blocking)
                foreach ($running as $pid => $taskId) {
                    $waitResult = pcntl_waitpid($pid, $rawStatus, WNOHANG);

                    if ($waitResult <= 0) {
                        continue; // Still running or error
                    }

                    unset($running[$pid]);

                    if (pcntl_wifexited($rawStatus) && pcntl_wexitstatus($rawStatus) === 0) {
                        $result = $this->storage->get($taskId);

                        if ($result !== null) {
                            $results[$taskId] = $result;
                            $this->processStatus[$pid] = 'COMPLETED';
                            $this->logger->log("Task {$taskId} completed", 'DEBUG');
                            $this->fireCallback('on_task_complete', $taskId, $result);
                        } else {
                            // Child exited 0 but result file missing/corrupt — treat as failure
                            $errMsg = 'Child exited successfully but result was not retrievable';
                            $this->logger->log("Task {$taskId}: {$errMsg}", 'WARNING');
                            $this->handleFailedTask($taskId, $pendingTasks, $errors, $errMsg);
                            $this->processStatus[$pid] = 'FAILED';
                        }
                    } else {
                        $errMsg = $this->describeExitStatus($pid, $rawStatus);
                        $this->handleFailedTask($taskId, $pendingTasks, $errors, $errMsg);
                        $this->processStatus[$pid] = 'FAILED';
                    }
                }

                if (!empty($pendingTasks) || !empty($running)) {
                    usleep($this->config['poll_interval_us']);
                }
            }
        } finally {
            $this->restoreSignalHandlers();
        }

        $this->logger->log(
            sprintf('Run completed: %d succeeded, %d failed', count($results), count($errors)),
            'INFO'
        );

        return ['results' => $results, 'errors' => $errors];
    }

    public function getProcessStatus(): array
    {
        return $this->processStatus;
    }

    public function cleanup(): void
    {
        $this->storage->cleanup();
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Runs inside the child process. MUST call exit() — never returns.
     */
    private function executeChild(int $taskId, array $task): never
    {
        // Restore default signal handling in child so it doesn't inherit parent's handlers
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);

        try {
            $result = ($task['function'])(...$task['params']);
            $this->storage->store($taskId, $result);
            exit(0);
        } catch (\Throwable $e) {
            $this->logger->log("Task {$taskId} exception: {$e->getMessage()}", 'ERROR');
            exit(1);
        }
    }

    private function installSignalHandlers(): void
    {
        pcntl_async_signals(true);

        $handler = function (int $signo): void {
            $this->logger->log("Received signal {$signo}, requesting graceful shutdown", 'WARNING');
            $this->shutdownRequested = true;
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private function restoreSignalHandlers(): void
    {
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
    }

    private function enforceTimeout(int $startTime, array &$running): void
    {
        if ((time() - $startTime) <= $this->config['time_limit']) {
            return;
        }

        $this->logger->log('Global timeout reached, terminating children', 'ERROR');
        $this->terminateChildren($running);
        throw MTManException::timeout($this->config['time_limit']);
    }

    /**
     * Send SIGTERM, wait briefly, then SIGKILL any stragglers. Reaps all children.
     */
    private function terminateChildren(array &$running): void
    {
        if (empty($running)) {
            return;
        }

        // Phase 1: polite SIGTERM
        foreach (array_keys($running) as $pid) {
            posix_kill($pid, SIGTERM);
        }

        // Give children 500ms to exit cleanly
        $deadline = microtime(true) + 0.5;
        while (!empty($running) && microtime(true) < $deadline) {
            foreach (array_keys($running) as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                    unset($running[$pid]);
                    $this->processStatus[$pid] = 'TERMINATED';
                }
            }
            if (!empty($running)) {
                usleep(5_000); // 5ms between reap attempts
            }
        }

        // Phase 2: force-kill survivors
        foreach (array_keys($running) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status); // blocking reap — SIGKILL is immediate
            unset($running[$pid]);
            $this->processStatus[$pid] = 'KILLED';
        }
    }

    private function handleFailedTask(
        int $taskId,
        array &$pendingTasks,
        array &$errors,
        string $errorMessage,
    ): void {
        $retries = $this->tasks[$taskId]['retries'];
        $maxRetries = $this->config['max_retries'];

        if ($retries < $maxRetries) {
            $this->tasks[$taskId]['retries']++;
            $attempt = $this->tasks[$taskId]['retries'];
            $pendingTasks[$taskId] = $this->tasks[$taskId];

            $this->logger->log(
                "Task {$taskId} failed ({$errorMessage}), retry {$attempt}/{$maxRetries}",
                'WARNING'
            );
        } else {
            $this->logger->log(
                "Task {$taskId} permanently failed after {$retries} retries: {$errorMessage}",
                'ERROR'
            );
            $errors[$taskId] = $errorMessage;
            $this->fireCallback('on_task_error', $taskId, $errorMessage);
        }
    }

    private function describeExitStatus(int $pid, int $rawStatus): string
    {
        if (pcntl_wifexited($rawStatus)) {
            $code = pcntl_wexitstatus($rawStatus);
            return "Process {$pid} exited with status {$code}";
        }
        if (pcntl_wifsignaled($rawStatus)) {
            $sig = pcntl_wtermsig($rawStatus);
            return "Process {$pid} killed by signal {$sig}";
        }
        return "Process {$pid} terminated abnormally";
    }

    private function fireCallback(string $name, int $taskId, mixed $extra = null): void
    {
        if (!isset($this->config[$name]) || !is_callable($this->config[$name])) {
            return;
        }

        try {
            if ($extra !== null) {
                ($this->config[$name])($taskId, $extra);
            } else {
                ($this->config[$name])($taskId);
            }
        } catch (\Throwable $e) {
            $this->logger->log("Callback {$name} threw: {$e->getMessage()}", 'WARNING');
        }
    }

    private function validateConfig(array $config): void
    {
        $unknown = array_diff(array_keys($config), self::VALID_CONFIG_KEYS);
        if (!empty($unknown)) {
            throw MTManException::invalidConfig(
                'Unknown config key(s): ' . implode(', ', $unknown)
            );
        }

        if (isset($config['max_processes']) && (!is_int($config['max_processes']) || $config['max_processes'] < 1)) {
            throw MTManException::invalidConfig('max_processes must be a positive integer');
        }

        if (isset($config['time_limit']) && (!is_int($config['time_limit']) || $config['time_limit'] < 1)) {
            throw MTManException::invalidConfig('time_limit must be a positive integer');
        }

        if (isset($config['max_retries']) && (!is_int($config['max_retries']) || $config['max_retries'] < 0)) {
            throw MTManException::invalidConfig('max_retries must be a non-negative integer');
        }

        if (isset($config['log_level'])) {
            $valid = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
            if (!in_array(strtoupper($config['log_level']), $valid, true)) {
                throw MTManException::invalidConfig(
                    'log_level must be one of: ' . implode(', ', $valid)
                );
            }
        }

        foreach (['on_task_start', 'on_task_complete', 'on_task_error'] as $cb) {
            if (isset($config[$cb]) && !is_callable($config[$cb])) {
                throw MTManException::invalidConfig("{$cb} must be callable");
            }
        }
    }
}
