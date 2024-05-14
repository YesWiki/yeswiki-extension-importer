<?php

namespace YesWiki\Importer\Service;

use Exception;
use Throwable;
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

    public function __construct(
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        FormManager $formManager,
        Wiki $wiki
    ) {
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->formManager = $formManager;
        $this->wiki = $wiki;
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

    private function findImporterClass(string $importer, string $source)
    {
        $classPrefixes = ['YesWiki\Importer\Service\\', 'YesWiki\Custom\Service\\'];
        foreach ($classPrefixes as $prefix) {
            $className = $prefix . $importer . 'Importer';
            if (class_exists($className)) {
                return new $className(
                    $source,
                    $this->params,
                    $this->services,
                    $this->entryManager,
                    $this,
                    $this->formManager,
                    $this->wiki
                );
            }
        }
        return false;
    }

    public function syncSource($source, $sourceOptions)
    {
        try {
            $importer = $this->findImporterClass($sourceOptions['importer'], $source);
            if (!$importer) {
                return [Command::INVALID, 'Importer ' . $sourceOptions['importer'] . ' not found'];
            }
            $data = $importer->getData();
            $data = $importer->mapData($data);
            $importer->createFormModel();
            $importer->syncData($data);
        } catch (\Throwable $th) {
            return [Command::INVALID, $th->getMessage()];
        }
        return [Command::SUCCESS, _t('SOURCE_SUCCESSFULLY_SYNCED', $source)];
    }

    public function curl($url, $headers = [], $isPost = false, $postData = null, $noSSLCheck = false, $showHeader = false, $timeoutInSec = 10)
    {
        $ch = curl_init($url);
        if ($showHeader) {
            curl_setopt($ch, CURLOPT_HEADER, 1);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutInSec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutInSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, $isPost);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($postData) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        if ($noSSLCheck) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $errors = curl_error($ch);
        if (!empty($errors)) {
            var_dump($errors);
        }
        return $response;
    }
}
