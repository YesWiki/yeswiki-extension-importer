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

/**
 * Imports Bazar entries from a remote YesWiki's form into a local form.
 *
 * Two sync modes (config['syncMode']):
 * - 'source_of_truth': the local form/lists/entries are a continuous mirror
 *   of the remote ones (including deletions).
 * - 'allow_local': the local form may differ (field mapping via
 *   config['fieldsMapping'] once it pre-exists); lists are merged without
 *   ever removing local-only values; entries are created/updated but never
 *   deleted, and are skipped if they were edited locally since our last sync.
 */
class YesWikiToYesWikiImporter extends Importer
{
    protected $source;
    protected $cookie;
    protected $remoteForm;
    protected $localFormExists;
    protected $listTagPairs = [];
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

    /**
     * Check if config input is good enough to be used by Importer
     * @param array $config
     * @return array $config checked config
     */
    public function checkConfig(array $config)
    {
        $config = parent::checkConfig($config);
        foreach (['url', 'remoteFormId', 'formId', 'syncMode'] as $key) {
            if (empty($config[$key])) {
                exit('Le paramètre "' . $key . '" est requis pour un importer YesWikiToYesWiki.' . "\n");
            }
        }
        if (empty($config['auth']['user']) || empty($config['auth']['password'])) {
            exit('Les paramètres "auth.user" et "auth.password" sont requis pour un importer YesWikiToYesWiki.' . "\n");
        }
        if (!in_array($config['syncMode'], ['source_of_truth', 'allow_local'], true)) {
            exit('Le paramètre "syncMode" doit valoir "source_of_truth" ou "allow_local".' . "\n");
        }
        return $config;
    }

    public static function getAdminFields(): array
    {
        return [
            'url' => ['type' => 'url', 'required' => true],
            'auth_user' => ['type' => 'text', 'required' => true],
            'auth_password' => ['type' => 'password', 'required' => true],
            'remoteFormId' => ['type' => 'text', 'required' => true],
            'localAdminUser' => ['type' => 'text', 'required' => false],
            'syncMode' => [
                'type' => 'select',
                'required' => true,
                'options' => [
                    'source_of_truth' => 'IMPORTER_SYNCMODE_SOURCE_OF_TRUTH',
                    'allow_local' => 'IMPORTER_SYNCMODE_ALLOW_LOCAL',
                ],
            ],
            'noSSLCheck' => ['type' => 'checkbox', 'required' => false],
            'timeoutInSec' => ['type' => 'number', 'required' => false],
        ];
    }

