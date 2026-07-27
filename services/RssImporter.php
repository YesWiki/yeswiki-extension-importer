<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Core\Service\EntryManager;
use YesWiki\Core\Service\FormManager;
use YesWiki\Core\Service\ListManager;
use YesWiki\Wiki;
use League\HTMLToMarkdown\HtmlConverter;

class RssImporter extends Importer
{
    protected $source;
    protected $databaseForms;

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
                "bn_label_nature" =>  "Imports de flux RSS",
                "bn_description" =>  "Imports de flux RSS",
                "bn_condition" =>  "",
                "bn_sem_context" =>  "",
                "bn_sem_type" =>  "",
                "bn_sem_use_template" =>  "1",
                "bn_template" =>  <<<EOT
texte***bf_titre***Titre***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***
image***bf_image***Image***400***400***1000***1000***right***0*** *** *** * *** * *** *** *** ***
textelong***bf_chapeau***Résumé***80***8*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
textelong***bf_description***Contenu***80***12*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
texte***bf_auteurice***Auteurices***255***255*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
tags***bf_categories***Catégories*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***
lien_internet***bf_url***Url de l'article*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***
EOT,
                "bn_ce_i18n" =>  "fr-FR",
                "bn_only_one_entry" =>  "N",
                "bn_only_one_entry_message" =>  null
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

    public static function getAdminFields(): array
    {
        return [
            'url' => ['type' => 'url', 'required' => true],
        ];
    }

    // bf_url is excluded on purpose: syncData() relies on it verbatim as the entry's dedup/identity key
    public static function getOwnFields(): array
    {
        return [
            ['key' => 'bf_titre', 'label' => 'Titre'],
            ['key' => 'bf_auteurice', 'label' => 'Auteurices'],
            ['key' => 'bf_categories', 'label' => 'Catégories'],
            ['key' => 'bf_chapeau', 'label' => 'Résumé'],
            ['key' => 'bf_description', 'label' => 'Contenu'],
        ];
    }

    public function getData()
    {
        // we use existing lib
        require_once('tools/syndication/vendor/autoload.php');
        $feed = new \SimplePie();
        $feed->set_feed_url($this->config['url']);
        $feed->enable_cache(true);
        $data = [];
        // the vendored SimplePie lib triggers PHP 8.1+ "null as array offset" deprecation
        // notices internally (e.g. IRI::scheme_normalization() on a null scheme); it's
        // third-party code we don't maintain, so silence only E_DEPRECATED for the
        // duration of this call instead of patching vendor or hiding other error types
        set_error_handler(static function (int $errno) {
            return $errno === E_DEPRECATED;
        }, E_DEPRECATED);
        try {
            $feed->init();
            $feed->handle_content_type();
            if ($feed->error()) {
                echo 'Erreur lors de la récupération du flux RSS "' . $this->config['url'] . '" : ' . $feed->error() . "\n";
                return $data;
            }
            $rssItems = $feed->get_items();
            echo count($rssItems) . ' article(s) trouvé(s) dans le flux.' . "\n";
            foreach ($rssItems as $item) {
                $content = $item->get_content();
                preg_match_all(
                    '~<img\s[^>]*?src\s*=\s*[\'\"]([^\'\"]*?)[\'\"][^>]*?>~',
                    $content,
                    $matches
                );
                $img = $matches[1][0] ?? '';
                if (empty($img)) {
                    // fallback to media:content/media:thumbnail/enclosure (e.g. Le Monde RSS feeds)
                    if ($enclosure = $item->get_enclosure()) {
                        $img = $enclosure->get_thumbnail() ?: $enclosure->get_link();
                    }
                }
                if (empty($img) && ($thumbnail = $item->get_thumbnail())) {
                    $img = $thumbnail['url'] ?? '';
                }
                $cats = [];
                $categories = $item->get_categories();
                if (!empty($categories)) {
                    foreach ($categories as $category) {
                        $cats[] = $category->get_label();
                    }
                }
                if ($author = $item->get_author()) {
                    $author = $author->get_name();
                }
                $data[] = [
                    'title' => $item->get_title(),
                    'author' => $author,
                    'categories' => $cats,
                    'summary' => $item->get_description(),
                    'link' => $item->get_link(),
                    'date' => $item->get_date("Y-m-d H:i:s"),
                    'content' => $content,
                    'image' => $img
                ];
            }
        } finally {
            restore_error_handler();
        }
        return $data;
    }

    public function mapData($data)
    {
        $preparedData = [];
        $converter = new HtmlConverter(array('strip_tags' => true)); // we will convert html to md, but safe
        foreach ($data as $i => $item) {
            $entry = [];
            $entry['bf_titre'] = $item['title'];
            $entry['bf_auteurice'] = $item['author'];
            $entry['bf_categories'] = implode(', ', $item['categories']);
            $entry['bf_description'] = $converter->convert($item['content']);
            $entry['bf_chapeau'] = $converter->convert($item['summary']);
            $entry['bf_url'] = $item['link'];
            $entry['date_creation_fiche'] = $item['date'];
            $entry['imagebf_image'] = $this->importerManager->downloadFile($item['image']);
            $preparedData[$i] = $this->applyFieldsMapping($entry);
        }
        return $preparedData;
    }

    public function syncData($data)
    {
        $existingEntries = $this->entryManager->search(['formsIds' => [$this->config['formId']]]);
        foreach ($data as $entry) {
            $res = multiArraySearch($existingEntries, 'bf_url', $entry['bf_url']);
            if (!$res) {
                $entry['antispam'] = 1;
                $this->entryManager->create($this->config['formId'], $entry, false, $entry['bf_url']);
                echo 'L\'article "'.($entry['bf_titre'] ?? $entry['bf_url']).'" créé.'."\n";
            } else {
                echo 'L\'article "'.($entry['bf_titre'] ?? $entry['bf_url']).'" existe déja.'."\n";
            }
        }
        return;
    }

    public function syncFormModel()
    {
        // test if the form exists, if not, install it
        $form = $this->formManager->getOne($this->config['formId']);
        if (empty($form)) {
            $this->databaseForms[0]['bn_id_nature'] = $this->config['formId'];
            $this->formManager->create($this->databaseForms[0]);
        } else {
            echo 'La base bazar existe deja.'."\n";
            // test if compatible
        }
        return;
    }

}
