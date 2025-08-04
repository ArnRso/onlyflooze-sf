<?php

namespace App\Service;

use App\Entity\RecurringTransaction;
use App\Entity\User;
use App\Repository\RecurringTransactionRepository;
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

    /**
     * @return RecurringTransaction[]
     */
    public function getUserRecurringTransactions(User $user): array
    {
        return $this->recurringTransactionRepository->findByUser($user);
    }

    /**
     * @return RecurringTransaction[]
     */
    public function getUserRecurringTransactionsWithTransactions(User $user): array
    {
        return $this->recurringTransactionRepository->findByUserWithTransactions($user);
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
}
