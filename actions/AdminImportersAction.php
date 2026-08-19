<?php

/**
 * Admin importers.
 */
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Core\Service\ConfigurationFileProvider;
use YesWiki\Core\Service\ConfigurationService;
use YesWiki\Core\YesWikiAction;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Importer\Service\ImportTimeline;
use YesWiki\Importer\Service\SyncScheduler;

class AdminImportersAction extends YesWikiAction
{
    public function run()
    {
        ImportTimeline::mark('action: entered run()');
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

        ImportTimeline::mark('action: admin check + is_writable done');
        $importerManager = $this->getService(ImporterManager::class);
        $importers = ImportTimeline::around('getAvailableImporters', function () use ($importerManager) {
            return $importerManager->getAvailableImporters();
        });
        ImportTimeline::mark('action: importers = ' . implode(', ', array_keys($importers)));
        $formManager = $this->getService(FormManager::class);
        // each importer class (from this extension or any other) declares its own admin
        // fields via Importer::getAdminFields()/needsBazarForm(), so this action stays
        // extension-agnostic instead of hardcoding a field list per importer name
        $importerFields = [];
        $importersWithoutForm = [];
        // an importer can offer field-mapping either from a fixed field list (getOwnFields(),
        // e.g. Rss/Imap) or by fetching an arbitrary remote form's fields live via the
        // mapping-fields AJAX endpoint (hasRemoteFieldMapping(), e.g. YesWikiToYesWiki)
        $importersWithFieldMapping = [];
        foreach ($importers as $shortName => $className) {
            ImportTimeline::mark('action: admin fields of ' . $shortName . ' (' . $className . ')');
            $importerFields[$shortName] = $importerManager->getAdminFieldsFor($shortName);
            $needsForm = is_callable([$className, 'needsBazarForm']) ? $className::needsBazarForm() : true;
            if (!$needsForm) {
                $importersWithoutForm[] = $shortName;
            }
            $hasOwnFields = is_callable([$className, 'getOwnFields']) && !empty($className::getOwnFields());
            $hasRemoteFields = is_callable([$className, 'hasRemoteFieldMapping']) && $className::hasRemoteFieldMapping();
            if ($hasOwnFields || $hasRemoteFields) {
                $importersWithFieldMapping[] = $shortName;
            }
        }

        ImportTimeline::mark('action: admin fields collected');
        $config = ImportTimeline::around('load wakka.config.php', function () use ($configFile) {
            $config = $this->getService(ConfigurationService::class)->getConfiguration($configFile);
            $config->load();
            return $config;
        });
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
            $id = $request->request->get('id') ?: $this->newSourceId($importer, $sourceOptions, $dataSources);
            $fieldsMapping = array_filter($request->request->all('fieldsMapping'));
            if (!empty($fieldsMapping)) {
                $sourceOptions['fieldsMapping'] = $fieldsMapping;
            }
            $dataSources[$id] = $sourceOptions;
            $config->dataSources = $dataSources;
            $config->write();
            $message = _t('IMPORTER_SOURCE_SAVED');
        }

        // both the sources table and the edit form show what was typed in, not how it ended up
        // stored (an importer may split one typed value into several config keys)
        ImportTimeline::mark('action: POST branch done');
        $editableDataSources = $this->editableDataSources($dataSources, $importers);
        $autoSync = $this->autoSyncStatus($dataSources);
        ImportTimeline::mark('action: autoSyncStatus read (' . count($dataSources) . ' sources)');
        $this->probeFormsOneByOne($formManager);
        $forms = ImportTimeline::around('form labels', function () use ($formManager) {
            return $this->formLabels($formManager);
        });
        ImportTimeline::mark('action: ' . count($forms) . ' forms listed');

        $templateVars = [
            'currentUrl' => $this->wiki->href(),
            'autoSync' => $autoSync,
            'importers' => $importers,
            'importerFields' => $importerFields,
            'importersWithoutForm' => $importersWithoutForm,
            'importersWithFieldMapping' => $importersWithFieldMapping,
            'forms' => $forms,
            'dataSources' => $editableDataSources,
            'dataSourcesJson' => json_encode($editableDataSources, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
            'message' => $message,
            'syncOutput' => $syncOutput,
            'syncedSourceId' => $syncedSourceId,
        ];

        return ImportTimeline::around('render admin-importers.twig', function () use ($templateVars) {
            return $this->render('@importer/admin-importers.twig', $templateVars);
        });
    }

