<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Deprecated;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?UuidInterface $id = null;
    #[ORM\Column(length: 180)]
    private ?string $email = null;
    #[ORM\Column(length: 100)]
    private ?string $firstName = null;
    #[ORM\Column(length: 100)]
    private ?string $lastName = null;
    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];
    /**
     * @var string|null The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;
    /**
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $transactions;

    /**
     * @var Collection<int, Tag>
     */
    #[ORM\OneToMany(targetEntity: Tag::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $tags;

    /**
     * @var Collection<int, RecurringTransaction>
     */
    #[ORM\OneToMany(targetEntity: RecurringTransaction::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $recurringTransactions;

    /**
     * @var Collection<int, CsvImportProfile>
     */
    #[ORM\OneToMany(targetEntity: CsvImportProfile::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $csvImportProfiles;

    /**
     * @var Collection<int, CsvImportSession>
     */
    #[ORM\OneToMany(targetEntity: CsvImportSession::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $csvImportSessions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->recurringTransactions = new ArrayCollection();
        $this->csvImportProfiles = new ArrayCollection();
        $this->csvImportSessions = new ArrayCollection();
    }

    public function getId(): ?UuidInterface
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string)$this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setUser($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }

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
            $tag->setUser($this);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        if ($this->tags->removeElement($tag)) {
            if ($tag->getUser() === $this) {
                $tag->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, RecurringTransaction>
     */
    public function getRecurringTransactions(): Collection
    {
        return $this->recurringTransactions;
    }

    public function addRecurringTransaction(RecurringTransaction $recurringTransaction): static
    {
        if (!$this->recurringTransactions->contains($recurringTransaction)) {
            $this->recurringTransactions->add($recurringTransaction);
            $recurringTransaction->setUser($this);
        }

        return $this;
    }

    public function removeRecurringTransaction(RecurringTransaction $recurringTransaction): static
    {
        if ($this->recurringTransactions->removeElement($recurringTransaction)) {
            if ($recurringTransaction->getUser() === $this) {
                $recurringTransaction->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CsvImportProfile>
     */
    public function getCsvImportProfiles(): Collection
    {
        return $this->csvImportProfiles;
    }

    public function addCsvImportProfile(CsvImportProfile $csvImportProfile): static
    {
        if (!$this->csvImportProfiles->contains($csvImportProfile)) {
            $this->csvImportProfiles->add($csvImportProfile);
            $csvImportProfile->setUser($this);
        }

        return $this;
    }

    public function removeCsvImportProfile(CsvImportProfile $csvImportProfile): static
    {
        if ($this->csvImportProfiles->removeElement($csvImportProfile)) {
            if ($csvImportProfile->getUser() === $this) {
                $csvImportProfile->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CsvImportSession>
     */
    public function getCsvImportSessions(): Collection
    {
        return $this->csvImportSessions;
    }

    public function addCsvImportSession(CsvImportSession $csvImportSession): static
    {
        if (!$this->csvImportSessions->contains($csvImportSession)) {
            $this->csvImportSessions->add($csvImportSession);
            $csvImportSession->setUser($this);
        }

        return $this;
    }

    public function removeCsvImportSession(CsvImportSession $csvImportSession): static
    {
        if ($this->csvImportSessions->removeElement($csvImportSession)) {
            if ($csvImportSession->getUser() === $this) {
                $csvImportSession->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array)$this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }
}
