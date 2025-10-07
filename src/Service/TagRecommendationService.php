<?php

namespace App\Service;

use App\DTO\TagRecommendation;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service intelligent pour recommander des tags pour une transaction.
 *
 * Utilise plusieurs stratégies :
 * 1. Correspondance exacte du label (95% confiance)
 * 2. Correspondance par mots-clés (60-85% confiance)
 * 3. Analyse du montant (40-60% confiance)
 * 4. Tags fréquents (20-30% confiance)
 */
class TagRecommendationService
{
    private const float CONFIDENCE_EXACT_MATCH = 95.0;
    private const float CONFIDENCE_KEYWORD_STRONG = 85.0;
    private const float CONFIDENCE_KEYWORD_MEDIUM = 70.0;
    private const float CONFIDENCE_KEYWORD_WEAK = 60.0;
    private const float CONFIDENCE_AMOUNT_PATTERN = 50.0;
    private const float CONFIDENCE_FREQUENT_TAG = 25.0;

    public function __construct(
        private readonly TagRepository          $tagRepository,
        private readonly EntityManagerInterface $entityManager
    )
    {
    }

    /**
     * Retourne les tags recommandés pour une transaction donnée.
     *
     * @param Transaction $transaction La transaction à analyser
     * @param int $limit Nombre maximum de recommandations à retourner
     * @return TagRecommendation[] Tableau de recommandations triées par confiance décroissante
     */
    public function getRecommendations(Transaction $transaction, int $limit = 5): array
    {
        $user = $transaction->getUser();
        if (!$user) {
            return [];
        }

        $recommendations = [];

        // Stratégie 1 : Correspondance exacte du label
        $exactMatchRecommendations = $this->findByExactLabel($transaction);
        $recommendations = array_merge($recommendations, $exactMatchRecommendations);

        // Stratégie 2 : Correspondance par mots-clés
        $keywordRecommendations = $this->findByKeywordMatching($transaction);
        $recommendations = array_merge($recommendations, $keywordRecommendations);

        // Stratégie 3 : Analyse du montant
        $amountRecommendations = $this->findByAmountPattern($transaction);
        $recommendations = array_merge($recommendations, $amountRecommendations);

        // Stratégie 4 : Tags fréquents (fallback)
        if (count($recommendations) < $limit) {
            $frequentRecommendations = $this->findMostFrequentTags($transaction);
            $recommendations = array_merge($recommendations, $frequentRecommendations);
        }

        // Dédupliquer et fusionner les recommandations pour le même tag
        $recommendations = $this->mergeAndDeduplicateRecommendations($recommendations);

        // Exclure les tags déjà assignés à la transaction
        $recommendations = $this->excludeExistingTags($recommendations, $transaction);

        // Trier par confiance décroissante et limiter
        usort($recommendations, static fn($a, $b) => $b->getConfidence() <=> $a->getConfidence());

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Stratégie 1 : Trouve des tags basés sur une correspondance exacte du label.
     *
     * @return TagRecommendation[]
     */
    private function findByExactLabel(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Chercher des transactions avec exactement le même label
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t', 'tag')
            ->from(Transaction::class, 't')
            ->innerJoin('t.tags', 'tag')
            ->where('t.user = :user')
            ->andWhere('t.label = :label')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('label', $label)
            ->setParameter('currentId', $transaction->getId())
            ->setMaxResults(50);

        $results = $qb->getQuery()->getResult();

        $tagCounts = [];
        foreach ($results as $similarTransaction) {
            if ($similarTransaction instanceof Transaction) {
                foreach ($similarTransaction->getTags() as $tag) {
                    $tagId = $tag->getId()->toString();
                    if (!isset($tagCounts[$tagId])) {
                        $tagCounts[$tagId] = ['tag' => $tag, 'count' => 0];
                    }
                    $tagCounts[$tagId]['count']++;
                }
            }
        }

        $recommendations = [];
        foreach ($tagCounts as $data) {
            $recommendations[] = new TagRecommendation(
                $data['tag'],
                self::CONFIDENCE_EXACT_MATCH,
                sprintf('Label identique (%d occurrences)', $data['count'])
            );
        }

        return $recommendations;
    }

    /**
     * Stratégie 2 : Trouve des tags basés sur des mots-clés du label.
     *
     * @return TagRecommendation[]
     */
    private function findByKeywordMatching(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Extraire les mots-clés du label (mots de 3+ caractères)
        $keywords = $this->extractKeywords($label);
        if (empty($keywords)) {
            return [];
        }

        $recommendations = [];

        // Pour chaque mot-clé, chercher des transactions similaires
        foreach ($keywords as $keyword) {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('t', 'tag')
                ->from(Transaction::class, 't')
                ->innerJoin('t.tags', 'tag')
                ->where('t.user = :user')
                ->andWhere('UPPER(t.label) LIKE :keyword')
                ->andWhere('t.id != :currentId')
                ->setParameter('user', $transaction->getUser())
                ->setParameter('keyword', '%' . strtoupper($keyword) . '%')
                ->setParameter('currentId', $transaction->getId())
                ->setMaxResults(30);

            $results = $qb->getQuery()->getResult();

            foreach ($results as $similarTransaction) {
                if ($similarTransaction instanceof Transaction) {
                    foreach ($similarTransaction->getTags() as $tag) {
                        // Calculer la confiance basée sur la similarité
                        $confidence = $this->calculateKeywordConfidence($keyword, $tag);

                        $recommendations[] = new TagRecommendation(
                            $tag,
                            $confidence,
                            sprintf('Mot-clé : "%s"', $keyword)
                        );
                    }
                }
            }
        }

        return $recommendations;
    }

    /**
     * Extrait les mots-clés pertinents d'un label.
     *
     * @return string[]
     */
    private function extractKeywords(string $label): array
    {
        // Nettoyer le label
        $label = mb_strtoupper($label);

        // Séparer par espaces et caractères spéciaux
        $words = preg_split('/[\s\-_\/]+/', $label);

        // Filtrer les mots courts et les mots communs
        $stopWords = ['CARTE', 'VIR', 'PRLV', 'DE', 'DU', 'LA', 'LE', 'LES', 'UN', 'UNE', 'INST', 'VERS'];
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            // Garder les mots de 3+ caractères qui ne sont pas des stop words
            if (mb_strlen($word) >= 3 && !in_array($word, $stopWords) && !preg_match('/^\d+$/', $word)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Calcule la confiance pour une correspondance par mot-clé.
     */
    private function calculateKeywordConfidence(string $keyword, Tag $tag): float
    {
        $keywordUpper = mb_strtoupper($keyword);
        $tagNameUpper = mb_strtoupper($tag->getName());

        // Si le tag correspond exactement au mot-clé → confiance forte
        if ($tagNameUpper === $keywordUpper) {
            return self::CONFIDENCE_KEYWORD_STRONG;
        }

        // Si le tag est contenu dans le mot-clé ou vice-versa → confiance moyenne
        if (str_contains($tagNameUpper, $keywordUpper) || str_contains($keywordUpper, $tagNameUpper)) {
            return self::CONFIDENCE_KEYWORD_MEDIUM;
        }

        // Sinon → confiance faible
        return self::CONFIDENCE_KEYWORD_WEAK;
    }

    /**
     * Stratégie 3 : Trouve des tags basés sur des patterns de montant.
     *
     * @return TagRecommendation[]
     */
    private function findByAmountPattern(Transaction $transaction): array
    {
        $amount = $transaction->getAmountAsFloat();
        $recommendations = [];

        // Pattern 1 : Remboursements (montants positifs)
        if ($amount > 0) {
            $positiveTags = $this->findTagsForPositiveTransactions($transaction);
            $recommendations = array_merge($recommendations, $positiveTags);
        }

        // Pattern 2 : Petits montants (< 10€ en valeur absolue)
        if (abs($amount) < 10) {
            $smallAmountTags = $this->findTagsForSmallAmounts($transaction);
            $recommendations = array_merge($recommendations, $smallAmountTags);
        }

        return $recommendations;
    }

    /**
     * Trouve des tags pour les transactions positives (remboursements).
     *
     * @return TagRecommendation[]
     */
    private function findTagsForPositiveTransactions(Transaction $transaction): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tag', 'COUNT(t.id) as tag_count')
            ->from(Tag::class, 'tag')
            ->innerJoin('tag.transactions', 't')
            ->where('tag.user = :user')
            ->andWhere('t.amount > 0')
            ->setParameter('user', $transaction->getUser())
            ->groupBy('tag.id')
            ->orderBy('tag_count', 'DESC')
            ->setMaxResults(5);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $tag = $data[0];
            $recommendations[] = new TagRecommendation(
                $tag,
                self::CONFIDENCE_AMOUNT_PATTERN,
                'Montant positif (remboursement)'
            );
        }

        return $recommendations;
    }

    /**
     * Trouve des tags pour les petits montants.
     *
     * @return TagRecommendation[]
     */
    private function findTagsForSmallAmounts(Transaction $transaction): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('tag', 'COUNT(t.id) as tag_count')
            ->from(Tag::class, 'tag')
            ->innerJoin('tag.transactions', 't')
            ->where('tag.user = :user')
            ->andWhere('ABS(t.amount) < 10')
            ->setParameter('user', $transaction->getUser())
            ->groupBy('tag.id')
            ->orderBy('tag_count', 'DESC')
            ->setMaxResults(5);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $tag = $data[0];
            $recommendations[] = new TagRecommendation(
                $tag,
                self::CONFIDENCE_AMOUNT_PATTERN,
                'Petit montant (<10€)'
            );
        }

