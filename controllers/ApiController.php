<?php

namespace YesWiki\Importer\Controller;

use Symfony\Component\Routing\Annotation\Route;
use YesWiki\Core\Service\FormManager;
use YesWiki\Core\ApiResponse;
use YesWiki\Core\YesWikiController;
use YesWiki\Importer\Service\ImporterManager;

class ApiController extends YesWikiController
{
    /**
     * Sync all configured data sources, protected by a shared secret passed in the "secret" header.
     * @Route("/api/sync", methods={"GET"}, options={"acl":{"public"}})
     */
    public function sync()
    {
        $expectedSecret = $this->wiki->config['sync_secret'] ?? null;
        $providedSecret = $this->getRequest()->headers->get('secret');

        if (empty($expectedSecret) || empty($providedSecret) || !hash_equals((string) $expectedSecret, (string) $providedSecret)) {
            return new ApiResponse(['error' => 'Unauthorized'], 401);
        }

        $importerManager = $this->getService(ImporterManager::class);
        $results = [];
        foreach ($this->wiki->config['dataSources'] ?? [] as $source => $sourceOptions) {
            $results[$source] = $importerManager->syncSource($source, $sourceOptions);
        }

        return new ApiResponse($results);
    }

    /**
     * Admin-only AJAX endpoint backing the live field-mapping table on the admin importers
     * page: given the in-progress add/edit form fields (posted with the same "{key}{importer}"
     * convention as AdminImportersAction) and a local formId, returns the remote/local field
     * lists to build the mapping table without a full page submit/reload.
     * @Route("/api/importer/mapping-fields", methods={"POST"}, options={"acl":{"public"}})
     */
    public function mappingFields()
    {
        if (!$this->wiki->UserIsAdmin()) {
            return new ApiResponse(['error' => 'Unauthorized'], 403);
        }

        $request = $this->wiki->request;
        $importer = (string) $request->request->get('importer', '');
        $formId = (string) $request->request->get('formId', '');

        if (empty($importer) || empty($formId) || $formId === 'new') {
            return new ApiResponse(['fieldMapping' => null]);
        }

        $importerManager = $this->getService(ImporterManager::class);
        $importers = $importerManager->getAvailableImporters();
        $className = $importers[$importer] ?? null;
        if (!$className) {
            return new ApiResponse(['fieldMapping' => null]);
        }

        $importerFields = [
            $importer => is_callable([$className, 'getAdminFields']) ? $className::getAdminFields() : [],
        ];
        $sourceOptions = $importerManager->collectSourceOptionsFromInput($importer, $importerFields, $request->request->all());

        $formManager = $this->getService(FormManager::class);
        $localForm = $formManager->getOne($formId);
        $fieldMapping = $localForm ? $importerManager->getFieldMapping($importer, $sourceOptions, $localForm) : null;

        return new ApiResponse(['fieldMapping' => $fieldMapping]);
    }
}
