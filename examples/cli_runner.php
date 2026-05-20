<?php
// cli_runner.php

require_once __DIR__ . '/../vendor/autoload.php';
use MTMan\MTMan;

function updateStatus($statusFile, $status) {
    file_put_contents($statusFile, json_encode($status, JSON_PRETTY_PRINT));
}

// Get JSON input from command line argument
$input = json_decode($argv[1], true);
$statusFile = $input['temp_dir'] . '/status.json';

try {
    // Initialize status
    updateStatus($statusFile, [
        'status' => 'initializing',
        'completed' => 0,
        'total' => count($input['tasks']),
        'current_tasks' => [],
        'results' => [],
        'errors' => []
    ]);

    $mtman = new MTMan([
        'threads_count' => $input['threads_count'] ?? 2,
        'time_limit' => $input['time_limit'] ?? 30,
        'temp_dir' => $input['temp_dir'],
        'on_task_start' => function($taskId) use ($statusFile) {
            $status = json_decode(file_get_contents($statusFile), true);
            $status['current_tasks'][$taskId] = [
                'status' => 'running',
                'start_time' => microtime(true)
            ];
            $status['status'] = 'running';
            updateStatus($statusFile, $status);
        },
        'on_task_complete' => function($taskId, $result) use ($statusFile) {
            $status = json_decode(file_get_contents($statusFile), true);
            unset($status['current_tasks'][$taskId]);
            $status['completed']++;
            $status['results'][$taskId] = $result;
            updateStatus($statusFile, $status);
        },
        'on_task_error' => function($taskId, $error) use ($statusFile) {
            $status = json_decode(file_get_contents($statusFile), true);
            unset($status['current_tasks'][$taskId]);
            $status['errors'][$taskId] = $error;
            updateStatus($statusFile, $status);
        }
    ]);

    // Add tasks safely without eval()
    foreach ($input['tasks'] as $task) {
        $taskType = $task['type'] ?? 'simple';
        $taskId = $task['id'] ?? 0;
        
        switch ($taskType) {
            case 'sleep':
                $mtman->addTask(function() use ($taskId) {
                    $sleepTime = random_int(1, 3);
                    sleep($sleepTime);
                    return [
                        "task_id" => $taskId,
                        "pid" => getmypid(),
                        "sleep_time" => $sleepTime,
                        "time" => microtime(true)
                    ];
                });
                break;
            case 'cpu':
                $mtman->addTask(function() use ($taskId) {
                    $start = microtime(true);
                    $result = 0;
                    for ($j = 0; $j < 100000; $j++) {
                        $result += sin($j);
                    }
                    return [
                        "task_id" => $taskId,
                        "pid" => getmypid(),
                        "calculation_time" => microtime(true) - $start,
                        "time" => microtime(true)
                    ];
                });
                break;
            case 'error':
                $mtman->addTask(function() use ($taskId) {
                    if (random_int(0, 1)) {
                        throw new Exception("Random error in task " . $taskId);
                    }
                    return [
                        "task_id" => $taskId,
                        "pid" => getmypid(),
                        "status" => "success",
                        "time" => microtime(true)
                    ];
                });
                break;
            default: // simple
                $mtman->addTask(function() use ($taskId) {
                    return [
                        "task_id" => $taskId,
                        "pid" => getmypid(),
                        "time" => microtime(true)
                    ];
                });
                break;
        }
    }

    // Run tasks
    $results = $mtman->run();

    // Final status update
    updateStatus($statusFile, [
        'status' => 'completed',
        'completed' => count($results),
        'total' => count($input['tasks']),
        'results' => $results,
        'thread_status' => $mtman->getThreadStatus(),
        'completion_time' => microtime(true)
    ]);

    echo json_encode([
        'success' => true,
        'status_file' => $statusFile
    ]);

} catch (Throwable $e) {
    updateStatus($statusFile, [
        'status' => 'error',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'status_file' => $statusFile
    ]);
}