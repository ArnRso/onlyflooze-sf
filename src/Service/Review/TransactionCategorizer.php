<?php

namespace App\Service\Review;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Enum\SuggestionOutcome;
use App\Enum\TransactionNature;
use App\Service\Matching\RuleLearner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Actions de la file de révision : catégorisation manuelle, validation de
 * suggestion, remise à trier. Chaque action alimente l'apprentissage des
 * règles et reste réversible.
 */
class TransactionCategorizer
{
    public function __construct(
        private readonly RuleLearner $ruleLearner,
        private readonly RuleReapplier $ruleReapplier,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Catégorisation manuelle (ou correction d'une suggestion). La règle
     * apprise est aussitôt réappliquée au stock « À trier » : les
     * transactions similaires reçoivent leur suggestion dans la foulée.
     */
    public function categorize(Transaction $transaction, Category $category, ?TransactionNature $nature = null): void
    {
        $isSuggestionConfirmation = $transaction->getSuggestedCategory() !== null
            && $transaction->getSuggestedCategory() === $category
            && $transaction->getMatchedRule() !== null;

        // La nature est posée avant l'apprentissage : la règle la mémorise
        // (transfert interne, remboursement) pour les prochaines suggestions.
        $transaction->setNature($nature ?? $transaction->getNature());

        if ($isSuggestionConfirmation) {
            $rule = $this->ruleLearner->confirmSuggestion($transaction);
        } else {
            $rule = $this->ruleLearner->learnFromCategorization($transaction, $category);
        }
        $transaction->setMatchedRule($rule ?? $transaction->getMatchedRule());

        // Le sort de la suggestion est mémorisé avant de l'effacer : c'est ce
        // qui permet de mesurer la précision du moteur dans le temps.
        $transaction->recordReviewOutcome(match (true) {
            $transaction->getSuggestedCategory() === null => SuggestionOutcome::None,
            $transaction->getSuggestedCategory() === $category => SuggestionOutcome::Accepted,
            default => SuggestionOutcome::Corrected,
        });

        $transaction->setCategory($category);
        $transaction->setCategorySource(CategorySource::Manual);
        $transaction->setSuggestedCategory(null);

        $this->entityManager->flush();

        $this->ruleReapplier->reapply();
    }

    /**
     * Remet une transaction dans la file « À trier » (automatisme réversible).
     */
    public function resetToReview(Transaction $transaction): void
    {
        $transaction->setCategory(null);
        $transaction->setCategorySource(CategorySource::Unclassified);
        $transaction->clearReviewOutcome();

        $this->entityManager->flush();
    }
}
