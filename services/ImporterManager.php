<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\TemplateEngine;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Wiki;

class ImporterManager
{
    protected $params;
    protected $services;
    protected $entryManager;
    protected $formManager;
    protected $wiki;
    protected $config;


    public function __construct(
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        FormManager $formManager,
        Wiki $wiki
    )
    {
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->formManager = $formManager;
        $this->wiki = $wiki;
        $config = $this->checkConfig($params->get('dataSources'));
        $this->config = $config;
    }

    /**
     * Check if config input is good enough to be used by Importer
     * @param array $config
     * @return array $config checked config
     */
    public function checkConfig(array $config)
    {
        return $config;
    }

    public function getAvailableImporters()
    {
        $services = array_filter($this->wiki->services->getServiceIds(), function ($subject) {
            return preg_match('/Importer$/', $subject);
        });
        $importers = [];
        foreach ($services as $serv) {
            $shortClass = str_replace(['YesWiki\Importer\Service\\', 'YesWiki\Custom\Service\\', 'Importer'], '', $serv);
            $importers[$shortClass] = $serv;
        }
        return $importers;
    }

    private function findClass(string $class)
    {
       if (class_exists($class)) {

       }
    }

    public function syncSource($source, $sourceOptions)
    {
        try {
            $importer = $this->findClass($sourceOptions['importer'].'Importer');    
            $data = $importer->getData();
            $data = $importer->parseData($data);
            $importer->createFormModel();
            $importer->syncData();
        } catch (\Throwable $th) {
            return [Command::INVALID, $th->getMessage()];
        }
        return [Command::SUCCESS, _t('SOURCE_SUCCESSFULLY_SYNCED', $source)];
    }
}
