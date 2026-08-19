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
 * Delete this file (and its mark() calls) once the timeout is understood.
 */
class ImportTimeline
{
    private static $enabled = null;
    private static $start = 0.0;
    private static $last = 'request start';

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
        error_log(sprintf(
            '[importer %d] %7.3fs %5.1fMB %s',
            getmypid(),
            microtime(true) - self::$start,
            memory_get_usage(true) / 1048576,
            $step
        ));
    }

    private static function boot(): void
    {
        self::$start = microtime(true);
        error_log(sprintf(
            '[importer %d] ===== %s %s (sapi %s, max_execution_time %s)',
            getmypid(),
            $_SERVER['REQUEST_METHOD'] ?? '-',
            $_SERVER['REQUEST_URI'] ?? '-',
            PHP_SAPI,
            ini_get('max_execution_time')
        ));
        register_shutdown_function(static function () {
            $error = error_get_last();
            $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($error !== null && in_array($error['type'], $fatals, true)) {
                error_log(sprintf(
                    '[importer %d] %7.3fs DIED after "%s" : %s in %s:%d',
                    getmypid(),
                    microtime(true) - self::$start,
                    self::$last,
                    $error['message'],
                    $error['file'],
                    $error['line']
                ));
                return;
            }
            error_log(sprintf(
                '[importer %d] %7.3fs ===== end (last step "%s")',
                getmypid(),
                microtime(true) - self::$start,
                self::$last
            ));
        });
    }
}
