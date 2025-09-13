<?php

namespace CityPaintsERP\Helpers;

defined('ABSPATH') || exit;

/**
 * File-based logger for CityPaintsERP plugin.
 *
 * - Writes logs under: {plugin_root}/logs/YYYY-MM-DD/{CallerName}_HH-mm.log
 * - If startSession() is used, writes to a single session file instead.
 * - Safe concurrent writes via fopen + flock.
 * - Convenience methods: info(), debug(), warn(), error(), critical().
 */
class Logger
{
    private string $pluginRoot;
    private string $baseLogDir;
    private string $prefix;
    private ?string $sessionFile = null;
    private bool $enabled = true;
    private int $retentionDays = 30;

    /**
     * @param string      $prefix       prefix used for filenames when caller can't be detected
     * @param string|null $pluginRoot   path to plugin root; default autodetected
     * @param bool        $enabled      enable/disable logging
     * @param int         $retentionDays delete logs older than this (days) when cleanOldLogs() is called
     */
    public function __construct(string $prefix = 'citypaints', ?string $pluginRoot = null, bool $enabled = true, int $retentionDays = 30)
    {
        $this->prefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prefix);
        $this->retentionDays = max(0, (int) $retentionDays);

        if (defined('CITYPAINTS_ENABLE_LOGS') && !CITYPAINTS_ENABLE_LOGS) {
            $this->enabled = false;
            return;
        }

        // resolve plugin root if not provided
        if ($pluginRoot) {
            $this->pluginRoot = rtrim($pluginRoot, '/\\');
        } else {
            // __DIR__ = .../src/Helpers
            $this->pluginRoot = dirname(dirname(__DIR__)); // plugin root (../..)
        }

        $this->baseLogDir = $this->pluginRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;

