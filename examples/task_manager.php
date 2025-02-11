<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (isset($_GET['action']) && $_GET['action'] === 'run') {
    header('Content-Type: application/json');
    
    try {
        $tempDir = dirname(__DIR__) . '/temp/test_' . uniqid();
        mkdir($tempDir, 0777, true);

        // Get parameters
        $taskType = $_POST['task_type'] ?? 'simple';
        $taskCount = (int)($_POST['task_count'] ?? 3);
        $threadCount = (int)($_POST['thread_count'] ?? 2);

        // Build tasks based on type
        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            switch ($taskType) {
                case 'sleep':
                    $tasks[] = 'function() { 
                        $sleepTime = random_int(1, 3);
                        sleep($sleepTime);
                        return [
                            "task_id" => ' . $i . ',
                            "pid" => getmypid(),
                            "sleep_time" => $sleepTime,
                            "time" => microtime(true)
                        ];
                    }';
                    break;

                case 'cpu':
                    $tasks[] = 'function() {
                        $start = microtime(true);
                        $result = 0;
                        for ($j = 0; $j < 100000; $j++) {
                            $result += sin($j);
                        }
                        return [
                            "task_id" => ' . $i . ',
                            "pid" => getmypid(),
                            "calculation_time" => microtime(true) - $start,
                            "time" => microtime(true)
                        ];
                    }';
                    break;

                case 'error':
                    $tasks[] = 'function() {
                        if (random_int(0, 1)) {
                            throw new Exception("Random error in task ' . $i . '");
                        }
                        return [
                            "task_id" => ' . $i . ',
                            "pid" => getmypid(),
                            "status" => "success",
                            "time" => microtime(true)
                        ];
                    }';
                    break;

                default: // simple
                    $tasks[] = 'function() {
                        return [
                            "task_id" => ' . $i . ',
                            "pid" => getmypid(),
                            "time" => microtime(true)
                        ];
                    }';
                    break;
            }
        }
        
        // Prepare input for CLI script
        $input = [
            'threads_count' => $threadCount,
            'time_limit' => 30,
            'temp_dir' => $tempDir,
            'tasks' => $tasks
        ];

        // Run CLI script
        $cmd = sprintf(
            'php %s/cli_runner.php %s 2>&1',
            __DIR__,
            escapeshellarg(json_encode($input))
        );

        $output = shell_exec($cmd);
        $result = json_decode($output, true);
        
        if ($result === null) {
            throw new Exception("Failed to decode CLI output: " . $output);
        }

        // Add temp_dir to result for status checking
        $result['temp_dir'] = $tempDir;
        echo json_encode($result);

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'temp_dir' => $tempDir ?? null
        ]);
    }
    exit;
}

