<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Wiki;

class YunohostAppImporter extends Importer
{
    protected $source;
    protected $databaseForms;
    protected $databaseLists;

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
        $this->databaseForms = [
            [
                "bn_id_nature" => null,
                "bn_label_nature" =>  "Applications Yunohost",
                "bn_description" =>  "Les applications disponibles dans le yunohost",
                "bn_condition" =>  "",
                "bn_sem_context" =>  "",
                "bn_sem_type" =>  "",
                "bn_sem_use_template" =>  "1",
                "bn_template" =>  <<<EOT
texte***bf_titre***Nom de l'application***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***
image***bf_image***Logo***400***400***1000***1000***right***0*** *** *** * *** * *** *** *** ***
textelong***bf_description***Description de l'application***80***12*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
liste***ListeVisibilite***Visibilité de l'application*** *** *** *** *** ***1*** *** *** * *** * *** *** *** ***
texte***yunohost_app_id***Identifiant de l'application Yunohost***255***255*** *** *** ***0*** *** *** * *** * *** *** *** ***
lien_internet***bf_url***Url d'accès au service*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***
EOT,
                "bn_ce_i18n" =>  "fr-FR",
                "bn_only_one_entry" =>  "N",
                "bn_only_one_entry_message" =>  null
            ]
        ];

        $this->databaseLists = [
            'ListeVisibilite' =>  [
                "titre_liste" => "Visibilité",
                "label" => [
                    "pub" => "Publique",
                    "priv" => "Privée"
                ]
            ]
        ];
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
                $preparedData[$i]['listeListeVisibilite'] = $item['public'] ? 'pub' : 'priv';
                $preparedData[$i]['imagebf_image'] = $this->importerManager->downloadFile('https:' . $item['logo'], $this->config['noSSLCheck']);
                $preparedData[$i]['bf_url'] = 'https://' . $item['url'];
            }
        }
        return $preparedData;
    }

    public function syncData($data)
    {
        $existingEntries = $this->entryManager->search(['formIds' => [$this->config['formId']]]);
        foreach ($data as $entry) {
            $res = multiArraySearch($existingEntries, 'yunohost_app_id', $entry['yunohost_app_id']);
            if (!$res) {
                $entry['antispam'] = 1;
                $this->entryManager->create($this->config['formId'], $entry);
            } else {
                echo 'L\'application "' . $entry['bf_titre'] . '" existe déja.' . "\n";
            }
        }
        return;
    }

    public function syncFormModel()
    {
        // test if the lists exist, if not, install them
        foreach ($this->databaseLists as $tag => $list) {
            $liste = $this->listManager->getOne($tag);
            if (empty($liste)) {
                // TODO : comment etre sur de l'id ?
                $this->listManager->create($list['titre_liste'], $list['label']);
            } else {
                echo 'La liste "' . $list['titre_liste'] . '" existe deja.' . "\n";
                // test if compatible
            }
        }
        // test if the form exists, if not, install it
        $form = $this->formManager->getOne($this->config['formId']);
        if (empty($form)) {
            $this->databaseForms[0]['bn_id_nature'] = $this->config['formId'];
            $this->formManager->create($this->databaseForms[0]);
        } else {
            echo 'La base bazar existe deja.' . "\n";
            // test if compatible
        }
        return;
    }
}
