<?php

namespace App\Service\Recurrence;

use App\Dto\RecurrenceSuggestion;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\RecurrenceDismissal;
use App\Entity\Transaction;
use App\Repository\RecurrenceDismissalRepository;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use App\Service\Matching\TokenSelectivity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Détection des récurrences par observation : le système PROPOSE la
 * promotion, l'utilisateur dispose. Jamais de création automatique.
 *
 * On ne surveille que les préfixes PRLV/ECH PRET/F et les crédits VIR. Les
 * occurrences sont groupées par tête de libellé (premier token discriminant :
 * « SFR » et « SFR-SOCIETE FRANCAISE… » convergent), triées ou non — un
 * prélèvement mensuel jamais catégorisé est une récurrence comme une autre.
 * Dans un groupe, les montants sont regroupés par proximité : un agrégateur
 * (PayPal) donne une proposition par abonnement, jamais une moyenne
 * fourre-tout. ≥ 2 occurrences espacées d'environ un mois → suggestion.
 */
class RecurrenceDetector
{
    private const int MIN_OCCURRENCES = 2;
    private const int MIN_INTERVAL_DAYS = 25;
    private const int MAX_INTERVAL_DAYS = 35;
    private const int AMOUNT_CLUSTER_TOLERANCE_PCT = 15;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly RecurrenceRepository $recurrenceRepository,
        private readonly RecurrenceDismissalRepository $dismissalRepository,
        private readonly RecurrenceMatcher $recurrenceMatcher,
        private readonly TokenSelectivity $tokenSelectivity,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<RecurrenceSuggestion>
     */
    public function suggest(): array
    {
        $generic = $this->tokenSelectivity->genericTokens();
        $active = $this->recurrenceRepository->findActive();
        $dismissals = $this->dismissalRepository->findAll();

        /** @var array<string, list<Transaction>> $groups */
        $groups = [];
        foreach ($this->transactionRepository->findRecurrenceObservations() as $transaction) {
            // Ce qui ressemble à une récurrence déjà suivie relève de la
            // recherche rétroactive, pas d'une nouvelle proposition.
            if ($this->matchesAnActiveRecurrence($transaction, $active)) {
                continue;
            }

            $head = $this->headToken($transaction, $generic);
            if ($head === null) {
                continue;
            }

            $groups[$transaction->getDirection()->value.'|'.$transaction->getType()->value.'|'.$head][] = $transaction;
        }

        $suggestions = [];
        foreach ($groups as $transactions) {
            foreach ($this->clusterByAmount($transactions) as $cluster) {
                $suggestion = $this->buildSuggestion($cluster, $generic);
                if ($suggestion !== null && !$this->isDismissed($suggestion, $dismissals)) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        usort($suggestions, static fn (RecurrenceSuggestion $a, RecurrenceSuggestion $b): int => strcmp($a->name, $b->name));

        return $suggestions;
    }

    /**
     * Promotion : crée la récurrence et rattache TOUTES les occurrences
     * observées — tout l'historique, triées ou non. Les montants historiques
     * ne sont pas signalés : comparer une mensualité de 2023 à l'attendu
     * d'aujourd'hui n'a pas de sens.
     */
    public function promote(RecurrenceSuggestion $suggestion): Recurrence
    {
        $recurrence = new Recurrence(
            $suggestion->name,
            $suggestion->direction,
            $suggestion->expectedDayOfMonth,
            $suggestion->expectedAmountCents,
        );
        $recurrence->setCategory($suggestion->category);
        $recurrence->setRule($suggestion->rule);
        $recurrence->setTokens($suggestion->tokens);

        $this->entityManager->persist($recurrence);

        foreach ($suggestion->transactions as $transaction) {
            $transaction->setRecurrence($recurrence);
            $transaction->setAmountOutOfTolerance(false);
        }

        $this->entityManager->flush();
        $this->recurrenceMatcher->resetCache();

        return $recurrence;
    }

    /**
     * L'utilisateur écarte la proposition : elle ne sera plus refaite.
     */
    public function dismiss(RecurrenceSuggestion $suggestion): void
    {
        $this->entityManager->persist(new RecurrenceDismissal(
            $suggestion->direction,
            $suggestion->type,
            $suggestion->headToken,
            $suggestion->expectedAmountCents,
        ));
        $this->entityManager->flush();
    }

    /**
     * Retrouve la proposition courante par sa clé (actions des boutons
     * promouvoir/ignorer de l'interface).
     */
    public function findSuggestionByKey(string $key): ?RecurrenceSuggestion
    {
        foreach ($this->suggest() as $suggestion) {
            if ($suggestion->key === $key) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @param list<Recurrence> $active
     */
    private function matchesAnActiveRecurrence(Transaction $transaction, array $active): bool
    {
        foreach ($active as $recurrence) {
            // Un refus explicite de l'utilisateur prime sur le libellé : la
            // transaction redevient libre d'être proposée ailleurs.
            if ($recurrence->isTransactionExcluded($transaction)) {
                continue;
            }
            if ($this->recurrenceMatcher->matchesLabel($recurrence, $transaction)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $generic
     */
    private function headToken(Transaction $transaction, array $generic): ?string
    {
        return TokenSelectivity::discriminant($transaction->getTokens(), $generic)[0]
            ?? $transaction->getTokens()[0]
            ?? null;
    }

    /**
     * Regroupe par montants voisins : triés par montant, on ouvre un nouveau
     * groupe dès qu'un saut dépasse la tolérance. Une dérive progressive
     * (EDF 46 → 100 € sur trois ans) reste un seul groupe ; deux abonnements
     * PayPal à 9,99 et 21,24 € en font deux.
     *
     * @param list<Transaction> $transactions
     *
     * @return list<list<Transaction>>
     */
    private function clusterByAmount(array $transactions): array
    {
        usort($transactions, static fn (Transaction $a, Transaction $b): int => abs($a->getAmountCents()) <=> abs($b->getAmountCents()));

        $clusters = [];
        $current = [];
        $previous = null;
        foreach ($transactions as $transaction) {
            $amount = abs($transaction->getAmountCents());
            if ($previous !== null && $amount - $previous > (int) round($previous * self::AMOUNT_CLUSTER_TOLERANCE_PCT / 100)) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $transaction;
            $previous = $amount;
        }
        if ($current !== []) {
            $clusters[] = $current;
        }

        foreach ($clusters as &$cluster) {
            usort($cluster, static fn (Transaction $a, Transaction $b): int => $a->getOperationDate() <=> $b->getOperationDate());
        }

        return $clusters;
    }

    /**
     * @param list<Transaction> $transactions triées chronologiquement
     * @param list<string>      $generic
     */
    private function buildSuggestion(array $transactions, array $generic): ?RecurrenceSuggestion
    {
        if (\count($transactions) < self::MIN_OCCURRENCES) {
            return null;
        }

        if (!$this->hasMonthlyInterval($transactions)) {
            return null;
        }

        $latest = $transactions[\count($transactions) - 1];
        $head = $this->headToken($latest, $generic) ?? '';
        $tokens = $this->commonDiscriminantTokens($transactions, $generic);
        if ($tokens === []) {
            $tokens = [$head];
        }

        // La règle n'est liée que si ses tokens désignent bien ce libellé :
        // une règle matchée par une vieille empreinte étrangère (cas réel :
        // GAZ sur un prélèvement d'eau) ferait revendiquer à la récurrence
        // les occurrences d'une autre.
        $rule = $latest->getMatchedRule();
        if ($rule !== null && ($rule->getTokens() === [] || array_diff($rule->getTokens(), $tokens) !== [])) {
            $rule = null;
        }

        return new RecurrenceSuggestion(
            sha1(implode('|', array_map(static fn (Transaction $t): string => (string) $t->getId(), $transactions))),
            implode(' ', $tokens),
            $latest->getDirection(),
            $latest->getType(),
            $head,
            $tokens,
            $rule,
            $this->dominantCategory($transactions),
            (int) $latest->getOperationDate()->format('j'),
            $this->averageAmountCents(\array_slice($transactions, -3)),
            $latest->getOperationDate(),
            $transactions,
        );
    }

    /**
     * @param list<RecurrenceDismissal> $dismissals
     */
    private function isDismissed(RecurrenceSuggestion $suggestion, array $dismissals): bool
    {
        foreach ($dismissals as $dismissal) {
            if ($dismissal->covers($suggestion->direction, $suggestion->type, $suggestion->headToken, $suggestion->expectedAmountCents, self::AMOUNT_CLUSTER_TOLERANCE_PCT)) {
                return true;
            }
        }

        return false;
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
     * @param list<Transaction> $transactions
     * @param list<string>      $generic
     *
     * @return list<string>
     */
    private function commonDiscriminantTokens(array $transactions, array $generic): array
    {
        $common = null;
        foreach ($transactions as $transaction) {
            $tokens = TokenSelectivity::discriminant($transaction->getTokens(), $generic);
            $common = $common === null ? $tokens : array_values(array_intersect($common, $tokens));
            if ($common === []) {
                return [];
            }
        }

        return $common ?? [];
    }

    /**
     * Catégorie la plus souvent choisie parmi les occurrences déjà triées.
     *
     * @param list<Transaction> $transactions
     */
    private function dominantCategory(array $transactions): ?Category
    {
        $counts = [];
        $categories = [];
        foreach ($transactions as $transaction) {
            $category = $transaction->getCategory();
            if ($category === null) {
                continue;
            }
            $id = (string) $category->getId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $categories[$id] = $category;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);

        return $categories[array_key_first($counts)];
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