// Check status endpoint
if (isset($_GET['status'])) {
    $statusFile = $_GET['status'] . '/status.json';
    if (file_exists($statusFile)) {
        header('Content-Type: application/json');
        echo file_get_contents($statusFile);
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>MTMan Task Manager</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 20px;
            background: #f0f0f0;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .controls {
            display: grid;
            gap: 15px;
            margin-bottom: 20px;
        }
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        label {
            font-weight: bold;
            color: #333;
        }
        select, input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
        }
        button:hover {
            background: #45a049;
        }
        pre {
            background: #f8f8f8;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 14px;
        }
        .error { color: #f44336; }
        .success { color: #4CAF50; }
        .warning { color: #ff9800; }
        
        .progress-container {
            margin: 20px 0;
            padding: 20px;
            background: #f8f8f8;
            border-radius: 8px;
        }
        .progress-bar {
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: #4CAF50;
            width: 0;
            transition: width 0.3s ease;
        }
        .progress-status {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .task-list {
            margin-top: 20px;
        }
        .task-item {
            padding: 10px;
            margin: 5px 0;
            background: white;
            border-radius: 4px;
            border-left: 4px solid #ccc;
            font-size: 14px;
            transition: all 0.3s;
        }
        .task-item.running {
            border-left-color: #2196F3;
            background: #e3f2fd;
        }
        .task-item.completed {
            border-left-color: #4CAF50;
            background: #e8f5e9;
        }
        .task-item.error {
            border-left-color: #f44336;
            background: #ffebee;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            padding: 15px;
            background: #e8f5e9;
            border-radius: 4px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            background: #f8f8f8;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>MTMan Task Manager</h1>
        
        <form id="taskForm" onsubmit="return runTasks(event)">
            <div class="controls">
                <div class="control-group">
                    <label>Task Type:</label>
                    <select name="task_type">
                        <option value="simple">Simple (Quick Test)</option>
                        <option value="sleep">Sleep (I/O Test)</option>
                        <option value="cpu">CPU Intensive</option>
                        <option value="error">Error Handling</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Number of Tasks:</label>
                    <input type="number" name="task_count" value="3" min="1" max="10">
                </div>
                <div class="control-group">
                    <label>Number of Threads:</label>
                    <input type="number" name="thread_count" value="2" min="1" max="8">
                </div>
            </div>
            <button type="submit">Run Tasks</button>
        </form>

        <div id="progress" style="display: none">
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-status">Starting tasks...</div>
            </div>
            <div class="task-list"></div>
        </div>

        <div id="output"></div>
    </div>

    <script>
        let statusChecker = null;
        let currentTempDir = null;

        async function checkStatus() {
            if (!currentTempDir) return;
            
            try {
                const response = await fetch(`?status=${encodeURIComponent(currentTempDir)}`);
                if (!response.ok) return;
                
                const data = await response.json();
                updateProgress(data);
                
                if (data.status === 'completed' || data.status === 'error') {
                    clearInterval(statusChecker);
                    showFinalResults(data);
                }
            } catch (error) {
                console.error('Status check failed:', error);
            }
        }

        function updateProgress(data) {
            const progress = document.getElementById('progress');
            const progressFill = progress.querySelector('.progress-fill');
            const progressStatus = progress.querySelector('.progress-status');
            const taskList = progress.querySelector('.task-list');
            
            // Update progress bar
            const percent = (data.completed / data.total) * 100;
            progressFill.style.width = `${percent}%`;
            progressStatus.textContent = `${data.completed} of ${data.total} tasks completed (${percent.toFixed(1)}%)`;
            
            // Update task list
            let taskHtml = '';
            
            // Running tasks
            Object.entries(data.current_tasks || {}).forEach(([taskId, task]) => {
                const runningTime = ((Date.now() / 1000) - task.start_time).toFixed(1);
                taskHtml += `
                    <div class="task-item running">
                        Task ${taskId}: Running (${runningTime}s)
                    </div>
                `;
            });
            
            // Completed tasks
            Object.entries(data.results || {}).forEach(([taskId, result]) => {
                taskHtml += `
                    <div class="task-item completed">
                        Task ${taskId}: Completed (PID: ${result.pid})
                    </div>
                `;
            });
            
            // Failed tasks
            Object.entries(data.errors || {}).forEach(([taskId, error]) => {
                taskHtml += `
                    <div class="task-item error">
                        Task ${taskId}: Failed - ${error}
                    </div>
                `;
            });
            
            taskList.innerHTML = taskHtml;
        }

        function showFinalResults(data) {
            const output = document.getElementById('output');
            
            let html = '<div class="result">';
            html += `<h3 class="${data.status === 'completed' ? 'success' : 'error'}">
                ${data.status === 'completed' ? 'Tasks Completed' : 'Execution Failed'}
            </h3>`;
            
            // Stats
            html += '<div class="stats">';
            html += `
                <div class="stat-box">
                    <strong>Total Tasks</strong><br>
                    ${data.total}
                </div>
                <div class="stat-box">
                    <strong>Completed</strong><br>
                    ${Object.keys(data.results || {}).length}
                </div>
                <div class="stat-box">
                    <strong>Errors</strong><br>
                    ${Object.keys(data.errors || {}).length}
                </div>
            `;
            html += '</div>';
            
            // Results
            if (Object.keys(data.results || {}).length > 0) {
                html += '<h4>Results:</h4>';
                html += `<pre>${JSON.stringify(data.results, null, 2)}</pre>`;
            }
            
            // Errors
            if (Object.keys(data.errors || {}).length > 0) {
                html += '<h4>Errors:</h4>';
                html += `<pre>${JSON.stringify(data.errors, null, 2)}</pre>`;
            }
            
            html += '</div>';
            output.innerHTML = html;
        }

        async function runTasks(event) {
            event.preventDefault();
            
            const output = document.getElementById('output');
            const progress = document.getElementById('progress');
            const form = event.target;
            
            // Reset UI
            output.innerHTML = '';
            progress.style.display = 'block';
            progress.querySelector('.progress-fill').style.width = '0';
            progress.querySelector('.progress-status').textContent = 'Starting tasks...';
            progress.querySelector('.task-list').innerHTML = '';
            
            try {
                const response = await fetch('?action=run', {
                    method: 'POST',
                    body: new FormData(form)
                });
                
                const data = await response.json();
                
                if (data.success && data.temp_dir) {
                    currentTempDir = data.temp_dir;
                    
                    // Start status checking
                    if (statusChecker) {
                        clearInterval(statusChecker);
                    }
                    statusChecker = setInterval(checkStatus, 500);
                } else {
                    throw new Error(data.error || 'Failed to start tasks');
                }
                
            } catch (error) {
                progress.style.display = 'none';
                output.innerHTML = `
                    <div class="result error">
                        <h3>Error</h3>
                        <pre>${error.message}</pre>
                    </div>
                `;
            }
            
            return false;
        }
    </script>
</body>
</html>