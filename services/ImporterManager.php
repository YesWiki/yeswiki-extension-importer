<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
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

    /**
     * Build a dataSources entry for $importer from the generic "{key}{importer}" input fields
     * declared by that importer's getAdminFields(). $input is a plain array keyed the same way
     * whether it comes from $_POST (AdminImportersAction) or a Symfony Request's ParameterBag
     * (ApiController's AJAX field-mapping endpoint), so both callers share this logic.
     */
    public function collectSourceOptionsFromInput(string $importer, array $importerFields, array $input): array
    {
        $available = $this->getAvailableImporters();
        $className = $available[$importer] ?? null;
        $sourceOptions = ['importer' => $importer];
        foreach ($importerFields[$importer] ?? [] as $key => $field) {
            $postKey = $key . $importer;
            if (($field['type'] ?? null) === 'checkbox') {
                $value = !empty($input[$postKey]);
            } elseif (!empty($input[$postKey])) {
                $value = $input[$postKey];
            } else {
                continue;
            }
            if ($className && is_callable([$className, 'normalizeAdminFieldValue'])) {
                $value = $className::normalizeAdminFieldValue($key, $value);
            }
            if (strpos($key, 'auth_') === 0) {
                $sourceOptions['auth'][substr($key, 5)] = $value;
            } else {
                $sourceOptions[$key] = $value;
            }
        }
        if (!empty($input['formId'])) {
            $sourceOptions['formId'] = $input['formId'];
        }
        return $sourceOptions;
    }

    /**
     * Build the remote/local field lists for the mapping table when $importer is pointed at an
     * existing local form: fields come from the importer's fixed getOwnFields() list, or (e.g.
     * YesWikiToYesWiki) are fetched from the remote form referenced in $sourceOptions. Returns
     * null if there's nothing to map against (no own fields and the remote fetch failed).
     */
    public function getFieldMapping(string $importer, array $sourceOptions, array $localForm): ?array
    {
        $available = $this->getAvailableImporters();
        $className = $available[$importer] ?? null;
        $ownFields = $className && is_callable([$className, 'getOwnFields']) ? $className::getOwnFields() : [];
        $remoteFields = !empty($ownFields) ? $ownFields : $this->fetchRemoteFormFields($sourceOptions);
        if (empty($remoteFields)) {
            return null;
        }
        return [
            'remote' => $remoteFields,
            'local' => $this->fieldsAsList($localForm['prepared'] ?? []),
        ];
    }

    /**
     * Log into the remote wiki and fetch its form's fields (key + label), to build the
     * field-mapping table. Returns null on any failure.
     */
    private function fetchRemoteFormFields(array $sourceOptions): ?array
    {
        if (empty($sourceOptions['url']) || empty($sourceOptions['remoteFormId'])) {
            return null;
        }
        $noSSLCheck = !empty($sourceOptions['noSSLCheck']);

        $loginResponse = $this->curl(
            rtrim($sourceOptions['url'], '/') . '/?api/login',
            ['Content-Type: application/x-www-form-urlencoded'],
            true,
            http_build_query([
                'username' => $sourceOptions['auth']['user'] ?? '',
                'password' => $sourceOptions['auth']['password'] ?? '',
            ]),
            $noSSLCheck,
            true
        );
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $loginResponse, $matches);
        $cookie = implode('; ', $matches[1]);

        $formResponse = $this->curl(
            rtrim($sourceOptions['url'], '/') . '/?api/forms/' . $sourceOptions['remoteFormId'],
            ['Cookie: ' . $cookie],
            false,
            [],
            $noSSLCheck
        );
        $remoteForm = json_decode($formResponse, true);
        if (empty($remoteForm['bn_template'])) {
            return null;
        }

        $templateLines = $this->formManager->parseTemplate($remoteForm['bn_template']);
        return $this->fieldsAsList($this->formManager->prepareData(['template' => $templateLines]));
    }

    private function fieldsAsList(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            // skip layout-only fields (tabs, labelhtml, acls, ...): they have no property name
            if ($field && !empty($field->getPropertyName())) {
                $result[] = ['key' => $field->getPropertyName(), 'label' => $field->getLabel()];
            }
        }
        return $result;
    }

    public function syncSource($source, $sourceOptions)
    {
        $startTime = microtime(true);
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
            return $th->getMessage() . ' ' . _t('IMPORTER_ELAPSED_TIME', ['duration' => $this->formatDuration($startTime)]);
        }
        //return [Command::SUCCESS, _t('SOURCE_SUCCESSFULLY_SYNCED', $source)];
        return _t('SOURCE_SUCCESSFULLY_SYNCED', ['source' => $source])
            . ' ' . _t('IMPORTER_ELAPSED_TIME', ['duration' => $this->formatDuration($startTime)]);
    }

    private function formatDuration(float $startTime): string
    {
        return number_format(microtime(true) - $startTime, 2) . 's';
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
            fclose($fp);
        }
        return $destFile;
    }
}
