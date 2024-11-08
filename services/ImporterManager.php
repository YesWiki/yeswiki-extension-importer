<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Wiki;

class ImporterManager
{
    protected $params;
    protected $services;
    protected $entryManager;
    protected $formManager;
    protected $listManager;
    protected $wiki;

    public function __construct(
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        FormManager $formManager,
        ListManager $listManager,
        Wiki $wiki
    ) {
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->formManager = $formManager;
        $this->listManager = $listManager;
        $this->wiki = $wiki;
    }

    public function getAvailableImporters()
    {

        $services = array_filter($this->wiki->services->getServiceIds(), function ($subject) {
            return preg_match('/Importer$/', $subject);
        });

        $importers = [];
        foreach ($services as $serv) {
            $short = explode('Service\\', $serv)[1];
            $shortClass = str_replace(['Importer'], '', $short);
            $importers[$shortClass] = $serv;
        }
        return $importers;
    }

    private function findImporterClass(string $importer, string $source)
    {
        $available = $this->getAvailableImporters();
        if (!empty($available[$importer])) {
            $className = $available[$importer];
        }
        if (!empty($className) && class_exists($className, false)) {
            return new $className(
                $source,
                $this->params,
                $this->services,
                $this->entryManager,
                $this,
                $this->formManager,
                $this->listManager,
                $this->wiki
            );
        }

        return false;
    }

    public function syncSource($source, $sourceOptions)
    {
        try {
            $importer = $this->findImporterClass($sourceOptions['importer'], $source);
            if (!$importer) {
                //return [Command::INVALID, 'Importer ' . $sourceOptions['importer'] . ' not found'];
                return 'Importer ' . $sourceOptions['importer'] . ' not found';
            }
            $data = $importer->getData();
            $data = $importer->mapData($data);
            $importer->syncFormModel();
            $importer->syncData($data);
        } catch (\Throwable $th) {
            //return [Command::INVALID, $th->getMessage()];
            return $th->getMessage();
        }
        //return [Command::SUCCESS, _t('SOURCE_SUCCESSFULLY_SYNCED', $source)];
        return _t('SOURCE_SUCCESSFULLY_SYNCED', ['source' => $source]);
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

    public function downloadFile($sourceUrl, $noSSLCheck = false, $timeoutInSec = 10, $replaceExisting = false)
    {
        $t = explode('/', $sourceUrl);
        $fileName = array_pop($t);
        $destFile = sha1($sourceUrl) . '_' . $fileName;
        $destPath = 'files/' . $destFile;
        if (!file_exists($destPath) || (file_exists($destPath) && $replaceExisting)) {
            $fp = fopen($destPath, 'wb');
            $ch = curl_init($sourceUrl);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutInSec);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutInSec);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            if ($noSSLCheck) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }
            curl_exec($ch);
            $errors = curl_error($ch);
            if (!empty($errors)) {
                var_dump($errors);
            }
            curl_close($ch);
            fclose($fp);
        }
        return $destFile;
    }
}
