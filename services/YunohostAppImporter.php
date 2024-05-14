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
        // curl 'https://aleks-test-install-bookworm.test/yunohost/portalapi/login' -X POST -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0' -H 'Accept: application/json' -H 'Accept-Language: fr,fr-FR;q=0.8,en-US;q=0.5,en;q=0.3' -H 'Accept-Encoding: gzip, deflate, br' -H 'Referer: https://aleks-test-install-bookworm.test/yunohost/sso/login' -H 'content-type: application/json' -H 'Origin: https://aleks-test-install-bookworm.test' -H 'Connection: keep-alive' -H 'Sec-Fetch-Dest: empty' -H 'Sec-Fetch-Mode: cors' -H 'Sec-Fetch-Site: same-origin' -H 'TE: trailers' --data-raw '{"credentials":"camille:Yunohost"}'
        echo 'coucou auth';
        return $this->importerManager->curl(
            $this->config['url'].'/yunohost/sso/login',
            [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate, br',
                'content-type: application/json',
            ],
            true,
            '{"credentials":"'.$this->config['auth']['user'].':'.$this->config['auth']['password'].'"}',
            (empty($this->config['noSSLCheck']) ? false : $this->config['noSSLCheck'])
        );
    }

    public function getData()
    {
        var_dump($this->authenticate());
        return $data ?? null;
    }

    public function mapData($data)
    {
        $preparedData = [];
        if (is_array($data)) {
            foreach ($data as $i => $item) {
                $preparedData[$i]['bf_titre'] = $item['title'] . "\n";
                $preparedData[$i]['bf_auteurice'] = $item['author'] . "\n";
                $preparedData[$i]['bf_categories'] = implode(', ', $item['categories']) . "\n";
                $preparedData[$i]['bf_description'] = $item['content'] . "\n";
                $preparedData[$i]['bf_chapeau'] = $item['summary'] . "\n";
                $preparedData[$i]['bf_url'] = $item['link'] . "\n";
                $preparedData[$i]['date_creation_fiche'] = $item['date'] . "\n";
                $preparedData[$i]['imagebf_image'] = $item['image'] . "\n-----\n";
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
