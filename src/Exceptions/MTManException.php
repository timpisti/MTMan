<?php

namespace MTMan\Exceptions;

class MTManException extends \Exception
{
    public const ERROR_PCNTL_MISSING = 1;
    public const ERROR_THREAD_CREATE = 2;
    public const ERROR_STORAGE_FULL = 3;
    public const ERROR_FILE_SIZE = 4;
    public const ERROR_TIMEOUT = 5;
}