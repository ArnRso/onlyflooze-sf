<?php

namespace App\Service\Review;

use App\Dto\NormalizedLabel;
use App\Enum\Direction;
use App\Enum\TransactionType;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\Matching\MatchingEngine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réapplique les règles apprises au stock « À trier » : les transactions
 * déjà importées profitent des apprentissages récents sous forme de
 * suggestions pré-remplies — jamais de catégorisation automatique.
 *
 * Seuls les niveaux 1-3 de la cascade (règles) sont rejoués : la périodicité
 * reste du ressort de l'import. Une suggestion issue d'une règle qui ne
 * matche plus (règle nettoyée, modifiée, supprimée) est retirée.
 */
class RuleReapplier
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly MatchingEngine $matchingEngine,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Retourne le nombre de transactions dont la suggestion a été posée,
     * mise à jour ou retirée.
     */
    public function reapply(): int
    {
        $rulesByDirection = [
            Direction::Debit->value => $this->ruleRepository->findByDirection(Direction::Debit),
            Direction::Credit->value => $this->ruleRepository->findByDirection(Direction::Credit),
        ];

        $updated = 0;
        foreach ($this->transactionRepository->findAllToReview() as $transaction) {
            $label = new NormalizedLabel($transaction->getType(), $transaction->getTokens());

            $match = $this->matchingEngine->matchAgainstRules(
                $label,
                $transaction->getAmountCents(),
                $rulesByDirection[$transaction->getDirection()->value],
            );

            if (!$match->isMatch() && $transaction->getType() === TransactionType::AnnulationCarte) {
                $match = $this->matchingEngine->matchRefundOrigin($label, $transaction->getAmountCents(), $transaction->getOperationDate());
            }

            if (!$match->isMatch() || $match->category === null) {
                // Les suggestions sans règle (périodicité, remboursement)
                // ne sont pas remises en cause ici.
                if ($transaction->getMatchedRule() !== null) {
                    $transaction->setSuggestedCategory(null);
                    $transaction->setMatchedRule(null);
                    ++$updated;
                }
                continue;
            }

            if ($transaction->getSuggestedCategory() === $match->category
                && $transaction->getMatchedRule() === $match->rule) {
                continue;
            }

            $transaction->setSuggestedCategory($match->category);
            $transaction->setMatchedRule($match->rule);
            ++$updated;
        }

        $this->entityManager->flush();

        return $updated;
    }
}
