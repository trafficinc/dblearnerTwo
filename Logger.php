<?php
// Logger.php

class Logger
{
    /** @var string Path to the log file */
    protected string $logFile;

    /**
     * @param string|null $logFile Path to write logs; defaults to ./app.log
     */
    public function __construct($logFile = null)
    {
        if ($logFile === null) {
            $logFile = __DIR__ . '/app.log';
        }

        // Ensure directory exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->logFile = $logFile;
    }

    /**
     * Write a log entry
     *
     * @param string $level   One of 'info', 'warning', 'error', 'debug'
     * @param string $message The message to log
     */
    public function log(string $level, string $message): void
    {
        $time   = (new \DateTime('now', new \DateTimeZone('America/New_York')))
                    ->format('Y-m-d H:i:s');
        $entry  = sprintf("[%s] %-7s %s\n", $time, strtoupper($level), $message);

        // Append to file
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);

        // Also echo to console
        echo $entry;
    }

    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }
}
