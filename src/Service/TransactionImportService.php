<?php

namespace App\Service;

use App\Entity\CsvImportProfile;
use App\Entity\CsvImportSession;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

readonly class TransactionImportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CsvParserService       $csvParserService
    )
    {
    }

    public function importTransactionsFromCsv(
        string           $filePath,
        CsvImportProfile $profile,
        User             $user
    ): CsvImportSession
    {
        // Create import session
        $session = new CsvImportSession();
        $session->setUser($user);
        $session->setProfile($profile);
        $session->setFilename(basename($filePath));
        $session->setStatus('processing');

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        try {
            // Parse CSV data
            $csvData = $this->csvParserService->parseCsvFile($filePath, $profile);

            $session->setTotalRows(count($csvData));

            $results = $this->processImportData($csvData, $user);

            // Update session with results
            $session->setSuccessfulImports($results['success']);
            $session->setDuplicates($results['duplicates']);
            $session->setErrors($results['errors']);
            $session->setErrorDetails($results['error_details']);
            $session->setStatus('completed');

        } catch (Exception $e) {
            $session->setStatus('failed');
            $session->setNotes($e->getMessage());
            $session->setErrors(1);
        }

        $this->entityManager->flush();

        return $session;
    }

    private function processImportData(array $csvData, User $user): array
    {
        $results = [
            'success' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'error_details' => []
        ];

        foreach ($csvData as $index => $rowData) {
            // Skip rows that had parsing errors
            if (isset($rowData['error']) && $rowData['error']) {
                $results['errors']++;
                $results['error_details'][] = [
                    'row' => $index + 1,
                    'message' => $rowData['message'],
                    'data' => $rowData['row_data'] ?? null
                ];
                continue;
            }

            try {
                $transaction = $this->createTransactionFromRowData($rowData, $user);

                // Check for duplicates manually as backup
                if ($this->isDuplicateTransaction($transaction, $user)) {
                    $results['duplicates']++;
                    continue;
                }

                $this->entityManager->persist($transaction);
                $this->entityManager->flush(); // Flush immediately to catch constraint violations

                $results['success']++;

            } catch (UniqueConstraintViolationException $e) {
                $results['duplicates']++;
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'row' => $index + 1,
                    'message' => $e->getMessage(),
                    'data' => $rowData
                ];
            }
        }

        // Final flush
        $this->entityManager->flush();

        return $results;
    }

    private function createTransactionFromRowData(array $rowData, User $user): Transaction
    {
        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setTransactionDate($rowData['date']);
        $transaction->setLabel($rowData['label']);
        $transaction->setAmount((string)$rowData['amount']);

        // Try to determine budget month from transaction date
        $budgetMonth = $rowData['date']->format('Y-m');
        $transaction->setBudgetMonth($budgetMonth);

        return $transaction;
    }

    private function isDuplicateTransaction(Transaction $transaction, User $user): bool
    {
        $existingTransaction = $this->entityManager->getRepository(Transaction::class)
            ->findOneBy([
                'user' => $user,
                'transactionDate' => $transaction->getTransactionDate(),
                'amount' => $transaction->getAmount(),
                'label' => $transaction->getLabel()
            ]);

        return $existingTransaction !== null;
    }

    public function previewCsvData(string $filePath, CsvImportProfile $profile, int $limit = 10): array
    {
        try {
            // Parse raw CSV with sample data and parsed data
            $csvData = $this->csvParserService->parseCsvFile($filePath, $profile);

            $validRows = 0;
            $sampleData = [];
            $errors = [];

            foreach (array_slice($csvData, 0, $limit) as $index => $rowData) {
                $rowResult = [
                    'raw_data' => $rowData['raw_data'] ?? [],
                    'parsed_data' => [],
                    'status' => 'ok',
                    'message' => null
                ];

                if (isset($rowData['error']) && $rowData['error']) {
                    $rowResult['status'] = 'error';
                    $rowResult['message'] = $rowData['message'] ?? 'Erreur de parsing';
                    $errors[] = "Ligne " . ($index + 1) . ": " . $rowResult['message'];
                } else {
                    $rowResult['parsed_data'] = [
                        'date' => $rowData['date']->format('Y-m-d'),
                        'label' => $rowData['label'],
                        'amount' => $rowData['amount']
                    ];
                    $validRows++;
                }

                $sampleData[] = $rowResult;
            }

            return [
                'total_rows' => count($csvData),
                'valid_rows' => $validRows,
                'sample_data' => $sampleData,
                'errors' => $errors,
                'success' => true
            ];

        } catch (Exception $e) {
            return [
                'total_rows' => 0,
                'valid_rows' => 0,
                'sample_data' => [],
                'errors' => [$e->getMessage()],
                'success' => false
            ];
        }
    }

    public function validateCsvFile(string $filePath): array
    {
        $errors = [];

        if (!file_exists($filePath)) {
            $errors[] = "Le fichier n'existe pas";
            return ['valid' => false, 'errors' => $errors];
        }

        if (!is_readable($filePath)) {
            $errors[] = "Le fichier n'est pas lisible";
            return ['valid' => false, 'errors' => $errors];
        }

        $fileSize = filesize($filePath);
        $maxSize = 10 * 1024 * 1024; // 10MB

        if ($fileSize > $maxSize) {
            $errors[] = "Le fichier est trop volumineux (max 10MB)";
        }

        $fileInfo = pathinfo($filePath);
        if (!isset($fileInfo['extension']) || strtolower($fileInfo['extension']) !== 'csv') {
            $errors[] = "Le fichier doit être au format CSV";
        }

        // Test opening the file
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $errors[] = "Impossible d'ouvrir le fichier CSV";
        } else {
            // Test reading first line
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                $errors[] = "Le fichier CSV semble vide";
            }
            fclose($handle);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'file_size' => $fileSize,
            'file_info' => $fileInfo ?? null
        ];
    }
}
