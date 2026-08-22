<?php

namespace App\Repository;

use App\Dto\CorpusEntry;
use App\Entity\Category;
use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Enum\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Nombre de transactions déjà en base pour chaque clé de dédoublonnage.
     *
     * @param list<string> $dedupKeys
     *
     * @return array<string, int>
     */
    public function countByDedupKeys(array $dedupKeys): array
    {
        if ($dedupKeys === []) {
            return [];
        }

        /** @var list<array{dedupKey: string, cnt: int}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.dedupKey AS dedupKey, COUNT(t.id) AS cnt')
            ->where('t.dedupKey IN (:keys)')
            ->setParameter('keys', $dedupKeys)
            ->groupBy('t.dedupKey')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['dedupKey']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @return list<Transaction>
     */
    public function findToReview(int $limit = 50): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.categorySource = :source')
            ->setParameter('source', CategorySource::Unclassified)
            ->orderBy('t.operationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste paginée pour l'écran transactions, avec filtres simples.
     *
     * @return array{items: list<Transaction>, total: int}
     */
    public function findForList(
        int $page,
        int $perPage,
        ?string $search = null,
        ?Category $category = null,
        bool $onlyToReview = false,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.operationDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC');

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(t.label) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        }

        if ($category !== null) {
            $qb->andWhere('t.category = :category OR t.category IN (SELECT c.id FROM '.Category::class.' c WHERE c.parent = :category)')
                ->setParameter('category', $category);
        }

        if ($onlyToReview) {
            $qb->andWhere('t.categorySource = :unclassified')
                ->setParameter('unclassified', CategorySource::Unclassified);
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $paginator = new Paginator($qb->getQuery());

        return [
            'items' => iterator_to_array($paginator, false),
            'total' => \count($paginator),
        ];
    }

    /**
     * Tout le stock « À trier », sans limite (réapplication des règles).
     *
     * @return list<Transaction>
     */
    public function findAllToReview(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.categorySource = :source')
            ->setParameter('source', CategorySource::Unclassified)
            ->orderBy('t.operationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countToReview(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.categorySource = :source')
            ->setParameter('source', CategorySource::Unclassified)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Transaction déjà catégorisée du même type, d'un montant proche
     * (±15 %), environ un mois plus tôt (fenêtre ±3 jours) : candidate pour
     * le matching par périodicité (niveau 4 de la cascade).
     */
    public function findPeriodicityCandidate(
        TransactionType $type,
        int $amountCents,
        \DateTimeImmutable $operationDate,
        int $tolerancePct = 15,
        int $dayWindow = 3,
    ): ?Transaction {
        $tolerance = (int) round(abs($amountCents) * $tolerancePct / 100);
        $bounds = [$amountCents - $tolerance, $amountCents + $tolerance];

        return $this->createQueryBuilder('t')
            ->where('t.type = :type')
            ->andWhere('t.categorySource != :unclassified')
            ->andWhere('t.category IS NOT NULL')
            ->andWhere('t.amountCents BETWEEN :minAmount AND :maxAmount')
            ->andWhere('t.operationDate BETWEEN :windowStart AND :windowEnd')
            ->setParameter('type', $type)
            ->setParameter('unclassified', CategorySource::Unclassified)
            ->setParameter('minAmount', min($bounds))
            ->setParameter('maxAmount', max($bounds))
            ->setParameter('windowStart', $operationDate->modify(sprintf('-1 month -%d days', $dayWindow)))
            ->setParameter('windowEnd', $operationDate->modify(sprintf('-1 month +%d days', $dayWindow)))
            ->orderBy('t.operationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Transaction>
     */
    public function findByMonth(\DateTimeImmutable $month): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.operationDate >= :start')
            ->andWhere('t.operationDate < :end')
            ->setParameter('start', $month->modify('first day of this month')->setTime(0, 0))
            ->setParameter('end', $month->modify('first day of next month')->setTime(0, 0))
            ->orderBy('t.operationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Transaction>
     */
    public function findByRecurrenceSince(Recurrence $recurrence, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.recurrence = :recurrence')
            ->andWhere('t.operationDate >= :since')
            ->setParameter('recurrence', $recurrence)
            ->setParameter('since', $since)
            ->orderBy('t.operationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Transactions observées pour la détection de récurrences : préfixes
     * PRLV/ECH PRET/F et crédits VIR, non encore rattachées, triées ou non —
     * sauf celles dont la règle a été écartée par l'utilisateur.
     *
     * @return list<Transaction>
     */
    public function findRecurrenceObservations(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.matchedRule', 'r')
            ->where('t.recurrence IS NULL')
            ->andWhere('r.id IS NULL OR r.recurrenceOptOut = false')
            ->andWhere('t.type IN (:candidateTypes) OR (t.type = :virement AND t.amountCents > 0)')
            ->setParameter('candidateTypes', [TransactionType::Prelevement, TransactionType::EcheancePret, TransactionType::Frais])
            ->setParameter('virement', TransactionType::Virement)
            ->orderBy('t.operationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Transactions non rattachées à une récurrence, dans le sens donné
     * (candidates pour la recherche rétroactive).
     *
     * @return list<Transaction>
     */
    public function findUnattachedByDirection(Direction $direction): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.recurrence IS NULL')
            ->andWhere($direction === Direction::Debit ? 't.amountCents < 0' : 't.amountCents > 0')
            ->orderBy('t.operationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tout le corpus vu par l'analyse de sélectivité des tokens : les tokens
     * de chaque transaction et, pour celles triées à la main, la catégorie.
     *
     * @return list<CorpusEntry>
     */
    public function findCorpusEntries(): array
    {
        $sql = 'SELECT tokens, CASE WHEN category_source = :manual THEN category_id END AS category_key FROM bank_transaction';

        /** @var list<array{tokens: string, category_key: string|null}> $rows */
        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['manual' => CategorySource::Manual->value])
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): CorpusEntry => new CorpusEntry(
                array_values(array_map(strval(...), (array) json_decode($row['tokens'], true))),
                $row['category_key'],
            ),
            $rows,
        );
    }

    /**
     * Sort des suggestions (acceptée / corrigée / absente) par mois de tri.
     *
     * @return list<array{month: string, outcome: string, cnt: int}>
     */
    public function countReviewOutcomesByMonth(): array
    {
        $sql = <<<'SQL'
            SELECT to_char(reviewed_at, 'YYYY-MM') AS month, suggestion_outcome AS outcome, count(*) AS cnt
            FROM bank_transaction
            WHERE reviewed_at IS NOT NULL AND suggestion_outcome IS NOT NULL
            GROUP BY 1, 2
            ORDER BY 1
            SQL;

        /** @var list<array{month: string, outcome: string, cnt: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['month' => $row['month'], 'outcome' => $row['outcome'], 'cnt' => (int) $row['cnt']],
            $rows,
        );
    }

    /**
     * Achat d'origine probable d'une annulation carte : débit catégorisé, de
     * montant exactement opposé, dans les semaines précédentes.
     *
     * @return list<Transaction>
     */
    public function findRefundOriginCandidates(int $refundAmountCents, \DateTimeImmutable $refundDate, int $maxDaysBefore = 90): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.amountCents = :amount')
            ->andWhere('t.category IS NOT NULL')
            ->andWhere('t.operationDate <= :refundDate')
            ->andWhere('t.operationDate >= :earliest')
            ->setParameter('amount', -abs($refundAmountCents))
            ->setParameter('refundDate', $refundDate)
            ->setParameter('earliest', $refundDate->modify(sprintf('-%d days', $maxDaysBefore)))
            ->orderBy('t.operationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Date de la toute première occurrence rattachée à la récurrence.
     */
    public function findFirstOccurrenceDate(Recurrence $recurrence): ?\DateTimeImmutable
    {
        /** @var array{minDate: string|null}|null $row */
        $row = $this->createQueryBuilder('t')
            ->select('MIN(t.operationDate) AS minDate')
            ->where('t.recurrence = :recurrence')
            ->setParameter('recurrence', $recurrence)
            ->getQuery()
            ->getOneOrNullResult();

        return isset($row['minDate']) ? new \DateTimeImmutable($row['minDate']) : null;
    }

    public function hasOccurrenceInMonth(Recurrence $recurrence, \DateTimeImmutable $month): bool
    {
        return $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.recurrence = :recurrence')
            ->andWhere('t.operationDate >= :start')
            ->andWhere('t.operationDate < :end')
            ->setParameter('recurrence', $recurrence)
            ->setParameter('start', $month->modify('first day of this month')->setTime(0, 0))
            ->setParameter('end', $month->modify('first day of next month')->setTime(0, 0))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Transaction rattachée à la récurrence pour le mois donné.
     */
    public function findOccurrenceInMonth(Recurrence $recurrence, \DateTimeImmutable $month): ?Transaction
    {
        return $this->createQueryBuilder('t')
            ->where('t.recurrence = :recurrence')
            ->andWhere('t.operationDate >= :start')
            ->andWhere('t.operationDate < :end')
            ->setParameter('recurrence', $recurrence)
            ->setParameter('start', $month->modify('first day of this month')->setTime(0, 0))
            ->setParameter('end', $month->modify('first day of next month')->setTime(0, 0))
            ->orderBy('t.operationDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dernières occurrences d'une récurrence, de la plus récente à la plus ancienne.
     *
     * @return list<Transaction>
     */
    public function findLatestByRecurrence(Recurrence $recurrence, int $limit = 3): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.recurrence = :recurrence')
            ->setParameter('recurrence', $recurrence)
            ->orderBy('t.operationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