    /**
     * "url" must be the remote wiki's base url (which may include a subfolder, for wikis not
     * installed at their domain's root): remoteUrl() appends its own "/?api/..." path to it. A
     * common mistake is pasting a full API url (e.g. copied from a browser tab while testing)
     * instead, whose "?api/..." query string then silently produces a broken url every request
     * is built from (rtrim() only strips a trailing "/", it won't remove a query already there).
     * Drop only the query/fragment, keeping scheme+host(+port)+path intact, so that mistake
     * self-corrects on save without breaking subfolder installs.
     */
    public static function normalizeAdminFieldValue(string $key, $value)
    {
        if ($key === 'url' && is_string($value) && $value !== '') {
            $parts = parse_url($value);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $value = $parts['scheme'] . '://' . $parts['host']
                    . (!empty($parts['port']) ? ':' . $parts['port'] : '')
                    . ($parts['path'] ?? '');
            }
        }
        return $value;
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
        $form = $this->remoteGet('api/forms/' . $this->config['remoteFormId']);
        $entries = $this->remoteGet('api/forms/' . $this->config['remoteFormId'] . '/entries');
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
            // local mirrors remote field-for-field
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
            $mappedEntry['_remote_date_maj_fiche'] = $remoteEntry['date_maj_fiche'] ?? null;
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
        foreach ($data as $mappedEntry) {
            $remoteUrl = $mappedEntry['_remote_url'];
            $remoteDateMajFiche = $mappedEntry['_remote_date_maj_fiche'] ?? null;
            unset($mappedEntry['_remote_url'], $mappedEntry['_remote_date_maj_fiche']);
            $title = $mappedEntry['bf_titre'] ?? $remoteUrl;
            // required by EntryManager::validate() on both create() and update()
            $mappedEntry['antispam'] = 1;

            try {
                $localId = $this->findLocalEntryByRemoteUrl($remoteUrl);
                if (!empty($localId) && empty($this->entryManager->getOne($localId))) {
                    // stale mapping: the tripleStore still links this remote url to a local
                    // entry that no longer exists (deleted outside this sync, or left over
                    // from an earlier run) - clear it and treat it as "not found" instead of
                    // crashing update() with a null $previousData; otherwise this dead mapping
                    // would keep winning the lookup and re-trigger this same fallback (and
                    // create a fresh duplicate entry) on every future sync
                    $this->getService(TripleStore::class)->delete($localId, TripleStore::SOURCE_URL_URI, null, '', '');
                    $localId = null;
                }

                if (empty($localId)) {
                    $created = $this->entryManager->create($this->config['formId'], $mappedEntry, false, $remoteUrl);
                    if (!empty($created['id_fiche'])) {
                        $seenLocalIds[] = $created['id_fiche'];
                        $this->markSynced($created['id_fiche'], date('Y-m-d H:i:s'));
                        echo 'Entrée "' . $title . '" créée.' . "\n";
                    }
                    continue;
                }

                $seenLocalIds[] = $localId;

                if ($isSourceOfTruth) {
                    if ($remoteDateMajFiche) {
                        $mappedEntry['date_maj_fiche'] = $remoteDateMajFiche;
                    }
                    $this->entryManager->update($localId, $mappedEntry, false, true);
                    echo 'Entrée "' . $title . '" mise à jour (miroir).' . "\n";
                    continue;
                }

                // allow_local: skip if a human edited the entry locally since our last write
                $lastSync = $this->getLastSyncTime($localId);
                $localEntry = $this->entryManager->getOne($localId);
                $localDateMajFiche = $localEntry['date_maj_fiche'] ?? null;
                if ($lastSync !== null && $localDateMajFiche !== null && $localDateMajFiche > $lastSync) {
                    echo 'Entrée "' . $title . '" modifiée localement, non synchronisée.' . "\n";
                    continue;
                }
                $this->entryManager->update($localId, $mappedEntry, false, true);
                $this->markSynced($localId, date('Y-m-d H:i:s'));
                echo 'Entrée "' . $title . '" mise à jour.' . "\n";
            } catch (\Throwable $ex) {
                // one invalid/incompatible remote entry must not abort the whole sync batch
                echo 'Erreur sur l\'entrée "' . $title . '" : ' . $ex->getMessage() . "\n";
            }
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

    // HELPERS

    private function noSSLCheck(): bool
    {
        return !empty($this->config['noSSLCheck']);
    }

    // a full form/entries/list dump can be large, the default 10s curl timeout is often too short
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

    /**
     * True for a field backed by a YesWiki List (checkbox/radio/liste), false for the "*fiche"
     * variants (checkboxfiche/radiofiche/listefiche) that link to another Bazar form's entries
     * instead: both share the EnumField base class and its getLinkedObjectName(), but only the
     * former points to an actual List page tag.
     */
    private function isListBackedField($field): bool
    {
        return $field instanceof EnumField && !$field->isEnumEntryField();
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
        // lists saved before the 2024 {title,nodes} format still store {titre_liste,label} on
        // disk: ListManager::getOne() normalizes this transparently for local reads, but here
        // we fetch the remote page's raw body directly, so we need the same conversion.
        return $this->listManager->convertDataStructure($decoded);
    }

    /**
     * @param array $templateLines parsed template lines (FormManager::parseTemplate())
     * @return array [propertyName => BazarField]
     */
    private function fieldsByProperty(array $templateLines): array
    {
        $fields = $this->formManager->prepareData(['template' => $templateLines]);
        $byProperty = [];
        foreach ($fields as $field) {
            $propertyName = $field ? $field->getPropertyName() : null;
            // skip layout-only fields (tabs, labelhtml, acls, ...): they have no property name
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
            // ActivityPub identity/keys are never copied from a remote instance
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
        $exists = $this->listManager->isList($localTag);

        if ($mirror) {
            if ($exists) {
                $this->listManager->update($localTag, $remoteTitle, $remoteNodes);
            } else {
                $this->listManager->create($remoteTitle, $remoteNodes, $localTag);
            }
            echo 'Liste "' . $localTag . '" synchronisée (miroir) avec ' . count($remoteNodes) . ' valeur(s).' . "\n";
            return;
        }

        // allow_local: non-destructive union, local-only values are never removed
        $existingList = $exists ? $this->listManager->getOne($localTag) : null;
        $byId = [];
        foreach (($existingList['nodes'] ?? []) as $node) {
            $byId[$node['id']] = $node;
        }
        foreach ($remoteNodes as $node) {
            $byId[$node['id']] = $node;
        }
        $mergedNodes = array_values($byId);
        $title = $existingList['title'] ?? $remoteTitle;
        if ($exists) {
            $this->listManager->update($localTag, $title, $mergedNodes);
        } else {
            $this->listManager->create($title, $mergedNodes, $localTag);
        }
        echo 'Liste "' . $localTag . '" fusionnée (total local : ' . count($mergedNodes) . ' valeur(s)).' . "\n";
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

    /**
     * EntryManager::update() (unlike create(), which always bypasses ACLs) enforces the
     * write ACL against the current logged-in user, and our CLI/console sync normally runs
     * with no logged-in user at all: without impersonating a local admin, every update on a
     * pre-existing entry would be rejected.
     */
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
            echo 'Entrée "' . $title . '" supprimée (disparue de la source).' . "\n";
        } catch (\Throwable $ex) {
            echo 'Erreur lors de la suppression de "' . $title . '" : ' . $ex->getMessage() . "\n";
        }
    }
}
