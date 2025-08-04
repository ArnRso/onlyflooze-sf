<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\RecurringTransactionRepository;
use DateMalformedStringException;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

readonly class RecurringTransactionService
{
    public function __construct(
        private EntityManagerInterface         $entityManager,
        private RecurringTransactionRepository $recurringTransactionRepository
    )
    {
    }

    public function createRecurringTransaction(RecurringTransaction $recurringTransaction, User $user): RecurringTransaction
    {
        $recurringTransaction->setUser($user);

        $this->entityManager->persist($recurringTransaction);
        $this->entityManager->flush();

        return $recurringTransaction;
    }

    public function updateRecurringTransaction(RecurringTransaction $recurringTransaction): RecurringTransaction
    {
        $this->entityManager->flush();

        return $recurringTransaction;
    }

    public function deleteRecurringTransaction(RecurringTransaction $recurringTransaction): void
    {
        $this->entityManager->remove($recurringTransaction);
        $this->entityManager->flush();
    }

    public function getUserRecurringTransactionById(User $user, UuidInterface $id): ?RecurringTransaction
    {
        return $this->recurringTransactionRepository->findByUserAndId($user, $id);
    }

    public function getUserRecurringTransactionByIdWithTransactionsAndTags(User $user, UuidInterface $id): ?RecurringTransaction
    {
        return $this->recurringTransactionRepository->findByUserAndIdWithTransactionsAndTags($user, $id);
    }

    public function getUserRecurringTransactionCount(User $user): int
    {
        return $this->recurringTransactionRepository->getCountByUser($user);
    }

    /**
     * @return array{total_transactions: int, total_amount: float, average_amount: float}
     */
    public function getRecurringTransactionStats(RecurringTransaction $recurringTransaction): array
    {
        $transactions = $recurringTransaction->getTransactions();
        $totalTransactions = $transactions->count();
        $totalAmount = 0;

        foreach ($transactions as $transaction) {
            $totalAmount += $transaction->getAmountAsFloat();
        }

        $averageAmount = $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0;

        return [
            'total_transactions' => $totalTransactions,
            'total_amount' => $totalAmount,
            'average_amount' => $averageAmount,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getMonthlyTotalsForRecurringTransaction(RecurringTransaction $recurringTransaction): array
    {
        $transactions = $recurringTransaction->getTransactions();
        $monthlyTotals = [];

        foreach ($transactions as $transaction) {
            $budgetMonth = $transaction->getBudgetMonth();
            if ($budgetMonth) {
                if (!isset($monthlyTotals[$budgetMonth])) {
                    $monthlyTotals[$budgetMonth] = 0;
                }
                $monthlyTotals[$budgetMonth] += $transaction->getAmountAsFloat();
            }
        }

        ksort($monthlyTotals);

        return $monthlyTotals;
    }

    /**
     * @return array<string, float>
     */
    public function getMonthlyTotalsForUser(User $user): array
    {
        $recurringTransactions = $this->getUserRecurringTransactionsWithTransactions($user);
        $monthlyTotals = [];

        foreach ($recurringTransactions as $recurringTransaction) {
            foreach ($recurringTransaction->getTransactions() as $transaction) {
                $budgetMonth = $transaction->getBudgetMonth();
                if ($budgetMonth) {
                    if (!isset($monthlyTotals[$budgetMonth])) {
                        $monthlyTotals[$budgetMonth] = 0;
                    }
                    $monthlyTotals[$budgetMonth] += $transaction->getAmountAsFloat();
                }
            }
        }

        ksort($monthlyTotals);

        return $monthlyTotals;
    }

    /**
     * @return RecurringTransaction[]
     */
    public function getUserRecurringTransactionsWithTransactions(User $user): array
    {
        return $this->recurringTransactionRepository->findByUserWithTransactions($user);
    }

    /**
     * Récupère les transactions récurrentes de l'utilisateur avec le nombre de transactions
     * @return array<int, array{0: RecurringTransaction, transactionCount: int}>
     */
    public function getUserRecurringTransactionsWithCount(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('rt', 'COUNT(t.id) as transactionCount')
            ->from(RecurringTransaction::class, 'rt')
            ->leftJoin('rt.transactions', 't')
            ->where('rt.user = :user')
            ->setParameter('user', $user)
            ->groupBy('rt.id')
            ->orderBy('rt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les données de récapitulatif mensuel pour les transactions récurrentes
     * @return array{recurring_transactions: array<int, array<string, mixed>>, totals: array{expected: float, paid: float, remaining: float}, available_months: array<string>}
     * @throws DateMalformedStringException
     */
    public function getMonthlyRecapData(User $user, string $selectedMonth): array
    {
        // Récupérer toutes les transactions récurrentes de l'utilisateur
        $recurringTransactions = $this->getUserRecurringTransactions($user);
        $recurringIds = array_map(static fn($rt) => $rt->getId()?->toString() ?? '', $recurringTransactions);
        $recurringIds = array_filter($recurringIds);

        if (empty($recurringIds)) {
            return [
                'recurring_transactions' => [],
                'totals' => ['expected' => 0, 'paid' => 0, 'remaining' => 0],
                'available_months' => $this->getAvailableMonths($user),
            ];
        }

        $previousMonth = $this->getPreviousMonth($selectedMonth);

        // Requête groupée pour toutes les transactions du mois courant
        $currentMonthData = $this->getMonthlyDataForAllRecurring($recurringIds, $selectedMonth);

        // Requête groupée pour toutes les transactions du mois précédent
        $previousMonthData = $this->getMonthlyDataForAllRecurring($recurringIds, $previousMonth);

        // Récupérer les dernières transactions pour les récurrentes sans données du mois précédent
        $recurringWithoutPrevious = [];
        foreach ($recurringTransactions as $recurring) {
            $id = $recurring->getId()?->toString();
            if (!$id) {
                continue;
            }
            if (empty($previousMonthData[$id])) {
                $recurringWithoutPrevious[] = $recurring;
            }
        }
        $latestTransactions = $this->getLatestTransactionsForRecurring($recurringWithoutPrevious);

        $recap = [
            'recurring_transactions' => [],
            'totals' => ['expected' => 0, 'paid' => 0, 'remaining' => 0],
            'available_months' => $this->getAvailableMonths($user),
        ];

        foreach ($recurringTransactions as $recurring) {
            $id = $recurring->getId()?->toString();
            if (!$id) {
                continue;
            }

            // Données du mois courant
            $currentData = $currentMonthData[$id] ?? ['transactions' => [], 'total' => 0];
            $transactionsForMonth = $currentData['transactions'];
            $totalPaidAmount = $currentData['total'];

            // Données du mois précédent pour le montant attendu
            $previousData = $previousMonthData[$id] ?? ['transactions' => [], 'total' => 0];
            $totalExpectedAmount = $previousData['total'];

            // Si pas de mois précédent, utiliser la dernière transaction connue
            if ($totalExpectedAmount == 0 && isset($latestTransactions[$id])) {
                $totalExpectedAmount = $latestTransactions[$id]->getAmountAsFloat();
            }

            // Calculs pour l'affichage
            $expectedFrequency = count($previousData['transactions']);
            $unitExpectedAmount = $expectedFrequency > 0 ? $totalExpectedAmount / $expectedFrequency : $totalExpectedAmount;

            $isPaid = count($transactionsForMonth) > 0;
            $latestTransaction = $isPaid ? $transactionsForMonth[0] : null;
            $remainingAmount = max(0, $totalExpectedAmount - $totalPaidAmount);

            $recurringData = [
                'recurring_transaction' => $recurring,
                'expected_amount' => $totalExpectedAmount,
                'is_paid' => $isPaid,
                'paid_amount' => $totalPaidAmount,
                'latest_transaction' => $latestTransaction,
                'transactions_count' => count($transactionsForMonth),
                'remaining_amount' => $remainingAmount,
                'expected_frequency' => $expectedFrequency,
                'unit_amount' => $unitExpectedAmount,
            ];

            $recap['recurring_transactions'][] = $recurringData;

            // Calculer les totaux
            $recap['totals']['expected'] += $totalExpectedAmount;
            $recap['totals']['paid'] += $totalPaidAmount;
        }

        $recap['totals']['remaining'] = $recap['totals']['expected'] - $recap['totals']['paid'];

        return $recap;
    }

    /**
     * @return RecurringTransaction[]
     */
    public function getUserRecurringTransactions(User $user): array
    {
        return $this->recurringTransactionRepository->findByUser($user);
    }

    /**
     * Récupère tous les mois budgétaires disponibles
     * @return array<string>
     */
    private function getAvailableMonths(User $user): array
    {
        $results = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT t.budgetMonth')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.budgetMonth IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('t.budgetMonth', 'DESC')
            ->getQuery()
            ->getResult();

        $months = array_column($results, 'budgetMonth');
        return array_filter($months, static fn($month) => $month !== null);
    }

    /**
     * Calcule le mois budgétaire précédent
     * @throws DateMalformedStringException
     */
    private function getPreviousMonth(string $currentMonth): string
    {
        $date = DateTime::createFromFormat('Y-m', $currentMonth);
        if (!$date) {
            return '';
        }

        $date->modify('-1 month');
        return $date->format('Y-m');
    }

    /**
     * Récupère toutes les données mensuelles pour plusieurs récurrentes en une seule requête
     * @param array<string> $recurringIds
     * @return array<string, array{transactions: array<Transaction>, total: float}>
     */
    private function getMonthlyDataForAllRecurring(array $recurringIds, string $month): array
    {
        if (empty($recurringIds) || empty($month)) {
            return [];
        }

        $transactions = $this->entityManager->createQueryBuilder()
            ->select('t', 'rt')
            ->from(Transaction::class, 't')
            ->join('t.recurringTransaction', 'rt')
            ->where('rt.id IN (:recurringIds)')
            ->andWhere('t.budgetMonth = :month')
            ->setParameter('recurringIds', $recurringIds)
            ->setParameter('month', $month)
            ->orderBy('rt.id', 'ASC')
            ->addOrderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Grouper par récurrente
        $groupedData = [];
        foreach ($transactions as $transaction) {
            $recurringTransaction = $transaction->getRecurringTransaction();
            if (!$recurringTransaction || !$recurringTransaction->getId()) {
                continue;
            }
            $recurringId = $recurringTransaction->getId()->toString();

            if (!isset($groupedData[$recurringId])) {
                $groupedData[$recurringId] = [
                    'transactions' => [],
                    'total' => 0
                ];
            }

            $groupedData[$recurringId]['transactions'][] = $transaction;
            $groupedData[$recurringId]['total'] += $transaction->getAmountAsFloat();
        }

        return $groupedData;
    }

    /**
     * Récupère les dernières transactions pour plusieurs récurrentes en une seule requête
     * @param array<RecurringTransaction> $recurringTransactions
     * @return array<string, Transaction>
     */
    private function getLatestTransactionsForRecurring(array $recurringTransactions): array
    {
        if (empty($recurringTransactions)) {
            return [];
        }

        $recurringIds = array_map(static fn($rt) => $rt->getId()?->toString() ?? '', $recurringTransactions);
        $recurringIds = array_filter($recurringIds);

        // Récupérer toutes les transactions pour ces récurrentes
        $allTransactions = $this->entityManager->createQueryBuilder()
            ->select('t', 'rt')
            ->from(Transaction::class, 't')
            ->join('t.recurringTransaction', 'rt')
            ->where('rt.id IN (:recurringIds)')
            ->setParameter('recurringIds', $recurringIds)
            ->orderBy('rt.id', 'ASC')
            ->addOrderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Garder seulement la plus récente par récurrente
        $latestTransactions = [];
        foreach ($allTransactions as $transaction) {
            $recurringTransaction = $transaction->getRecurringTransaction();
            if ($recurringTransaction && $recurringTransaction->getId()) {
                $recurringId = $recurringTransaction->getId()->toString();
                if (!isset($latestTransactions[$recurringId])) {
                    $latestTransactions[$recurringId] = $transaction;
                }
            }
        }

        return $latestTransactions;
    }

}
