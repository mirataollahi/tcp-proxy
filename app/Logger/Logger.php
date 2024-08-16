<?php

declare(strict_types=1);
namespace App\Logger;

use Swoole\Coroutine;
use Throwable;

class Logger
{
    /**
     * @var LoggerConfigs Logger service configs
     */
    public LoggerConfigs $configs;

    /**
     * Check log directory exists . if not exists , create the logs dir in app root
     */
    public function __construct(array $appConfigs = [])
    {
        $this->configs = new LoggerConfigs($appConfigs);
        try {
            if (!is_dir($this->configs->logsDirectory)) {
                mkdir($this->configs->logsDirectory);
                $this->success("[Logger] [Initialize] => Successfully logs directory in {$this->configs->logsDirectory}");
            }
        } catch (Throwable $exception) {
            $this->error("[Logger] [Initialize] => error in create logs directory because {$exception->getMessage()}");
        }
    }

    /**
     * Colorize the output string in command line
     */
    private function colorize(?string $string, int $color = LogColor::WHITE): string
    {
        $prefix = null;
        if ($this->configs->enableLogDateTime){
            $dateTime = date('Y-m-d H:i:s');
            $prefix .= "[$dateTime] ";
        }
        return "\033[{$color}m$prefix {$string}\033[0m";
    }

    /**
     * Log an information message in command line
     */
    public function info(string $message): void
    {
        if ($this->isLevelEnable(LogLevel::INFO)) {
            $this->print($this->colorize($message, LogColor::BLUE) . PHP_EOL);
        }
    }

    /**
     * Log a success message in command line
     */
    public function success(?string $message = null): void
    {
        if ($this->isLevelEnable(LogLevel::SUCCESS)) {
            $this->print($this->colorize($message, LogColor::GREEN).PHP_EOL);
        }
    }

    /**
     * Log a warning message in command line
     */
    public function warning(?string $message = null): void
    {
        if ($this->isLevelEnable(LogLevel::WARNING)) {
            $this->print($this->colorize($message, LogColor::YELLOW).PHP_EOL);
        }
    }

    /**
     * Log a error message in command line
     */
    public function error(?string $message = null): void
    {
        if ($this->isLevelEnable(LogLevel::ERROR)) {
            $this->print($this->colorize($message, LogColor::RED) . PHP_EOL);
        }
    }

    /**
     * Log a debug log message
     */
    public function debug(?string $message = null): void
    {
        if ($this->isLevelEnable(LogLevel::ERROR)) {
            $this->print($this->colorize($message, LogColor::CYAN) . PHP_EOL);
        }
    }

    /**
     * Checking a log level is enabled or not
     *
     * @param int $logLevel
     * @return bool
     */
    public function isLevelEnable(int $logLevel): bool
    {
        return boolval($this->configs->logLevel & $logLevel);
    }

    /**
     * Pass lines in command line
     */
    public function endLines(int $lineNumbers = 1): void
    {
        $this->print(
            str_repeat(PHP_EOL, $lineNumbers)
        );
    }

    /**
     * Print a text string in command line async or blocking mode
     */
    public function print(string|null $message = null): void
    {
        if ($this->configs->asyncStdout)
            Coroutine::create(function () use ($message) {
                echo $message;
            });
        echo $message;
    }

    /**
     * Log an exception in command line
     */
    public function exception(Throwable $throwable): void
    {
        echo PHP_EOL;
        $expType = get_class($throwable);
        $this->print(
            $this->colorize("Exception $expType thrown : ", LogColor::RED) . PHP_EOL
        );
        $this->print(
            $this->colorize("Message => {$throwable->getMessage()}", LogColor::RED) . PHP_EOL
        );
        $this->print(
            $this->colorize("File => {$throwable->getFile()} ({$throwable->getLine()})", LogColor::RED) . PHP_EOL
        );
        echo PHP_EOL;
    }
}
