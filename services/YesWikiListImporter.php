<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Wiki;

class YesWikiListImporter extends Importer
{
    protected $source;

    public function __construct(
        string $source,
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        ImporterManager $importerManager,
        FormManager $formManager,
        ListManager $listManager,
        Wiki $wiki
    ) {
        $this->source = $source;
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->importerManager = $importerManager;
        $this->formManager = $formManager;
        $this->listManager = $listManager;
        $this->wiki = $wiki;
        $config = $this->checkConfig($params->get('dataSources')[$this->source]);
        $this->config = $config;
    }

    /**
     * Check if config input is good enough to be used by Importer
     * @param array $config
     * @return array $config checked config
     */
    public function checkConfig(array $config)
    {
        $config = parent::checkConfig($config);
        if (empty($config['url'])) {
            exit('Le paramètre "url" est requis pour un importer YesWikiList.' . "\n");
        }
        if (empty($config['listId'])) {
            exit('Le paramètre "listId" est requis pour un importer YesWikiList.' . "\n");
        }
        return $config;
    }

    public function getData()
    {
        $response = $this->importerManager->curl(
            $this->config['url'],
            [],
            false,
            [],
            (empty($this->config['noSSLCheck']) ? false : $this->config['noSSLCheck'])
        );
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    // only id_fiche and bf_titre are used to build the list's key/label pairs
    public function mapData($data)
    {
        $nodes = [];
        foreach ($data as $item) {
            if (empty($item['id_fiche']) || !isset($item['bf_titre']) || $item['bf_titre'] === '') {
                continue;
            }
            $nodes[] = [
                'id' => $item['id_fiche'],
                'label' => $item['bf_titre'],
            ];
        }
        usort($nodes, function ($a, $b) {
            return strcmp(
                strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $a['label'])),
                strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $b['label']))
            );
        });
        return $nodes;
    }

    public function syncData($data)
    {
        $listId = $this->config['listId'];
        $title = $this->config['title'] ?? $listId;
        if ($this->listManager->isList($listId)) {
            $this->listManager->update($listId, $title, $data);
            echo 'La liste "' . $listId . '" a été mise à jour avec ' . count($data) . ' valeur(s).' . "\n";
        } else {
            $this->listManager->create($title, $data, $listId);
            echo 'La liste "' . $listId . '" a été créée avec ' . count($data) . ' valeur(s).' . "\n";
        }
        return;
    }
}
