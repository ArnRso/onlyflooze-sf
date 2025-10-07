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
 * Utilise plusieurs stratégies :
 * 1. Correspondance exacte du label (95% confiance)
 * 2. Correspondance par mots-clés (60-85% confiance)
 * 3. Montant similaire (40-60% confiance)
 * 4. Transactions récurrentes fréquentes (20-30% confiance)
 */
class RecurringTransactionRecommendationService
{
    private const float CONFIDENCE_EXACT_MATCH = 95.0;
    private const float CONFIDENCE_KEYWORD_STRONG = 85.0;
    private const float CONFIDENCE_KEYWORD_MEDIUM = 70.0;
    private const float CONFIDENCE_KEYWORD_WEAK = 60.0;
    private const float CONFIDENCE_AMOUNT_PATTERN = 50.0;
    private const float CONFIDENCE_FREQUENT = 25.0;

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

        // Stratégie 2 : Correspondance par mots-clés
        $keywordRecommendations = $this->findByKeywordMatching($transaction);
        $recommendations = array_merge($recommendations, $keywordRecommendations);

        // Stratégie 3 : Montant similaire
        $amountRecommendations = $this->findByAmountPattern($transaction);
        $recommendations = array_merge($recommendations, $amountRecommendations);

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
            ->setMaxResults(3);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $rtId = $data['rt_id'];
            $count = $data['match_count'];

            // Hydrater l'entité RecurringTransaction
            $recurringTransaction = $this->entityManager->find(RecurringTransaction::class, $rtId);
            if ($recurringTransaction) {
                $recommendations[] = new RecurringTransactionRecommendation(
                    $recurringTransaction,
                    self::CONFIDENCE_EXACT_MATCH,
                    sprintf('Label identique (%d occurrences)', $count)
                );
            }
        }

        return $recommendations;
    }

    /**
     * Stratégie 2 : Trouve des transactions récurrentes basées sur des mots-clés du label.
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
                if (!$recurringTransaction) {
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
        $stopWords = ['CARTE', 'VIR', 'PRLV', 'DE', 'DU', 'LA', 'LE', 'LES', 'UN', 'UNE', 'INST', 'VERS', 'ECH', 'PRET'];
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            // Garder les mots de 3+ caractères qui ne sont pas des stop words
            if (mb_strlen($word) >= 3 && !in_array($word, $stopWords, true) && !preg_match('/^\d+$/', $word)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Calcule la confiance pour une correspondance par mot-clé.
     */
    private function calculateKeywordConfidence(string $keyword, RecurringTransaction $recurringTransaction): float
    {
        $keywordUpper = mb_strtoupper($keyword);
        $rtNameUpper = mb_strtoupper($recurringTransaction->getName());

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
     * Stratégie 3 : Trouve des transactions récurrentes basées sur des montants similaires.
     *
     * @param Transaction $transaction
     * @return RecurringTransactionRecommendation[]
     * @throws ORMException
     * @throws OptimisticLockException
     */
    private function findByAmountPattern(Transaction $transaction): array
    {
        $amount = $transaction->getAmountAsFloat();
        $tolerance = abs($amount) * 0.1; // Tolérance de 10%

        $minAmount = $amount - $tolerance;
        $maxAmount = $amount + $tolerance;

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('IDENTITY(t.recurringTransaction) as rt_id', 'COUNT(t.id) as match_count')
            ->from(Transaction::class, 't')
            ->where('t.user = :user')
            ->andWhere('t.amount >= :minAmount')
            ->andWhere('t.amount <= :maxAmount')
            ->andWhere('t.recurringTransaction IS NOT NULL')
            ->andWhere('t.id != :currentId')
            ->setParameter('user', $transaction->getUser())
            ->setParameter('minAmount', (string)$minAmount)
            ->setParameter('maxAmount', (string)$maxAmount)
            ->setParameter('currentId', $transaction->getId())
            ->groupBy('rt_id')
            ->orderBy('match_count', 'DESC')
            ->setMaxResults(5);

        $results = $qb->getQuery()->getResult();

        $recommendations = [];
        foreach ($results as $data) {
            $rtId = $data['rt_id'];
            $count = $data['match_count'];

            // Hydrater l'entité RecurringTransaction
            $recurringTransaction = $this->entityManager->find(RecurringTransaction::class, $rtId);
            if ($recurringTransaction) {
                $recommendations[] = new RecurringTransactionRecommendation(
                    $recurringTransaction,
                    self::CONFIDENCE_AMOUNT_PATTERN,
                    sprintf('Montant similaire (%.2f€, %d occurrences)', $amount, $count)
                );
            }
        }

        return $recommendations;
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
     *
     * @param RecurringTransactionRecommendation[] $recommendations
     * @return RecurringTransactionRecommendation[]
     */
    private function mergeAndDeduplicateRecommendations(array $recommendations): array
    {
        $merged = [];

        foreach ($recommendations as $recommendation) {
            $rtId = $recommendation->getRecurringTransaction()->getId()->toString();

            if (!isset($merged[$rtId]) || $merged[$rtId]->getConfidence() < $recommendation->getConfidence()) {
                $merged[$rtId] = $recommendation;
            }
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
        if (!$existingRt) {
            return $recommendations;
        }

        $existingRtId = $existingRt->getId()->toString();

        return array_filter(
            $recommendations,
            static fn(RecurringTransactionRecommendation $rec) => $rec->getRecurringTransaction()->getId()->toString() !== $existingRtId
        );
    }
}
