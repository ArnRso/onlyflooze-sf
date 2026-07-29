<?php

namespace App\Tests\Service\Import;

use App\Exception\CsvParseException;
use App\Service\Import\BankCsvParser;
use PHPUnit\Framework\TestCase;

class BankCsvParserTest extends TestCase
{
    private BankCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BankCsvParser();
    }

    public function testParseNominal(): void
    {
        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""
            "24/07/2026";"24/07/2026";"VIR RADIANCE MUTUELLE";"";"29,98"
            "23/07/2026";"23/07/2026";"CARTE 22/07 CARREFOUR HYPER LORMONT";"83,43";""
            CSV;

        $rows = $this->parser->parse($csv);

        self::assertCount(3, $rows);

        self::assertSame('2026-07-24', $rows[0]->operationDate->format('Y-m-d'));
        self::assertSame('CARTE 23/07 TCHU BORDEAUX', $rows[0]->label);
        self::assertSame(-1290, $rows[0]->amountCents);

        self::assertSame(2998, $rows[1]->amountCents);

        self::assertSame(-8343, $rows[2]->amountCents);
    }

    public function testParseKeepsLegitimateDuplicates(): void
    {
        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""
            "24/07/2026";"24/07/2026";"CARTE 23/07 TABAC LE CELTIQUE CENON";"10,50";""
            CSV;

        $rows = $this->parser->parse($csv);

        self::assertCount(2, $rows);
    }

    public function testParseWindows1252Content(): void
    {
        $csv = "\"Date operation\";\"Date valeur\";\"Libelle\";\"Debit\";\"Credit\"\n"
            ."\"24/07/2026\";\"24/07/2026\";\"CARTE 23/07 BOULANGERIE P\xE2TISSERIE\";\"3,20\";\"\"";

        $rows = $this->parser->parse($csv);

        self::assertSame('CARTE 23/07 BOULANGERIE PâTISSERIE', $rows[0]->label);
    }

    public function testParseUtf8Bom(): void
    {
        $csv = "\xEF\xBB\xBF\"Date operation\";\"Date valeur\";\"Libelle\";\"Debit\";\"Credit\"\n"
            .'"24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""';

        $rows = $this->parser->parse($csv);

        self::assertCount(1, $rows);
    }

    public function testParseThousandsAmount(): void
    {
        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "27/12/2025";"27/12/2025";"PRLV DGFIP FINANCES PUBLIQUES";"2 278,00";""
            CSV;

        $rows = $this->parser->parse($csv);

        self::assertSame(-227800, $rows[0]->amountCents);
    }

    public function testParseAmountWithoutDecimals(): void
    {
        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "27/12/2025";"27/12/2025";"VIR HPG";"";"2500"
            CSV;

        $rows = $this->parser->parse($csv);

        self::assertSame(250000, $rows[0]->amountCents);
    }

    public function testRejectsUnknownHeader(): void
    {
        $this->expectException(CsvParseException::class);

        $this->parser->parse("\"Date\";\"Montant\"\n\"24/07/2026\";\"12,90\"");
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(CsvParseException::class);

        $this->parser->parse('');
    }

    public function testRejectsRowWithBothDebitAndCredit(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('Ligne 2');

        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";"5,00"
            CSV;

        $this->parser->parse($csv);
    }

    public function testRejectsRowWithoutAmount(): void
    {
        $this->expectException(CsvParseException::class);

        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "24/07/2026";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"";""
            CSV;

        $this->parser->parse($csv);
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(CsvParseException::class);

        $csv = <<<CSV
            "Date operation";"Date valeur";"Libelle";"Debit";"Credit"
            "2026-07-24";"24/07/2026";"CARTE 23/07 TCHU BORDEAUX";"12,90";""
            CSV;

        $this->parser->parse($csv);
    }
}
