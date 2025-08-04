<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

readonly class TransactionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository  $transactionRepository
    )
    {
    }

    public function createTransaction(Transaction $transaction, User $user): Transaction
    {
        $transaction->setUser($user);

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $transaction;
    }

    public function updateTransaction(Transaction $transaction): Transaction
    {
        $this->entityManager->flush();

        return $transaction;
    }

    public function deleteTransaction(Transaction $transaction): void
    {
        $this->entityManager->remove($transaction);
        $this->entityManager->flush();
    }

    /**
     * @return Transaction[]
     */
    public function getUserTransactionsByDateRange(User $user, DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
    {
        return $this->transactionRepository->findByUserAndDateRange($user, $startDate, $endDate);
    }

    public function getUserTransactionStats(User $user): array
    {
        $transactions = $this->getUserTransactions($user);
        $total = $this->getUserTransactionTotal($user);
        $count = $this->getUserTransactionCount($user);

        $positiveTotal = 0;
        $negativeTotal = 0;
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($transactions as $transaction) {
            $amount = $transaction->getAmountAsFloat();
            if ($amount >= 0) {
                $positiveTotal += $amount;
                $positiveCount++;
            } else {
                $negativeTotal += $amount;
                $negativeCount++;
            }
        }

        return [
            'total' => $total,
            'count' => $count,
            'positive_total' => $positiveTotal,
            'negative_total' => $negativeTotal,
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'average' => $count > 0 ? $total / $count : 0,
        ];
    }

    /**
     * @return Transaction[]
     */
    public function getUserTransactions(User $user): array
    {
        return $this->transactionRepository->findByUser($user);
    }

    public function getUserTransactionTotal(User $user): float
    {
        return $this->transactionRepository->getTotalByUser($user);
    }

    public function getUserTransactionCount(User $user): int
    {
        return $this->transactionRepository->getCountByUser($user);
    }

    public function getUserTransactionsQuery(User $user): Query
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t', 'tags', 'rt')
            ->from(Transaction::class, 't')
            ->leftJoin('t.tags', 'tags')
            ->leftJoin('t.recurringTransaction', 'rt')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery();
    }

    /**
     * @return Transaction[]
     */
    public function getRecentTransactions(User $user, int $limit = 5): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function getUserTransactionsByBudgetMonth(User $user, string $budgetMonth): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.budgetMonth = :budgetMonth')
            ->setParameter('user', $user)
            ->setParameter('budgetMonth', $budgetMonth)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Transaction[]
     */
    public function getUserTransactionsByRecurringTransaction(User $user, RecurringTransaction $recurringTransaction): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.recurringTransaction = :recurringTransaction')
            ->setParameter('user', $user)
            ->setParameter('recurringTransaction', $recurringTransaction)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalByBudgetMonth(User $user, string $budgetMonth): float
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('SUM(t.amount)')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.budgetMonth = :budgetMonth')
            ->setParameter('user', $user)
            ->setParameter('budgetMonth', $budgetMonth)
            ->getQuery()
            ->getSingleScalarResult();

        return (float)($result ?? 0);
    }

    /**
     * @return array<string, float>
     */
    public function getBudgetMonthsSummary(User $user): array
    {
        $results = $this->entityManager->createQueryBuilder()
            ->select('t.budgetMonth, SUM(t.amount) as total')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.budgetMonth IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('t.budgetMonth')
            ->orderBy('t.budgetMonth', 'DESC')
            ->getQuery()
            ->getResult();

        $summary = [];
        foreach ($results as $result) {
            $summary[$result['budgetMonth']] = (float)$result['total'];
        }

        return $summary;
    }

}
