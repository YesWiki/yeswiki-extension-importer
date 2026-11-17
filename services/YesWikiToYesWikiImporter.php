<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Field\EnumField;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Core\Controller\AuthController;
use YesWiki\Core\Service\PageManager;
use YesWiki\Core\Service\TripleStore;
use YesWiki\Core\Service\UserManager;
use YesWiki\Wiki;

// Imports Bazar entries from a remote YesWiki form into a local form, with optional list and file sync.
class YesWikiToYesWikiImporter extends Importer
{
    private const ORIGIN_URL_URI = 'http://outils-reseaux.org/_vocabulary/originUrl';

    protected $source;
    protected $cookie;
    protected $remoteForm;
    protected $localFormExists;
    protected $listTagPairs = [];
    protected $fileFieldKeys = [];
    protected $impersonationPreviousUser;

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

    // Check if config input is good enough to be used by Importer.
    public function checkConfig(array $config)
    {
        $config = parent::checkConfig($config);
        $parsedUrl = !empty($config['url']) ? self::parseEntriesUrl($config['url']) : null;
        if (!empty($parsedUrl)) {
            $config = array_merge($config, $parsedUrl);
        }
        foreach (['url', 'formId', 'syncMode'] as $key) {
            if (empty($config[$key])) {
                exit('Le paramètre "' . $key . '" est requis pour un importer YesWikiToYesWiki.' . "\n");
            }
        }
        if (empty($config['remoteFormId'])) {
            exit('Le paramètre "url" doit être l\'url de l\'api des fiches du formulaire distant, ' .
                'de la forme "https://mon-wiki-distant.fr/?api/forms/12/entries/json".' . "\n");
        }
        if (empty($config['auth']['user']) || empty($config['auth']['password'])) {
            exit('Les paramètres "auth.user" et "auth.password" sont requis pour un importer YesWikiToYesWiki.' . "\n");
        }
        $config['conflictPolicy'] = $config['conflictPolicy'] ?? EntryMerger::POLICY_LOCAL;
        if (!in_array($config['conflictPolicy'], [EntryMerger::POLICY_LOCAL, EntryMerger::POLICY_REMOTE], true)) {
            exit('Le paramètre "conflictPolicy" doit valoir "local" ou "remote".' . "\n");
        }
        if (!in_array($config['syncMode'], ['source_of_truth', 'allow_local'], true)) {
            exit('Le paramètre "syncMode" doit valoir "source_of_truth" ou "allow_local".' . "\n");
        }
        $config['filesMode'] = $config['filesMode'] ?? 'download';
        if (!in_array($config['filesMode'], ['download', 'url'], true)) {
            exit('Le paramètre "filesMode" doit valoir "download" ou "url".' . "\n");
        }
        return $config;
    }

    public static function getAdminFields(): array
    {
        return [
            'url' => [
                'type' => 'url',
                'required' => true,
                'label' => 'IMPORTER_FIELD_YESWIKITOYESWIKI_URL',
                'help' => 'IMPORTER_FIELD_YESWIKITOYESWIKI_URL_HELP',
            ],
            'auth_user' => ['type' => 'text', 'required' => true],
            'auth_password' => ['type' => 'password', 'required' => true],
            'localAdminUser' => ['type' => 'text', 'required' => false],
            'syncMode' => [
                'type' => 'select',
                'required' => true,
                'options' => [
                    'source_of_truth' => 'IMPORTER_SYNCMODE_SOURCE_OF_TRUTH',
                    'allow_local' => 'IMPORTER_SYNCMODE_ALLOW_LOCAL',
                ],
            ],
            'conflictPolicy' => [
                'type' => 'select',
                'required' => false,
                'help' => 'IMPORTER_FIELD_CONFLICTPOLICY_HELP',
                'options' => [
                    EntryMerger::POLICY_LOCAL => 'IMPORTER_CONFLICTPOLICY_LOCAL',
                    EntryMerger::POLICY_REMOTE => 'IMPORTER_CONFLICTPOLICY_REMOTE',
                ],
            ],
            'filesMode' => [
                'type' => 'select',
                'required' => true,
                'options' => [
                    'download' => 'IMPORTER_FILESMODE_DOWNLOAD',
                    'url' => 'IMPORTER_FILESMODE_URL',
                ],
            ],
            'keepRemoteUpdateDate' => [
                'type' => 'checkbox',
                'required' => false,
                'help' => 'IMPORTER_FIELD_KEEPREMOTEUPDATEDATE_HELP',
            ],
            'noSSLCheck' => ['type' => 'checkbox', 'required' => false],
            'timeoutInSec' => ['type' => 'number', 'required' => false],
        ];
    }

