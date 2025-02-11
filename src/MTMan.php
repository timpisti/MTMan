<?php

namespace MTMan;

use MTMan\Exceptions\MTManException;
use MTMan\Storage\ResultStorage;
use MTMan\Logger\Logger;

class MTMan
{
    private array $config;
    private array $tasks = [];
    private ResultStorage $storage;
    private Logger $logger;
    private array $threadStatus = [];
    private int $taskCounter = 0;

    public function __construct(array $config = [])
    {
        if (!extension_loaded('pcntl')) {
            throw new MTManException('PCNTL extension required', MTManException::ERROR_PCNTL_MISSING);
        }

        $projectRoot = dirname(__DIR__);
        $this->config = array_merge([
            'threads_count' => 4,
            'time_limit' => 60,
            'log_level' => 'INFO',
            'max_retries' => 3,
            'temp_dir' => $projectRoot . '/temp'
        ], $config);

        if (!is_dir($this->config['temp_dir'])) {
            mkdir($this->config['temp_dir'], 0777, true);
        }

        $this->storage = new ResultStorage($this->config['temp_dir']);
        $this->logger = new Logger($this->config['temp_dir'], $this->config['log_level']);
    }

    public function addTask(callable $function, array $params = []): void
    {
        $this->tasks[$this->taskCounter] = [
            'function' => $function,
            'params' => $params,
            'retries' => 0
        ];
        $this->taskCounter++;
    }

	public function run(): array
	{
		$startTime = time();
		$results = [];
		$running = [];
		$pendingTasks = $this->tasks;

		while (!empty($pendingTasks) || !empty($running)) {
			$this->checkTimeout($startTime);
			
			// Start new tasks
			while (count($running) < $this->config['threads_count'] && !empty($pendingTasks)) {
				$taskId = array_key_first($pendingTasks);
				$task = $pendingTasks[$taskId];
				
				$this->logger->log("Forking for task $taskId", 'DEBUG');
				$pid = pcntl_fork();
				
				if ($pid === -1) {
					throw new MTManException('Fork failed');
				}
				
				if ($pid === 0) { // Child process
					try {
						$this->logger->log("Child process started for task $taskId", 'DEBUG');
						$result = ($task['function'])(...$task['params']);
						$this->logger->log("Task $taskId completed, storing result", 'DEBUG');
						$this->storage->store($taskId, $result);
						$this->logger->log("Result stored for task $taskId", 'DEBUG');
						exit(0);
					} catch (\Throwable $e) {
						$this->logger->log("Task $taskId failed: " . $e->getMessage(), 'ERROR');
						exit(1);
					}
				}
				
				// Parent process continues
				$running[$pid] = $taskId;
				unset($pendingTasks[$taskId]);
				$this->threadStatus[$pid] = 'RUNNING';
				$this->logger->log("Parent: Started task $taskId with PID $pid", 'DEBUG');
			}
			
			// Check completed tasks
			foreach ($running as $pid => $taskId) {
				$status = pcntl_waitpid($pid, $exitStatus, WNOHANG);
				
				if ($status === $pid) {
					$this->logger->log("Process $pid completed with status " . pcntl_wexitstatus($exitStatus), 'DEBUG');
					
					if (pcntl_wexitstatus($exitStatus) === 0) {
						$this->logger->log("Attempting to retrieve result for task $taskId", 'DEBUG');
						$result = $this->storage->get($taskId);
						
						if ($result !== null) {
							$this->logger->log("Retrieved result for task $taskId", 'DEBUG');
							$results[$taskId] = $result;
							$this->threadStatus[$pid] = 'COMPLETED';
						} else {
							$this->logger->log("No result found for task $taskId", 'WARNING');
						}
					} else {
						$this->handleFailedTask($taskId, $pendingTasks);
						$this->threadStatus[$pid] = 'FAILED';
					}
					unset($running[$pid]);
				}
			}
			
			usleep(1000);
		}

		// Wait for any remaining processes
		foreach ($running as $pid => $taskId) {
			pcntl_waitpid($pid, $exitStatus);
			$this->logger->log("Final wait: Process $pid completed", 'DEBUG');
			
			if (pcntl_wexitstatus($exitStatus) === 0) {
				$result = $this->storage->get($taskId);
				if ($result !== null) {
					$results[$taskId] = $result;
				}
			}
		}

		$this->logger->log("Run completed with " . count($results) . " results", 'INFO');
		return $results;
	}

    private function checkTimeout(int $startTime): void
    {
        if (time() - $startTime > $this->config['time_limit']) {
            $this->terminateAllThreads();
            throw new MTManException('Execution timeout');
        }
    }

    private function handleFailedTask(int $taskId, array &$pendingTasks): void
    {
        if (!isset($this->tasks[$taskId]['retries'])) {
            $this->tasks[$taskId]['retries'] = 0;
        }

        if ($this->tasks[$taskId]['retries'] < $this->config['max_retries']) {
            $this->tasks[$taskId]['retries']++;
            $pendingTasks[$taskId] = $this->tasks[$taskId];
            $this->logger->log(
                "Task $taskId failed, scheduling retry {$this->tasks[$taskId]['retries']}/{$this->config['max_retries']}", 
                'WARNING'
            );
            
            // Add a small delay before retry
            usleep(100000); // 100ms delay
        } else {
            $this->logger->log(
                "Task $taskId failed permanently after {$this->tasks[$taskId]['retries']} retries", 
                'ERROR'
            );
        }
    }

    private function terminateAllThreads(): void
    {
        foreach ($this->threadStatus as $pid => $status) {
            if ($status === 'RUNNING') {
                posix_kill($pid, SIGTERM);
            }
        }
    }

    public function getThreadStatus(): array
    {
        return $this->threadStatus;
    }

    public function cleanup(): void
    {
        $this->storage->cleanup();
    }
}