<?php

/**
 * Admin importers.
 */
use YesWiki\Core\Service\FormManager;
use YesWiki\Core\Service\ConfigurationFileProvider;
use YesWiki\Core\Service\ConfigurationService;
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

        $configFile = ConfigurationFileProvider::getConfigFileFromEnv();
        if (!is_writable($configFile)) {
            return $this->render('@templates/alert-message.twig', [
                'type' => 'danger',
                'message' => _t('ERROR_NO_ACCESS') . ' ' . _t('FILE_WRITE_PROTECTED'),
            ]);
        }

        $importerManager = $this->getService(ImporterManager::class);
        $importers = $importerManager->getAvailableImporters();
        $formManager = $this->getService(FormManager::class);
        // each importer class (from this extension or any other) declares its own admin
        // fields via Importer::getAdminFields()/needsBazarForm(), so this action stays
        // extension-agnostic instead of hardcoding a field list per importer name
        $importerFields = [];
        $importersWithoutForm = [];
        // an importer can offer field-mapping either from a fixed field list (getOwnFields(),
        // e.g. Rss/Imap) or by fetching an arbitrary remote form's fields live via the
        // mapping-fields AJAX endpoint (e.g. YesWikiToYesWiki, detected here by its
        // "remoteFormId" admin field)
        $importersWithFieldMapping = [];
        foreach ($importers as $shortName => $className) {
            $fields = is_callable([$className, 'getAdminFields']) ? $className::getAdminFields() : [];
            $importerFields[$shortName] = $fields;
            $needsForm = is_callable([$className, 'needsBazarForm']) ? $className::needsBazarForm() : true;
            if (!$needsForm) {
                $importersWithoutForm[] = $shortName;
            }
            $hasOwnFields = is_callable([$className, 'getOwnFields']) && !empty($className::getOwnFields());
            if ($hasOwnFields || array_key_exists('remoteFormId', $fields)) {
                $importersWithFieldMapping[] = $shortName;
            }
        }

        $config = $this->getService(ConfigurationService::class)->getConfiguration($configFile);
        $config->load();
        $dataSources = isset($config->dataSources) && is_array($config->dataSources) ? $config->dataSources : [];

        $request = $this->wiki->request;

        // the admin no longer types a raw formId: "new" means "create a form, pick its id now"
        if ($request->request->get('formId', '') === 'new') {
            $request->request->set('formId', (string) $formManager->findNewId());
        }

        $message = null;
        $syncOutput = null;
        $syncedSourceId = null;

        $delete = $request->request->get('delete');
        $syncSource = $request->request->get('syncSource');
        $importer = $request->request->get('importer');

        if (!empty($delete) && isset($dataSources[$delete])) {
            unset($dataSources[$delete]);
            $config->dataSources = $dataSources;
            $config->write();
            $message = _t('IMPORTER_SOURCE_DELETED');
        } elseif (!empty($syncSource) && isset($dataSources[$syncSource])) {
            $syncedSourceId = $syncSource;
            // a sync can take a while (remote wikis, large forms/entries/lists); this is an
            // admin-triggered, one-off action so the regular script execution time limit
            // would otherwise cut it short
            set_time_limit(0);
            ob_start();
            $result = $importerManager->syncSource($syncedSourceId, $dataSources[$syncedSourceId]);
            $syncOutput = trim(ob_get_clean() . "\n" . $result);
        } elseif (!empty($importer)) {
            $sourceOptions = $importerManager->collectSourceOptionsFromInput($importer, $importerFields, $request->request->all());
            $id = $request->request->get('id') ?: $this->generateId($importer, $sourceOptions);
            $fieldsMapping = array_filter($request->request->all('fieldsMapping'));
            if (!empty($fieldsMapping)) {
                $sourceOptions['fieldsMapping'] = $fieldsMapping;
            }
            $dataSources[$id] = $sourceOptions;
            $config->dataSources = $dataSources;
            $config->write();
            $message = _t('IMPORTER_SOURCE_SAVED');
        }

        return $this->render('@importer/admin-importers.twig', [
            'currentUrl' => $this->wiki->href(),
            'importers' => $importers,
            'importerFields' => $importerFields,
            'importersWithoutForm' => $importersWithoutForm,
            'importersWithFieldMapping' => $importersWithFieldMapping,
            'forms' => $formManager->getAll(),
            'dataSources' => $dataSources,
            'dataSourcesJson' => json_encode($dataSources, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
            'message' => $message,
            'syncOutput' => $syncOutput,
            'syncedSourceId' => $syncedSourceId,
        ]);
    }

    /**
     * Derive a stable id from the source's type and url, so re-saving the same source
     * (same importer + url) keeps producing the same id instead of a random one.
     */
    public function generateId(string $importer, array $sourceOptions): string
    {
        $url = $sourceOptions['url'] ?? $sourceOptions['imap_server_and_folder'] ?? '';
        return strtolower($importer) . '_' . substr(sha1($importer . '|' . $url), 0, 12);
    }
}
