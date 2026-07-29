<?php

namespace App\Service\Import;

use App\Dto\BankCsvRow;
use App\Entity\ImportBatch;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;
use App\Service\Matching\MatchingEngine;
use App\Service\Normalization\LabelNormalizer;
use App\Service\Recurrence\RecurrenceMatcher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pipeline d'import du CSV bancaire :
 * parsing → normalisation → dédoublonnage par comptage → cascade de matching.
 *
 * Le dédoublonnage se fait par comptage d'occurrences, jamais par hash
 * unique : si l'import contient N fois la ligne (date, libellé, montant) et
 * que la base en contient déjà M, on insère N−M. Les exports se chevauchent,
 * c'est le cas nominal ; les doublons légitimes existent.
 */
class TransactionImporter
{
    public function __construct(
        private readonly BankCsvParser $parser,
        private readonly LabelNormalizer $normalizer,
        private readonly MatchingEngine $matchingEngine,
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurrenceMatcher $recurrenceMatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function import(string $csvContent, string $filename): ImportBatch
    {
        $rows = $this->parser->parse($csvContent);
        // L'export est antichronologique : on remet dans l'ordre chronologique.
        $rows = array_reverse($rows);

        $batch = new ImportBatch($filename);

        $dedupKeys = [];
        foreach ($rows as $row) {
            $dedupKeys[] = Transaction::computeDedupKey($row->operationDate, $row->label, $row->amountCents);
        }
        $existingCounts = $this->transactionRepository->countByDedupKeys(array_values(array_unique($dedupKeys)));

        $newCount = 0;
        $duplicateCount = 0;
        $suggestedCount = 0;
        $toReviewCount = 0;

        $seen = [];
        foreach ($rows as $index => $row) {
            $key = $dedupKeys[$index];
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            // Les M premières occurrences de l'import sont déjà en base.
            if ($seen[$key] <= ($existingCounts[$key] ?? 0)) {
                ++$duplicateCount;
                continue;
            }

            $transaction = $this->createTransaction($row);
            $transaction->setImportBatch($batch);
            $this->recurrenceMatcher->attach($transaction);
            $this->entityManager->persist($transaction);

            ++$newCount;
            if ($transaction->getSuggestedCategory() !== null) {
                ++$suggestedCount;
            } else {
                ++$toReviewCount;
            }
        }

        $batch->setNewCount($newCount)
            ->setDuplicateCount($duplicateCount)
            ->setSuggestedCount($suggestedCount)
            ->setToReviewCount($toReviewCount);

        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return $batch;
    }

    private function createTransaction(BankCsvRow $row): Transaction
    {
        $normalized = $this->normalizer->normalize($row->label, $row->operationDate);

        $transaction = new Transaction(
            $row->operationDate,
            $row->valueDate,
            $row->label,
            $row->amountCents,
            $normalized->type,
        );
        $transaction->setTokens($normalized->tokens);
        $transaction->setPurchaseDate($normalized->purchaseDate);

        // Une annulation carte crédite sa catégorie d'origine : c'est un
        // remboursement, jamais un revenu.
        if ($normalized->type === TransactionType::AnnulationCarte) {
            $transaction->setNature(TransactionNature::Reimbursement);
        }

        $match = $this->matchingEngine->match($normalized, $row->amountCents, $row->operationDate);

        // Jamais de catégorisation automatique : le système propose,
        // l'utilisateur dispose.
        if ($match->isMatch() && $match->category !== null) {
            $transaction->setSuggestedCategory($match->category);
            $transaction->setMatchedRule($match->rule);
        }

        return $transaction;
    }
}
