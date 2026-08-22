<?php

namespace App\Service\Dashboard;

use App\Dto\CategoryMonthTotal;
use App\Dto\MonthOverview;
use App\Dto\RecurrenceForecast;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Service\Recurrence\RecurrenceStatusProvider;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Agrégats du dashboard mensuel : totaux par poste de budget (catégorie
 * racine) comparés au mois précédent, récurrences du mois, reste estimé.
 *
 * Les transferts internes sont exclus du budget ; les remboursements
 * créditent leur catégorie au lieu de compter comme revenu.
 */
class DashboardService
{
    private const int HISTORY_MONTHS = 6;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurrenceStatusProvider $recurrenceStatusProvider,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getMonthOverview(\DateTimeImmutable $month): MonthOverview
    {
        $transactions = $this->budgetedTransactions($month);
        $previousTransactions = $this->budgetedTransactions($month->modify('first day of previous month'));
        $statuses = $this->recurrenceStatusProvider->forMonth($month);

        $expense = 0;
        $income = 0;
        foreach ($transactions as $transaction) {
            if ($transaction->getAmountCents() < 0) {
                $expense += $transaction->getAmountCents();
            } else {
                $income += $transaction->getAmountCents();
            }
        }

        return new MonthOverview(
            $month->modify('first day of this month')->setTime(0, 0),
            $this->buildCategoryTotals($transactions, $previousTransactions),
            $expense,
            $income,
            $expense + $income,
            $this->transactionRepository->countToReview(),
            $statuses,
            RecurrenceForecast::fromStatuses($statuses),
            $this->buildHistoryChart($month),
        );
    }

    /**
     * @return list<Transaction>
     */
    private function budgetedTransactions(\DateTimeImmutable $month): array
    {
        return array_values(array_filter(
            $this->transactionRepository->findByMonth($month),
            static fn (Transaction $t): bool => $t->getNature()->isBudgeted(),
        ));
    }

    /**
     * @param list<Transaction> $transactions
     * @param list<Transaction> $previousTransactions
     *
     * @return list<CategoryMonthTotal>
     */
    private function buildCategoryTotals(array $transactions, array $previousTransactions): array
    {
        $current = $this->totalsByRootCategory($transactions);
        $previous = $this->totalsByRootCategory($previousTransactions);

        $totals = [];
        foreach ($current as $key => [$category, $amount]) {
            $totals[] = new CategoryMonthTotal($category, $amount, $previous[$key][1] ?? 0);
        }
        foreach ($previous as $key => [$category, $amount]) {
            if (!isset($current[$key])) {
                $totals[] = new CategoryMonthTotal($category, 0, $amount);
            }
        }

        usort($totals, static fn (CategoryMonthTotal $a, CategoryMonthTotal $b): int => $a->amountCents <=> $b->amountCents);

        return $totals;
    }

    /**
     * @param list<Transaction> $transactions
     *
     * @return array<string, array{?Category, int}>
     */
    private function totalsByRootCategory(array $transactions): array
    {
        $totals = [];
        foreach ($transactions as $transaction) {
            $root = $transaction->getCategory()?->getRoot();
            $key = $root !== null ? (string) $root->getId() : '';
            $totals[$key] ??= [$root, 0];
            $totals[$key][1] += $transaction->getAmountCents();
        }

        return $totals;
    }

    private function buildHistoryChart(\DateTimeImmutable $month): Chart
    {
        $labels = [];
        $expenses = [];
        $incomes = [];

        for ($i = self::HISTORY_MONTHS - 1; $i >= 0; --$i) {
            $historyMonth = $month->modify(sprintf('first day of this month -%d months', $i));
            $labels[] = $historyMonth->format('m/Y');

            $expense = 0;
            $income = 0;
            foreach ($this->budgetedTransactions($historyMonth) as $transaction) {
                if ($transaction->getAmountCents() < 0) {
                    $expense += $transaction->getAmountCents();
                } else {
                    $income += $transaction->getAmountCents();
                }
            }
            $expenses[] = round(abs($expense) / 100, 2);
            $incomes[] = round($income / 100, 2);
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Dépenses',
                    'backgroundColor' => 'rgba(220, 53, 69, 0.7)',
                    'data' => $expenses,
                ],
                [
                    'label' => 'Revenus',
                    'backgroundColor' => 'rgba(25, 135, 84, 0.7)',
                    'data' => $incomes,
                ],
            ],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ]);

        return $chart;
    }
}
