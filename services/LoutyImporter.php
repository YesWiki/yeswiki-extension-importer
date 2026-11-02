<?php

// Imports clients, quotes, invoices and credit notes of whitelisted Louty activities into three Bazar forms.
// Louty fields are rewritten at each sync; any other field added to these forms is left untouched.

namespace YesWiki\Importer\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Bazar\Service\EntryManager;
use YesWiki\Bazar\Service\FormManager;
use YesWiki\Bazar\Service\ListManager;
use YesWiki\Core\Controller\AuthController;
use YesWiki\Core\Controller\UserController;
use YesWiki\Core\Service\UserManager;
use YesWiki\Wiki;

class LoutyImporter extends Importer
{
    public const TYPES = ['DV' => 'devis', 'FC' => 'facture', 'AV' => 'avoir'];

    protected $source;
    protected ?LoutyClient $client = null;

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
        $this->config = $this->checkConfig($params->get('dataSources')[$this->source]);
    }

    public function checkConfig(array $config)
    {
        foreach (['clientFormId', 'pieceFormId', 'ligneFormId', 'activites'] as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("LoutyImporter: \"$key\" is missing in the config of source {$this->source}");
            }
        }
        if (empty($config['auth']['user']) || empty($config['auth']['password'])) {
            throw new \InvalidArgumentException("LoutyImporter: Louty login and password are missing in the config of source {$this->source}");
        }
        $config['activites'] = is_array($config['activites']) ? $config['activites'] : explode(',', $config['activites']);
        return $config;
    }

    public static function getAdminFields(): array
    {
        return [
            'auth_user' => ['type' => 'text', 'required' => true],
            'auth_password' => ['type' => 'password', 'required' => true],
            'activites' => ['type' => 'text', 'required' => true],
            'clientFormId' => ['type' => 'number', 'required' => true],
            'pieceFormId' => ['type' => 'number', 'required' => true],
            'ligneFormId' => ['type' => 'number', 'required' => true],
            'localAdminUser' => ['type' => 'text', 'required' => false],
            'ignorerAnnulations' => ['type' => 'checkbox', 'required' => false],
        ];
    }

    public static function needsBazarForm(): bool
    {
        return false;
    }

    // Fetches the piece list, the details of new or changed pieces, and their clients.
    public function getData()
    {
        $this->client ??= new LoutyClient($this->config['activites'], $this->config['url'] ?? 'https://appli.louty.net');
        $this->client->login($this->config['auth']['user'], $this->config['auth']['password']);
        $known = $this->indexByLoutyId($this->config['pieceFormId']);
        $data = ['pieces' => [], 'details' => [], 'clients' => []];
        foreach ($this->config['activites'] as $activite) {
            foreach ($this->client->listPieces(trim($activite)) as $row) {
                $row['hash'] = self::hash($row);
                $data['pieces'][$row['id']] = $row;
                $entry = $known[$row['id']] ?? null;
                if ($entry === null || ($entry['bf_louty_hash'] ?? '') !== $row['hash'] || $row['etat'] === 'B' || $row['type'] === 'AV') {
                    $data['details'][$row['id']] = $this->client->getPiece($row['id']);
                }
            }
        }
        echo count($data['pieces']) . ' pièce(s) Louty, ' . count($data['details']) . ' à relire en détail.' . "\n";
        if ($this->config['ignorerAnnulations'] ?? true) {
            $annulees = self::cancelledPairs($data['pieces'], $data['details']);
            $data['pieces'] = array_diff_key($data['pieces'], array_flip($annulees));
            $data['details'] = array_diff_key($data['details'], array_flip($annulees));
            echo (count($annulees) / 2) . ' facture(s) annulée(s) par un avoir total, ignorée(s) avec leur avoir.' . "\n";
        }
        $data['clients'] = $this->client->getClients(array_column($data['pieces'], 'clientId'));
        return $data;
    }

    // Ids of invoices fully cancelled by a credit note, and of those credit notes (their HT amounts cancel out).
    public static function cancelledPairs(array $rows, array $details): array
    {
        $ids = [];
        foreach ($rows as $id => $row) {
            $origine = $details[$id]['pieceOrigineId'] ?? null;
            if ($row['type'] !== 'AV' || !$origine || !isset($rows[$origine]) || $rows[$origine]['type'] !== 'FC') {
                continue;
            }
            if (abs((float) $row['montantHT'] + (float) $rows[$origine]['montantHT']) < 0.01) {
                $ids[] = $id;
                $ids[] = $origine;
            }
        }
        return array_values(array_unique($ids));
    }

    // Turns Louty data into Bazar entries, keyed by Louty id.
    public function mapData($data)
    {
        $clients = [];
        foreach ($data['clients'] as $id => $c) {
            $clients[$id] = self::mapClient($c);
        }
        $pieces = [];
        $lignes = [];
        foreach ($data['pieces'] as $id => $row) {
            $detail = $data['details'][$id] ?? null;
            $pieces[$id] = self::mapPiece($row, $detail);
            if ($detail !== null) {
                $lignes[$id] = array_map(fn ($l) => self::mapLigne($l, $row), $detail['lignes']);
            }
        }
        return ['clients' => $clients, 'pieces' => $pieces, 'lignes' => $lignes, 'origines' => array_map(fn ($d) => $d['pieceOrigineId'], $data['details'])];
    }

    public function syncFormModel()
    {
        foreach (['clientFormId' => 'client', 'pieceFormId' => 'piece', 'ligneFormId' => 'ligne'] as $key => $kind) {
            $formId = $this->config[$key];
            if (!empty($this->formManager->getOne($formId))) {
                continue;
            }
            $this->formManager->create([
                'bn_id_nature' => $formId,
                'bn_label_nature' => ['client' => 'Clients Louty', 'piece' => 'Pièces Louty', 'ligne' => 'Lignes de pièces Louty'][$kind],
                'bn_description' => 'Importé de Louty par la source ' . $this->source,
                'bn_condition' => '',
                'bn_sem_context' => '',
                'bn_sem_type' => '',
                'bn_sem_use_template' => '1',
                'bn_template' => self::formTemplate($kind, $this->config),
                'bn_ce_i18n' => 'fr-FR',
                'bn_only_one_entry' => 'N',
                'bn_only_one_entry_message' => null,
            ]);
            echo "Formulaire \"$kind\" créé (id $formId)." . "\n";
        }
    }

    // Writes clients, then pieces, then lines; deletes pieces that disappeared from Louty (deleted drafts).
    public function syncData($data)
    {
        $this->asSyncUser(function () use ($data) {
            $stats = ['créée(s)' => 0, 'mise(s) à jour' => 0, 'supprimée(s)' => 0];
            $clientTags = $this->upsertAll($this->config['clientFormId'], $data['clients'], $stats);
            foreach ($data['pieces'] as $id => &$piece) {
                $piece['bf_client'] = $clientTags[$piece['_clientId']] ?? '';
            }
            unset($piece);
            $pieceTags = $this->upsertAll($this->config['pieceFormId'], $data['pieces'], $stats);
            $origines = [];
            foreach ($data['origines'] as $id => $origineId) {
                if ($origineId && isset($pieceTags[$origineId])) {
                    $origines[$id] = ['bf_piece_origine' => $pieceTags[$origineId]];
                }
            }
            $this->upsertAll($this->config['pieceFormId'], $origines, $stats, true);
            $existingLignes = $this->indexByLoutyId($this->config['ligneFormId']);
            $ligneTags = [];
            foreach ($data['lignes'] as $pieceId => $lignes) {
                $byId = [];
                foreach ($lignes as $ligne) {
                    $ligne['bf_piece'] = $pieceTags[$pieceId];
                    $byId[$ligne['bf_louty_id']] = $ligne;
                }
                $ligneTags += $this->upsertAll($this->config['ligneFormId'], $byId, $stats);
                foreach ($existingLignes as $ligneId => $entry) {
                    if (($entry['bf_piece'] ?? '') === $pieceTags[$pieceId] && !isset($byId[$ligneId])) {
                        $this->entryManager->delete($entry['id_fiche'], true);
                        $stats['supprimée(s)']++;
                    }
                }
            }
            $lignesOrigine = [];
            foreach ($data['lignes'] as $lignes) {
                foreach ($lignes as $ligne) {
                    $origine = (string) ($ligne['_ligneOrigineId'] ?? '');
                    $tag = $origine === '' ? null : ($ligneTags[$origine] ?? $existingLignes[$origine]['id_fiche'] ?? null);
                    if ($tag) {
                        $lignesOrigine[$ligne['bf_louty_id']] = ['bf_ligne_origine' => $tag];
                    }
                }
            }
            $this->upsertAll($this->config['ligneFormId'], $lignesOrigine, $stats, true);
            $allLignes = null;
            foreach ($this->indexByLoutyId($this->config['pieceFormId']) as $id => $entry) {
                if (!isset($data['pieces'][$id])) {
                    $allLignes ??= $this->indexByLoutyId($this->config['ligneFormId']);
                    foreach ($allLignes as $ligne) {
                        if (($ligne['bf_piece'] ?? '') === $entry['id_fiche']) {
                            $this->entryManager->delete($ligne['id_fiche'], true);
                        }
                    }
                    $this->entryManager->delete($entry['id_fiche'], true);
                    $stats['supprimée(s)']++;
                }
            }
            echo implode(', ', array_map(fn ($k, $v) => "$v fiche(s) $k", array_keys($stats), $stats)) . '.' . "\n";
        });
    }

    public static function mapClient(array $c): array
    {
        $nom = trim(($c['nom'] ?? '') . ' ' . ($c['prenom'] ?? ''));
        return [
            'bf_titre' => $nom,
            'bf_louty_id' => (string) $c['id'],
            'bf_numero_client' => $c['compteClient']['code'] ?? '',
            'bf_categorie' => $c['categorieLibelle'] ?? '',
            'bf_adresse' => implode("\n", array_filter($c['listAdresse'] ?? [$c['resumeAdresse'] ?? ''])),
            'bf_courriel' => $c['courrielPrincipal'] ?? (explode(',', $c['resumeCourriel'] ?? '')[0] ?? ''),
            'bf_telephone' => $c['telephonePrincipal'] ?? ($c['resumeTelephone'] ?? ''),
            'bf_contact' => $c['resumeContact'] ?? '',
            'bf_siret' => $c['numeroSiret'] ?? '',
            'bf_tva' => $c['numeroTva'] ?? '',
        ];
    }

    public static function mapPiece(array $row, ?array $detail): array
    {
        $type = self::TYPES[$row['type']] ?? strtolower($row['typeLabel']);
        $numero = $row['numero'] !== '' ? $row['numero'] : 'brouillon ' . $row['id'];
        $entry = [
            'bf_titre' => ucfirst($type) . ' ' . $numero . ' - ' . $row['clientNom'],
            'bf_louty_id' => (string) $row['id'],
            'bf_louty_hash' => $row['hash'] ?? self::hash($row),
            'bf_type' => $type,
            'bf_numero' => $row['numero'],
            'bf_date' => $row['date'] ?? '',
            'bf_date_echeance' => $row['dateEcheance'] ?? '',
            'bf_date_validite' => $row['dateValidite'] ?? '',
            'bf_date_accord' => $row['dateAccord'] ?? '',
            'bf_etat' => $row['etatLabel'],
            'bf_reglement' => $row['reglement'],
            'bf_activite' => $row['activite'],
            'bf_montant_ht' => self::number($row['montantHT']),
            'bf_montant_ttc' => self::number($row['montantTTC']),
            'bf_net_a_payer' => self::number($row['netAPayer']),
            'bf_facture_numero' => $row['factureNumero'] ?? '',
            'bf_titre_louty' => $row['titre'],
            '_clientId' => $row['clientId'],
        ];
        if ($detail !== null) {
            $entry['bf_reglement_libelle'] = $detail['reglement'];
            $entry['bf_lignes_ignorees'] = (string) $detail['lignesIgnorees'];
        }
        return $entry;
    }

    public static function mapLigne(array $l, array $row): array
    {
        $type = self::TYPES[$row['type']] ?? strtolower($row['typeLabel']);
        return [
            'bf_titre' => ($row['numero'] !== '' ? $row['numero'] : 'brouillon ' . $row['id']) . ' #' . $l['ordre'] . ' ' . $l['libelle'],
            'bf_louty_id' => (string) $l['id'],
            'bf_type' => $type,
            'bf_date' => $row['date'] ?? '',
            'bf_ordre' => (string) $l['ordre'],
            'bf_libelle' => $l['libelle'],
            'bf_memo' => $l['memo'],
            'bf_article' => $l['article'],
            'bf_taux_tva' => self::number($l['tauxTva']),
            'bf_prix_unit' => self::number($l['prixUnit']),
            'bf_mode_prix' => $l['modePrix'] ?? '',
            'bf_quantite' => self::number($l['quantite']),
            'bf_unite' => $l['unite'],
            'bf_montant_ht' => self::number($l['montantHT']),
            'bf_montant_ttc' => self::number($l['montantTTC']),
            'bf_activite' => $l['activite'],
            '_ligneOrigineId' => $l['ligneOrigineId'],
        ];
    }

    // Returns the Bazar template of one of the three forms; acls default to @admins.
    public static function formTemplate(string $kind, array $config): string
    {
        $text = fn ($name, $label) => "texte***$name***$label***255***255*** *** ***text***0*** *** *** * *** * *** *** *** ***";
        $long = fn ($name, $label) => "textelong***$name***$label***80***4*** *** ***wiki***0*** *** *** * *** * *** *** *** ***";
        $date = fn ($name, $label) => "listedatedeb***$name***$label*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***";
        $link = fn ($formId, $name, $label) => "listefiche***$formId***$label*** *** *** ***$name*** ***0*** *** *** * *** * *** *** *** ***";
        $acls = 'acls***' . ($config['acls'] ?? '@admins') . '***' . ($config['acls'] ?? '@admins') . '***' . ($config['acls'] ?? '@admins') . '***';
        $lines = [
            'client' => [
                "texte***bf_titre***Nom***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***",
                $text('bf_louty_id', 'Id Louty'), $text('bf_numero_client', 'N° client'), $text('bf_categorie', 'Catégorie'),
                $long('bf_adresse', 'Adresse'), "champs_mail***bf_courriel***Courriel*** *** *** *** *** ***0*** *** *** * *** * *** *** *** ***",
                $text('bf_telephone', 'Téléphone'), $text('bf_contact', 'Contact'), $text('bf_siret', 'SIRET'), $text('bf_tva', 'N° TVA'),
            ],
            'piece' => [
                "texte***bf_titre***Titre***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***",
                $text('bf_type', 'Type (devis, facture, avoir)'), $text('bf_numero', 'Numéro'), $date('bf_date', 'Date'),
                $link($config['clientFormId'], 'bf_client', 'Client'), $text('bf_etat', 'État'), $text('bf_reglement', 'Règlement (code)'),
                $text('bf_reglement_libelle', 'Règlement'), $text('bf_montant_ht', 'Montant HT'), $text('bf_montant_ttc', 'Montant TTC'),
                $text('bf_net_a_payer', 'Net à payer'), $date('bf_date_echeance', 'Échéance de paiement'), $date('bf_date_validite', 'Validité (devis)'),
                $date('bf_date_accord', 'Accord (devis)'), $text('bf_facture_numero', 'Facturé par (devis)'),
                $link($config['pieceFormId'], 'bf_piece_origine', 'Pièce d\'origine'), $text('bf_activite', 'Activité'),
                $text('bf_titre_louty', 'Titre dans Louty'), $text('bf_lignes_ignorees', 'Lignes d\'autres activités'),
                $text('bf_louty_id', 'Id Louty'), $text('bf_louty_hash', 'Empreinte Louty'),
            ],
            'ligne' => [
                "texte***bf_titre***Titre***255***255*** *** ***text***1*** *** *** * *** * *** *** *** ***",
                $link($config['pieceFormId'], 'bf_piece', 'Pièce'), $text('bf_type', 'Type de pièce'), $date('bf_date', 'Date de la pièce'),
                $text('bf_ordre', 'Ordre'), $text('bf_libelle', 'Libellé'), $long('bf_memo', 'Mémo'), $text('bf_article', 'Article'),
                $text('bf_quantite', 'Quantité'), $text('bf_unite', 'Unité'), $text('bf_prix_unit', 'Prix unitaire'),
                $text('bf_mode_prix', 'Prix saisi en (HT/TTC)'), $text('bf_taux_tva', 'Taux TVA'), $text('bf_montant_ht', 'Montant HT'),
                $text('bf_montant_ttc', 'Montant TTC'), $text('bf_activite', 'Activité'),
                $link($config['ligneFormId'], 'bf_ligne_origine', 'Ligne d\'origine'), $text('bf_louty_id', 'Id Louty'),
            ],
        ][$kind];
        $lines[] = $acls;
        return implode("\r\n", $lines);
    }

    public static function hash(array $row): string
    {
        unset($row['hash']);
        return sha1(json_encode($row));
    }

    private static function number(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, '.', '');
    }

    // Creates or updates entries keyed by Louty id; returns Louty id => entry tag.
    private function upsertAll($formId, array $entries, array &$stats, bool $onlyExisting = false): array
    {
        $existing = $this->indexByLoutyId($formId);
        $tags = [];
        foreach ($entries as $loutyId => $entry) {
            $entry = array_filter($entry, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
            $current = $existing[$loutyId] ?? null;
            if ($current === null) {
                if ($onlyExisting) {
                    continue;
                }
                $entry['antispam'] = 1;
                $entry['bf_louty_id'] = (string) $loutyId;
                $created = $this->entryManager->create($formId, $entry);
                $tags[$loutyId] = $created['id_fiche'];
                $stats['créée(s)']++;
                continue;
            }
            $tags[$loutyId] = $current['id_fiche'];
            $changed = array_filter($entry, fn ($v, $k) => html_entity_decode((string) ($current[$k] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') !== (string) $v, ARRAY_FILTER_USE_BOTH);
            if (empty($changed)) {
                continue;
            }
            $this->entryManager->update($current['id_fiche'], $changed + ['antispam' => 1, 'bf_titre' => $entry['bf_titre'] ?? $current['bf_titre']]);
            $stats['mise(s) à jour']++;
        }
        return $tags;
    }

    private function indexByLoutyId($formId): array
    {
        $index = [];
        foreach ($this->entryManager->search(['formsIds' => [$formId]], false) as $entry) {
            if (!empty($entry['bf_louty_id'])) {
                $index[$entry['bf_louty_id']] = $entry;
            }
        }
        return $index;
    }

    // Runs $callback logged in as localAdminUser (first admin by default), then restores the previous user.
    private function asSyncUser(callable $callback): void
    {
        $name = $this->config['localAdminUser'] ?? $this->getService(UserController::class)->getFirstAdmin();
        $user = $name ? $this->getService(UserManager::class)->getOneByName($name) : null;
        if (!$user) {
            throw new \RuntimeException("LoutyImporter: wiki user \"$name\" not found, set localAdminUser");
        }
        $auth = $this->getService(AuthController::class);
        $previous = $auth->getLoggedUser();
        $auth->login($user);
        try {
            $callback();
        } finally {
            $auth->logout();
            if (!empty($previous)) {
                $auth->login($previous);
            }
        }
    }
}
