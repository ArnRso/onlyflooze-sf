<?php

namespace App\Entity;

use App\Enum\Direction;
use App\Repository\RecurrenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Dépense ou revenu prévisible, suivi mois par mois.
 *
 * Le montant attendu est la moyenne des 2-3 dernières occurrences (jamais de
 * l'historique complet) ; la tolérance s'exprime en pourcentage.
 */
#[ORM\Entity(repositoryClass: RecurrenceRepository::class)]
class Recurrence
{
    public const int DEFAULT_TOLERANCE_PCT = 15;
    public const int DEFAULT_DAY_WINDOW = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CategorizationRule $rule = null;

    #[ORM\Column(enumType: Direction::class)]
    private Direction $direction;

    #[ORM\Column]
    private int $expectedDayOfMonth;

    #[ORM\Column]
    private int $expectedAmountCents;

    #[ORM\Column]
    private int $tolerancePct = self::DEFAULT_TOLERANCE_PCT;

    #[ORM\Column]
    private int $dayWindow = self::DEFAULT_DAY_WINDOW;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Date de la dernière occurrence réelle quand la récurrence s'est
     * arrêtée (prêt soldé, abonnement résilié…). Les mois postérieurs ne
     * l'attendent plus.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * Transactions explicitement refusées par l'utilisateur lors de la
     * recherche rétroactive : ne plus jamais les proposer.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $excludedTransactionIds = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, Direction $direction, int $expectedDayOfMonth, int $expectedAmountCents)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->direction = $direction;
        $this->expectedDayOfMonth = $expectedDayOfMonth;
        $this->expectedAmountCents = $expectedAmountCents;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getRule(): ?CategorizationRule
    {
        return $this->rule;
    }

    public function setRule(?CategorizationRule $rule): static
    {
        $this->rule = $rule;

        return $this;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function getExpectedDayOfMonth(): int
    {
        return $this->expectedDayOfMonth;
    }

    public function setExpectedDayOfMonth(int $expectedDayOfMonth): static
    {
        $this->expectedDayOfMonth = $expectedDayOfMonth;

        return $this;
    }

    public function getExpectedAmountCents(): int
    {
        return $this->expectedAmountCents;
    }

    public function setExpectedAmountCents(int $expectedAmountCents): static
    {
        $this->expectedAmountCents = $expectedAmountCents;

        return $this;
    }

    public function getTolerancePct(): int
    {
        return $this->tolerancePct;
    }

    public function setTolerancePct(int $tolerancePct): static
    {
        $this->tolerancePct = $tolerancePct;

        return $this;
    }

    public function getDayWindow(): int
    {
        return $this->dayWindow;
    }

    public function setDayWindow(int $dayWindow): static
    {
        $this->dayWindow = $dayWindow;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    /**
     * La récurrence est-elle terminée pour le mois donné ? (le mois de la
     * dernière occurrence reste couvert, les suivants non).
     */
    public function isEndedForMonth(\DateTimeImmutable $month): bool
    {
        return $this->endedAt !== null && $month->modify('first day of this month')->setTime(0, 0) > $this->endedAt;
    }

    public function isTransactionExcluded(Transaction $transaction): bool
    {
        return \in_array((string) $transaction->getId(), $this->excludedTransactionIds, true);
    }

    public function excludeTransaction(Transaction $transaction): static
    {
        if (!$this->isTransactionExcluded($transaction)) {
            $this->excludedTransactionIds[] = (string) $transaction->getId();
        }

        return $this;
    }

    public function isAmountWithinTolerance(int $amountCents): bool
    {
        $tolerance = (int) round(abs($this->expectedAmountCents) * $this->tolerancePct / 100);

        return abs(abs($amountCents) - abs($this->expectedAmountCents)) <= $tolerance;
    }
}
