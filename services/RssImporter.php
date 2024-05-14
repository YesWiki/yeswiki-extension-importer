<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Wiki;

class RssImporter extends Importer
{
    protected $source;

    public function __construct(
        string $source,
        ParameterBagInterface $params,
        ContainerInterface $services,
        EntryManager $entryManager,
        FormManager $formManager,
        Wiki $wiki
    ) {
        $this->source = $source;
        $this->params = $params;
        $this->services = $services;
        $this->entryManager = $entryManager;
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
        echo 'authenticate';
        return;
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
