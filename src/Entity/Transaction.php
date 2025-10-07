<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\TransactionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'unique_transaction_per_user', columns: ['user_id', 'transaction_date', 'amount', 'label'])]
class Transaction
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?UuidInterface $id = null;
    #[ORM\Column(length: 255)]
    private ?string $label = null;
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $transactionDate = null;
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $info = null;
    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;
    /**
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'transactions')]
    #[ORM\JoinTable(name: 'transaction_tag')]
    private Collection $tags;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $budgetMonth = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    private ?RecurringTransaction $recurringTransaction = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?UuidInterface
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getTransactionDate(): ?\DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTimeImmutable $transactionDate): static
    {
        $this->transactionDate = $transactionDate;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getAmountAsFloat(): float
    {
        return (float) $this->amount;
    }

    public function getInfo(): ?string
    {
        return $this->info;
    }

    public function setInfo(?string $info): static
    {
        $this->info = $info;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

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

    public function getBudgetMonth(): ?string
    {
        return $this->budgetMonth;
    }

    public function setBudgetMonth(?string $budgetMonth): static
    {
        $this->budgetMonth = $budgetMonth;

        return $this;
    }

    public function getRecurringTransaction(): ?RecurringTransaction
    {
        return $this->recurringTransaction;
    }

    public function setRecurringTransaction(?RecurringTransaction $recurringTransaction): static
    {
        $this->recurringTransaction = $recurringTransaction;

        return $this;
    }
}
