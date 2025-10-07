<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Ramsey\Uuid\Uuid;

readonly class TransactionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRepository $transactionRepository,
    ) {
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
    public function getUserTransactionsByDateRange(User $user, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        return $this->transactionRepository->findByUserAndDateRange($user, $startDate, $endDate);
    }

    /**
     * @return array{total: float, count: int, positive_total: float, negative_total: float, positive_count: int, negative_count: int, average: float}
     */
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
                ++$positiveCount;
            } else {
                $negativeTotal += $amount;
                ++$negativeCount;
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

    /**
     * @return Query<mixed, Transaction>
     */
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

        return (float) ($result ?? 0);
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
            $summary[$result['budgetMonth']] = (float) $result['total'];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return Query<mixed, Transaction>
     *
     * @throws \DateMalformedStringException
     */
    public function searchUserTransactions(User $user, array $criteria = []): Query
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t', 'tags', 'rt')
            ->from(Transaction::class, 't')
            ->leftJoin('t.tags', 'tags')
            ->leftJoin('t.recurringTransaction', 'rt')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        if (!empty($criteria['label'])) {
            $qb->andWhere('LOWER(t.label) LIKE LOWER(:label)')
                ->setParameter('label', '%'.$criteria['label'].'%');
        }

        if (!empty($criteria['minAmount'])) {
            $qb->andWhere('t.amount >= :minAmount')
                ->setParameter('minAmount', $criteria['minAmount']);
        }

        if (!empty($criteria['maxAmount'])) {
            $qb->andWhere('t.amount <= :maxAmount')
                ->setParameter('maxAmount', $criteria['maxAmount']);
        }

        if (!empty($criteria['startDate'])) {
            $qb->andWhere('t.transactionDate >= :startDate')
                ->setParameter('startDate', new \DateTimeImmutable($criteria['startDate']));
        }

        if (!empty($criteria['endDate'])) {
            $qb->andWhere('t.transactionDate <= :endDate')
                ->setParameter('endDate', new \DateTimeImmutable($criteria['endDate']));
        }

        if (!empty($criteria['budgetMonth'])) {
            $qb->andWhere('t.budgetMonth = :budgetMonth')
                ->setParameter('budgetMonth', $criteria['budgetMonth']);
        }

        if (isset($criteria['hasRecurringTransaction'])) {
            if ($criteria['hasRecurringTransaction'] === 'yes') {
                $qb->andWhere('t.recurringTransaction IS NOT NULL');
            } elseif ($criteria['hasRecurringTransaction'] === 'no') {
                $qb->andWhere('t.recurringTransaction IS NULL');
            }
        }

        if (!empty($criteria['tagIds'])) {
            $qb->andWhere('tags.id IN (:tagIds)')
                ->setParameter('tagIds', $criteria['tagIds']);
        }

        if (!empty($criteria['specificTag'])) {
            // Use EXISTS subquery to filter by specific tag while keeping all tags in results
            $qb->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\Tag filterTag
                JOIN filterTag.transactions filterTrans
                WHERE filterTrans.id = t.id
                AND filterTag.id = :specificTagId
            )')
                ->setParameter('specificTagId', $criteria['specificTag']);
        }

        if (!empty($criteria['specificRecurringTransaction'])) {
            $qb->andWhere('rt.id = :specificRecurringTransactionId')
                ->setParameter('specificRecurringTransactionId', $criteria['specificRecurringTransaction']);
        }

        $qb->orderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC');

        return $qb->getQuery();
    }

    /**
     * @param array<string> $transactionIds
     */
    public function assignTransactionsToRecurring(array $transactionIds, RecurringTransaction $recurringTransaction): int
    {
        // Convertir les strings en UUID
        $uuidTransactionIds = array_map(static fn ($id) => Uuid::fromString($id), $transactionIds);

        $qb = $this->entityManager->createQueryBuilder()
            ->update(Transaction::class, 't')
            ->set('t.recurringTransaction', ':recurringTransaction')
            ->where('t.id IN (:transactionIds)')
            ->andWhere('t.user = :user')
            ->setParameter('recurringTransaction', $recurringTransaction)
            ->setParameter('transactionIds', $uuidTransactionIds)
            ->setParameter('user', $recurringTransaction->getUser());

        $updatedCount = $qb->getQuery()->execute();
        $this->entityManager->flush();

        return $updatedCount;
    }

    /**
     * @param string[] $transactionIds
     * @param Tag[]    $tags
     */
    public function assignTagsToTransactions(array $transactionIds, array $tags): int
    {
        // Convertir les strings en UUID
        $uuidTransactionIds = array_map(static fn ($id) => Uuid::fromString($id), $transactionIds);

        // Récupérer les transactions
        $transactions = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Transaction::class, 't')
            ->where('t.id IN (:transactionIds)')
            ->setParameter('transactionIds', $uuidTransactionIds)
            ->getQuery()
            ->getResult();

        foreach ($transactions as $transaction) {
            foreach ($tags as $tag) {
                if (!$transaction->getTags()->contains($tag)) {
                    $transaction->addTag($tag);
                }
            }
        }

        $this->entityManager->flush();

        return count($transactions);
    }

    /**
     * Trouve la prochaine transaction de l'utilisateur qui n'a ni tags ni transaction récurrente.
     * Exclut la transaction spécifiée.
     */
    public function findNextUntaggedTransaction(User $user, ?Transaction $currentTransaction = null): ?Transaction
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('SIZE(t.tags) = 0')
            ->andWhere('t.recurringTransaction IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->setMaxResults(1);

        if ($currentTransaction) {
            $qb->andWhere('t.id != :currentId')
                ->setParameter('currentId', $currentTransaction->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
