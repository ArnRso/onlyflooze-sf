<?php

namespace App\Entity;

use App\Enum\CategorySource;
use App\Enum\Direction;
use App\Enum\SuggestionOutcome;
use App\Enum\TransactionNature;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne du relevé bancaire.
 *
 * Le montant est signé, en centimes (négatif = débit). "dedupKey" sert au
 * dédoublonnage par comptage d'occurrences : ce n'est PAS une clé unique,
 * des doublons légitimes existent.
 */
#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'bank_transaction')]
#[ORM\Index(name: 'idx_transaction_dedup_key', columns: ['dedup_key'])]
#[ORM\Index(name: 'idx_transaction_operation_date', columns: ['operation_date'])]
#[ORM\Index(name: 'idx_transaction_category_source', columns: ['category_source'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $valueDate;

    #[ORM\Column(type: 'text')]
    private string $label;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(enumType: TransactionType::class)]
    private TransactionType $type;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $tokens = [];

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $purchaseDate = null;

    #[ORM\Column(enumType: TransactionNature::class)]
    private TransactionNature $nature;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $suggestedCategory = null;

    #[ORM\Column(enumType: CategorySource::class)]
    private CategorySource $categorySource = CategorySource::Unclassified;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CategorizationRule $matchedRule = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $suggestionAtReview = null;

    #[ORM\Column(nullable: true, enumType: SuggestionOutcome::class)]
    private ?SuggestionOutcome $suggestionOutcome = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Recurrence $recurrence = null;

    #[ORM\Column]
    private bool $amountOutOfTolerance = false;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'bank_transaction_tag')]
    private Collection $tags;

    #[ORM\Column(length: 40)]
    private string $dedupKey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImportBatch $importBatch = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        \DateTimeImmutable $operationDate,
        \DateTimeImmutable $valueDate,
        string $label,
        int $amountCents,
        TransactionType $type,
    ) {
        $this->id = Uuid::v7();
        $this->operationDate = $operationDate;
        $this->valueDate = $valueDate;
        $this->label = $label;
        $this->amountCents = $amountCents;
        $this->type = $type;
        $this->nature = TransactionNature::defaultForAmountCents($amountCents);
        $this->dedupKey = self::computeDedupKey($operationDate, $label, $amountCents);
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function computeDedupKey(\DateTimeImmutable $operationDate, string $label, int $amountCents): string
    {
        return sha1($operationDate->format('Y-m-d').'|'.trim($label).'|'.$amountCents);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOperationDate(): \DateTimeImmutable
    {
        return $this->operationDate;
    }

    public function getValueDate(): \DateTimeImmutable
    {
        return $this->valueDate;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getDirection(): Direction
    {
        return Direction::fromAmountCents($this->amountCents);
    }

    public function getType(): TransactionType
    {
        return $this->type;
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

        return $this;
    }

    public function getPurchaseDate(): ?\DateTimeImmutable
    {
        return $this->purchaseDate;
    }

    public function setPurchaseDate(?\DateTimeImmutable $purchaseDate): static
    {
        $this->purchaseDate = $purchaseDate;

        return $this;
    }

    public function getNature(): TransactionNature
    {
        return $this->nature;
    }

    public function setNature(TransactionNature $nature): static
    {
        $this->nature = $nature;

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

    public function getSuggestedCategory(): ?Category
    {
        return $this->suggestedCategory;
    }

    public function setSuggestedCategory(?Category $suggestedCategory): static
    {
        $this->suggestedCategory = $suggestedCategory;

        return $this;
    }

    public function getCategorySource(): CategorySource
    {
        return $this->categorySource;
    }

    public function setCategorySource(CategorySource $categorySource): static
    {
        $this->categorySource = $categorySource;

        return $this;
    }

    public function isToReview(): bool
    {
        return $this->categorySource === CategorySource::Unclassified;
    }

    public function getMatchedRule(): ?CategorizationRule
    {
        return $this->matchedRule;
    }

    public function setMatchedRule(?CategorizationRule $matchedRule): static
    {
        $this->matchedRule = $matchedRule;

        return $this;
    }

    public function getSuggestionAtReview(): ?Category
    {
        return $this->suggestionAtReview;
    }

    public function getSuggestionOutcome(): ?SuggestionOutcome
    {
        return $this->suggestionOutcome;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    /**
     * Fige ce que le moteur avait suggéré au moment où l'utilisateur tranche.
     */
    public function recordReviewOutcome(SuggestionOutcome $outcome): static
    {
        $this->suggestionAtReview = $this->suggestedCategory;
        $this->suggestionOutcome = $outcome;
        $this->reviewedAt = new \DateTimeImmutable();

        return $this;
    }

    public function clearReviewOutcome(): static
    {
        $this->suggestionAtReview = null;
        $this->suggestionOutcome = null;
        $this->reviewedAt = null;

        return $this;
    }

    public function getRecurrence(): ?Recurrence
    {
        return $this->recurrence;
    }

    public function setRecurrence(?Recurrence $recurrence): static
    {
        $this->recurrence = $recurrence;

        return $this;
    }

    public function isAmountOutOfTolerance(): bool
    {
        return $this->amountOutOfTolerance;
    }

    public function setAmountOutOfTolerance(bool $amountOutOfTolerance): static
    {
        $this->amountOutOfTolerance = $amountOutOfTolerance;

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getImportBatch(): ?ImportBatch
    {
        return $this->importBatch;
    }

    public function setImportBatch(?ImportBatch $importBatch): static
    {
        $this->importBatch = $importBatch;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
