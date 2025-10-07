<?php

namespace App\Service;

use App\DTO\RecurringTransactionRecommendation;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;

/**
 * Service intelligent pour recommander des transactions récurrentes pour une transaction.
 *
 * Utilise plusieurs stratégies basées sur l'analyse des labels :
 * 1. Correspondance exacte du label avec scoring bayésien (75-95% confiance)
 * 2. Correspondance par similarité floue (70-85% confiance selon degré de similarité)
 * 3. Correspondance par mots-clés avec normalisation (60-80% confiance)
 * 4. Transactions récurrentes fréquentes comme fallback (20-30% confiance)
 */
class RecurringTransactionRecommendationService
{
    private const float CONFIDENCE_FUZZY_MATCH_HIGH = 85.0;
    private const float CONFIDENCE_FUZZY_MATCH_MEDIUM = 80.0;
    private const float CONFIDENCE_FUZZY_MATCH_LOW = 75.0;
    private const float CONFIDENCE_KEYWORD_STRONG = 80.0;
    private const float CONFIDENCE_KEYWORD_MEDIUM = 70.0;
    private const float CONFIDENCE_KEYWORD_WEAK = 60.0;
    private const float CONFIDENCE_FREQUENT = 25.0;

    // Seuils de similarité pour fuzzy matching (en pourcentage)
    private const float SIMILARITY_THRESHOLD_HIGH = 95.0;
    private const float SIMILARITY_THRESHOLD_MEDIUM = 90.0;
    private const float SIMILARITY_THRESHOLD_LOW = 85.0;

    public function __construct(
        private readonly EntityManagerInterface         $entityManager
    )
    {
    }

