<?php

/**
 * Admin importers.
 */
use YesWiki\Core\YesWikiAction;
use YesWiki\Importer\Service\ImporterManager;

class AdminImportersAction extends YesWikiAction
{
    public function run()
    {
        if (!$this->wiki->UserIsAdmin()) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'danger',
                'message' => get_class($this) . ' : ' . _t('BAZ_NEED_ADMIN_RIGHTS'),
            ]);
        }
        if (!empty($_POST)) {
            if (empty($_POST['id'])) {
                // generate a unique key if not exists
                $_POST['id'] = $this->generateId();
            }
            $keys = ['url'];
            foreach ($keys as $key) {
                if (!empty($_POST[$key.$_POST['importer']])) {
                    $_POST[$key] = $_POST[$key.$_POST['importer']];
                    unset($_POST[$key.$_POST['importer']]);
                }
            }
            dump($_POST);

        }
        $importerManager = $this->getService(ImporterManager::class);
        $importers = $importerManager->getAvailableImporters();
        return $this->render('@importer/admin-importers.twig', [
            'currentUrl' => $this->wiki->href(),
            'importers' => $importers
        ]);
    }

    public function generateId(): string
    {
        $data = random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
