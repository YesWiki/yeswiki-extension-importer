<?php

use YesWiki\Core\YesWikiAction;

class SyncAction extends YesWikiAction
{
    public function run()
    {
        if (!$this->wiki->UserIsAdmin()) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'danger',
                'message' => get_class($this) . ' : ' . _t('BAZ_NEED_ADMIN_RIGHTS'),
            ]);
        }

        $output = null;
        $returnCode = null;

        if (!empty($_POST['sync'])) {
            // a sync can take much longer than a regular request (remote wikis, large
            // forms/entries/lists); exec() blocks this script, so its own execution time
            // limit would otherwise kill this admin-triggered, one-off action
            set_time_limit(0);

            $yeswikiRoot = getcwd();
            // Call the console entrypoint directly instead of the "yeswicli" bash wrapper:
            // that wrapper shells out to a bare "php", which isn't resolvable from a
            // PHP-FPM/webserver process whose PATH doesn't include it (e.g. NixOS), even
            // though it works fine from an interactive shell. PHP_BINDIR reliably points to
            // the CLI's own bin directory regardless of the current SAPI.
            $phpBinary = PHP_BINDIR . '/php';
            if (!is_executable($phpBinary)) {
                $phpBinary = 'php';
            }
            $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($yeswikiRoot . '/includes/commands/console') . ' importer:sync 2>&1';
            exec($cmd, $outputLines, $returnCode);
            $output = implode("\n", $outputLines);
        }

        return $this->render('@importer/sync.twig', [
            'currentUrl' => $this->wiki->href(),
            'output' => $output,
            'returnCode' => $returnCode,
        ]);
    }
}
