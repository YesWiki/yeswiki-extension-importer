<?php

// HTTP session on Louty (appli.louty.net), restricted to a whitelist of activity codes.
// Read-only unless built with $allowWrite, and even then it can only create and delete drafts, never validate.

namespace YesWiki\Importer\Service;

class LoutyClient
{
    private const ALLOWED_POSTS = [
        '/Louty/api/authentication/login',
        '/Louty/api/Client/getPaginatedDtoList',
    ];
    private const WRITE_POSTS = [
        '/GestionCom/PiecesEdit/getNatureEncaissement',
        '/GestionCom/PiecesEdit/DemandeChangementActivite',
        '/GestionCom/PiecesEdit/ChangeActivite',
        '/GestionCom/PiecesEdit/GetNewRow',
        '/GestionCom/PiecesEdit/UpdateMontantHT',
        '/GestionCom/PiecesEdit/ValideLigne',
        '/GestionCom/PiecesEdit/VerifiePieceCoherente',
        '/GestionCom/PiecesEdit/VerifiePieceValidee',
        '/GestionCom/PiecesEdit/Enregistre',
        '/GestionCom/PiecesEdit/removePieceFromCache',
        '/Louty/api/PieceGc/delete',
    ];
    private const PIECE_FLAGS_ON = [
        'devisFacture', 'devisNonFacture', 'facture', 'avoir', 'brouillon', 'attenteValidation', 'valide', 'imprime',
        'confirme', 'nonReglable', 'reglementEnAttente', 'reglementPartiel', 'reglementPartielDontRappro', 'reglementTotal',
        'reglementTotalDontSansSuite', 'reglementTotalDontRappro', 'reglPartielAttenteValid', 'reglPartielAttenteConfirm',
        'reglTotalAttenteValid', 'reglTotalAttenteConfirm', 'piecesArchivees',
    ];
    private const PIECE_FLAGS_OFF = ['bc', 'blFacture', 'blNonFacture', 'proforma', 'acompte', 'situation'];
    private const PAGE_SIZE = 100;

    private $curl;
    private array $session = [];
    private array $activiteIds = [];

