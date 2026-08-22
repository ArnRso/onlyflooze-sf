<?php

namespace App\Service\Recurrence;

use App\Dto\NormalizedLabel;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use App\Service\Matching\MatchingEngine;

/**
 * Rattachement transaction ↔ récurrence, à l'import.
 *
 * Réutilise la cascade de matching sur la règle de la récurrence, puis ses
 * propres tokens ; à défaut, fenêtre de date + tolérance de montant. Un
 * écart de montant hors tolérance ne bloque pas le rattachement : la
 * transaction est rattachée mais SIGNALÉE (cas réel : DGFIP passé de 242 €
 * à 2 278 €).
 */
class RecurrenceMatcher
{
    /** @var list<Recurrence>|null */
    private ?array $activeRecurrences = null;

    public function __construct(
        private readonly RecurrenceRepository $recurrenceRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly MatchingEngine $matchingEngine,
    ) {
    }

    public function attach(Transaction $transaction): void
    {
        foreach ($this->getActiveRecurrences() as $recurrence) {
            if (!$this->matches($recurrence, $transaction)) {
                continue;
            }

            // Une seule occurrence par mois : la première arrivée gagne.
            if ($this->transactionRepository->hasOccurrenceInMonth($recurrence, $transaction->getOperationDate())) {
                continue;
            }

            $transaction->setRecurrence($recurrence);
            $transaction->setAmountOutOfTolerance(!$recurrence->isAmountWithinTolerance($transaction->getAmountCents()));

            if ($transaction->getCategory() === null && $recurrence->getCategory() !== null) {
                $transaction->setSuggestedCategory($transaction->getSuggestedCategory() ?? $recurrence->getCategory());
            }

            $this->refreshExpectedAmount($recurrence, $transaction);

            return;
        }
    }

    public function resetCache(): void
    {
        $this->activeRecurrences = null;
    }

    /**
     * La transaction ressemble-t-elle à une occurrence de cette récurrence ?
     * (cascade sur la règle liée ou les tokens de la récurrence, sinon
     * fenêtre de date + tolérance de montant). Utilisé à l'import et par la
     * recherche rétroactive — qui fournit le montant de référence le plus
     * proche dans le temps, pour que la tolérance suive la dérive des prix
     * au lieu de comparer 2023 à aujourd'hui.
     */
    public function matches(Recurrence $recurrence, Transaction $transaction, ?int $referenceAmountCents = null): bool
    {
        if ($recurrence->getDirection() !== $transaction->getDirection()) {
            return false;
        }

        // Une récurrence terminée n'attend plus rien après sa date de fin.
        if ($recurrence->isEndedForMonth($transaction->getOperationDate())) {
            return false;
        }

        $reference = $referenceAmountCents ?? $recurrence->getExpectedAmountCents();
        $label = new NormalizedLabel($transaction->getType(), $transaction->getTokens());

        // La règle de la récurrence matche le libellé : rattachement sûr,
        // même si la date ou le montant dévient (ils seront signalés).
        $rule = $recurrence->getRule();
        if ($rule !== null && $this->ruleMatches($recurrence, $label, $transaction->getAmountCents())) {
            // Règle d'agrégateur (PayPal) scopée par montant : le libellé
            // ne suffit pas, le montant doit rester dans la tolérance —
            // sinon n'importe quel prélèvement PayPal volerait le slot
            // mensuel de cet abonnement.
            if ($rule->getAmountCents() !== null) {
                return $recurrence->isAmountCloseTo($transaction->getAmountCents(), $reference);
            }

            return true;
        }

        // Tokens propres à la récurrence (promotion sans règle, création
        // manuelle) : même garantie que la règle, sauf si plusieurs
        // récurrences se partagent ce libellé (agrégateur) — le montant
        // départage alors.
        if ($this->tokensMatch($recurrence, $label)) {
            if ($this->sharesTokensWithAnotherRecurrence($recurrence)) {
                return $recurrence->isAmountCloseTo($transaction->getAmountCents(), $reference);
            }

            return true;
        }

        // Sans règle ni tokens (ou libellé qui a dérivé) : fenêtre de date
        // + tolérance de montant.
        return $this->isWithinDateWindow($recurrence, $transaction->getOperationDate())
            && $recurrence->isAmountCloseTo($transaction->getAmountCents(), $reference);
    }

