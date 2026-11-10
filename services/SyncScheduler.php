<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

// Syncs the sources flagged "syncOnMaintenance" on the cadence of core maintenance, without a cron.
class SyncScheduler
{
    public const FALLBACK_INTERVAL_SEC = 1800;

    private const CORE_MAINTENANCE_LOCK = 'maintenance.lock';

    private const STATE_DIR = 'importer';

    private const MAX_LOG_LENGTH = 20000;

    protected $params;
    protected $services;
    private $triggered = false;

    public function __construct(ParameterBagInterface $params, ContainerInterface $services)
    {
        $this->params = $params;
        $this->services = $services;
    }

    // Claim whatever is due and sync it once this request's response has been sent
    public function triggerAfterResponse(): void
    {
        if ($this->triggered) {
            return;
        }
        $this->triggered = true;
        if (PHP_SAPI === 'cli') {
            return;
        }
        ImportTimeline::mark('scheduler: claiming due sources');
        try {
            $dueSources = $this->claimDueSources();
        } catch (\Throwable $ex) {
            ImportTimeline::mark('scheduler: claim failed (' . $ex->getMessage() . ')');
            return;
        }
        ImportTimeline::mark('scheduler: ' . count($dueSources) . ' source(s) due: ' . implode(', ', array_keys($dueSources)));
        if (empty($dueSources)) {
            return;
        }
        $wikiRoot = getcwd();
        register_shutdown_function(function () use ($dueSources, $wikiRoot) {
            $this->runAfterResponse($dueSources, $wikiRoot);
        });
    }

    private function runAfterResponse(array $dueSources, $wikiRoot): void
    {
        if ($wikiRoot !== false && getcwd() !== $wikiRoot) {
            ImportTimeline::mark('scheduler: working directory was ' . getcwd() . ', back to ' . $wikiRoot);
            @chdir($wikiRoot);
        }
        ImportTimeline::mark('scheduler: after-response sync starting (fastcgi_finish_request '
            . (function_exists('fastcgi_finish_request') ? 'available' : 'MISSING - the browser waits for this') . ')');
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @set_time_limit(0);
        try {
            $this->run($dueSources);
        } catch (\Throwable $ex) {
        }
        ImportTimeline::mark('scheduler: after-response sync finished');
    }

    // The sources due for an automatic sync, already claimed so a concurrent request skips them.
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
            $minIntervalInSec = max(0, (int) ($options['syncIntervalInMin'] ?? 0)) * 60;
            if ($minIntervalInSec > 0 && (time() - $lastRun) < $minIntervalInSec) {
                continue;
            }
            if ($coreSweptAt !== null) {
                if ($lastRun >= $coreSweptAt) {
                    continue;
                }
            } elseif ((time() - $lastRun) < self::FALLBACK_INTERVAL_SEC) {
                continue;
            }
            if (!$this->claim($id)) {
                continue;
            }
            $due[$id] = $options;
        }
        return $due;
    }

    // Sync the given sources and record their output
    public function run(array $sources): void
    {
        if (empty($sources)) {
            return;
        }
        $importerManager = $this->services->get(ImporterManager::class);
        foreach ($sources as $id => $options) {
            ob_start();
            try {
                $result = $importerManager->syncSource((string) $id, $options);
            } catch (\Throwable $ex) {
                $result = 'Erreur : ' . $ex->getMessage();
            }
            $output = trim(ob_get_clean() . "\n" . $result);
            $this->recordRun((string) $id, $output);
            ImportTimeline::mark('scheduler: synced source ' . $id);
        }
    }

    // What the last sync of $source did, or null if it never ran
    public function getLastSync(string $source): ?array
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

    private function dataSources(): array
    {
        $dataSources = $this->params->has('dataSources') ? $this->params->get('dataSources') : [];
        return is_array($dataSources) ? $dataSources : [];
    }

    // When core last ran its maintenance sweep, or null if that can't be told from here
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

    // Stamp the source's state file before syncing, so no other request starts the same sync.
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

    // Record that $source has just been synced, and what it printed
    public function recordRun(string $source, string $output): void
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

    // Path of the source's "last sync" file, or null if its directory can't be created.
    private function stateFile(string $source): ?string
    {
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