    /**
     * Retourne les transactions récurrentes recommandées pour une transaction donnée.
     *
     * @param Transaction $transaction La transaction à analyser
     * @param int $limit Nombre maximum de recommandations à retourner
     * @return RecurringTransactionRecommendation[] Tableau de recommandations triées par confiance décroissante
     * @throws ORMException
     * @throws OptimisticLockException
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

        // Stratégie 4 : Transactions récurrentes fréquentes (fallback)
        if (count($recommendations) < $limit) {
            $frequentRecommendations = $this->findMostFrequentRecurringTransactions($transaction);
            $recommendations = array_merge($recommendations, $frequentRecommendations);
        }

        // Dédupliquer et fusionner les recommandations
        $recommendations = $this->mergeAndDeduplicateRecommendations($recommendations);

        // Exclure la transaction récurrente déjà assignée
        $recommendations = $this->excludeExisting($recommendations, $transaction);

        // Trier par confiance décroissante et limiter
        usort($recommendations, static fn($a, $b) => $b->getConfidence() <=> $a->getConfidence());

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * Stratégie 1 : Trouve des transactions récurrentes basées sur une correspondance exacte du label.
     * Utilise un scoring bayésien pour calculer la probabilité P(recurring | label).
     *
     * @param Transaction $transaction
     * @return RecurringTransactionRecommendation[]
     * @throws ORMException
     * @throws OptimisticLockException
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

        $totalWithLabel = (int)$qbTotal->getQuery()->getSingleScalarResult();

        if ($totalWithLabel === 0) {
            return [];
        }

        // Chercher des transactions avec le même label ayant une transaction récurrente
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('IDENTITY(t.recurringTransaction) as rt_id', 'COUNT(t.id) as match_count')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.label = :label')
            ->andWhere('t.recurringTransaction IS NOT NULL')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('label', $label)
            ->setParameter('currentId', $transaction->getId())
            ->groupBy('rt_id')
            ->orderBy('match_count', 'DESC')
            ->setMaxResults(5);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $rtId = $data['rt_id'];
            $count = $data['match_count'];

            // Calculer P(recurring | label)
            $probability = ($count / $totalWithLabel) * 100;
            $confidence = $this->calculateBayesianConfidence($probability);

            // Hydrater l'entité RecurringTransaction
            $recurringTransaction = $this->entityManager->find(RecurringTransaction::class, $rtId);
            if ($recurringTransaction) {
                $recommendations[] = new RecurringTransactionRecommendation(
                    $recurringTransaction,
                    $confidence,
                    sprintf('Label identique (%d/%d = %.0f%%)', $count, $totalWithLabel, $probability)
                );
            }
        }

        return $recommendations;
    }

    /**
     * Calcule la confiance basée sur la probabilité bayésienne P(recurring | label).
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
     * Stratégie 2 : Trouve des transactions récurrentes basées sur des labels similaires (fuzzy matching).
     *
     * @param Transaction $transaction
     * @return RecurringTransactionRecommendation[]
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function findBySimilarLabels(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Normaliser le label pour comparaison
        $normalizedLabel = mb_strtoupper($this->normalizeLabel($label));

        // Récupérer toutes les transactions avec récurrence de l'utilisateur
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('t')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.recurringTransaction IS NOT NULL')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('currentId', $transaction->getId())
            ->setMaxResults(200);

        $results = $qb->getQuery()->getResult();

        $recurringScores = [];

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
                $recurringTransaction = $similarTransaction->getRecurringTransaction();
                if ($recurringTransaction && $recurringTransaction->getId()) {
                    $rtId = $recurringTransaction->getId()->toString();

                    // Garder le meilleur score de similarité pour chaque transaction récurrente
                    if (!isset($recurringScores[$rtId]) || $recurringScores[$rtId]['similarity'] < $similarity) {
                        $recurringScores[$rtId] = [
                            'recurringTransaction' => $recurringTransaction,
                            'similarity' => $similarity,
                        ];
                    }
                }
            }
        }

        $recommendations = [];
        foreach ($recurringScores as $data) {
            $similarity = $data['similarity'];
            $confidence = $this->calculateFuzzyMatchConfidence($similarity);

            $recommendations[] = new RecurringTransactionRecommendation(
                $data['recurringTransaction'],
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
     * Stratégie 3 : Trouve des transactions récurrentes basées sur des mots-clés du label.
     *
     * @param Transaction $transaction
     * @return RecurringTransactionRecommendation[]
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function findByKeywordMatching(Transaction $transaction): array
    {
        $label = $transaction->getLabel();
        if (!$label) {
            return [];
        }

        // Extraire les mots-clés du label
        $keywords = $this->extractKeywords($label);
        if (empty($keywords)) {
            return [];
        }

        $recommendations = [];
        $recurringTransactionScores = [];

        // Pour chaque mot-clé, chercher des transactions similaires
        foreach ($keywords as $keyword) {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('IDENTITY(t.recurringTransaction) as rt_id', 'COUNT(t.id) as match_count')
                ->from(Transaction::class, 't')
                ->where('t.user = :user')
                ->andWhere('UPPER(t.label) LIKE :keyword')
                ->andWhere('t.recurringTransaction IS NOT NULL')
                ->andWhere('t.id != :currentId')
                ->setParameter('user', $transaction->getUser())
                ->setParameter('keyword', '%' . strtoupper($keyword) . '%')
                ->setParameter('currentId', $transaction->getId())
                ->groupBy('rt_id')
                ->orderBy('match_count', 'DESC')
                ->setMaxResults(10);

            $results = $qb->getQuery()->getResult();

            foreach ($results as $data) {
                $rtId = $data['rt_id'];

                // Hydrater l'entité RecurringTransaction
                $recurringTransaction = $this->entityManager->find(RecurringTransaction::class, $rtId);
                if (!$recurringTransaction || !$recurringTransaction->getId()) {
                    continue;
                }

                $rtIdString = $recurringTransaction->getId()->toString();

                // Calculer la confiance basée sur la correspondance du mot-clé
                $confidence = $this->calculateKeywordConfidence($keyword, $recurringTransaction);

                if (!isset($recurringTransactionScores[$rtIdString]) || $recurringTransactionScores[$rtIdString]['confidence'] < $confidence) {
                    $recurringTransactionScores[$rtIdString] = [
                        'recurringTransaction' => $recurringTransaction,
                        'confidence' => $confidence,
                        'keyword' => $keyword,
                    ];
                }
            }
        }

        foreach ($recurringTransactionScores as $data) {
            $recommendations[] = new RecurringTransactionRecommendation(
                $data['recurringTransaction'],
                $data['confidence'],
                sprintf('Mot-clé : "%s"', $data['keyword'])
            );
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
    private function calculateKeywordConfidence(string $keyword, RecurringTransaction $recurringTransaction): float
    {
        $keywordUpper = mb_strtoupper($keyword);
        $rtName = $recurringTransaction->getName();
        if ($rtName === null) {
            return self::CONFIDENCE_KEYWORD_WEAK;
        }
        $rtNameUpper = mb_strtoupper($rtName);

        // Si le nom de la transaction récurrente correspond exactement au mot-clé → confiance forte
        if ($rtNameUpper === $keywordUpper) {
            return self::CONFIDENCE_KEYWORD_STRONG;
        }

        // Si le nom contient le mot-clé ou vice-versa → confiance moyenne
        if (str_contains($rtNameUpper, $keywordUpper) || str_contains($keywordUpper, $rtNameUpper)) {
            return self::CONFIDENCE_KEYWORD_MEDIUM;
        }

        // Sinon → confiance faible
        return self::CONFIDENCE_KEYWORD_WEAK;
    }

    /**
     * Stratégie 4 : Retourne les transactions récurrentes les plus fréquemment utilisées.
     *
     * @param Transaction $transaction
     * @return RecurringTransactionRecommendation[]
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function findMostFrequentRecurringTransactions(Transaction $transaction): array
    {
        $user = $transaction->getUser();
        if (!$user) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('rt.id as rt_id', 'COUNT(t.id) as transaction_count')
            ->from(RecurringTransaction::class, 'rt')
            ->leftJoin('rt.transactions', 't')
            ->where('rt.user = :user')
            ->setParameter('user', $user)
            ->groupBy('rt.id')
            ->orderBy('transaction_count', 'DESC')
            ->setMaxResults(5);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $rtId = $data['rt_id'];
            $count = $data['transaction_count'];

            if ($count > 0) {
                // Hydrater l'entité RecurringTransaction
                $recurringTransaction = $this->entityManager->find(RecurringTransaction::class, $rtId);
                if ($recurringTransaction) {
                    $recommendations[] = new RecurringTransactionRecommendation(
                        $recurringTransaction,
                        self::CONFIDENCE_FREQUENT,
                        sprintf('Transaction fréquente (%d utilisations)', $count)
                    );
                }
            }
        }

        return $recommendations;
    }

    /**
     * Fusionne et déduplique les recommandations.
     * Si plusieurs recommandations pointent vers la même transaction récurrente :
     * - Garde la meilleure confiance
     * - Ajoute un bonus si multiple sources (cohérence)
     * - Combine les raisons
     *
     * @param RecurringTransactionRecommendation[] $recommendations
     * @return RecurringTransactionRecommendation[]
     */
    private function mergeAndDeduplicateRecommendations(array $recommendations): array
    {
        $grouped = [];

        // Grouper les recommandations par transaction récurrente
        foreach ($recommendations as $recommendation) {
            $id = $recommendation->getRecurringTransaction()->getId();
            if ($id === null) {
                continue;
            }
            $rtId = $id->toString();

            if (!isset($grouped[$rtId])) {
                $grouped[$rtId] = [
                    'recurringTransaction' => $recommendation->getRecurringTransaction(),
                    'recommendations' => [],
                ];
            }

            $grouped[$rtId]['recommendations'][] = $recommendation;
        }

        // Fusionner intelligemment
        $merged = [];
        foreach ($grouped as $rtId => $data) {
            $recs = $data['recommendations'];
            $recurringTransaction = $data['recurringTransaction'];

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

            $merged[$rtId] = new RecurringTransactionRecommendation(
                $recurringTransaction,
                $finalConfidence,
                $combinedReason
            );
        }

        return array_values($merged);
    }

    /**
     * Exclut la transaction récurrente déjà assignée.
     *
     * @param RecurringTransactionRecommendation[] $recommendations
     * @return RecurringTransactionRecommendation[]
     */
    private function excludeExisting(array $recommendations, Transaction $transaction): array
    {
        $existingRt = $transaction->getRecurringTransaction();
        if (!$existingRt || !$existingRt->getId()) {
            return $recommendations;
        }

        $existingRtId = $existingRt->getId()->toString();

        return array_filter(
            $recommendations,
            static function (RecurringTransactionRecommendation $rec) use ($existingRtId) {
                $recId = $rec->getRecurringTransaction()->getId();
                return $recId === null || $recId->toString() !== $existingRtId;
            }
        );
    }
}
