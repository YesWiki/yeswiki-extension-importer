<?php

// Read-only HTTP session on Louty (appli.louty.net), restricted to a whitelist of activity codes.

namespace YesWiki\Importer\Service;

class LoutyClient
{
    private const ALLOWED_POSTS = [
        '/Louty/api/authentication/login',
        '/Louty/api/Client/getPaginatedDtoList',
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

    public function __construct(
        private array $activites,
        private string $baseUrl = 'https://appli.louty.net',
        private int $timeoutInSec = 60,
        private int $delayInMs = 300
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

    private function assertActivite(string $activite): void
    {
        if (!in_array($activite, $this->activites, true)) {
            throw new \RuntimeException("Louty: activity \"$activite\" is not whitelisted");
        }
    }

    private function request(string $method, string $path, array $query = [], ?string $body = null, array $headers = []): string
    {
        if ($method !== 'GET' && !in_array($path, self::ALLOWED_POSTS, true)) {
            throw new \LogicException("Louty: $method $path is not allowed");
        }
        if ($method === 'GET' && str_contains($path, '/PiecesEdit/') && !str_starts_with($path, '/GestionCom/PiecesEdit/Open/')) {
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
            curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body]);
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
