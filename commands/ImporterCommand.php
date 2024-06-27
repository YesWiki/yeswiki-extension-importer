<?php

namespace YesWiki\Importer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Core\Service\ConsoleService;
use YesWiki\Wiki;
use YesWiki\Importer\Service\ImporterManager;

class ImporterCommand extends Command
{
    protected $consoleService;
    protected $params;
    protected $wiki;
    protected $importer;

    public function __construct(Wiki &$wiki)
    {
        parent::__construct();
        $this->consoleService = $wiki->services->get(ConsoleService::class);
        $this->params = $wiki->services->get(ParameterBagInterface::class);
        $this->wiki = $wiki;
        $this->importer = $wiki->services->get(ImporterManager::class);
    }

    protected function configure()
    {
        $this
            ->setName('importer:sync')
            // the short description shown while running "./yeswicli list"
            ->setDescription('Sync selected data sources to this YesWiki.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Synchronize selected data sources to this YesWiki.' . "\n" .
                "If no source indicated it will sync them all\n")

            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'The key name in the config file for source, leave empty for all sources')
            ->addOption('wipe', 'w', InputOption::VALUE_NONE, 'Will delete entries and database model and rebuild from scratch');
    }
    protected function checkConfig(OutputInterface $output)
    {
        if (empty($this->wiki->config['dataSources'])) {
            $output->writeln("No data sources found in config, does dataSources contain something?");
            $this->wiki->config['dataSources'] = [];
        }
        $importers = $this->importer->getAvailableImporters();
        foreach ($this->wiki->config['dataSources'] as $id => $source) {
            if (empty($source['importer'])) {
                $output->writeln("The importer is missing for data source \"{$id}\"");
                return false;
            }
            if (!in_array($source['importer'], array_keys($importers))) {
                $output->writeln("The importer \"{$source['importer']}\" was not found in custom/services or tools/importer/services");
                return false;
            }
        }
        return true;
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->checkConfig($output)) {
            return Command::INVALID;
        }

        $source = $input->getOption('source');
        $hasWipe = $input->getOption('wipe');
        if (!$source) {
            $output->writeln("Importing all sources");
            foreach ($this->wiki->config['dataSources'] as $source => $sourceOptions) {
                if (empty($this->wiki->config['dataSources'][$source])) {
                    $output->writeln("No data source with key \"{$source}\" found in config, does dataSources[\"{$source}\"] contain something?");
                    # TODO: should we continue if source isn't found or exit? Let's continue for now..
                    continue;
                }
                $output->writeln("Importing source \"{$source}\"");
                $output->writeln($this->importer->syncSource($source, $sourceOptions));
            }
            return Command::SUCCESS;
        } else {
            if (empty($this->wiki->config['dataSources'][$source])) {
                $output->writeln("No data source with key \"{$source}\" found in config, does dataSources[\"{$source}\"] contain something?");
                return Command::SUCCESS;
            }
            $output->writeln("Importing source \"{$source}\"");
            $res = $this->importer->syncSource($source, $this->wiki->config['dataSources'][$source]);
            $output->writeln($res);
            return Command::SUCCESS;
        }
    }
}
