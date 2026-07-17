<?php

namespace YesWiki\Importer\Controller;

use Symfony\Component\Routing\Annotation\Route;
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
}
