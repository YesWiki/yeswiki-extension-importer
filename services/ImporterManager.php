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
        if ($className && is_callable([$className, 'normalizeAdminOptions'])) {
            $sourceOptions = $className::normalizeAdminOptions($sourceOptions);
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

    /**
     * Download $sourceUrl into the wiki's upload directory and return the local file name to
     * store in an entry's file/image field (empty string on failure).
     * $destFileName lets the caller keep the source's own file name (e.g. a YesWiki to YesWiki
     * import, where reusing the remote name keeps the sync idempotent and keeps YesWiki's
     * "{tag}_{field}_{name}" convention readable); it is always sanitized here, since it comes
     * from a remote source. Without it, the name is derived from the url as before.
     */
    public function downloadFile($sourceUrl, $noSSLCheck = false, $timeoutInSec = 10, $replaceExisting = false, $destFileName = null)
    {
        if (empty($sourceUrl)) {
            return '';
        }
        $urlFileName = rawurldecode(basename(parse_url($sourceUrl, PHP_URL_PATH) ?: $sourceUrl));
        $destFile = $this->sanitizeDownloadedFileName($destFileName ?? (sha1($sourceUrl) . '_' . $urlFileName));
        if (empty($destFile)) {
            echo 'Fichier "' . $sourceUrl . '" non téléchargé : nom ou extension de fichier non autorisé.' . "\n";
            return '';
        }
        $destPath = $this->uploadPath() . '/' . $destFile;
        if (file_exists($destPath) && !$replaceExisting) {
            return $destFile;
        }
        // download to a temporary file first: a failed request (404 html page, timeout, ssl
        // error) must not leave a truncated or bogus file behind under the name we are about
        // to store in the entry, where it would then look like a successful download forever
        $tmpPath = $destPath . '.part';
        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            echo 'Impossible d\'écrire dans "' . $this->uploadPath() . '".' . "\n";
            return '';
        }
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
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        clearstatcache(true, $tmpPath);
        if (!empty($errors) || ($httpCode >= 400) || filesize($tmpPath) === 0) {
            unlink($tmpPath);
            echo 'Téléchargement de "' . $sourceUrl . '" échoué'
                . (!empty($errors) ? ' : ' . $errors : ($httpCode ? ' (code http ' . $httpCode . ')' : '')) . '.' . "\n";
            return '';
        }
        rename($tmpPath, $destPath);
        chmod($destPath, 0755);
        return $destFile;
    }

    private function uploadPath(): string
    {
        $attachConfig = $this->params->has('attach_config') ? $this->params->get('attach_config') : [];
        $path = !empty($attachConfig['upload_path']) ? $attachConfig['upload_path'] : 'files';
        return rtrim($path, '/');
    }

    /**
     * Keep a downloaded file's name usable as a plain file name inside the upload directory:
     * it comes from a remote source, so it must not escape that directory nor land there with
     * an extension the wiki would refuse on a regular upload (a server-side executable one
     * above all). Returns '' when the name can't be made safe.
     */
    private function sanitizeDownloadedFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
        $fileName = trim($fileName, '.');
        if ($fileName === '' || strlen($fileName) > 200) {
            return '';
        }
        // YesWiki itself stores some attachments with a trailing "_" on the extension, and
        // strips it back before checking it: do the same, so ".php_" can't slip through
        $extension = preg_replace('/_+$/', '', strtolower(pathinfo($fileName, PATHINFO_EXTENSION)));
        $forbiddenExts = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar',
            'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'com', 'bat', 'htaccess', 'htpasswd',
        ];
        if (in_array($extension, $forbiddenExts, true)) {
            return '';
        }
        // when the wiki declares its authorized extensions (not all YesWiki versions do),
        // hold downloads to the very same list as a manual upload through a bazar field
        $authorizedExts = $this->params->has('authorized-extensions') ? $this->params->get('authorized-extensions') : null;
        if (is_array($authorizedExts) && $extension !== '' && !array_key_exists($extension, $authorizedExts)) {
            return '';
        }
        return $fileName;
    }
}
