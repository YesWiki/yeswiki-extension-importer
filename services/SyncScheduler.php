<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Syncs, without any external cron, the data sources flagged "syncOnMaintenance", on the
 * cadence of YesWiki's own maintenance housekeeping.
 *
 * ## Why this isn't simply plugged into core maintenance
 *
 * Core's maintenance is a hardcoded list of core tasks (purge referrers, purge old page
 * revisions, and on recent versions expired keys and the search index drain) run from inside
 * an ordinary page view. It dispatches no event and reads no list of extension tasks, so
 * there is nothing for an extension to register into - on either generation of YesWiki:
 *
 * - doryphore (4.x): `Wiki::Run()` calls `Maintenance()` on roughly one request in nine
 *   (`intval(GetMicroTime()) % 9`), keeping no state at all.
 * - recent core: `YesWikiRuntime::doRun()` runs it at most once every 30 minutes, gated on
 *   the mtime of the "maintenance.lock" file it touches in the cache directory.
 *
 * That lock file is the one usable trace: where it exists, a source is due as soon as core
 * has swept since we last synced it, so imports land right after core's own housekeeping.
 * Where it doesn't (4.x, whose maintenance leaves nothing behind), we keep the same cadence
 * on our own clock instead - the point of the setting is "sync itself periodically, without a
 * cron", and that holds either way.
 *
 * ## Nothing runs inside the visitor's page
 *
 * The visitor whose page view happens to cross the interval must not pay for a sync that
 * talks to remote wikis for minutes. Sources are only *claimed* during the request (which
 * also keeps two concurrent requests from syncing the same source twice) and synced once the
 * response has been sent - see triggerAfterResponse().
 */
class SyncScheduler
{
    /** Cadence used when core's maintenance lock is unreadable: core's own interval. */
    public const FALLBACK_INTERVAL_SEC = 1800;

    /** Touched by core right before each of its maintenance sweeps, inside the cache dir. */
    private const CORE_MAINTENANCE_LOCK = 'maintenance.lock';

    /** Where this extension keeps one "last automatic sync" file per source. */
    private const STATE_DIR = 'importer';

    /** A sync's kept output, tail first: a log nobody rotates must not grow forever. */
    private const MAX_LOG_LENGTH = 20000;

    protected $params;
    protected $services;
    private $triggered = false;

    public function __construct(ParameterBagInterface $params, ContainerInterface $services)
    {
        $this->params = $params;
        $this->services = $services;
    }