    public function __construct(
        private array $activites,
        private string $baseUrl = 'https://appli.louty.net',
        private int $timeoutInSec = 60,
        private int $delayInMs = 300,
        private bool $allowWrite = false
    ) {
        $this->activites = array_values(array_filter(array_map('trim', $activites)));
        if (empty($this->activites)) {
            throw new \InvalidArgumentException('LoutyClient: at least one activity code is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // Logs in and keeps the session cookies; returns Louty's user profile.
    public function login(string $user, string $password): array
    {
        $response = $this->request('POST', '/Louty/api/authentication/login', [], http_build_query(['login' => $user, 'password' => $password]), [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'x-client-type: angular',
            'Accept: application/json',
        ]);
        $profile = json_decode($response, true);
        if (empty($profile['individuId']) || empty($profile['structureId'])) {
            throw new \RuntimeException('Louty: login failed');
        }
        $this->session = [
            'individuCourantId' => $profile['individuId'],
            'structureCouranteId' => $profile['structureId'],
        ];
        return $profile;
    }

    // Lists every piece (quotes, invoices, credit notes, any state) of a whitelisted activity.
    public function listPieces(string $activite): array
    {
        $this->assertActivite($activite);
        $pieces = [];
        for ($skip = 0; ; $skip += self::PAGE_SIZE) {
            $query = array_fill_keys(self::PIECE_FLAGS_ON, 'true') + array_fill_keys(self::PIECE_FLAGS_OFF, 'false') + [
                'anciennete' => '0',
                'activiteOpe0' => 'egal',
                'activiteVal0' => $activite,
                'skip' => $skip,
                'take' => self::PAGE_SIZE,
                'sort' => 'DATE',
                'order' => 'DESC',
            ];
            $page = LoutyParser::pieceList($this->request('GET', '/GestionCom/Pieces', $query));
            $new = 0;
            foreach ($page as $piece) {
                if ($piece['activite'] === $activite && !isset($pieces[$piece['id']])) {
                    $pieces[$piece['id']] = $piece;
                    $new++;
                }
            }
            if (count($page) < self::PAGE_SIZE || $new === 0) {
                break;
            }
        }
        return array_values($pieces);
    }

    // Reads one piece in consultation mode and keeps only the lines of whitelisted activities.
    public function getPiece(int $id): array
    {
        $piece = LoutyParser::piece($this->request('GET', '/GestionCom/PiecesEdit/Open/' . $id, $this->session));
        if ($piece['id'] !== $id) {
            throw new \RuntimeException("Louty: piece $id could not be read");
        }
        $this->assertActivite($piece['activite']);
        $piece['lignesIgnorees'] = count(array_filter($piece['lignes'], fn ($l) => !in_array($l['activite'], $this->activites, true)));
        $piece['lignes'] = array_values(array_filter($piece['lignes'], fn ($l) => in_array($l['activite'], $this->activites, true)));
        return $piece;
    }

    // Reads the given clients, by Louty id.
    public function getClients(array $ids): array
    {
        $clients = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::PAGE_SIZE) as $chunk) {
            $body = json_encode(['taillePage' => self::PAGE_SIZE, 'idList' => $chunk, 'filtreRapide' => ['lignes' => []], 'filtreAvance' => null, 'page' => 1]);
            $response = json_decode($this->request('POST', '/Louty/api/Client/getPaginatedDtoList', [], $body, [
                'Content-Type: application/json',
                'x-client-type: angular',
                'Accept: application/json',
            ]), true);
            foreach ($response['list'] ?? [] as $client) {
                if (in_array((int) $client['id'], $chunk, true)) {
                    $clients[(int) $client['id']] = $client;
                }
            }
        }
        return $clients;
    }

    // Creates a draft invoice (never validated) for a Louty client; each line: libelle, memo, montantHT, article.
    public function createDraftInvoice(string $activite, int $clientId, array $lignes, string $date, string $titre, int $echeanceReglementId = 14): array
    {
        $this->assertActivite($activite);
        if (empty($lignes)) {
            throw new \InvalidArgumentException('Louty: a draft needs at least one line');
        }
        $activiteId = $this->activiteId($activite);
        $page = $this->request('GET', '/GestionCom/PiecesEdit/Create', ['pieceType' => 'FC']);
        $hidden = LoutyParser::hiddenInputs($page);
        $tempId = (int) ($hidden['pieceId'] ?? 0);
        $sessionId = $hidden['sessionId'] ?? '';
        if ($tempId >= 0 || $sessionId === '') {
            throw new \RuntimeException('Louty: unexpected draft creation page');
        }
        $ids = ['pieceId' => $tempId, 'sessionId' => $sessionId] + $this->session;
        $nature = $this->postJson('/GestionCom/PiecesEdit/getNatureEncaissement', []);
        $presentation = $this->postJson('/GestionCom/PiecesEdit/DemandeChangementActivite', ['activiteId' => $activiteId] + $ids);
        $change = $this->postJson('/GestionCom/PiecesEdit/ChangeActivite', ['activiteId' => $activiteId] + $ids);
        if (empty($change['success'])) {
            throw new \RuntimeException("Louty: could not switch the draft to activity $activite");
        }
        foreach (array_values($lignes) as $ligne) {
            $row = $this->postJson('/GestionCom/PiecesEdit/GetNewRow', [
                'articleCode' => $ligne['article'], 'activiteId' => $activiteId, 'individuId' => '', 'activiteAutoriseeIds' => '',
            ] + $ids);
            $pliId = (int) ($row['id'] ?? 0);
            $keys = LoutyParser::hiddenInputs($row['html'] ?? '');
            if ($pliId >= 0 || (string) ($keys["pli_activite_key_$pliId"] ?? '') !== (string) $activiteId) {
                throw new \RuntimeException('Louty: new line is not on activity ' . $activite);
            }
            $montants = $this->postJson('/GestionCom/PiecesEdit/UpdateMontantHT', ['pliId' => $pliId, 'montantHT' => self::frenchNumber($ligne['montantHT'])] + $ids)['infoMontantsLigne'] ?? [];
            $valid = $this->postJson('/GestionCom/PiecesEdit/ValideLigne', [
                'id' => $pliId,
                'articleId' => $keys["pli_article_key_$pliId"] ?? '',
                'libelle' => mb_substr($ligne['libelle'], 0, 40),
                'prixUnit' => $montants['prixUnit'] ?? self::frenchNumber($ligne['montantHT']),
                'uniteId' => $keys["pli_unite_key_$pliId"] ?? '',
                'quantite' => 1,
                'montantHT' => $montants['montantHT'] ?? self::frenchNumber($ligne['montantHT']),
                'montantTTC' => $montants['montantTTC'] ?? '',
                'activiteId' => $activiteId,
                'memo' => $ligne['memo'],
            ] + $ids);
            if (empty($valid['success'])) {
                throw new \RuntimeException('Louty: line "' . $ligne['libelle'] . '" refused');
            }
        }
        $header = [
            'id' => $tempId, 'pieceLigneeId' => '', 'pieceTypeId' => 'FACTURE_CLIENT',
            'natureEncaissementId' => $nature['natureEncaissementId'] ?? 2, 'echeanceReglementId' => $echeanceReglementId,
            'dateEcheance' => '', 'clientId' => $clientId, 'date' => date('d/m/Y', strtotime($date)), 'titre' => $titre,
            'activiteId' => $activiteId, 'memoTete' => '', 'memoPied' => '', 'memoInterne' => '', 'marche' => '',
            'dateAccord' => '', 'dateValidite' => '', 'versionHash' => '',
            'activitePresentationId' => $presentation['identiteVisuelleDefautId'] ?? '', 'typAdresseId' => 1,
            'activitesAutoriseesIds' => '', 'proprietesActivitesAutoriseesIds' => '', 'toutesActivites' => 'true',
            'modelePublie' => 'false', 'modeleStructure' => 'false', 'individuId' => '', 'individuStructureId' => '',
        ] + $ids;
        foreach (['VerifiePieceCoherente', 'VerifiePieceValidee'] as $check) {
            if (empty($this->postJson("/GestionCom/PiecesEdit/$check", $header)['success'])) {
                throw new \RuntimeException("Louty: $check refused the draft");
            }
        }
        $saved = $this->postJson('/GestionCom/PiecesEdit/Enregistre', $header);
        $this->request('POST', '/GestionCom/PiecesEdit/removePieceFromCache', [], ['sessionId' => $sessionId, 'pieceId' => (string) $tempId]);
        $pieceId = (int) ($saved['pieceId'] ?? 0);
        if (empty($saved['success']) || $pieceId <= 0) {
            throw new \RuntimeException('Louty: draft not saved');
        }
        if (($saved['etatLibelle'] ?? '') !== 'Brouillon') {
            throw new \RuntimeException("Louty: piece $pieceId was saved as \"" . ($saved['etatLibelle'] ?? '?') . '" instead of a draft, check it in Louty');
        }
        $piece = $this->getPiece($pieceId);
        $attendus = array_map(fn ($l) => round((float) $l['montantHT'], 2), array_values($lignes));
        $lus = array_map(fn ($l) => round((float) $l['montantHT'], 2), $piece['lignes']);
        if ($attendus !== $lus || $piece['clientId'] !== $clientId) {
            $this->deleteDraft($pieceId);
            throw new \RuntimeException("Louty: draft $pieceId did not match what was sent and was deleted");
        }
        return ['pieceId' => $pieceId, 'etat' => $saved['etatLibelle'], 'ligneIds' => array_column($piece['lignes'], 'id')];
    }

    // Deletes a piece, only if Louty still shows it as a draft.
    public function deleteDraft(int $pieceId): void
    {
        $piece = $this->getPiece($pieceId);
        if ($piece['etat'] !== 'Brouillon') {
            throw new \RuntimeException("Louty: piece $pieceId is \"{$piece['etat']}\", only drafts can be deleted");
        }
        $response = json_decode($this->request('POST', '/Louty/api/PieceGc/delete', [], json_encode([
            'keys' => [], 'value' => (string) $pieceId,
            'structureCouranteId' => (string) $this->session['structureCouranteId'], 'individuCourantId' => (string) $this->session['individuCourantId'],
        ]), ['Content-Type: application/json', 'x-client-type: angular', 'Accept: application/json']), true);
        if (empty($response['success'])) {
            throw new \RuntimeException("Louty: draft $pieceId not deleted");
        }
    }

    public static function frenchNumber(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private function activiteId(string $activite): int
    {
        if (!isset($this->activiteIds[$activite])) {
            $query = array_fill_keys(self::PIECE_FLAGS_ON, 'true') + array_fill_keys(self::PIECE_FLAGS_OFF, 'false')
                + ['anciennete' => '0', 'activiteOpe0' => 'egal', 'activiteVal0' => $activite, 'skip' => 0, 'take' => 1, 'sort' => 'DATE', 'order' => 'DESC'];
            $row = LoutyParser::pieceList($this->request('GET', '/GestionCom/Pieces', $query))[0] ?? null;
            if (!$row || $row['activite'] !== $activite || $row['activiteId'] <= 0) {
                throw new \RuntimeException("Louty: cannot find the id of activity $activite");
            }
            $this->activiteIds[$activite] = $row['activiteId'];
        }
        return $this->activiteIds[$activite];
    }

    private function postJson(string $path, array $fields): array
    {
        $response = $this->request('POST', $path, [], http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest']);
        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Louty: $path did not answer JSON");
        }
        return $json;
    }

    private function assertActivite(string $activite): void
    {
        if (!in_array($activite, $this->activites, true)) {
            throw new \RuntimeException("Louty: activity \"$activite\" is not whitelisted");
        }
    }

    private function request(string $method, string $path, array $query = [], string|array|null $body = null, array $headers = []): string
    {
        $writable = $this->allowWrite && in_array($path, self::WRITE_POSTS, true);
        if ($method !== 'GET' && !in_array($path, self::ALLOWED_POSTS, true) && !$writable) {
            throw new \LogicException("Louty: $method $path is not allowed");
        }
        $readable = str_starts_with($path, '/GestionCom/PiecesEdit/Open/') || ($this->allowWrite && $path === '/GestionCom/PiecesEdit/Create');
        if ($method === 'GET' && str_contains($path, '/PiecesEdit/') && !$readable) {
            throw new \LogicException("Louty: GET $path is not allowed");
        }
        if ($this->curl === null) {
            $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_COOKIEFILE => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'YesWiki LoutyImporter',
            ]);
        }
        usleep($this->delayInMs * 1000);
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : ''),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutInSec,
            CURLOPT_TIMEOUT => $this->timeoutInSec,
        ]);
        if ($method === 'GET') {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body ?? '']);
        }
        $response = curl_exec($this->curl);
        $status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        if ($response === false || $status >= 400) {
            throw new \RuntimeException("Louty: $method $path failed (" . ($response === false ? curl_error($this->curl) : "HTTP $status") . ')');
        }
        $finalUrl = curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);
        if (str_contains($finalUrl, 'loginOutlet') || str_contains($finalUrl, '/Account/Login')) {
            throw new \RuntimeException('Louty: session expired or not logged in');
        }
        return $response;
    }
}
