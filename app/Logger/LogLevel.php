<?php

declare(strict_types=1);
namespace App\Logger;

class LogLevel
{
    const INFO = 2;
    const SUCCESS = 4;
    const WARNING = 8;
    const ERROR = 16;
    const DEBUG = 32;

    /**
     * Get log level string
     */
    public static function getText(int $logLevel): string
    {
        return match ($logLevel) {
            self::INFO => 'Info',
            self::SUCCESS => 'Success',
            self::WARNING => 'Warning',
            self::ERROR => 'Error',
            self::DEBUG => 'Debug',
        };
    }
}
