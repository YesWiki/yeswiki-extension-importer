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
            $yeswikiRoot = getcwd();
            $cmd = escapeshellcmd($yeswikiRoot . '/yeswicli') . ' importer:sync 2>&1';
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
