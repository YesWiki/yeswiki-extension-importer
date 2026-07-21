<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Wiki;

abstract class Importer
{
    protected $params;
    protected $services;
    protected $entryManager;
    protected $importerManager;
    protected $formManager;
    protected $listManager;
    protected $wiki;
    protected $config;


    public function __construct(
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        ImporterManager $importerManager,
        FormManager $formManager,
        ListManager $listManager,
        Wiki $wiki
    ) {
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->importerManager = $importerManager;
        $this->formManager = $formManager;
        $this->listManager = $listManager;
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

    public function syncFormModel()
    {
        return;
    }

    public function syncData(array $data)
    {
        return;
    }

    /**
     * Declare the config fields this importer needs, so AdminImportersAction (from this
     * extension) can render/save them for the admin, even when the importer itself lives
     * in another extension.
     * Each entry: 'key' => ['type' => 'text'|'url'|'password'|'checkbox', 'required' => bool]
     * A key prefixed "auth_" is stored nested as config['auth'][...] (e.g. "auth_user" -> auth.user).
     * @return array
     */
    public static function getAdminFields(): array
    {
        return [];
    }

    /**
     * Whether this importer creates/updates Bazar entries and therefore needs a target Bazar formId.
     */
    public static function needsBazarForm(): bool
    {
        return true;
    }

    /**
     * Declare the fixed set of fields this importer writes into an entry, so
     * AdminImportersAction can offer a field-mapping table when the admin points it at an
     * already-existing Bazar form instead of letting it create its own. Only list fields safe
     * to rename: an importer must not include here any key it also relies on programmatically
     * after mapData() (e.g. a dedup/identity key), since applyFieldsMapping() would rename it
     * away. Return [] (the default) if this importer has no fixed field list to offer (e.g. it
     * fetches an arbitrary remote form's fields instead, like YesWikiToYesWikiImporter).
     * Each entry: ['key' => propertyName, 'label' => humanLabel]
     * @return array
     */
    public static function getOwnFields(): array
    {
        return [];
    }

    /**
     * Normalize a raw admin-posted field value before it's stored in config, e.g. to recover
     * from a common paste mistake (a full API url pasted where only the wiki's base url is
     * expected). Default: no-op. Override for fields whose expected shape can be sanitized.
     * @param mixed $value
     * @return mixed
     */
    public static function normalizeAdminFieldValue(string $key, $value)
    {
        return $value;
    }

    // HELPERS
    protected function getService($class)
    {
        return $this->services->get($class);
    }

    /**
     * Rename this importer's own field keys to the local form's field keys, per
     * config['fieldsMapping'] (built from the admin's field-mapping table, itself populated
     * from getOwnFields()). A no-op when no mapping was configured (own form, field-for-field).
     */
    protected function applyFieldsMapping(array $entry): array
    {
        $mapping = $this->config['fieldsMapping'] ?? [];
        if (empty($mapping)) {
            return $entry;
        }
        $mapped = [];
        foreach ($entry as $key => $value) {
            $mapped[$mapping[$key] ?? $key] = $value;
        }
        return $mapped;
    }
}