    /**
     * Claim whatever is due and sync it once this request's response has been sent. Called
     * from whichever per-request hook the running YesWiki offers (see the "performable.before"
     * subscriber and the doryphore "__show" handler callback): both may exist, only one fires,
     * and calling this twice in a request is a no-op anyway.
     */
    public function triggerAfterResponse(): void
    {
        if ($this->triggered) {
            return;
        }
        $this->triggered = true;
        // the console (importer:sync and every other command) has no page to serve and no
        // reason to trigger a background sync of its own
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $dueSources = $this->claimDueSources();
        } catch (\Throwable $ex) {
            return; // scheduling an import must never break the page it was noticed from
        }
        if (empty($dueSources)) {
            return;
        }
        register_shutdown_function(function () use ($dueSources) {
            $this->runAfterResponse($dueSources);
        });
    }

    private function runAfterResponse(array $dueSources): void
    {
        // the visitor has their page: let go of their connection where php-fpm allows it, and
        // keep going even when the browser hangs up, so a sync isn't left half applied
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        // syncing several sources against remote wikis is exactly the kind of work the
        // regular request time limit is meant to stop; this one is deliberate
        @set_time_limit(0);
        try {
            $this->run($dueSources);
        } catch (\Throwable $ex) {
            // run() already swallows per-source errors; this is the last resort
        }
    }

    /**
     * The sources due for an automatic sync right now, already claimed (their state file is
     * touched before returning, so a concurrent request sees them as just-synced instead of
     * starting the same sync in parallel).
     * @return array [sourceId => sourceOptions]
     */
    public function claimDueSources(): array
    {
        $coreSweptAt = $this->coreMaintenanceTime();
        $due = [];
        foreach ($this->dataSources() as $id => $options) {
            if (empty($options['syncOnMaintenance'])) {
                continue;
            }
            $id = (string) $id;
            $lastRun = $this->lastRunTime($id);
            // optional per-source floor, for a source too heavy for every maintenance sweep
            $minIntervalInSec = max(0, (int) ($options['syncIntervalInMin'] ?? 0)) * 60;
            if ($minIntervalInSec > 0 && (time() - $lastRun) < $minIntervalInSec) {
                continue;
            }
            if ($coreSweptAt !== null) {
                if ($lastRun >= $coreSweptAt) {
                    continue; // core hasn't swept since our last sync of this source
                }
            } elseif ((time() - $lastRun) < self::FALLBACK_INTERVAL_SEC) {
                continue;
            }
            if (!$this->claim($id)) {
                continue; // no writable state file: we could not tell this run from the next
            }
            $due[$id] = $options;
        }
        return $due;
    }

    /**
     * Sync the given sources and record their output. Never throws and never prints: it runs
     * on somebody's page request (after the response), where an exception would be a fatal
     * error in a page that was never about importing anything, and where a stray echo would
     * land in the middle of that page's html.
     * @param array $sources [sourceId => sourceOptions], as returned by claimDueSources()
     */
    public function run(array $sources): void
    {
        if (empty($sources)) {
            return;
        }
        $importerManager = $this->services->get(ImporterManager::class);
        foreach ($sources as $id => $options) {
            ob_start();
            try {
                // syncSource() returns the outcome and echoes the per-entry detail
                $result = $importerManager->syncSource((string) $id, $options);
            } catch (\Throwable $ex) {
                $result = 'Erreur : ' . $ex->getMessage();
            }
            $output = trim(ob_get_clean() . "\n" . $result);
            $this->recordRun((string) $id, $output);
        }
    }

    /**
     * What the last automatic sync of $source did, or null if it never ran.
     * @return array|null ['time' => int timestamp, 'output' => string]
     */
    public function getLastAutoSync(string $source): ?array
    {
        $file = $this->stateFile($source);
        if ($file === null || !is_file($file)) {
            return null;
        }
        return [
            'time' => (int) filemtime($file),
            'output' => (string) file_get_contents($file),
        ];
    }

    // HELPERS

    private function dataSources(): array
    {
        $dataSources = $this->params->has('dataSources') ? $this->params->get('dataSources') : [];
        return is_array($dataSources) ? $dataSources : [];
    }

    /**
     * When core last ran its maintenance sweep, or null if that can't be told from here.
     */
    private function coreMaintenanceTime(): ?int
    {
        $lock = $this->cachePath() . '/' . self::CORE_MAINTENANCE_LOCK;
        $time = is_file($lock) ? @filemtime($lock) : false;
        return $time === false ? null : (int) $time;
    }

    private function lastRunTime(string $source): int
    {
        $file = $this->stateFile($source);
        $time = ($file !== null && is_file($file)) ? @filemtime($file) : false;
        return $time === false ? 0 : (int) $time;
    }

    /**
     * Take this source's slot for the current run by stamping its state file now, before the
     * sync itself (which happens after the response, and may take minutes): the point of the
     * stamp is that no other request starts the same sync meanwhile.
     */
    private function claim(string $source): bool
    {
        $file = $this->stateFile($source);
        if ($file === null) {
            return false;
        }
        if (!is_file($file)) {
            return @file_put_contents($file, '') !== false;
        }
        return @touch($file);
    }

    private function recordRun(string $source, string $output): void
    {
        $file = $this->stateFile($source);
        if ($file === null) {
            return;
        }
        if (strlen($output) > self::MAX_LOG_LENGTH) {
            $output = '[...]' . substr($output, -self::MAX_LOG_LENGTH);
        }
        @file_put_contents($file, $output);
    }

    /**
     * Path of $source's "last automatic sync" file, or null if the directory it belongs in
     * can't be created (a read-only cache dir just means no automatic sync on this wiki).
     */
    private function stateFile(string $source): ?string
    {
        // source ids are generated by AdminImportersAction, but a hand-written config can use
        // anything as a key, and it ends up in a file name here
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $source);
        if ($name === '' || $name === null) {
            return null;
        }
        $dir = $this->cachePath() . '/' . self::STATE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        return $dir . '/' . $name . '.log';
    }

    private function cachePath(): string
    {
        $attachConfig = $this->params->has('attach_config') ? $this->params->get('attach_config') : [];
        $path = !empty($attachConfig['cache_path']) ? $attachConfig['cache_path'] : 'cache';
        return rtrim($path, '/');
    }
}