    public static function hasRemoteFieldMapping(): bool
    {
        return true;
    }

    // Build the stored config from the remote entries API url the admin pasted.
    public static function normalizeAdminOptions(array $options): array
    {
        $parsed = !empty($options['url']) ? self::parseEntriesUrl($options['url']) : null;
        if (empty($parsed)) {
            return $options;
        }
        if (empty($parsed['entriesQuery'])) {
            unset($parsed['entriesQuery'], $options['entriesQuery']);
        }
        return array_merge($options, $parsed);
    }

    // Rebuild the entries API url the admin pasted, to prefill the edit form with
    public static function denormalizeAdminOptions(array $options): array
    {
        if (empty($options['url']) || empty($options['remoteFormId'])) {
            return $options;
        }
        $options['url'] = rtrim($options['url'], '/') . '/?api/forms/' . $options['remoteFormId'] . '/entries/json'
            . (empty($options['entriesQuery']) ? '' : '&' . $options['entriesQuery']);
        return $options;
    }

    // Extract the remote wiki url, form id and entries query from an entries API url.
    public static function parseEntriesUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (empty($parts) || empty($parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $handler = '';
        $extraParams = [];
        foreach (array_filter(explode('&', $parts['query'] ?? '')) as $param) {
            if ($handler === '' && (strpos($param, 'api/') === 0 || strpos($param, 'wiki=api/') === 0)) {
                $handler = preg_replace('~^wiki=~', '', $param);
            } else {
                $extraParams[] = $param;
            }
        }
        $handlerIsPath = ($handler === '');
        if ($handlerIsPath) {
            $handler = $path;
        }
        if (!preg_match('~(?:^|/)api/forms/([^/?&]+)/entries~', rawurldecode($handler), $matches)) {
            return null;
        }
        $basePath = $path;
        if ($handlerIsPath) {
            $apiPos = strpos($path, 'api/forms');
            $basePath = $apiPos === false ? '' : substr($path, 0, $apiPos);
        }
        $basePath = preg_replace('~/[^/]*\.php$~', '', rtrim($basePath, '/'));
        return [
            'url' => ($parts['scheme'] ?? 'https') . '://' . $parts['host']
                . (!empty($parts['port']) ? ':' . $parts['port'] : '') . $basePath,
            'remoteFormId' => $matches[1],
            'entriesQuery' => implode('&', $extraParams),
        ];
    }

    public function authenticate()
    {
        $response = $this->importerManager->curl(
            $this->remoteUrl('api/login'),
            ['Content-Type: application/x-www-form-urlencoded'],
            true,
            http_build_query([
                'username' => $this->config['auth']['user'],
                'password' => $this->config['auth']['password'],
            ]),
            $this->noSSLCheck(),
            true,
            $this->timeoutInSec()
        );
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $this->cookie = implode('; ', $matches[1]);
        return $this->cookie;
    }

    public function getData()
    {
        $this->authenticate();
        $entriesPath = 'api/forms/' . $this->config['remoteFormId'] . '/entries';
        if (!empty($this->config['entriesQuery'])) {
            $entriesPath .= '&' . $this->config['entriesQuery'];
        }
        if (!empty($this->wiki->config['base_url'])) {
            $entriesPath .= '&excludeSource=' . rawurlencode($this->wiki->config['base_url']);
        }
        $form = $this->remoteGet('api/forms/' . $this->config['remoteFormId']);
        $entries = $this->remoteGet($entriesPath);
        return [
            'form' => is_array($form) ? $form : [],
            'entries' => is_array($entries) ? $entries : [],
        ];
    }

    public function mapData($data)
    {
        $this->remoteForm = $data['form'] ?? [];
        $remoteEntries = $data['entries'] ?? [];
        $isSourceOfTruth = $this->config['syncMode'] === 'source_of_truth';

        $remoteTemplateLines = $this->formManager->parseTemplate($this->remoteForm['bn_template'] ?? '');
        $remoteFieldsByProperty = $this->fieldsByProperty($remoteTemplateLines);

        $localForm = $this->formManager->getOne($this->config['formId']);
        $this->localFormExists = !empty($localForm);

        if (!$this->localFormExists || $isSourceOfTruth) {
            $mapping = [];
            foreach ($remoteFieldsByProperty as $propertyName => $field) {
                $mapping[$propertyName] = $propertyName;
            }
            $this->listTagPairs = [];
            foreach ($remoteFieldsByProperty as $field) {
                if ($this->isListBackedField($field)) {
                    $tag = $field->getLinkedObjectName();
                    if (!empty($tag)) {
                        $this->listTagPairs[$tag . '|' . $tag] = [$tag, $tag];
                    }
                }
            }
        } else {
            $mapping = $this->config['fieldsMapping'] ?? [];
            if (empty($mapping)) {
                throw new \Exception(
                    'Le paramètre "fieldsMapping" est requis : le formulaire local existe déjà ' .
                    'et le mode "allow_local" nécessite une correspondance de champs ' .
                    '(configurable depuis {{adminimporters}}).'
                );
            }
            $localFieldsByProperty = $this->fieldsByProperty($localForm['template'] ?? []);
            $this->listTagPairs = [];
            foreach ($mapping as $remoteField => $localField) {
                $remoteFieldObj = $remoteFieldsByProperty[$remoteField] ?? null;
                $localFieldObj = $localFieldsByProperty[$localField] ?? null;
                if ($this->isListBackedField($remoteFieldObj) && $this->isListBackedField($localFieldObj)) {
                    $remoteTag = $remoteFieldObj->getLinkedObjectName();
                    $localTag = $localFieldObj->getLinkedObjectName();
                    if (!empty($remoteTag) && !empty($localTag)) {
                        $this->listTagPairs[$remoteTag . '|' . $localTag] = [$remoteTag, $localTag];
                    }
                }
            }
        }

        $this->fileFieldKeys = [];
        foreach ($mapping as $remoteField => $localField) {
            if ($this->isFileBackedField($remoteFieldsByProperty[$remoteField] ?? null)) {
                $this->fileFieldKeys[] = $localField;
            }
        }
        echo empty($this->fileFieldKeys)
            ? 'Aucun champ fichier/image dans la correspondance : les fichiers ne seront pas importés.' . "\n"
            : 'Champs fichier/image (' . $this->filesMode() . ') : ' . implode(', ', $this->fileFieldKeys) . "\n";

        $mappedEntries = [];
        foreach ($remoteEntries as $remoteEntry) {
            if (!is_array($remoteEntry) || empty($remoteEntry['url'])) {
                continue;
            }
            $mappedEntry = [];
            foreach ($mapping as $remoteField => $localField) {
                if (array_key_exists($remoteField, $remoteEntry)) {
                    $mappedEntry[$localField] = $remoteEntry[$remoteField];
                }
            }
            $mappedEntry['_remote_url'] = $remoteEntry['url'];
            $mappedEntry['_remote_created'] = $remoteEntry['date_creation_fiche'] ?? $remoteEntry['created_at'] ?? null;
            $mappedEntry['_remote_updated'] = $remoteEntry['date_maj_fiche'] ?? $remoteEntry['updated_at'] ?? null;
            $mappedEntry['_remote_origin'] = is_string($remoteEntry['origin_url'] ?? null) ? $remoteEntry['origin_url'] : null;
            $mappedEntries[] = $mappedEntry;
        }
        return $mappedEntries;
    }

    public function syncFormModel()
    {
        if ($this->localFormExists) {
            if ($this->config['syncMode'] === 'source_of_truth') {
                $this->formManager->update($this->buildLocalFormData());
                echo 'Le formulaire local a été synchronisé (miroir) avec le formulaire distant.' . "\n";
            } else {
                echo 'Le formulaire local existe déjà, aucune modification (mode allow_local).' . "\n";
            }
            return;
        }
        $this->formManager->create($this->buildLocalFormData());
        echo 'Le formulaire local a été créé à partir du formulaire distant.' . "\n";
    }

    public function syncData($data)
    {
        $impersonating = $this->impersonateLocalAdmin();
        try {
            $this->doSyncData($data);
        } finally {
            $this->endImpersonation($impersonating);
        }
    }

    private function doSyncData($data)
    {
        $isSourceOfTruth = $this->config['syncMode'] === 'source_of_truth';

        foreach ($this->listTagPairs as [$remoteTag, $localTag]) {
            $this->syncList($remoteTag, $localTag, $isSourceOfTruth);
        }

        $seenLocalIds = [];
        $unchangedCount = 0;
        $localIdsByOrigin = $this->localIdsByOrigin();
        foreach ($data as $mappedEntry) {
            $remoteUrl = $mappedEntry['_remote_url'];
            $remoteCreatedAt = $mappedEntry['_remote_created'] ?? null;
            $remoteUpdatedAt = $mappedEntry['_remote_updated'] ?? null;
            $remoteOrigin = $mappedEntry['_remote_origin'] ?? null;
            unset($mappedEntry['_remote_url'], $mappedEntry['_remote_created'], $mappedEntry['_remote_updated'], $mappedEntry['_remote_origin']);
            $title = $mappedEntry['bf_titre'] ?? $remoteUrl;
            $mappedEntry['antispam'] = 1;

            try {
                $localId = $this->findLocalEntryByRemoteUrl($remoteUrl);
                $localEntry = empty($localId) ? null : $this->entryManager->getOne($localId);
                if (!empty($localId) && empty($localEntry)) {
                    $this->getService(TripleStore::class)->delete($localId, TripleStore::SOURCE_URL_URI, null, '', '');
                    $this->getService(TripleStore::class)->delete($localId, self::ORIGIN_URL_URI, null, '', '');
                    $this->getService(TripleStore::class)->delete($localId, $this->baseProperty(), null, '', '');
                    $localId = null;
                }

                if (empty($localId)) {
                    $sameOriginId = $localIdsByOrigin[$remoteOrigin ?? $remoteUrl] ?? null;
                    if (!empty($sameOriginId) && !empty($this->entryManager->getOne($sameOriginId))) {
                        $seenLocalIds[] = $sameOriginId;
                        echo 'Entrée "' . $title . '" déjà présente ici par un autre chemin (fiche ' . $sameOriginId
                            . ') : ignorée, la source qui l\'a importée en premier la met à jour.' . "\n";
                        continue;
                    }
                    $newValues = $this->withRemoteDates($this->importEntryFiles($mappedEntry, null), $remoteCreatedAt, $remoteUpdatedAt);
                    $created = $this->entryManager->create($this->config['formId'], $newValues, false, $remoteUrl);
                    if (!empty($created['id_fiche'])) {
                        $seenLocalIds[] = $created['id_fiche'];
                        $localIdsByOrigin[$remoteOrigin ?? $remoteUrl] = $created['id_fiche'];
                        $this->markSynced($created['id_fiche'], date('Y-m-d H:i:s'));
                        $this->markOrigin($created['id_fiche'], $remoteOrigin);
                        if (!$isSourceOfTruth) {
                            $stored = $this->entryManager->getOne($created['id_fiche'], false, null, false) ?? [];
                            $this->setBase($created['id_fiche'], EntryMerger::base($newValues, $stored, $this->passKeys()));
                        }
                        echo 'Entrée "' . $title . '" créée.' . "\n";
                    }
                    continue;
                }

                $seenLocalIds[] = $localId;
                $this->markOrigin($localId, $remoteOrigin);

                if ($isSourceOfTruth) {
                    $newValues = $this->withRemoteDates($this->importEntryFiles($mappedEntry, $localEntry), $remoteCreatedAt, $remoteUpdatedAt);
                    $changed = $this->changedFields($localEntry, $newValues);
                    if (empty($changed)) {
                        $unchangedCount++;
                        continue;
                    }
                    $this->entryManager->update($localId, $newValues, false, true);
                    echo 'Entrée "' . $title . '" mise à jour (miroir) : ' . implode(', ', $changed) . '.' . "\n";
                    continue;
                }

                $remoteValues = $this->withRemoteDates($this->importEntryFiles($mappedEntry, $localEntry), $remoteCreatedAt, $remoteUpdatedAt);
                $base = $this->getBase($localId);
                if ($base === null && $this->modifiedLocallySinceSync($localId, $localEntry)) {
                    $this->setBase($localId, EntryMerger::base($remoteValues, $remoteValues, $this->passKeys()));
                    echo 'Entrée "' . $title . '" modifiée localement, non synchronisée : '
                        . 'version de référence enregistrée, les prochaines synchros fusionneront.' . "\n";
                    continue;
                }
                $merge = EntryMerger::merge($localEntry, $remoteValues, $base ?? [], $this->config['conflictPolicy'], $this->passKeys());
                foreach ($merge['conflicts'] as $key => $conflict) {
                    $kept = $this->config['conflictPolicy'] === EntryMerger::POLICY_REMOTE ? 'distante' : 'locale';
                    echo 'Entrée "' . $title . '" : conflit sur "' . $key . '", valeur ' . $kept . ' gardée '
                        . '(locale : "' . $this->excerpt($conflict['local']) . '", distante : "' . $this->excerpt($conflict['remote']) . '").' . "\n";
                }
                $changed = $this->changedFields($localEntry, $merge['values']);
                $stored = $localEntry;
                if (empty($changed)) {
                    $unchangedCount++;
                } else {
                    $this->entryManager->update($localId, $merge['values'], false, true);
                    $this->markSynced($localId, date('Y-m-d H:i:s'));
                    $stored = $this->entryManager->getOne($localId, false, null, false) ?? $merge['values'];
                    echo 'Entrée "' . $title . '" mise à jour : ' . implode(', ', $changed) . '.' . "\n";
                }
                $newBase = EntryMerger::storedInto($merge['base'], $stored, $merge['taken']);
                if ($newBase !== $base) {
                    $this->setBase($localId, $newBase);
                }
            } catch (\Throwable $ex) {
                echo 'Erreur sur l\'entrée "' . $title . '" : ' . $ex->getMessage() . "\n";
            }
        }

        if ($unchangedCount > 0) {
            echo $unchangedCount . ' entrée(s) déjà à jour, non réécrite(s).' . "\n";
        }

        if ($isSourceOfTruth) {
            $existingEntries = $this->entryManager->search(['formsIds' => [$this->config['formId']]]);
            foreach ($existingEntries as $entry) {
                if (!in_array($entry['id_fiche'], $seenLocalIds, true)) {
                    $this->deleteLocalEntry($entry['id_fiche'], $entry['bf_titre'] ?? $entry['id_fiche']);
                }
            }
        }
    }

    private function noSSLCheck(): bool
    {
        return !empty($this->config['noSSLCheck']);
    }

    // Timeout for remote calls, longer than curl's default since a full dump can be large.
    private function timeoutInSec(): int
    {
        return !empty($this->config['timeoutInSec']) ? (int) $this->config['timeoutInSec'] : 120;
    }

    private function remoteUrl(string $path): string
    {
        return rtrim($this->config['url'], '/') . '/?' . ltrim($path, '/');
    }

    private function remoteGet(string $path)
    {
        $response = $this->importerManager->curl(
            $this->remoteUrl($path),
            ['Cookie: ' . $this->cookie],
            false,
            [],
            $this->noSSLCheck(),
            false,
            $this->timeoutInSec()
        );
        return json_decode($response, true);
    }

    // True for a list-backed enum field (checkbox/radio/liste), false for the "*fiche" variants.
    private function isListBackedField($field): bool
    {
        return $field instanceof EnumField && !$field->isEnumEntryField();
    }

    // True for a field whose value is an attached file name (image, fichier, and any field deriving from them)
    private function isFileBackedField($field): bool
    {
        for ($class = is_object($field) ? get_class($field) : ''; !empty($class); $class = get_parent_class($class)) {
            $shortName = substr(strrchr('\\' . $class, '\\'), 1);
            if (in_array($shortName, ['FileField', 'ImageField'], true)) {
                return true;
            }
        }
        return false;
    }

    // The entry keys holding the creation and modification dates on the running core
    private function dateKeys(): array
    {
        return defined(get_class($this->entryManager) . '::LEGACY_ENTRY_KEYS')
            ? ['created_at', 'updated_at']
            : ['date_creation_fiche', 'date_maj_fiche'];
    }

    // Stamp an entry about to be written with the dates it carries on the source wiki
    private function withRemoteDates(array $values, ?string $createdAt, ?string $updatedAt): array
    {
        [$createdKey, $updatedKey] = $this->dateKeys();
        if (!empty($createdAt)) {
            $values[$createdKey] = $createdAt;
        }
        if (!empty($updatedAt) && !empty($this->config['keepRemoteUpdateDate'])) {
            $values[$updatedKey] = $updatedAt;
        }
        return $values;
    }

    private function filesMode(): string
    {
        return $this->config['filesMode'] ?? 'download';
    }

    // Replace, in an entry about to be written locally, each remote file name by something the local wiki can serve.
    private function importEntryFiles(array $mappedEntry, ?array $localEntry): array
    {
        foreach ($this->fileFieldKeys as $key) {
            $value = $mappedEntry[$key] ?? null;
            if (is_string($value) && strpos($value, 'defaultimage') === 0) {
                $mappedEntry[$key] = '';
                continue;
            }
            if (empty($value) || !is_string($value) || filter_var($value, FILTER_VALIDATE_URL) !== false) {
                continue;
            }
            $remoteFileUrl = $this->remoteFileUrl($value);
            if ($this->filesMode() === 'url') {
                $mappedEntry[$key] = $remoteFileUrl;
                continue;
            }
            $localFileName = $this->importerManager->downloadFile(
                $remoteFileUrl,
                $this->noSSLCheck(),
                $this->timeoutInSec(),
                false,
                $value,
                empty($this->cookie) ? [] : ['Cookie: ' . $this->cookie]
            );
            $currentValue = $localEntry[$key] ?? '';
            if ($localFileName === '' && !empty($currentValue)) {
                echo 'Fichier "' . $value . '" non récupéré : le fichier local "' . $currentValue . '" est conservé.' . "\n";
                $localFileName = $currentValue;
            }
            $mappedEntry[$key] = $localFileName;
        }
        return $mappedEntry;
    }

    // Url of a file attached to a remote entry
    private function remoteFileUrl(string $fileName): string
    {
        $remotePath = trim($this->config['remoteFilesPath'] ?? 'files', '/');
        return rtrim($this->config['url'], '/') . '/' . $remotePath . '/' . rawurlencode($fileName);
    }

    private function fetchRemotePage(string $tag): ?array
    {
        $page = $this->remoteGet('api/pages/' . rawurlencode($tag));
        if (empty($page['body'])) {
            echo 'Impossible de récupérer la page distante "' . $tag . '" (introuvable, non accessible, ou réponse invalide).' . "\n";
            return null;
        }
        $decoded = json_decode($page['body'], true);
        if (!is_array($decoded)) {
            return null;
        }
        return $this->listManager->convertDataStructure($decoded);
    }

    private function fieldsByProperty(array $templateLines): array
    {
        $fields = $this->formManager->prepareData(['template' => $templateLines]);
        $byProperty = [];
        foreach ($fields as $field) {
            $propertyName = $field ? $field->getPropertyName() : null;
            if (!empty($propertyName)) {
                $byProperty[$propertyName] = $field;
            }
        }
        return $byProperty;
    }

    private function buildLocalFormData(): array
    {
        $remote = $this->remoteForm;
        return [
            'bn_id_nature' => $this->config['formId'],
            'bn_label_nature' => $remote['bn_label_nature'] ?? '',
            'bn_template' => $remote['bn_template'] ?? '',
            'bn_description' => $remote['bn_description'] ?? '',
            'bn_sem_template' => $remote['bn_sem_template'] ?? '',
            'bn_sem_reverse_template' => $remote['bn_sem_reverse_template'] ?? '',
            'bn_activitypub_username' => '',
            'bn_activitypub_private_key' => null,
            'bn_activitypub_public_key' => null,
            'bn_condition' => $remote['bn_condition'] ?? '',
            'bn_only_one_entry' => $remote['bn_only_one_entry'] ?? 'N',
            'bn_only_one_entry_message' => $remote['bn_only_one_entry_message'] ?? null,
        ];
    }

    private function syncList(string $remoteTag, string $localTag, bool $mirror): void
    {
        $remoteList = $this->fetchRemotePage($remoteTag);
        if (empty($remoteList)) {
            return;
        }
        $remoteNodes = $remoteList['nodes'] ?? [];
        $remoteTitle = $remoteList['title'] ?? $localTag;

        if ($mirror) {
            $result = $this->writeList($localTag, $remoteTitle, $remoteNodes);
            echo $result === 'unchanged'
                ? 'Liste "' . $localTag . '" déjà à jour (miroir, ' . count($remoteNodes) . ' valeur(s)), non réécrite.' . "\n"
                : 'Liste "' . $localTag . '" synchronisée (miroir) avec ' . count($remoteNodes) . ' valeur(s).' . "\n";
            return;
        }

        $existingList = $this->listManager->isList($localTag) ? $this->listManager->getOne($localTag) : null;
        $byId = [];
        foreach (($existingList['nodes'] ?? []) as $node) {
            $byId[$node['id']] = $node;
        }
        foreach ($remoteNodes as $node) {
            $byId[$node['id']] = $node;
        }
        $mergedNodes = array_values($byId);
        $title = $existingList['title'] ?? $remoteTitle;
        $result = $this->writeList($localTag, $title, $mergedNodes);
        echo $result === 'unchanged'
            ? 'Liste "' . $localTag . '" déjà à jour (total local : ' . count($mergedNodes) . ' valeur(s)), non réécrite.' . "\n"
            : 'Liste "' . $localTag . '" fusionnée (total local : ' . count($mergedNodes) . ' valeur(s)).' . "\n";
    }

    // The fields writing $newValues would actually change in the local entry.
    private function changedFields(?array $localEntry, array $newValues): array
    {
        if (empty($localEntry)) {
            return array_keys($newValues);
        }
        $changed = [];
        $updatedKey = $this->dateKeys()[1];
        foreach ($newValues as $key => $value) {
            if (in_array($key, ['antispam', $updatedKey], true)) {
                continue;
            }
            if (EntryMerger::comparable($value) !== EntryMerger::comparable($localEntry[$key] ?? null)) {
                $changed[] = $key;
            }
        }
        return $changed;
    }

    // The local entry already holding each origin URL, whichever source imported it first.
    private function localIdsByOrigin(): array
    {
        $tripleStore = $this->getService(TripleStore::class);
        $originById = [];
        foreach ([self::ORIGIN_URL_URI, TripleStore::SOURCE_URL_URI] as $property) {
            foreach ($tripleStore->getMatching(null, $property, null, '=', '=', '=') as $triple) {
                $originById[$triple['resource']] = $originById[$triple['resource']] ?? $triple['value'];
            }
        }
        $idsByOrigin = [];
        foreach ($originById as $id => $origin) {
            $idsByOrigin[$origin] = $idsByOrigin[$origin] ?? (string) $id;
        }
        return $idsByOrigin;
    }

    private function findLocalEntryByRemoteUrl(string $remoteUrl): ?string
    {
        $matches = $this->getService(TripleStore::class)->getMatching(null, TripleStore::SOURCE_URL_URI, $remoteUrl);
        return $matches[0]['resource'] ?? null;
    }

    private function syncTimeProperty(): string
    {
        return 'sync_last_sync_time_' . $this->source;
    }

    private function getLastSyncTime(string $localId): ?string
    {
        return $this->getService(TripleStore::class)->getOne($localId, $this->syncTimeProperty(), '', '');
    }

    private function markSynced(string $localId, string $now): void
    {
        $tripleStore = $this->getService(TripleStore::class);
        $property = $this->syncTimeProperty();
        $existing = $tripleStore->getOne($localId, $property, '', '');
        if ($existing === null) {
            $tripleStore->create($localId, $property, $now, '', '');
        } else {
            $tripleStore->update($localId, $property, $existing, $now, '', '');
        }
    }

    // Keys written as the remote gives them, outside the merge: the spam marker and the entry dates.
    private function passKeys(): array
    {
        return array_merge(['antispam'], $this->dateKeys());
    }

    private function baseProperty(): string
    {
        return 'sync_base_' . $this->source;
    }

    // Fingerprint of the entry as the last import wrote it, or null if none was recorded.
    private function getBase(string $localId): ?array
    {
        $json = $this->getService(TripleStore::class)->getOne($localId, $this->baseProperty(), '', '');
        $base = $json === null ? null : json_decode($json, true);
        return is_array($base) ? $base : null;
    }

    private function setBase(string $localId, array $base): void
    {
        $tripleStore = $this->getService(TripleStore::class);
        $property = $this->baseProperty();
        $existing = $tripleStore->getOne($localId, $property, '', '');
        $json = json_encode($base);
        if ($existing === null) {
            $tripleStore->create($localId, $property, $json, '', '');
        } elseif ($existing !== $json) {
            $tripleStore->update($localId, $property, $existing, $json, '', '');
        }
    }

    // Whether the entry was edited here after the last sync, for entries imported before merging existed.
    private function modifiedLocallySinceSync(string $localId, array $localEntry): bool
    {
        $lastSync = $this->getLastSyncTime($localId);
        $localDateMajFiche = $localEntry['date_maj_fiche'] ?? null;
        return $lastSync !== null && $localDateMajFiche !== null && $localDateMajFiche > $lastSync;
    }

    // A value shortened for the sync log.
    private function excerpt($value): string
    {
        $text = EntryMerger::comparable($value);
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
    }

    // Record where the remote entry was first written, when it was itself imported, so the next wiki can pass it on.
    private function markOrigin(string $localId, ?string $originUrl): void
    {
        $tripleStore = $this->getService(TripleStore::class);
        $existing = $tripleStore->getOne($localId, self::ORIGIN_URL_URI, '', '');
        if ($existing === $originUrl) {
            return;
        }
        if ($existing !== null) {
            $tripleStore->delete($localId, self::ORIGIN_URL_URI, null, '', '');
        }
        if ($originUrl !== null && $originUrl !== '') {
            $tripleStore->create($localId, self::ORIGIN_URL_URI, $originUrl, '', '');
        }
    }

    // Log in as a local admin so update() passes the write ACL; returns whether it did.
    private function impersonateLocalAdmin(): bool
    {
        if (empty($this->config['localAdminUser'])) {
            echo 'Aucun "localAdminUser" configuré : la mise à jour de fiches existantes ' .
                'échouera si leurs droits d\'écriture ne sont pas publics.' . "\n";
            return false;
        }
        $adminUser = $this->getService(UserManager::class)->getOneByName($this->config['localAdminUser']);
        if (!$adminUser) {
            echo 'Utilisateur local "' . $this->config['localAdminUser'] . '" introuvable : ' .
                'la mise à jour de fiches existantes risque d\'échouer par manque de droits.' . "\n";
            return false;
        }
        $authController = $this->getService(AuthController::class);
        $this->impersonationPreviousUser = $authController->getLoggedUser();
        $authController->login($adminUser);
        return true;
    }

    private function endImpersonation(bool $wasImpersonating): void
    {
        if (!$wasImpersonating) {
            return;
        }
        $authController = $this->getService(AuthController::class);
        $authController->logout();
        if (!empty($this->impersonationPreviousUser)) {
            $authController->login($this->impersonationPreviousUser);
        }
    }

    private function deleteLocalEntry(string $localId, string $title): void
    {
        try {
            $this->getService(PageManager::class)->deleteOrphaned($localId);
            $tripleStore = $this->getService(TripleStore::class);
            $tripleStore->delete($localId, TripleStore::TYPE_URI, null, '', '');
            $tripleStore->delete($localId, TripleStore::SOURCE_URL_URI, null, '', '');
            $tripleStore->delete($localId, $this->syncTimeProperty(), null, '', '');
            $tripleStore->delete($localId, self::ORIGIN_URL_URI, null, '', '');
            $tripleStore->delete($localId, $this->baseProperty(), null, '', '');
            echo 'Entrée "' . $title . '" supprimée (disparue de la source).' . "\n";
        } catch (\Throwable $ex) {
            echo 'Erreur lors de la suppression de "' . $title . '" : ' . $ex->getMessage() . "\n";
        }
    }
}
