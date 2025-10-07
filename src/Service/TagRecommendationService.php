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
 * Utilise plusieurs stratégies basées sur l'analyse des labels :
 * 1. Correspondance exacte du label (95% confiance)
 * 2. Correspondance par similarité floue (80-90% confiance selon degré de similarité)
 * 3. Correspondance par mots-clés avec normalisation (60-85% confiance)
 * 4. Tags fréquents comme fallback (20-30% confiance)
 */
class TagRecommendationService
{
    private const float CONFIDENCE_FUZZY_MATCH_HIGH = 90.0;
    private const float CONFIDENCE_FUZZY_MATCH_MEDIUM = 85.0;
    private const float CONFIDENCE_FUZZY_MATCH_LOW = 80.0;
    private const float CONFIDENCE_KEYWORD_STRONG = 85.0;
    private const float CONFIDENCE_KEYWORD_MEDIUM = 70.0;
    private const float CONFIDENCE_KEYWORD_WEAK = 60.0;
    private const float CONFIDENCE_FREQUENT_TAG = 25.0;

    // Seuils de similarité pour fuzzy matching (en pourcentage)
    private const float SIMILARITY_THRESHOLD_HIGH = 95.0;
    private const float SIMILARITY_THRESHOLD_MEDIUM = 90.0;
    private const float SIMILARITY_THRESHOLD_LOW = 85.0;

    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Retourne les tags recommandés pour une transaction donnée.
     *
     * @param Transaction $transaction La transaction à analyser
     * @param int         $limit       Nombre maximum de recommandations à retourner
     *
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

        // Stratégie 2 : Correspondance par similarité floue (labels presque identiques)
        $fuzzyMatchRecommendations = $this->findBySimilarLabels($transaction);
        $recommendations = array_merge($recommendations, $fuzzyMatchRecommendations);

        // Stratégie 3 : Correspondance par mots-clés
        $keywordRecommendations = $this->findByKeywordMatching($transaction);
        $recommendations = array_merge($recommendations, $keywordRecommendations);

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
        usort($recommendations, static fn ($a, $b) => $b->getConfidence() <=> $a->getConfidence());

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Stratégie 1 : Trouve des tags basés sur une correspondance exacte du label.
     * Utilise un scoring bayésien pour calculer la probabilité P(tag | label).
     *
     * @return TagRecommendation[]
     */
    private function findByExactLabel(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Compter le nombre total de transactions avec ce label
        $qbTotal = $this->entityManager->createQueryBuilder();
        $qbTotal->select('COUNT(t.id)')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.label = :label')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('label', $label)
            ->setParameter('currentId', $transaction->getId());

        $totalWithLabel = (int) $qbTotal->getQuery()->getSingleScalarResult();

        if ($totalWithLabel === 0) {
            return [];
        }

        // Chercher des transactions avec exactement le même label et leurs tags
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
            ->setMaxResults(100);

        $results = $qb->getQuery()->getResult();

        $tagCounts = [];
        foreach ($results as $similarTransaction) {
            foreach ($similarTransaction->getTags() as $tag) {
                $tagIdObj = $tag->getId();
                if ($tagIdObj === null) {
                    continue;
                }
                $tagId = $tagIdObj->toString();
                if (!isset($tagCounts[$tagId])) {
                    $tagCounts[$tagId] = ['tag' => $tag, 'count' => 0];
                }
                ++$tagCounts[$tagId]['count'];
            }
        }

        $recommendations = [];
        foreach ($tagCounts as $data) {
            // Calculer P(tag | label) = nombre de fois où ce tag a été appliqué à ce label / nombre total de ce label
            $probability = ($data['count'] / $totalWithLabel) * 100;

            // Ajuster la confiance en fonction de la probabilité
            // Si P(tag|label) >= 90% → confiance très élevée (95%)
            // Si P(tag|label) >= 75% → confiance élevée (90%)
            // Si P(tag|label) >= 50% → confiance haute (85%)
            // Sinon → confiance moyenne (80%)
            $confidence = $this->calculateBayesianConfidence($probability);

            $recommendations[] = new TagRecommendation(
                $data['tag'],
                $confidence,
                sprintf('Label identique (%d/%d = %.0f%%)', $data['count'], $totalWithLabel, $probability)
            );
        }

        return $recommendations;
    }

    /**
     * Calcule la confiance basée sur la probabilité bayésienne P(tag | label).
     */
    private function calculateBayesianConfidence(float $probability): float
    {
        if ($probability >= 90.0) {
            return 95.0;
        }

        if ($probability >= 75.0) {
            return 90.0;
        }

        if ($probability >= 50.0) {
            return 85.0;
        }

        if ($probability >= 25.0) {
            return 80.0;
        }

        return 75.0;
    }

