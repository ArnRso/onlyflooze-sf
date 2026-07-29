<?php

namespace App\Entity;

use App\Enum\Direction;
use App\Enum\TransactionNature;
use App\Repository\CategorizationRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Règle de catégorisation apprise des corrections manuelles.
 *
 * - "tokens" est l'intersection des tokens de toutes les transactions classées
 *   pareil : c'est le(s) token(s) discriminant(s), matché(s) sur mot entier.
 * - "fingerprints" garde les empreintes normalisées exactes déjà vues (niveau 1
 *   de la cascade).
 * - "amountCents" optionnel : sous-règle par montant (cas PayPal, agrégateur).
 * - La règle est scopée par sens (débit/crédit) et pointe vers un id de
 *   catégorie, jamais un nom.
 */
#[ORM\Entity(repositoryClass: CategorizationRuleRepository::class)]
class CategorizationRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(enumType: Direction::class)]
    private Direction $direction;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tokens = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $fingerprints = [];

    #[ORM\Column(nullable: true)]
    private ?int $amountCents = null;

    #[ORM\Column(nullable: true, enumType: TransactionNature::class)]
    private ?TransactionNature $nature = null;

    #[ORM\Column]
    private int $confirmations = 0;

    #[ORM\Column]
    private int $corrections = 0;

    #[ORM\Column]
    private bool $recurrenceOptOut = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, Category $category, Direction $direction)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->category = $category;
        $this->direction = $direction;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    /**
     * @return list<string>
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * @param list<string> $tokens
     */
    public function setTokens(array $tokens): static
    {
        $this->tokens = $tokens;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFingerprints(): array
    {
        return $this->fingerprints;
    }

    public function addFingerprint(string $fingerprint): static
    {
        if (!\in_array($fingerprint, $this->fingerprints, true)) {
            $this->fingerprints[] = $fingerprint;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(?int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getNature(): ?TransactionNature
    {
        return $this->nature;
    }

    public function setNature(?TransactionNature $nature): static
    {
        $this->nature = $nature;

        return $this;
    }

    public function getConfirmations(): int
    {
        return $this->confirmations;
    }

    public function recordConfirmation(): static
    {
        ++$this->confirmations;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCorrections(): int
    {
        return $this->corrections;
    }

    public function recordCorrection(): static
    {
        ++$this->corrections;
        $this->confirmations = max(0, $this->confirmations - 1);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isRecurrenceOptOut(): bool
    {
        return $this->recurrenceOptOut;
    }

    public function setRecurrenceOptOut(bool $recurrenceOptOut): static
    {
        $this->recurrenceOptOut = $recurrenceOptOut;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
