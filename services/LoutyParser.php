<?php

// Parses the HTML pages of Louty's GestionCom module (piece list and piece detail) into plain arrays.

namespace YesWiki\Importer\Service;

class LoutyParser
{
    // Converts a French amount ("1 234,56", "-9 999,99") into a float, or null when empty.
    public static function amount(?string $text): ?float
    {
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' || !preg_match('/^-?\d+(,\d+)?$/', $text)) {
            return null;
        }
        return (float) str_replace(',', '.', $text);
    }

    // Converts a dd/mm/yyyy date into yyyy-mm-dd, or null when empty or malformed.
    public static function date(?string $text): ?string
    {
        if (!preg_match('#(\d{2})/(\d{2})/(\d{4})#', (string) $text, $m)) {
            return null;
        }
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    // Returns one array per row of the /GestionCom/Pieces list.
    public static function pieceList(string $html): array
    {
        $xpath = self::xpath($html);
        $pieces = [];
        foreach ($xpath->query('//tr[starts-with(@id, "pie_")]') as $row) {
            $id = substr($row->getAttribute('id'), 4);
            if (!ctype_digit($id)) {
                continue;
            }
            $cell = fn (string $class) => $xpath->query('./td[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $row)->item(0);
            $text = fn (?\DOMNode $node) => $node ? self::clean($node->textContent) : '';
            $byId = fn (string $prefix) => $text($xpath->query('.//*[@id="' . $prefix . $id . '"]', $row)->item(0));
            $type = $xpath->query('.//div[contains(@class, "pastille_PieceType")]', $row)->item(0);
            $etat = $cell('col_etat');
            $etatPastilles = $etat ? $xpath->query('.//div[contains(@class, "pastille")]', $etat) : [];
            $etatTitles = [];
            foreach ($etatPastilles as $div) {
                $etatTitles[] = self::clean($div->getAttribute('title'));
            }
            $reglement = $cell('ETAT_REGLEMENT');
            $reglementCode = '';
            if ($reglement) {
                foreach ($xpath->query('.//*[@class]', $reglement) as $node) {
                    $reglementCode = trim($node->getAttribute('class'));
                    break;
                }
            }
            $factureNumero = null;
            foreach ($etat ? $xpath->query('.//*[@title]', $etat) : [] as $node) {
                if (preg_match('/n°\s*(\S+)/u', $node->getAttribute('title'), $m)) {
                    $factureNumero = $m[1];
                }
            }
            $numero = $xpath->query('./td[2]', $row)->item(0);
            $pieces[] = [
                'id' => (int) $id,
                'type' => $type ? self::clean($type->textContent) : '',
                'typeLabel' => $type ? self::clean($type->getAttribute('title')) : '',
                'numero' => $text($numero),
                'date' => self::date($byId('datePiece_')),
                'activite' => $byId('activitePiece_'),
                'activiteId' => (int) $byId('activiteIdPiece_'),
                'clientNumero' => $byId('numClientPiece_'),
                'clientId' => (int) $byId('idClientPiece_'),
                'clientNom' => $byId('nomClient_'),
                'montantHT' => self::amount($byId('montantHTPiece_')),
                'montantTTC' => self::amount($byId('montantTTCPiece_')),
                'netAPayer' => self::amount($text($cell('col_netAPayer'))),
                'etat' => $etat ? self::clean($xpath->query('.//div[contains(@class, "pastille")]', $etat)->item(0)?->textContent ?? '') : '',
                'etatLabel' => $etatTitles[0] ?? '',
                'reglement' => $reglementCode,
                'factureNumero' => $factureNumero,
                'titre' => $text($cell('col_titre')),
                'dateEcheance' => self::date($text($cell('col_dateEcheance'))),
                'dateValidite' => self::date($text($cell('col_dateValidite'))),
                'dateAccord' => self::date($text($cell('col_dateAccord'))),
                'dateValidation' => self::date($text($cell('col_dateValidation'))),
            ];
        }
        return $pieces;
    }

    // Returns the header and lines of a /GestionCom/PiecesEdit/Open/<id> page.
    public static function piece(string $html): array
    {
        $xpath = self::xpath($html);
        $value = function (string $id) use ($xpath): string {
            $node = $xpath->query('//*[@id="' . $id . '"]')->item(0);
            if (!$node) {
                return '';
            }
            return self::clean($node->nodeName === 'textarea' ? $node->textContent : $node->getAttribute('value'));
        };
        $lines = [];
        foreach ($xpath->query('//tr[starts-with(@id, "pli_")]') as $row) {
            $lineId = substr($row->getAttribute('id'), 4);
            if (!ctype_digit($lineId)) {
                continue;
            }
            $article = $xpath->query('//select[@id="select_article_' . $lineId . '"]/option[@selected]', $row)->item(0)
                ?? $xpath->query('//select[@id="select_article_' . $lineId . '"]/option', $row)->item(0);
            $lines[] = [
                'id' => (int) $lineId,
                'ordre' => (int) $value('pli_ordre_' . $lineId),
                'articleId' => (int) $value('pli_article_key_' . $lineId),
                'article' => $article ? self::clean($article->textContent) : '',
                'libelle' => $value('pli_libelle_' . $lineId),
                'memo' => $value('pli_memo_' . $lineId),
                'tauxTva' => self::amount($value('pli_tauxTva_' . $lineId)),
                'prixUnit' => self::amount($value('pli_prixunit_' . $lineId)),
                'modePrix' => self::clean($xpath->query('//*[@id="mode_ht_ttc_' . $lineId . '"]')->item(0)?->textContent ?? ''),
                'quantite' => self::amount($value('pli_quantite_' . $lineId)),
                'unite' => $value('pli_unite_' . $lineId),
                'montantHT' => self::amount($value('pli_ht_' . $lineId)),
                'montantTTC' => self::amount($value('pli_ttc_' . $lineId)),
                'activite' => $value('pli_activite_' . $lineId),
                'ligneOrigineId' => (int) $row->getAttribute('plilignetransformationid') ?: null,
            ];
        }
        $activite = $value('activiteCode');
        return [
            'id' => (int) $value('pieceId'),
            'etat' => $value('etat'),
            'date' => self::date($value('date')),
            'dateEcheance' => self::date($value('dateEcheance')),
            'dateValidite' => self::date($value('dateValidite')),
            'dateAccord' => self::date($value('dateAccord')),
            'clientId' => (int) $value('clientId'),
            'clientLibelle' => $value('clientLibelle'),
            'activite' => trim(explode(' - ', $activite, 2)[0]),
            'titre' => $value('titre'),
            'pieceOrigineId' => (int) $value('pieceLigneeId') ?: null,
            'reglement' => $value('inputEtatReglement'),
            'lignes' => $lines,
        ];
    }

    // Returns id (or name) => value of the hidden inputs of an HTML page or fragment.
    public static function hiddenInputs(string $html): array
    {
        $inputs = [];
        foreach (self::xpath($html)->query('//input[@type="hidden"]') as $input) {
            $key = $input->getAttribute('id') ?: $input->getAttribute('name');
            if ($key !== '') {
                $inputs[$key] = $input->getAttribute('value');
            }
        }
        return $inputs;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
