<?php

namespace YesWiki\Importer\Service;

/**
 * TEMPORARY - request timeline used to track down the adminimporters timeout in production.
 *
 * A request that dies on max_execution_time prints nothing when display_errors is off, so
 * this writes each step to the php error log as it is reached, and - from a shutdown
 * function - the fatal error itself with the file and line php was killed on. The last step
 * logged before the gap is where the time went.
 *
 * Off unless the url carries "&importerdebug=1" or IMPORTER_DEBUG_TIMING is set in the
 * environment, so it can be deployed and left dormant.
 *
 * Every line goes to the php error log and, because a php-fpm pool often has no error_log of
 * its own, to "cache/importer-timeline.log" under the wiki as well. Delete that file when
 * done: it holds step names, timings and memory, nothing secret, but it is inside the
 * webroot.
 *
 * Delete this file (and its mark() calls) once the timeout is understood.
 */
class ImportTimeline
{
    private static $enabled = null;
    private static $start = 0.0;
    private static $last = 'request start';
    private static $logFile = false;

    /** Force the timeline on, for a context with no url to add a parameter to (cron, cli). */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            self::$enabled = !empty($_GET['importerdebug']) || getenv('IMPORTER_DEBUG_TIMING') !== false;
        }
        return self::$enabled;
    }

    /**
     * Record that $step has just been reached, with the time since the first mark of this
     * request and the memory in use.
     */
    public static function mark(string $step): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (self::$start === 0.0) {
            self::boot();
        }
        self::$last = $step;
        self::write(sprintf(
            '%7.3fs %5.1fMB %s',
            microtime(true) - self::$start,
            memory_get_usage(true) / 1048576,
            $step
        ));
    }

    /**
     * A step whose duration matters on its own: logs how long $work took, and (unlike a bare
     * mark) still names the step if $work never returns.
     * @return mixed whatever $work returns
     */
    public static function around(string $step, callable $work)
    {
        if (!self::isEnabled()) {
            return $work();
        }
        self::mark('-> ' . $step);
        $before = microtime(true);
        $result = $work();
        self::write(sprintf(
            '%7.3fs %5.1fMB <- %s (took %.3fs)',
            microtime(true) - self::$start,
            memory_get_usage(true) / 1048576,
            $step,
            microtime(true) - $before
        ));
        return $result;
    }

    /** The php error log, plus a file, since an fpm pool often has no error_log of its own. */
    private static function write(string $line): void
    {
        $line = sprintf('[importer %d] %s', getmypid(), $line);
        error_log($line);
        $file = self::logFile();
        if ($file !== null) {
            @file_put_contents($file, date('H:i:s') . ' ' . $line . "\n", FILE_APPEND);
        }
    }

    /**
     * Where the copy of the timeline goes. The wiki's cache dir is the one directory it is
     * always allowed to write to; anything else is a temp dir the admin may not be able to
     * reach, so fall back to it only when the cache dir refuses.
     */
    private static function logFile(): ?string
    {
        if (self::$logFile === false) {
            self::$logFile = null;
            foreach (['cache', sys_get_temp_dir()] as $dir) {
                if (is_dir($dir) && is_writable($dir)) {
                    self::$logFile = $dir . '/importer-timeline.log';
                    break;
                }
            }
        }
        return self::$logFile;
    }

    private static function boot(): void
    {
        self::$start = microtime(true);
        self::write(sprintf(
            '===== %s %s (sapi %s, max_execution_time %s)',
            $_SERVER['REQUEST_METHOD'] ?? '-',
            $_SERVER['REQUEST_URI'] ?? '-',
            PHP_SAPI,
            ini_get('max_execution_time')
        ));
        register_shutdown_function(static function () {
            $error = error_get_last();
            $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($error !== null && in_array($error['type'], $fatals, true)) {
                self::write(sprintf(
                    '%7.3fs DIED after "%s" : %s in %s:%d',
                    microtime(true) - self::$start,
                    self::$last,
                    $error['message'],
                    $error['file'],
                    $error['line']
                ));
                return;
            }
            self::write(sprintf(
                '%7.3fs ===== end (last step "%s")',
                microtime(true) - self::$start,
                self::$last
            ));
        });
    }
}
