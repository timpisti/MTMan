#!/bin/bash

# Create coverage directory if it doesn't exist
mkdir -p coverage

# Clean up old coverage reports
rm -rf coverage/*
rm -f coverage.txt

# Run tests with coverage
echo "Running PHPUnit tests with coverage..."
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text

# Check exit status
if [ $? -eq 0 ]; then
    echo "All tests passed!"
else
    echo "Some tests failed!"
    exit 1
fi