    /**
     * Le libellé seul désigne-t-il cette récurrence (règle ou tokens), sans
     * considération de date, de montant ni de fin ? Sert à distinguer ce qui
     * relève de la recherche rétroactive de ce qui mérite une nouvelle
     * proposition.
     */
    public function matchesLabel(Recurrence $recurrence, Transaction $transaction): bool
    {
        if ($recurrence->getDirection() !== $transaction->getDirection()) {
            return false;
        }

        $label = new NormalizedLabel($transaction->getType(), $transaction->getTokens());

        return ($recurrence->getRule() !== null && $this->ruleMatches($recurrence, $label, $transaction->getAmountCents()))
            || $this->tokensMatch($recurrence, $label);
    }

    /**
     * On neutralise le scope par montant de la règle : c'est justement les
     * écarts de montant qu'on veut détecter.
     */
    private function ruleMatches(Recurrence $recurrence, NormalizedLabel $label, int $amountCents): bool
    {
        $rule = $recurrence->getRule();
        if ($rule === null) {
            return false;
        }

        return $this->matchingEngine->matchAgainstRules($label, $rule->getAmountCents() ?? $amountCents, [$rule])->isMatch();
    }

    private function tokensMatch(Recurrence $recurrence, NormalizedLabel $label): bool
    {
        return $recurrence->getTokens() !== [] && array_diff($recurrence->getTokens(), $label->tokens) === [];
    }

    private function sharesTokensWithAnotherRecurrence(Recurrence $recurrence): bool
    {
        foreach ($this->getActiveRecurrences() as $other) {
            if ($other !== $recurrence
                && $other->getDirection() === $recurrence->getDirection()
                && $other->getTokens() !== []
                && array_diff($other->getTokens(), $recurrence->getTokens()) === []) {
                return true;
            }
        }

        return false;
    }

    private function isWithinDateWindow(Recurrence $recurrence, \DateTimeImmutable $date): bool
    {
        $expected = $this->expectedDateForMonth($recurrence, $date);

        return abs((int) $expected->diff($date)->days) <= $recurrence->getDayWindow();
    }

    private function expectedDateForMonth(Recurrence $recurrence, \DateTimeImmutable $month): \DateTimeImmutable
    {
        $daysInMonth = (int) $month->format('t');
        $day = min($recurrence->getExpectedDayOfMonth(), $daysInMonth);

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
    }

    /**
     * Montant attendu = moyenne des 2-3 dernières occurrences (la tolérance
     * suit les dernières révisions de prix, pas la moyenne historique).
     */
    private function refreshExpectedAmount(Recurrence $recurrence, Transaction $justAttached): void
    {
        // Une occurrence signalée hors tolérance est une anomalie (cas réel :
        // rattrapage de mutuelle à 201,66 € au lieu de 67,22 €) : elle ne
        // doit pas recaler le montant attendu, sinon la tolérance des mois
        // suivants est faussée.
        if ($justAttached->isAmountOutOfTolerance()) {
            return;
        }

        // Les 2 occurrences précédentes (déjà en base) + celle qui vient
        // d'être rattachée : moyenne sur 3 occurrences maximum.
        $amounts = [$justAttached->getAmountCents()];
        foreach ($this->transactionRepository->findLatestByRecurrence($recurrence, 4) as $previous) {
            if ($previous->getId()->equals($justAttached->getId())
                || $previous->isAmountOutOfTolerance()
                || \count($amounts) >= 3) {
                continue;
            }
            $amounts[] = $previous->getAmountCents();
        }

        $recurrence->setExpectedAmountCents((int) round(array_sum($amounts) / \count($amounts)));
        $recurrence->setExpectedDayOfMonth((int) $justAttached->getOperationDate()->format('j'));
    }

    /**
     * @return list<Recurrence>
     */
    private function getActiveRecurrences(): array
    {
        return $this->activeRecurrences ??= $this->recurrenceRepository->findActive();
    }
}