    /**
     * The "id => label" list the target-form select is built from.
     *
     * Deliberately not FormManager::getAll(): that one loads and fully prepares every form in
     * the wiki, and on a wiki whose forms are expensive to prepare it is what made this page
     * time out (three minutes on hpf, where thirteen forms cost thirteen seconds each). All
     * the select needs is an id and a label.
     *
     * Recent core answers that with one query, and failing that can at least list the ids for
     * one more. Only a core offering neither falls back to getAll(): a select labelled by bare
     * form ids is a worse select, but it is not a page that never loads.
     */
    private function formLabels(FormManager $formManager): array
    {
        if (is_callable([$formManager, 'getAllLabels'])) {
            return $formManager->getAllLabels();
        }
        if (is_callable([$formManager, 'getAllIds'])) {
            $labels = [];
            foreach ($formManager->getAllIds() as $formId) {
                $labels[$formId] = (string) $formId;
            }
            return $labels;
        }
        $labels = [];
        foreach ($formManager->getAll() as $formId => $form) {
            $labels[$formId] = $form['label'] ?? $form['bn_label_nature'] ?? (string) $formId;
        }
        return $labels;
    }

    /**
     * TEMPORARY - with "&importerdebug=forms" in the url, load the forms one at a time so the
     * timeline names the one that hangs. getAll() loads them all inside a single call, so on
     * its own it only tells us that some form, somewhere, is the problem.
     */
    private function probeFormsOneByOne(FormManager $formManager): void
    {
        if (($_GET['importerdebug'] ?? '') !== 'forms' || !is_callable([$formManager, 'getAllIds'])) {
            return;
        }
        $ids = ImportTimeline::around('getAllIds()', function () use ($formManager) {
            return $formManager->getAllIds();
        });
        foreach ($ids as $formId) {
            ImportTimeline::around('getOne(' . $formId . ')', function () use ($formManager, $formId) {
                return $formManager->getOne($formId);
            });
        }
    }

    /**
     * What each source's automatic sync (config 'syncOnMaintenance') has been up to, so that
     * a sync nobody triggered by hand isn't invisible: an admin needs to see that it ran, when,
     * and what it did.
     */
    private function autoSyncStatus(array $dataSources): array
    {
        $scheduler = $this->getService(SyncScheduler::class);
        $status = [];
        foreach ($dataSources as $id => $source) {
            $status[$id] = [
                'enabled' => !empty($source['syncOnMaintenance']),
                'last' => $scheduler->getLastAutoSync((string) $id),
            ];
        }
        return $status;
    }

    /**
     * Turn the stored sources back into what the admin form was filled with, so that editing
     * then re-saving a source unchanged doesn't alter it (an importer may store its config
     * differently from how it's typed in, see Importer::normalizeAdminOptions()).
     */
    private function editableDataSources(array $dataSources, array $importers): array
    {
        $editable = [];
        foreach ($dataSources as $id => $source) {
            $className = $importers[$source['importer'] ?? ''] ?? null;
            $editable[$id] = ($className && is_callable([$className, 'denormalizeAdminOptions']))
                ? $className::denormalizeAdminOptions($source)
                : $source;
        }
        return $editable;
    }

    /**
     * The id of a source being created.
     *
     * generateId() derives an id from the importer and the url so that the same source keeps
     * the same id, but two genuinely different sources can share both: the same remote form
     * imported into two local forms, the same feed imported twice with different settings, the
     * same wiki with two different &query= filters. Creating one of those used to land on the
     * existing source's id and replace it, losing a configured source without saying so, so a
     * created source now takes the next free id instead. Editing a source posts its id and
     * never comes through here.
     */
    private function newSourceId(string $importer, array $sourceOptions, array $dataSources): string
    {
        $baseId = $this->generateId($importer, $sourceOptions);
        $id = $baseId;
        $suffix = 2;
        while (isset($dataSources[$id])) {
            $id = $baseId . '_' . $suffix;
            $suffix++;
        }
        return $id;
    }

    /**
     * Derive a stable id from the source's type and url, so re-saving the same source
     * (same importer + url) keeps producing the same id instead of a random one. Two sources
     * can share a wiki's url while importing different things from it, hence the remote
     * form/list they target being part of the id too.
     */
    public function generateId(string $importer, array $sourceOptions): string
    {
        $url = $sourceOptions['url'] ?? $sourceOptions['imap_server_and_folder'] ?? '';
        $target = $sourceOptions['remoteFormId'] ?? $sourceOptions['listId'] ?? '';
        return strtolower($importer) . '_' . substr(sha1($importer . '|' . $url . '|' . $target), 0, 12);
    }
}
