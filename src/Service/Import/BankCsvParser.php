<?php

namespace App\Service\Import;

use App\Dto\BankCsvRow;
use App\Exception\CsvParseException;

/**
 * Parse le CSV exporté par la banque.
 *
 * Format constaté : séparateur ";", champs entre guillemets, colonnes
 * "Date operation;Date valeur;Libelle;Debit;Credit", montants au format
 * français (virgule décimale), ordre antichronologique. L'encodage est
 * détecté (UTF-8 ou Windows-1252/ISO-8859-1).
 */
class BankCsvParser
{
    private const array EXPECTED_HEADER = ['date operation', 'date valeur', 'libelle', 'debit', 'credit'];

    /**
     * @return list<BankCsvRow>
     */
    public function parse(string $content): array
    {
        $content = $this->toUtf8($content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            throw new CsvParseException('Contenu illisible.');
        }

        $rows = [];
        $headerChecked = false;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, ';', '"', '\\');

            if (!$headerChecked) {
                $this->assertHeader($fields, $lineNumber);
                $headerChecked = true;
                continue;
            }

            if (\count($fields) < 5) {
                throw CsvParseException::forLine($lineNumber, sprintf('5 colonnes attendues, %d trouvée(s).', \count($fields)));
            }

            $rows[] = new BankCsvRow(
                $this->parseDate((string) $fields[0], $lineNumber),
                $this->parseDate((string) $fields[1], $lineNumber),
                trim((string) $fields[2]),
                $this->parseAmountCents((string) $fields[3], (string) $fields[4], $lineNumber),
            );
        }

        if (!$headerChecked) {
            throw new CsvParseException('Fichier vide ou sans en-tête.');
        }

        return $rows;
    }

    private function toUtf8(string $content): string
    {
        // Retire un éventuel BOM UTF-8
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param list<string|null> $fields
     */
    private function assertHeader(array $fields, int $lineNumber): void
    {
        $normalized = array_map(
            static fn (?string $field): string => mb_strtolower(trim((string) $field)),
            $fields,
        );

        if (array_slice($normalized, 0, 5) !== self::EXPECTED_HEADER) {
            throw CsvParseException::forLine($lineNumber, sprintf('En-tête inattendu : "%s". Attendu : "Date operation;Date valeur;Libelle;Debit;Credit".', implode(';', array_map(strval(...), $fields))));
        }
    }

    private function parseDate(string $value, int $lineNumber): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', trim($value));
        if ($date === false) {
            throw CsvParseException::forLine($lineNumber, sprintf('Date invalide : "%s".', $value));
        }

        return $date;
    }

    private function parseAmountCents(string $debit, string $credit, int $lineNumber): int
    {
        $debit = trim($debit);
        $credit = trim($credit);

        if ($debit !== '' && $credit !== '') {
            throw CsvParseException::forLine($lineNumber, 'Débit et crédit renseignés simultanément.');
        }

        if ($debit === '' && $credit === '') {
            throw CsvParseException::forLine($lineNumber, 'Ni débit ni crédit renseigné.');
        }

        $raw = $debit !== '' ? $debit : $credit;
        $cents = $this->frenchAmountToCents($raw, $lineNumber);

        return $debit !== '' ? -$cents : $cents;
    }

    private function frenchAmountToCents(string $amount, int $lineNumber): int
    {
        // Supprime les séparateurs de milliers (espace, espace insécable, point)
        $normalized = str_replace([' ', "\u{a0}", "\u{202f}"], '', $amount);
        $normalized = (string) preg_replace('/\.(?=\d{3}(\D|$))/', '', $normalized);

        if (preg_match('/^-?\d+(?:,\d{1,2})?$/', $normalized) !== 1) {
            throw CsvParseException::forLine($lineNumber, sprintf('Montant invalide : "%s".', $amount));
        }

        [$units, $decimals] = array_pad(explode(',', $normalized, 2), 2, '0');
        $sign = str_starts_with($units, '-') ? -1 : 1;
        $units = ltrim($units, '-');

        return $sign * ((int) $units * 100 + (int) str_pad($decimals, 2, '0'));
    }
}