    /**
     * Stratégie 2 : Trouve des tags basés sur des labels similaires (fuzzy matching).
     *
     * @return TagRecommendation[]
     */
    private function findBySimilarLabels(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Normaliser le label pour comparaison
        $normalizedLabel = mb_strtoupper($this->normalizeLabel($label));

        // Récupérer toutes les transactions avec tags de l'utilisateur
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t', 'tag')
            ->from(Transaction::class, 't')
            ->innerJoin('t.tags', 'tag')
            ->where('t.user = :user')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('currentId', $transaction->getId())
            ->setMaxResults(200); // Limiter pour performance

        $results = $qb->getQuery()->getResult();

        $tagScores = [];

        foreach ($results as $similarTransaction) {
            $similarLabel = $similarTransaction->getLabel();
            if (!$similarLabel) {
                continue;
            }

            $normalizedSimilarLabel = mb_strtoupper($this->normalizeLabel($similarLabel));

            // Calculer la similarité
            $similarity = $this->calculateSimilarity($normalizedLabel, $normalizedSimilarLabel);

            // Ne garder que les labels avec une similarité élevée (mais pas 100% = exact match déjà traité)
            if ($similarity >= self::SIMILARITY_THRESHOLD_LOW && $similarity < 100.0) {
                foreach ($similarTransaction->getTags() as $tag) {
                    $tagIdObj = $tag->getId();
                    if ($tagIdObj === null) {
                        continue;
                    }
                    $tagId = $tagIdObj->toString();

                    // Garder le meilleur score de similarité pour chaque tag
                    if (!isset($tagScores[$tagId]) || $tagScores[$tagId]['similarity'] < $similarity) {
                        $tagScores[$tagId] = [
                            'tag' => $tag,
                            'similarity' => $similarity,
                        ];
                    }
                }
            }
        }

        $recommendations = [];
        foreach ($tagScores as $data) {
            $similarity = $data['similarity'];
            $confidence = $this->calculateFuzzyMatchConfidence($similarity);

            $recommendations[] = new TagRecommendation(
                $data['tag'],
                $confidence,
                sprintf('Label similaire (%.0f%% de correspondance)', $similarity)
            );
        }

        return $recommendations;
    }

    /**
     * Calcule la similarité entre deux chaînes en pourcentage (0-100).
     * Utilise la distance de Levenshtein normalisée.
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLength === 0) {
            return 100.0;
        }

        $distance = levenshtein($str1, $str2);
        $similarity = (1 - ($distance / $maxLength)) * 100;

        return max(0.0, $similarity);
    }

    /**
     * Calcule le niveau de confiance basé sur le pourcentage de similarité.
     */
    private function calculateFuzzyMatchConfidence(float $similarity): float
    {
        if ($similarity >= self::SIMILARITY_THRESHOLD_HIGH) {
            return self::CONFIDENCE_FUZZY_MATCH_HIGH;
        }

        if ($similarity >= self::SIMILARITY_THRESHOLD_MEDIUM) {
            return self::CONFIDENCE_FUZZY_MATCH_MEDIUM;
        }

        return self::CONFIDENCE_FUZZY_MATCH_LOW;
    }

    /**
     * Stratégie 3 : Trouve des tags basés sur des mots-clés du label.
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
                ->setParameter('keyword', '%'.strtoupper($keyword).'%')
                ->setParameter('currentId', $transaction->getId())
                ->setMaxResults(30);

            $results = $qb->getQuery()->getResult();

            foreach ($results as $similarTransaction) {
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

        return $recommendations;
    }

    /**
     * Extrait les mots-clés pertinents d'un label avec normalisation avancée.
     *
     * @return string[]
     */
    private function extractKeywords(string $label): array
    {
        // Nettoyer et normaliser le label
        $label = mb_strtoupper($label);

        // Normaliser les variations communes
        $label = $this->normalizeLabel($label);

        // Séparer par espaces et caractères spéciaux
        $words = preg_split('/[\s\-_\/]+/', $label);
        if ($words === false) {
            return [];
        }

        // Stop words étendus : mots bancaires, articles, prépositions, dates
        $stopWords = [
            // Termes bancaires
            'CARTE', 'VIR', 'VIRT', 'PRLV', 'INST', 'VERS', 'ANN', 'ECH', 'PRET',
            // Articles et prépositions
            'DE', 'DU', 'LA', 'LE', 'LES', 'UN', 'UNE', 'DES', 'AU', 'AUX', 'ET', 'EN', 'POUR', 'PAR', 'SUR',
            // Termes génériques
            'PAYM', 'PAYMENT', 'PAYMENTS', 'SA', 'SAS', 'SARL', 'EURL', 'PAI',
        ];

        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);

            // Ignorer si vide après trim
            if ($word === '') {
                continue;
            }

            // Ignorer les dates (format jj/mm ou jj/mm/aa)
            if (preg_match('/^\d{1,2}\/\d{1,2}(\/\d{2,4})?$/', $word)) {
                continue;
            }

            // Ignorer les numéros purs ou codes courts
            if (preg_match('/^\d+$/', $word) || preg_match('/^[A-Z0-9]{1,2}$/', $word)) {
                continue;
            }

