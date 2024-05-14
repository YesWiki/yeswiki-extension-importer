<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Core\Service\AclService;
use YesWiki\Core\Service\TemplateEngine;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Wiki;

abstract class Importer
{
    protected $params;
    protected $services;
    protected $entryManager;
    protected $importerManager;
    protected $formManager;
    protected $wiki;
    protected $config;


    public function __construct(
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        ImporterManager $importerManager,
        FormManager $formManager,
        Wiki $wiki
    ) {
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->importerManager = $importerManager;
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

    public function authenticate()
    {
        return;
    }

    public function getData()
    {
        return;
    }

    public function parseData(array $data)
    {
        return;
    }

    public function createFormModel()
    {
        return;
    }

    public function syncData(array $data)
    {
        return;
    }

    // HELPERS
    protected function getService($class)
    {
        return $this->services->get($class);
    }
}
