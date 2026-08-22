<?php

namespace App\Service\Recurrence;

use App\Dto\NormalizedLabel;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recherche rétroactive : parcourt tout l'historique non rattaché et propose
 * les transactions qui ressemblent à des occurrences de la récurrence.
 * L'utilisateur tranche ligne par ligne : rattacher ou ignorer (un refus est
 * mémorisé et jamais reproposé).
 *
 * La tolérance de montant est glissante : une mensualité de 2024 est
 * comparée à l'occurrence rattachée la plus proche dans le temps, pas à
 * l'attendu d'aujourd'hui (cas réel : Bouygues 39,99 € pendant deux ans,
 * puis 24,99 €).
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
        return $this->candidatesAmong(
            $recurrence,
            $this->transactionRepository->findUnattachedByDirection($recurrence->getDirection()),
        );
    }

    /**
     * Nombre de correspondances possibles par récurrence, en un seul passage
     * sur l'historique (vignette « N à rattacher » de la liste).
     *
     * @param list<Recurrence> $recurrences
     *
     * @return array<string, int> indexé par id de récurrence
     */
    public function countCandidates(array $recurrences): array
    {
        $unattached = [];
        foreach (Direction::cases() as $direction) {
            $unattached[$direction->value] = $this->transactionRepository->findUnattachedByDirection($direction);
        }

        $counts = [];
        foreach ($recurrences as $recurrence) {
            $counts[(string) $recurrence->getId()] = \count($this->candidatesAmong($recurrence, $unattached[$recurrence->getDirection()->value]));
        }

        return $counts;
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
        $recurrence->addFingerprint((new NormalizedLabel($transaction->getType(), $transaction->getTokens()))->getFingerprint());

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

    /**
     * @param list<Transaction> $unattached
     *
     * @return list<Transaction>
     */
    private function candidatesAmong(Recurrence $recurrence, array $unattached): array
    {
        $attached = $this->transactionRepository->findBy(['recurrence' => $recurrence], ['operationDate' => 'ASC']);
        $types = array_values(array_unique(array_map(static fn (Transaction $t): TransactionType => $t->getType(), $attached), SORT_REGULAR));

        $candidates = [];
        foreach ($unattached as $transaction) {
            if ($recurrence->isTransactionExcluded($transaction)) {
                continue;
            }
            // Le repli date + montant ne traverse pas les types : un
            // prélèvement ne dérive jamais en paiement carte (cas réel : 71
            // achats à ~10 € proposés pour une cotisation bancaire).
            if ($types !== [] && !\in_array($transaction->getType(), $types, true)
                && !$this->recurrenceMatcher->matchesLabel($recurrence, $transaction)) {
                continue;
            }
            $reference = $this->nearestAttachedAmount($attached, $transaction->getOperationDate());
            if ($this->recurrenceMatcher->matches($recurrence, $transaction, $reference)) {
                $candidates[] = $transaction;
            }
        }

        return $candidates;
    }

    /**
     * Montant de l'occurrence rattachée la plus proche dans le temps, ou
     * null s'il n'y en a aucune (l'attendu courant fait alors référence).
     *
     * @param list<Transaction> $attached triées chronologiquement
     */
    private function nearestAttachedAmount(array $attached, \DateTimeImmutable $date): ?int
    {
        $nearest = null;
        $nearestDistance = null;
        foreach ($attached as $occurrence) {
            $distance = abs((int) $occurrence->getOperationDate()->diff($date)->days);
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $occurrence->getAmountCents();
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }
}
