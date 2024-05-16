<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
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

    public function getData()
    {
        // we use existing lib
        require_once('tools/syndication/vendor/autoload.php');
        $feed = new \SimplePie();
        $feed->set_feed_url($this->config['url']);
        $feed->enable_cache(true);
        $feed->init();
        $feed->handle_content_type();
        $data = [];
        if ($feed) {
            $rssItems = $feed->get_items();
            foreach ($rssItems as $item) {
                $content = $item->get_content();
                preg_match_all(
                    '~<img\s[^>]*?src\s*=\s*[\'\"]([^\'\"]*?)[\'\"][^>]*?>~',
                    $content,
                    $matches
                );
                $img = $matches[1][0] ?? '';
                $cats = [];
                foreach ($item->get_categories() as $category) {
                    $cats[] = $category->get_label();
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
        }
        return $data;
    }

    public function mapData($data)
    {
        $preparedData = [];
        $converter = new HtmlConverter(array('strip_tags' => true)); // we will convert html to md, but safe
        foreach ($data as $i => $item) {
            $preparedData[$i]['bf_titre'] = $item['title'];
            $preparedData[$i]['bf_auteurice'] = $item['author'];
            $preparedData[$i]['bf_categories'] = implode(', ', $item['categories']);
            $preparedData[$i]['bf_description'] = $converter->convert($item['content']);
            $preparedData[$i]['bf_chapeau'] = $converter->convert($item['summary']);
            $preparedData[$i]['bf_url'] = $item['link'];
            $preparedData[$i]['date_creation_fiche'] = $item['date'];
            $preparedData[$i]['imagebf_image'] = $this->importerManager->downloadFile($item['image']);
        }
        return $preparedData;
    }

    public function syncData($data)
    {
        $existingEntries = $this->entryManager->search(['formIds' => [$this->config['formId']]]);
        foreach ($data as $entry) {
            $res = multiArraySearch($existingEntries, 'bf_url', $entry['bf_url']);
            if (!$res) {
                $entry['antispam'] = 1;
                $this->entryManager->create($this->config['formId'], $entry, false, $entry['bf_url']);
            } else {
                echo 'L\'article "'.$entry['bf_titre'].'" existe déja.'."\n";
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
