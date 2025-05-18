<?php

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Importer\Service\ImporterManager;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use League\HTMLToMarkdown\HtmlConverter;
use YesWiki\Wiki;

class ImapImporter extends Importer
{
    protected $source;
    protected $databaseForms;
    protected $mailBox;

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
                "bn_label_nature" =>  "Imports de mails depuis imap",
                "bn_description" =>  "Imports de mails depuis imap",
                "bn_condition" =>  "",
                "bn_sem_context" =>  "",
                "bn_sem_type" =>  "",
                "bn_sem_use_template" =>  "1",
                "bn_template" =>  <<<EOT
texte***bf_titre***Sujet***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***
date***bf_date***Date de réception*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***
texte***bf_auteurice***Emeteurice***255***255*** *** *** ***0*** *** *** * *** * *** *** *** ***
email***bf_auteurice_email***Email émeteurice*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***
textelong***bf_description***Message***80***12*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
texte***message_id***message_id***80***8*** *** ***wiki***0*** *** *** * *** * *** *** *** ***
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
        $this->config['attachments_folder'] = $this->config['attachments_folder'] ?? null;
        if (!empty($this->config['attachments_folder']) && !is_dir($this->config['attachments_folder'])) {
            if (!mkdir($this->config['attachments_folder'], 0755, true)) {
                exit("Folder for attachments {$this->config['attachments_folder']} could'nt be created.");
            }
        }
        return $config;
    }

    public function authenticate()
    {
        // Create PhpImap\Mailbox instance for all further actions
        $this->mailBox = new \PhpImap\Mailbox(
            $this->config['imap_server_and_folder'],
            $this->config['imap_user'],
            $this->config['imap_password'],
            $this->config['attachments_folder'],
            'UTF-8', // Server encoding (optional)
            true, // Trim leading/ending whitespaces of IMAP path (optional)
            true // Attachment filename mode (optional; false = random filename; true = original filename)
        );
        // set some connection arguments (if appropriate)
        $this->mailBox->setConnectionArgs(
            CL_EXPUNGE // expunge deleted mails upon mailbox close
            #  | OP_SECURE // don't do non-secure authentication
        );
    }


    public function getData()
    {
        $this->authenticate();
        try {
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailBox->searchMailbox($this->config['imap_query']);
        } catch (PhpImap\Exceptions\ConnectionException $ex) {
            echo "IMAP connection failed: " . implode(",", $ex->getErrors('all'));
            die();
        }

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            die('No emails found.');
        }
        $data = [];
        foreach ($mailsIds as $m) {
            $mail = $this->mailBox->getMail($m, false);
            $data[$mail->messageId] = $mail;
        }

        return $data;
    }

    public function mapData($data)
    {
        $preparedData = [];
        $converter = new HtmlConverter(array('strip_tags' => true)); // we will convert html to md, but safe
        foreach ($data as $i => $email) {
            if ($email->textHtml) {
                $message = $email->textHtml;
            } else {
                $message = $email->textPlain;
            }
            $preparedData[$i]['bf_titre'] = $email->subject;
            $preparedData[$i]['bf_auteurice'] = (string) ($email->fromName ?? $email->fromAddress);
            $preparedData[$i]['bf_auteurice_email'] = (string) $email->fromAddress;
            $preparedData[$i]['bf_description'] = $converter->convert($message);
            $preparedData[$i]['message_id'] = $i;
            $preparedData[$i]['date_creation_fiche'] = $preparedData[$i]['bf_date'] = date_format(date_create($email->date), 'Y-m-d H:i:s');
        }
        return $preparedData;
    }

    public function syncData($data)
    {
        $existingEntries = $this->entryManager->search(['formsIds' => [$this->config['formId']]]);
        foreach ($data as $entry) {
            dump($entry['message_id']);
            $res = multiArraySearch($existingEntries, 'message_id', $entry['message_id']);
            if (!$res) {
                $entry['antispam'] = 1;
                $this->entryManager->create($this->config['formId'], $entry, false);
                echo 'L\'email "' . $entry['bf_titre'] . '" a été créé.' . "\n";
            } else {
                echo 'L\'email "' . $entry['bf_titre'] . '" existe déja.' . "\n";
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
            echo 'La base bazar existe déja.' . "\n";
            // test if compatible
        }
        return;
    }
}