        return $recommendations;
    }

    /**
     * Stratégie 4 : Retourne les tags les plus fréquemment utilisés par l'utilisateur.
     *
     * @return TagRecommendation[]
     */
    private function findMostFrequentTags(Transaction $transaction): array
    {
        $user = $transaction->getUser();
        if (!$user) {
            return [];
        }

        $tagsWithCount = $this->tagRepository->findByUserWithTransactionCount($user);

        $recommendations = [];
        foreach ($tagsWithCount as $data) {
            $tag = $data[0];
            $count = $data['transactionCount'];

            if ($count > 0) {
                $recommendations[] = new TagRecommendation(
                    $tag,
                    self::CONFIDENCE_FREQUENT_TAG,
                    sprintf('Tag fréquent (%d utilisations)', $count)
                );
            }
        }

        return $recommendations;
    }

    /**
     * Fusionne et déduplique les recommandations pour le même tag.
     * Si plusieurs recommandations pointent vers le même tag, garde celle avec la meilleure confiance.
     *
     * @param TagRecommendation[] $recommendations
     * @return TagRecommendation[]
     */
    private function mergeAndDeduplicateRecommendations(array $recommendations): array
    {
        $merged = [];

        foreach ($recommendations as $recommendation) {
            $tagId = $recommendation->getTag()->getId()->toString();

            if (!isset($merged[$tagId]) || $merged[$tagId]->getConfidence() < $recommendation->getConfidence()) {
                $merged[$tagId] = $recommendation;
            }
        }

        return array_values($merged);
    }

    /**
     * Exclut les tags déjà assignés à la transaction.
     *
     * @param TagRecommendation[] $recommendations
     * @return TagRecommendation[]
     */
    private function excludeExistingTags(array $recommendations, Transaction $transaction): array
    {
        $existingTagIds = [];
        foreach ($transaction->getTags() as $tag) {
            $existingTagIds[] = $tag->getId()->toString();
        }

        return array_filter(
            $recommendations,
            static fn(TagRecommendation $rec) => !in_array($rec->getTag()->getId()->toString(), $existingTagIds, true)
        );
    }
}