        if (!file_exists($this->baseLogDir)) {
            wp_mkdir_p($this->baseLogDir);
        }
    }

    /**
     * Start a new session file. Subsequent writes use this file until endSession() or new startSession().
     *
     * @param string|null $sessionPrefix optional friendly name for the session file
     * @return string path to the created session file
     */
    public function startSession(?string $sessionPrefix = null): string
    {
        $sessionPrefix = $sessionPrefix ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $sessionPrefix) : 'session';
        $ts = current_time('timestamp');
        $date = date('Y-m-d', $ts);
        $time = date('H-i-s', $ts);
        $uniq = uniqid('', true);

        $dir = $this->baseLogDir . $date . DIRECTORY_SEPARATOR;
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $filename = sprintf('%s_%s_%s_%s.log', $this->prefix, $sessionPrefix, $time, str_replace('.', '_', $uniq));
        $filepath = $dir . $filename;

        // touch file
        $fp = @fopen($filepath, 'a');
        if ($fp) {
            fclose($fp);
        }

        $this->sessionFile = $filepath;

        return $filepath;
    }

    /**
     * End session mode; subsequent writes will go to per-caller per-minute files.
     */
    public function endSession(): void
    {
        $this->sessionFile = null;
    }

    /**
     * General write method (level aware).
     *
     * @param string $level  e.g. INFO, DEBUG, ERROR
     * @param string $title
     * @param mixed  $context
     * @return string|false  filepath written to or false on failure/disabled
     */
    public function write(string $level, string $title, $context = null)
    {
        if (!$this->enabled) {
            return false;
        }

        $ts = current_time('timestamp');
        $humanTime = date('Y-m-d H:i:s', $ts);

        if ($this->sessionFile) {
            $filepath = $this->sessionFile;
        } else {
            $dateFolder = date('Y-m-d', $ts);
            $timePart = date('H-i', $ts); // hour-minute as requested

            $dir = $this->baseLogDir . $dateFolder . DIRECTORY_SEPARATOR;
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }

            $caller = $this->detectCaller();
            $filename = "{$caller}_{$timePart}.log";
            $filepath = $dir . $filename;
        }

        // Build content
        $content  = "[{$humanTime}] [{$level}] {$title}\n";
        $content .= str_repeat('-', 60) . "\n";

        if ($context !== null) {
            if (is_scalar($context)) {
                $content .= (string) $context . "\n";
            } else {
                // arrays / objects / exceptions
                if ($context instanceof \Throwable) {
                    $content .= "Exception: " . $context->getMessage() . "\n";
                    $content .= $context->getTraceAsString() . "\n";
                } else {
                    $content .= print_r($context, true);
                }
            }
            $content .= "\n";
        }

        // Write with lock
        $written = false;
        $fp = @fopen($filepath, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $content);
                fflush($fp);
                flock($fp, LOCK_UN);
                $written = true;
            }
            fclose($fp);
        } else {
            // fallback to file_put_contents in case fopen fails (rare)
            @file_put_contents($filepath, $content, FILE_APPEND | LOCK_EX);
            $written = true;
        }

        return $written ? $filepath : false;
    }

    /* ----------------- Convenience level methods ----------------- */

    public function info(string $title, $context = null)
    {
        return $this->write('INFO', $title, $context);
    }

    public function debug(string $title, $context = null)
    {
        return $this->write('DEBUG', $title, $context);
    }

    public function warn(string $title, $context = null)
    {
        return $this->write('WARN', $title, $context);
    }

    public function error(string $title, $context = null)
    {
        return $this->write('ERROR', $title, $context);
    }

    public function critical(string $title, $context = null)
    {
        return $this->write('CRITICAL', $title, $context);
    }

    // alias
    public function log(string $title, $context = null)
    {
        return $this->info($title, $context);
    }

    /* ----------------- Helpers & config ----------------- */

    /**
     * Return the currently active log file (session or computed file for current minute + caller).
     */
    public function getCurrentLogFile(): string
    {
        if ($this->sessionFile) {
            return $this->sessionFile;
        }

        $ts = current_time('timestamp');
        $dateFolder = date('Y-m-d', $ts);
        $timePart = date('H-i', $ts);
        $dir = $this->baseLogDir . $dateFolder . DIRECTORY_SEPARATOR;
        $caller = $this->detectCaller();
        return $dir . "{$caller}_{$timePart}.log";
    }

    /**
     * Enable or disable logging at runtime.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = (bool) $enabled;
    }

    /**
     * Set retention days for cleanOldLogs.
     */
    public function setRetentionDays(int $days): void
    {
        $this->retentionDays = max(0, $days);
    }

    /**
     * Remove log directories older than retentionDays.
     *
     * @return int number of directories removed
     */
    public function cleanOldLogs(): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $removed = 0;
        $items = glob($this->baseLogDir . '*', GLOB_ONLYDIR);
        if (!$items) {
            return 0;
        }

        $now = current_time('timestamp');

        foreach ($items as $dir) {
            // directory name expected YYYY-MM-DD
            $basename = basename($dir);
            $ts = strtotime($basename);
            if ($ts === false) {
                continue;
            }

            $ageDays = ($now - $ts) / DAY_IN_SECONDS;
            if ($ageDays > $this->retentionDays) {
                $this->rrmdir($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Recursive remove directory.
     */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $object;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Try detect the caller class name (or fallback to file name).
     */
    private function detectCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        foreach ($trace as $frame) {
            if (!empty($frame['class']) && $frame['class'] !== __CLASS__) {
                // sanitize class name (replace backslashes)
                return $this->sanitizeCallerName($frame['class']);
            }
            if (!empty($frame['file']) && strpos($frame['file'], 'Logger.php') === false) {
                return $this->sanitizeCallerName(pathinfo($frame['file'], PATHINFO_FILENAME));
            }
        }

        return $this->prefix;
    }

    /**
     * Sanitize caller names to be safe as filenames.
     */
    private function sanitizeCallerName(string $name): string
    {
        // replace namespace separators, spaces, and other non-alphanum chars with underscore
        $n = str_replace('\\', '_', $name);
        $n = preg_replace('/[^A-Za-z0-9_\-]/', '_', $n);
        // limit length
        return substr($n, 0, 80);
    }
}
