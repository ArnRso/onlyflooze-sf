<?php

namespace App\Service\Recurrence;

use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recherche rétroactive : parcourt tout l'historique non rattaché et propose
 * les transactions qui ressemblent à des occurrences de la récurrence.
 * L'utilisateur tranche ligne par ligne : rattacher ou ignorer (un refus est
 * mémorisé et jamais reproposé).
 */
class RecurrenceBackfill
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurrenceMatcher $recurrenceMatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Transaction>
     */
    public function findCandidates(Recurrence $recurrence): array
    {
        $candidates = [];
        foreach ($this->transactionRepository->findUnattachedByDirection($recurrence->getDirection()) as $transaction) {
            if ($recurrence->isTransactionExcluded($transaction)) {
                continue;
            }
            if ($this->recurrenceMatcher->matches($recurrence, $transaction)) {
                $candidates[] = $transaction;
            }
        }

        return $candidates;
    }

    /**
     * L'utilisateur confirme : la transaction devient une occurrence de la
     * récurrence. Pas de signalement d'écart ici : comparer une mensualité
     * historique au montant attendu ACTUEL n'a pas de sens (cas réel : les
     * mensualités gaz à 99 € de l'hiver face à un attendu retombé à 83 €).
     */
    public function attach(Recurrence $recurrence, Transaction $transaction): void
    {
        $transaction->setRecurrence($recurrence);

        if ($transaction->getCategory() === null && $recurrence->getCategory() !== null) {
            $transaction->setSuggestedCategory($transaction->getSuggestedCategory() ?? $recurrence->getCategory());
        }

        $this->entityManager->flush();
    }

    /**
     * L'utilisateur refuse : mémorisé, la ligne ne sera plus jamais proposée
     * pour cette récurrence.
     */
    public function exclude(Recurrence $recurrence, Transaction $transaction): void
    {
        $recurrence->excludeTransaction($transaction);

        $this->entityManager->flush();
    }

    /**
     * Rattachement fait par erreur : la transaction est détachée ET exclue
     * (sans exclusion, la recherche rétroactive la reproposerait aussitôt).
     */
    public function detach(Recurrence $recurrence, Transaction $transaction): void
    {
        $transaction->setRecurrence(null);
        $transaction->setAmountOutOfTolerance(false);
        $recurrence->excludeTransaction($transaction);

        $this->entityManager->flush();
    }
}
