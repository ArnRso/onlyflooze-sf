<?php

namespace App\Service\Recurrence;

use App\Dto\RecurrenceForecast;
use App\Dto\RecurrenceMonthStatus;
use App\Entity\Recurrence;
use App\Enum\RecurrenceState;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * États des récurrences pour un mois donné : passée / à venir / en retard,
 * bornés dans le temps — une récurrence n'est attendue ni avant sa première
 * occurrence, ni après sa date de fin.
 */
class RecurrenceStatusProvider
{
    public function __construct(
        private readonly RecurrenceRepository $recurrenceRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Récurrences attendues sur le mois (les terminées et pas-encore-nées
     * sont exclues du dashboard).
     *
     * @return list<RecurrenceMonthStatus>
     */
    public function forMonth(\DateTimeImmutable $month): array
    {
        $statuses = [];
        foreach ($this->recurrenceRepository->findActive() as $recurrence) {
            $status = $this->statusFor($recurrence, $month);
            if ($status->state->isExpectedForMonth()) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    public function statusFor(Recurrence $recurrence, \DateTimeImmutable $month): RecurrenceMonthStatus
    {
        $expectedDate = $this->expectedDateForMonth($recurrence, $month);
        $monthStart = $month->modify('first day of this month')->setTime(0, 0);

        if ($recurrence->isEndedForMonth($month)) {
            return new RecurrenceMonthStatus($recurrence, RecurrenceState::Ended, $expectedDate);
        }

        $firstOccurrenceDate = $this->transactionRepository->findFirstOccurrenceDate($recurrence);
        $startMonth = ($firstOccurrenceDate ?? $recurrence->getCreatedAt())->modify('first day of this month')->setTime(0, 0);
        if ($monthStart < $startMonth) {
            return new RecurrenceMonthStatus($recurrence, RecurrenceState::NotStarted, $expectedDate);
        }

        $transaction = $this->transactionRepository->findOccurrenceInMonth($recurrence, $month);
        if ($transaction !== null) {
            return new RecurrenceMonthStatus($recurrence, RecurrenceState::Passed, $expectedDate, $transaction);
        }

        $deadline = $expectedDate->modify(sprintf('+%d days', $recurrence->getDayWindow()));
        if ($this->clock->now() <= $deadline) {
            return new RecurrenceMonthStatus($recurrence, RecurrenceState::Upcoming, $expectedDate);
        }

        // En retard ce mois-ci ET déjà manquante le mois précédent :
        // probablement arrêtée (prêt soldé, abonnement résilié) — on propose
        // de la marquer terminée, l'utilisateur dispose.
        $lastOccurrence = $this->transactionRepository->findLatestByRecurrence($recurrence, 1)[0] ?? null;
        $probablyEnded = $lastOccurrence !== null
            && $lastOccurrence->getOperationDate() < $monthStart->modify('-1 month');

        return new RecurrenceMonthStatus($recurrence, RecurrenceState::Late, $expectedDate, null, $probablyEnded);
    }

    /**
     * Déjà passé / reste à sortir / reste à rentrer sur le mois, d'après les
     * récurrences (sert au reste-à-vivre du dashboard).
     */
    public function forecastForMonth(\DateTimeImmutable $month): RecurrenceForecast
    {
        return RecurrenceForecast::fromStatuses($this->forMonth($month));
    }

    /**
     * Montant total restant à passer sur le mois (récurrences à venir ou en
     * retard), en centimes signés.
     */
    public function remainingAmountCentsForMonth(\DateTimeImmutable $month): int
    {
        return $this->forecastForMonth($month)->getRemainingCents();
    }

    private function expectedDateForMonth(Recurrence $recurrence, \DateTimeImmutable $month): \DateTimeImmutable
    {
        $daysInMonth = (int) $month->format('t');
        $day = min($recurrence->getExpectedDayOfMonth(), $daysInMonth);

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->setTime(0, 0);
    }
}
