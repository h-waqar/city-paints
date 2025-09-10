<?php

namespace CityPaintsERP\Helpers;

defined('ABSPATH') || exit;

/**
 * Lightweight file-based logger for debugging and sync traces.
 *
 * Usage:
 *   $logger = new Logger();
 *   $logger->log('Sync started', ['count' => 123]);
 *   $logger->error('Something went wrong', $exception);
 */
class Logger
{
    private string $logDir;
    private string $currentFile;

    public function __construct(string $prefix = 'log')
    {
        $this->logDir = plugin_dir_path(__FILE__) . '../../logs/';

        if (!file_exists($this->logDir)) {
            wp_mkdir_p($this->logDir);
        }

        $timestamp = current_time('timestamp');
        $datePart  = date('Y-m-d_H-i-s', $timestamp);
        $uniq      = uniqid('', true);

        $this->currentFile = $this->logDir . "{$prefix}_{$datePart}_{$uniq}.log";
    }

    /**
     * Write a debug/info log line.
     */
    public function log(string $title, $data = null): void
    {
        $this->write('INFO', $title, $data);
    }

    /**
     * Write an error log line.
     */
    public function error(string $title, $data = null): void
    {
        $this->write('ERROR', $title, $data);
    }

    /**
     * Internal write helper.
     */
    private function write(string $level, string $title, $data = null): void
    {
        $timestamp = current_time('timestamp');
        $humanTime = date('Y-m-d H:i:s', $timestamp);

        $content  = "[{$humanTime}] [{$level}] {$title}\n";
        $content .= str_repeat('-', 60) . "\n";

        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $content .= print_r($data, true);
            } else {
                $content .= (string)$data;
            }
            $content .= "\n";
        }

        $content .= "\n";

        file_put_contents($this->currentFile, $content, FILE_APPEND);
    }

    /**
     * Get the current log file path.
     */
    public function getLogFile(): string
    {
        return $this->currentFile;
    }
}
