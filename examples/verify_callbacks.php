<?php
require_once __DIR__ . '/../vendor/autoload.php';
use MTMan\MTMan;

$tempDir = __DIR__ . '/../temp/verify_' . uniqid();
@mkdir($tempDir, 0777, true);

echo "Initializing MTMan with callbacks...\n";

$started = [];
$completed = [];
$failed = [];

$mtman = new MTMan([
    'threads_count' => 2,
    'time_limit' => 5,
    'temp_dir' => $tempDir,
    'on_task_start' => function($taskId) use (&$started) {
        echo "[Callback] Task $taskId started\n";
        $started[] = $taskId;
    },
    'on_task_complete' => function($taskId, $result) use (&$completed) {
        echo "[Callback] Task $taskId completed with result: " . json_encode($result) . "\n";
        $completed[] = $taskId;
    },
    'on_task_error' => function($taskId, $error) use (&$failed) {
        echo "[Callback] Task $taskId failed permanently. Error: $error\n";
        $failed[] = $taskId;
    }
]);

// Add 3 tasks: one simple, one failing with retry, one failing permanently
$mtman->addTask(function() {
    return "Simple task result";
});

$mtman->addTask(function() use ($tempDir) {
    $file = $tempDir . '/attempt_tracker.txt';
    $attempt = file_exists($file) ? (int)file_get_contents($file) : 0;
    $attempt++;
    file_put_contents($file, $attempt);
    if ($attempt < 2) {
        throw new Exception("Temporary failure");
    }
    return "Retry task success";
});

$mtman->addTask(function() {
    throw new Exception("Permanent failure");
});

echo "Running MTMan...\n";
$results = $mtman->run();

echo "\n--- Summary ---\n";
echo "Started tasks count: " . count($started) . " (Expected: 4, since one retried)\n";
echo "Completed tasks count: " . count($completed) . " (Expected: 2)\n";
echo "Failed tasks count: " . count($failed) . " (Expected: 1)\n";
echo "Results returned: " . json_encode($results) . "\n";

// Clean up temp dir
@array_map('unlink', glob("$tempDir/*"));
@rmdir($tempDir);
