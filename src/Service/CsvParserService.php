<?php

namespace App\Service;

use App\Entity\CsvImportProfile;
use DateTimeImmutable;
use Exception;

readonly class CsvParserService
{
    public function analyzeFile(string $filePath, string $delimiter, string $encoding): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("Fichier CSV introuvable : $filePath");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception("Impossible d'ouvrir le fichier CSV");
        }

        $analysis = [
            'total_rows' => 0,
            'column_count' => 0,
            'consistent_columns' => true,
            'sample_rows' => [],
            'delimiter' => $delimiter,
            'encoding' => $encoding
        ];

        try {
            $expectedColumnCount = null;
            $maxRows = 10; // Analyser seulement les 10 premières lignes

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $analysis['total_rows'] < $maxRows) {
                $columnCount = count($row);

                if ($expectedColumnCount === null) {
                    $expectedColumnCount = $columnCount;
                    $analysis['column_count'] = $columnCount;
                } elseif ($columnCount !== $expectedColumnCount) {
                    $analysis['consistent_columns'] = false;
                }

                // Garder quelques lignes d'exemple
                if (count($analysis['sample_rows']) < 5) {
                    $analysis['sample_rows'][] = $row;
                }

                $analysis['total_rows']++;
            }
        } finally {
            fclose($handle);
        }

        return $analysis;
    }

    public function parseCsvFile(string $filePath, CsvImportProfile $profile): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("Fichier CSV introuvable : {$filePath}");
        }

        $data = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new Exception("Impossible d'ouvrir le fichier CSV");
        }

        try {
            $rowIndex = 0;
            while (($row = fgetcsv($handle, 0, $profile->getDelimiter())) !== false) {
                if ($profile->isHasHeader() && $rowIndex === 0) {
                    $rowIndex++;
                    continue;
                }

                try {
                    $parsedRow = $this->parseRow($row, $profile);
                    if ($parsedRow) {
                        $parsedRow['raw_data'] = $row;
                        $data[] = $parsedRow;
                    }
                } catch (Exception $e) {
                    $data[] = [
                        'error' => true,
                        'message' => $e->getMessage(),
                        'raw_data' => $row,
                        'row_index' => $rowIndex
                    ];
                }

                $rowIndex++;
            }
        } finally {
            fclose($handle);
        }

        return $data;
    }

    private function parseRow(array $row, CsvImportProfile $profile): ?array
    {
        $mapping = $profile->getColumnMapping();

        if (empty($mapping)) {
            throw new Exception("Configuration de mapping des colonnes manquante");
        }

        $parsedData = [];

        // Parse date
        if (!isset($mapping['date']) || !isset($row[$mapping['date']])) {
            throw new Exception("Colonne date manquante ou non configurée");
        }

        $dateString = trim($row[$mapping['date']]);
        if (empty($dateString)) {
            throw new Exception("Date vide dans la ligne");
        }

        $parsedData['date'] = $this->parseDate($dateString, $profile->getDateFormat());

        // Parse label
        if (!isset($mapping['label']) || !isset($row[$mapping['label']])) {
            throw new Exception("Colonne libellé manquante ou non configurée");
        }

        $parsedData['label'] = trim($row[$mapping['label']]);
        if (empty($parsedData['label'])) {
            throw new Exception("Libellé vide dans la ligne");
        }

        // Parse amount based on type
        if ($profile->getAmountType() === 'single') {
            if (!isset($mapping['amount']) || !isset($row[$mapping['amount']])) {
                throw new Exception("Colonne montant manquante ou non configurée");
            }

            $parsedData['amount'] = $this->parseAmount($row[$mapping['amount']]);
        } else {
            // Credit/Debit columns
            $creditAmount = 0;
            $debitAmount = 0;

            if (isset($mapping['credit']) && isset($row[$mapping['credit']])) {
                $creditValue = trim($row[$mapping['credit']]);
                if (!empty($creditValue)) {
                    $creditAmount = $this->parseAmount($creditValue);
                }
            }

            if (isset($mapping['debit']) && isset($row[$mapping['debit']])) {
                $debitValue = trim($row[$mapping['debit']]);
                if (!empty($debitValue)) {
                    $debitAmount = $this->parseAmount($debitValue);
                }
            }

            if ($creditAmount == 0 && $debitAmount == 0) {
                throw new Exception("Aucun montant trouvé dans les colonnes crédit/débit");
            }

            // Credit is positive, debit is negative
            $parsedData['amount'] = $creditAmount - $debitAmount;
        }

        return $parsedData;
    }

    private function parseDate(string $dateString, string $format): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($format, $dateString);

        if ($date === false) {
            // Try common fallback formats
            $fallbackFormats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];

            foreach ($fallbackFormats as $fallbackFormat) {
                if ($fallbackFormat !== $format) {
                    $date = DateTimeImmutable::createFromFormat($fallbackFormat, $dateString);
                    if ($date !== false) {
                        break;
                    }
                }
            }
        }

        if ($date === false) {
            throw new Exception("Format de date invalide : {$dateString} (format attendu : {$format})");
        }

        return $date;
    }

    private function parseAmount(string $amountString): float
    {
        if (empty($amountString)) {
            return 0.0;
        }

        // Clean amount string
        $cleanAmount = $amountString;

        // Remove currency symbols and spaces
        $cleanAmount = preg_replace('/[€$£¥\s]/', '', $cleanAmount);

        // Handle French format (comma as decimal separator)
        if (substr_count($cleanAmount, ',') === 1 && substr_count($cleanAmount, '.') === 0) {
            $cleanAmount = str_replace(',', '.', $cleanAmount);
        }

        // Remove thousand separators (assuming they are spaces, dots, or commas before the decimal)
        $cleanAmount = preg_replace('/[\s\.](?=\d{3}(\D|$))/', '', $cleanAmount);
        $cleanAmount = preg_replace('/,(?=\d{3}(\D|$))/', '', $cleanAmount);

        if (!is_numeric($cleanAmount)) {
            throw new Exception("Montant invalide : {$amountString}");
        }

        return (float)$cleanAmount;
    }

    public function detectDelimiter(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return ',';
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ',';
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if ($firstLine === false) {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }

        return array_search(max($counts), $counts) ?: ',';
    }
}