            // Ignorer les codes de transaction (ex: LR9HUD, NQDK92)
            if (preg_match('/^[A-Z0-9]{6,10}$/', $word) && preg_match('/\d/', $word)) {
                continue;
            }

            // Garder les mots de 3+ caractères qui ne sont pas des stop words
            if (mb_strlen($word) >= 3 && !in_array($word, $stopWords, true)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Normalise un label en standardisant les variations communes.
     */
    private function normalizeLabel(string $label): string
    {
        // Normaliser les points et espaces dans les abréviations
        // "S.a.r.l." -> "SARL", "S.a r.l" -> "SARL"
        $normalized = preg_replace('/S\.?\s*A\.?\s*R\.?\s*L\.?/i', 'SARL', $label);
        $normalized = $normalized !== null ? $normalized : $label;

        $normalized = preg_replace('/S\.?\s*A\.?\s*S\.?/i', 'SAS', $normalized);
        $normalized = $normalized !== null ? $normalized : $label;

        $normalized = preg_replace('/E\.?\s*U\.?\s*R\.?\s*L\.?/i', 'EURL', $normalized);
        $normalized = $normalized !== null ? $normalized : $label;

        // Normaliser "et Cie" variations
        $normalized = preg_replace('/\s+ET\s+C(IE)?\.?\s*$/i', '', $normalized);
        $normalized = $normalized !== null ? $normalized : $label;

        // Normaliser les parenthèses et crochets
        $normalized = str_replace(['(', ')', '[', ']'], ' ', $normalized);

        // Normaliser les espaces multiples
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = $normalized !== null ? $normalized : $label;

        return trim($normalized);
    }

    /**
     * Calcule la confiance pour une correspondance par mot-clé.
     */
    private function calculateKeywordConfidence(string $keyword, Tag $tag): float
    {
        $keywordUpper = mb_strtoupper($keyword);
        $tagName = $tag->getName();
        if ($tagName === null) {
            return self::CONFIDENCE_KEYWORD_WEAK;
        }
        $tagNameUpper = mb_strtoupper($tagName);

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
     * Stratégie 3 : Retourne les tags les plus fréquemment utilisés par l'utilisateur.
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
     * Si plusieurs recommandations pointent vers le même tag :
     * - Garde la meilleure confiance
     * - Ajoute un bonus si multiple sources (cohérence)
     * - Combine les raisons.
     *
     * @param TagRecommendation[] $recommendations
     *
     * @return TagRecommendation[]
     */
    private function mergeAndDeduplicateRecommendations(array $recommendations): array
    {
        $grouped = [];

        // Grouper les recommandations par tag
        foreach ($recommendations as $recommendation) {
            $id = $recommendation->getTag()->getId();
            if ($id === null) {
                continue;
            }
            $tagId = $id->toString();

            if (!isset($grouped[$tagId])) {
                $grouped[$tagId] = [
                    'tag' => $recommendation->getTag(),
                    'recommendations' => [],
                ];
            }

            $grouped[$tagId]['recommendations'][] = $recommendation;
        }

        // Fusionner intelligemment
        $merged = [];
        foreach ($grouped as $tagId => $data) {
            $recs = $data['recommendations'];
            $tag = $data['tag'];

            // Trouver la meilleure confiance
            $maxConfidence = 0.0;
            $reasons = [];
            foreach ($recs as $rec) {
                if ($rec->getConfidence() > $maxConfidence) {
                    $maxConfidence = $rec->getConfidence();
                }
                $reasons[] = $rec->getReason();
            }

            // Bonus de confiance si multiple sources (max +3%)
            $sourceBonus = 0.0;
            if (count($recs) >= 3) {
                $sourceBonus = 3.0;
            } elseif (count($recs) === 2) {
                $sourceBonus = 2.0;
            }

            $finalConfidence = min(100.0, $maxConfidence + $sourceBonus);

            // Combiner les raisons (garder les 2 meilleures)
            $uniqueReasons = array_unique($reasons);
            $combinedReason = implode(' + ', array_slice($uniqueReasons, 0, 2));
            if (count($uniqueReasons) > 2) {
                $combinedReason .= sprintf(' (+%d autres)', count($uniqueReasons) - 2);
            }

            $merged[$tagId] = new TagRecommendation(
                $tag,
                $finalConfidence,
                $combinedReason
            );
        }

        return array_values($merged);
    }

    /**
     * Exclut les tags déjà assignés à la transaction.
     *
     * @param TagRecommendation[] $recommendations
     *
     * @return TagRecommendation[]
     */
    private function excludeExistingTags(array $recommendations, Transaction $transaction): array
    {
        $existingTagIds = [];
        foreach ($transaction->getTags() as $tag) {
            $id = $tag->getId();
            if ($id !== null) {
                $existingTagIds[] = $id->toString();
            }
        }

        return array_filter(
            $recommendations,
            static function (TagRecommendation $rec) use ($existingTagIds) {
                $recId = $rec->getTag()->getId();

                return $recId === null || !in_array($recId->toString(), $existingTagIds, true);
            }
        );
    }
}
