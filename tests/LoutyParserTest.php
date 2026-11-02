<?php

// Tests the Louty HTML parser and the entry mapping on hand-written fixtures that copy Louty's markup.

namespace YesWiki\Importer\Test;

use PHPUnit\Framework\TestCase;
use YesWiki\Importer\Service\LoutyImporter;
use YesWiki\Importer\Service\LoutyParser;

class LoutyParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/fixtures/louty/' . $name);
    }

    public function testAmountsAndDates(): void
    {
        $this->assertSame(1078.0, LoutyParser::amount("1\u{00A0}078,00"));
        $this->assertSame(-9999.99, LoutyParser::amount('-9 999,99'));
        $this->assertSame(93.6, LoutyParser::amount('93,600'));
        $this->assertNull(LoutyParser::amount(''));
        $this->assertNull(LoutyParser::amount('n/a'));
        $this->assertSame('2026-01-15', LoutyParser::date('15/01/2026'));
        $this->assertNull(LoutyParser::date(''));
    }

    public function testPieceList(): void
    {
        [$facture, $devis] = LoutyParser::pieceList(self::fixture('liste.html'));
        $this->assertSame(1001, $facture['id']);
        $this->assertSame('FC', $facture['type']);
        $this->assertSame('20260042', $facture['numero']);
        $this->assertSame('2026-01-15', $facture['date']);
        $this->assertSame('AAYESWI0', $facture['activite']);
        $this->assertSame(777, $facture['clientId']);
        $this->assertSame(1078.0, $facture['montantHT']);
        $this->assertSame(1293.6, $facture['montantTTC']);
        $this->assertSame('C', $facture['etat']);
        $this->assertSame('TOTAL', $facture['reglement']);
        $this->assertSame('2026-02-14', $facture['dateEcheance']);
        $this->assertNull($facture['factureNumero']);

        $this->assertSame('DV', $devis['type']);
        $this->assertSame('Validé & imp.', $devis['etatLabel']);
        $this->assertSame('20260042', $devis['factureNumero']);
        $this->assertSame('NON_REGLABLE', $devis['reglement']);
        $this->assertSame('2026-01-05', $devis['dateAccord']);
    }

    public function testPieceDetail(): void
    {
        $piece = LoutyParser::piece(self::fixture('piece.html'));
        $this->assertSame(1001, $piece['id']);
        $this->assertSame('AAYESWI0', $piece['activite']);
        $this->assertSame(1002, $piece['pieceOrigineId']);
        $this->assertSame('Payée', $piece['reglement']);
        $this->assertCount(2, $piece['lignes']);

        [$hebergement, $autre] = $piece['lignes'];
        $this->assertSame(5001, $hebergement['id']);
        $this->assertSame('Hébergement Solo 3 Go', $hebergement['libelle']);
        $this->assertSame('exemple.org, du 01/01/2026 au 31/12/2026', $hebergement['memo']);
        $this->assertSame('PS 20%', $hebergement['article']);
        $this->assertSame('TTC', $hebergement['modePrix']);
        $this->assertSame(78.0, $hebergement['montantHT']);
        $this->assertSame(4001, $hebergement['ligneOrigineId']);

        $this->assertSame('AAAUTRE0', $autre['activite']);
        $this->assertSame(1000.0, $autre['montantHT']);
        $this->assertSame('', $autre['memo']);
        $this->assertNull($autre['ligneOrigineId']);
    }

    public function testHiddenInputs(): void
    {
        $inputs = LoutyParser::hiddenInputs('<tr id="pli_-1"><td><input type="hidden" id="pli_article_key_-1" value="7431" /><input type="hidden" id="pli_unite_key_-1" value="546" /><input type="text" id="x" value="no" /></td></tr>');
        $this->assertSame(['pli_article_key_-1' => '7431', 'pli_unite_key_-1' => '546'], $inputs);
        $this->assertSame('10,00', \YesWiki\Importer\Service\LoutyClient::frenchNumber(10));
        $this->assertSame('45,50', \YesWiki\Importer\Service\LoutyClient::frenchNumber(45.5));
    }

    public function testMapping(): void
    {
        [$facture] = LoutyParser::pieceList(self::fixture('liste.html'));
        $detail = LoutyParser::piece(self::fixture('piece.html'));
        $detail['lignesIgnorees'] = 1;
        $entry = LoutyImporter::mapPiece($facture, $detail);
        $this->assertSame('Facture 20260042 - Association Exemple', $entry['bf_titre']);
        $this->assertSame('facture', $entry['bf_type']);
        $this->assertSame('1078.00', $entry['bf_montant_ht']);
        $this->assertSame('1', $entry['bf_lignes_ignorees']);
        $this->assertSame(LoutyImporter::hash($facture), $entry['bf_louty_hash']);

        $ligne = LoutyImporter::mapLigne($detail['lignes'][0], $facture);
        $this->assertSame('20260042 #1 Hébergement Solo 3 Go', $ligne['bf_titre']);
        $this->assertSame('78.00', $ligne['bf_montant_ht']);
        $this->assertSame(4001, $ligne['_ligneOrigineId']);
    }

    public function testFormTemplatesLinkTheConfiguredForms(): void
    {
        $config = ['clientFormId' => 101, 'pieceFormId' => 102, 'ligneFormId' => 103];
        $this->assertStringContainsString('listefiche***101***Client*** *** *** ***bf_client***', LoutyImporter::formTemplate('piece', $config));
        $this->assertStringContainsString('listefiche***102***Pièce*** *** *** ***bf_piece***', LoutyImporter::formTemplate('ligne', $config));
        $this->assertStringEndsWith('acls***@admins***@admins***@admins***', LoutyImporter::formTemplate('client', $config));
    }
}
