<?php

namespace App\Service\Recurrence;

use App\Dto\RecurrenceSuggestion;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détection des récurrences par observation : le système PROPOSE la
 * promotion, l'utilisateur dispose. Jamais de création automatique.
 *
 * On ne surveille que les préfixes PRLV/ECH PRET/F et les crédits VIR
 * réguliers, groupés par règle apprise : ≥ 2 occurrences espacées d'environ
 * un mois → suggestion.
 */
class RecurrenceDetector
{
    private const int MIN_OCCURRENCES = 2;
    private const int MIN_INTERVAL_DAYS = 25;
    private const int MAX_INTERVAL_DAYS = 35;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurrenceRepository $recurrenceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<RecurrenceSuggestion>
     */
    public function suggest(): array
    {
        $candidates = $this->transactionRepository->findRecurrenceCandidates();

        $rulesWithRecurrence = [];
        foreach ($this->recurrenceRepository->findAll() as $recurrence) {
            if ($recurrence->getRule() !== null) {
                $rulesWithRecurrence[(string) $recurrence->getRule()->getId()] = true;
            }
        }

        /** @var array<string, list<Transaction>> $groups */
        $groups = [];
        foreach ($candidates as $transaction) {
            $rule = $transaction->getMatchedRule();
            if ($rule === null || isset($rulesWithRecurrence[(string) $rule->getId()])) {
                continue;
            }
            $groups[(string) $rule->getId()][] = $transaction;
        }

        $suggestions = [];
        foreach ($groups as $transactions) {
            $suggestion = $this->buildSuggestion($transactions);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Promotion : crée la récurrence et rattache les occurrences passées.
     */
    public function promote(RecurrenceSuggestion $suggestion): Recurrence
    {
        $recurrence = new Recurrence(
            $suggestion->rule->getName(),
            $suggestion->direction,
            $suggestion->expectedDayOfMonth,
            $suggestion->expectedAmountCents,
        );
        $recurrence->setCategory($suggestion->category);
        $recurrence->setRule($suggestion->rule);

        $this->entityManager->persist($recurrence);

        foreach ($this->transactionRepository->findRecurrenceCandidates() as $transaction) {
            if ($transaction->getMatchedRule() === $suggestion->rule) {
                $transaction->setRecurrence($recurrence);
            }
        }

        $this->entityManager->flush();

        return $recurrence;
    }

    /**
     * L'utilisateur écarte la proposition : elle ne sera plus refaite.
     */
    public function dismiss(RecurrenceSuggestion $suggestion): void
    {
        $suggestion->rule->setRecurrenceOptOut(true);
        $this->entityManager->flush();
    }

    /**
     * Retrouve la suggestion courante portée par une règle (actions des
     * boutons promouvoir/ignorer de l'interface).
     */
    public function findSuggestionForRule(string $ruleId): ?RecurrenceSuggestion
    {
        foreach ($this->suggest() as $suggestion) {
            if ((string) $suggestion->rule->getId() === $ruleId) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @param list<Transaction> $transactions triées chronologiquement
     */
    private function buildSuggestion(array $transactions): ?RecurrenceSuggestion
    {
        if (\count($transactions) < self::MIN_OCCURRENCES) {
            return null;
        }

        if (!$this->hasMonthlyInterval($transactions)) {
            return null;
        }

        $rule = $transactions[0]->getMatchedRule();
        if ($rule === null) {
            return null;
        }

        $latest = \array_slice($transactions, -3);

        return new RecurrenceSuggestion(
            $rule,
            $transactions[\count($transactions) - 1]->getCategory(),
            $transactions[0]->getDirection(),
            (int) $transactions[\count($transactions) - 1]->getOperationDate()->format('j'),
            $this->averageAmountCents($latest),
            $transactions[\count($transactions) - 1]->getOperationDate(),
            $transactions,
        );
    }

    /**
     * Au moins une paire d'occurrences consécutives espacées d'environ un mois.
     *
     * @param list<Transaction> $transactions triées chronologiquement
     */
    private function hasMonthlyInterval(array $transactions): bool
    {
        for ($i = 1, $count = \count($transactions); $i < $count; ++$i) {
            $days = (int) $transactions[$i - 1]->getOperationDate()->diff($transactions[$i]->getOperationDate())->days;
            if ($days >= self::MIN_INTERVAL_DAYS && $days <= self::MAX_INTERVAL_DAYS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Montant attendu = moyenne des 2-3 dernières occurrences, jamais de
     * l'historique complet (les montants dérivent : EDF 76,33 → 88,00 → 84,09).
     *
     * @param list<Transaction> $transactions
     */
    private function averageAmountCents(array $transactions): int
    {
        $amounts = array_map(static fn (Transaction $t): int => $t->getAmountCents(), $transactions);

        return (int) round(array_sum($amounts) / \count($amounts));
    }
}
