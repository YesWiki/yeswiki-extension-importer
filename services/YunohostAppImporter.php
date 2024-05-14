<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Wiki;

class YunohostAppImporter extends Importer
{
    protected $source;

    public function __construct(
        string $source,
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        ImporterManager $importerManager,
        FormManager $formManager,
        Wiki $wiki
    ) {
        $this->source = $source;
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
        $this->importerManager = $importerManager;
        $this->formManager = $formManager;
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
        return $config;
    }

    public function authenticate()
    {

        $response = $this->importerManager->curl(
            $this->config['url'] . '/yunohost/portalapi/login',
            [
                'X-Requested-With: YunohostImporter',
                'Accept-Encoding: gzip, deflate, br',
            ],
            true,
            ['credentials' => $this->config['auth']['user'] . ':' . $this->config['auth']['password']],
            (empty($this->config['noSSLCheck']) ? false : $this->config['noSSLCheck']),
            true
        );
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        return $matches[1][0] ?? null;
    }

    public function getData()
    {
        $cookie = $this->authenticate();
        $response = $this->importerManager->curl(
            $this->config['url'] . '/yunohost/portalapi/me',
            [
                'X-Requested-With: YunohostImporter',
                'Accept-Encoding: gzip, deflate, br',
                'Cookie: ' . $cookie
            ],
            false,
            [],
            (empty($this->config['noSSLCheck']) ? false : $this->config['noSSLCheck'])
        );
        $data = json_decode($response, true)['apps'] ?? null;

        return $data ?? null;
    }

    public function mapData($data)
    {
        $preparedData = [];
        if (is_array($data)) {
            foreach ($data as $i => $item) {
                $preparedData[$i]['bf_titre'] = $item['label'];
                $preparedData[$i]['yunohost_app_id'] = $i;
                $preparedData[$i]['bf_description'] = $item['description'][$this->config['lang']];
                $preparedData[$i]['public'] = $item['public'];
                $preparedData[$i]['imagebf_image'] = 'https:' . $item['logo'];
                $preparedData[$i]['bf_url'] = 'https://' . $item['url'];
            }
        }
        return $preparedData;
    }

    public function syncData($data)
    {
        return;
    }

    public function syncFormModel()
    {
        return;
    }
}
