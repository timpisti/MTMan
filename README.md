# MTMan
A Multithreading Solution for PHP
## Overview
MTMan is a production-ready multithreading management library for PHP 8.3+ that provides true parallel execution capabilities through native process management. It offers enterprise-grade features including automatic resource cleanup, configurable retry mechanisms, and comprehensive logging.
## Key Advantages
### True Multithreading (vs ReactPHP)
While ReactPHP uses an event loop for asynchronous operations, MTMan provides actual parallel execution
### Simplified Architecture (vs Swoole)
Unlike Swoole's complex C-level implementation, MTMan provides a pure PHP solution
### Enhanced Versatility (vs Guzzle)
While Guzzle focuses on HTTP requests, MTMan handles any parallel processing need
### Production-Ready Features (vs Spatie Ray)
Unlike development tools like Spatie Ray, MTMan is built for production use
## Technical Specifications
Thread Management
- OS-level process isolation through PCNTL
- Configurable thread limits
- Automatic thread cleanup
- Process status monitoring
## Basic Usage
```php
$mtman = new MTMan(['threads_count' => 4]);

// Add parallel tasks
$mtman->addTask(function() {
    return processDataSet1();
});
$mtman->addTask(function() {
    return processDataSet2();
});

// Execute and get results
$results = $mtman->run();
``` 
## Advanced Configuration
```php
$config = [
    'threads_count' => 4,
    'time_limit' => 60,
    'log_level' => 'DEBUG',
    'max_retries' => 3,
    'temp_dir' => '/var/mtman/temp'
];

$mtman = new MTMan($config);
```

### License
MIT License - see LICENSE file for details.
### Contributing
See CONTRIBUTING.md for contribution guidelines.
