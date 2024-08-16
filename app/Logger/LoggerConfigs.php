<?php

declare(strict_types=1);
namespace App\Logger;

class LoggerConfigs
{
    /**
     * @var bool Application is running in debug mode or not
     */
    public bool $isDebugMode;

    /**
     * @var string Logger service directory path
     */
    public string $logsDirectory = BASE_PATH . '/logs';

    /**
     * @var bool Add date time in each log entry
     */
    public bool $enableLogDateTime = true;

    /**
     * @var bool Add log level text in each log entry
     */
    public bool $enableLogLevelText = false;

    /**
     * @var int Application current active logs levels
     */
    public int $logLevel = LogLevel::INFO | LogLevel::SUCCESS | LogLevel::WARNING | LogLevel::ERROR;

    /**
     * @var bool Print logs in async or blocking mode
     */
    public bool $asyncStdout = false;

    public function __construct(array $appConfigs = [])
    {
        if (array_key_exists('is_debug',$appConfigs)){
            $this->isDebugMode = $appConfigs['is_debug'];
        }

        if (array_key_exists('log_levels',$appConfigs)){
            $this->logLevel = $appConfigs['log_levels'];
        }

        if (array_key_exists('enable_log_date_time',$appConfigs)){
            $this->enableLogDateTime = $appConfigs['enable_log_date_time'];
        }

        if (array_key_exists('enable_log_level_prefix',$appConfigs)){
            $this->enableLogLevelText = $appConfigs['enable_log_level_prefix'];
        }
    }
}
