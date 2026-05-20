<?php

namespace MTMan\Exceptions;

class MTManException extends \RuntimeException
{
    public const ERROR_PCNTL_MISSING = 1;
    public const ERROR_FORK_FAILED = 2;
    public const ERROR_STORAGE = 3;
    public const ERROR_TIMEOUT = 4;
    public const ERROR_INVALID_CONFIG = 5;

    /**
     * @var array<int, string> Map error codes to human-readable labels.
     */
    private const LABELS = [
        self::ERROR_PCNTL_MISSING => 'PCNTL extension required',
        self::ERROR_FORK_FAILED => 'Process fork failed',
        self::ERROR_STORAGE => 'Storage operation failed',
        self::ERROR_TIMEOUT => 'Execution timeout exceeded',
        self::ERROR_INVALID_CONFIG => 'Invalid configuration',
    ];

    public static function pcntlMissing(): self
    {
        return new self(self::LABELS[self::ERROR_PCNTL_MISSING], self::ERROR_PCNTL_MISSING);
    }

    public static function forkFailed(): self
    {
        return new self(self::LABELS[self::ERROR_FORK_FAILED], self::ERROR_FORK_FAILED);
    }

    public static function storage(string $detail): self
    {
        return new self(self::LABELS[self::ERROR_STORAGE] . ': ' . $detail, self::ERROR_STORAGE);
    }

    public static function timeout(int $limitSeconds): self
    {
        return new self(
            self::LABELS[self::ERROR_TIMEOUT] . " ({$limitSeconds}s limit)",
            self::ERROR_TIMEOUT
        );
    }

    public static function invalidConfig(string $detail): self
    {
        return new self(self::LABELS[self::ERROR_INVALID_CONFIG] . ': ' . $detail, self::ERROR_INVALID_CONFIG);
    }
}
