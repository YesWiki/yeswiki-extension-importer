<?php

/**
 * Admin importers.
 */
use YesWiki\Core\Service\ConfigurationFileProvider;
use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Core\YesWikiAction;
use YesWiki\Importer\Service\ImporterManager;

class AdminImportersAction extends YesWikiAction
{
    // fields that are posted as "{field}{importer}" (e.g. "urlYesWikiList") and stored under "{field}"
    private const IMPORTER_SPECIFIC_KEYS = ['url', 'listId', 'title'];

    public function run()
    {
        if (!$this->wiki->UserIsAdmin()) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'danger',
                'message' => get_class($this) . ' : ' . _t('BAZ_NEED_ADMIN_RIGHTS'),
            ]);
        }

        $configFile = ConfigurationFileProvider::getConfigFileFromEnv();
        if (!is_writable($configFile)) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'danger',
                'message' => _t('ERROR_NO_ACCESS') . ' ' . _t('FILE_WRITE_PROTECTED'),
            ]);
        }

        $config = $this->getService(ConfigurationService::class)->getConfiguration($configFile);
        $config->load();
        $dataSources = isset($config->dataSources) && is_array($config->dataSources) ? $config->dataSources : [];

        $message = null;
        if (!empty($_POST['delete']) && isset($dataSources[$_POST['delete']])) {
            unset($dataSources[$_POST['delete']]);
            $config->dataSources = $dataSources;
            $config->write();
            $message = _t('IMPORTER_SOURCE_DELETED');
        } elseif (!empty($_POST['importer'])) {
            $id = !empty($_POST['id']) ? $_POST['id'] : $this->generateId();
            $sourceOptions = ['importer' => $_POST['importer']];
            foreach (self::IMPORTER_SPECIFIC_KEYS as $key) {
                if (!empty($_POST[$key . $_POST['importer']])) {
                    $sourceOptions[$key] = $_POST[$key . $_POST['importer']];
                }
            }
            if (!empty($_POST['formId'])) {
                $sourceOptions['formId'] = $_POST['formId'];
            }
            $dataSources[$id] = $sourceOptions;
            $config->dataSources = $dataSources;
            $config->write();
            $message = _t('IMPORTER_SOURCE_SAVED');
        }

        $importerManager = $this->getService(ImporterManager::class);
        $importers = $importerManager->getAvailableImporters();
        return $this->render('@importer/admin-importers.twig', [
            'currentUrl' => $this->wiki->href(),
            'importers' => $importers,
            'dataSources' => $dataSources,
            'message' => $message,
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
