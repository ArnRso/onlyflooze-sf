<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\CsvImportProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CsvImportProfileRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CsvImportProfile
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?UuidInterface $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'csvImportProfiles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 1)]
    private string $delimiter = ',';

    #[ORM\Column(length: 20)]
    private string $encoding = 'UTF-8';

    /**
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $columnMapping = [];

    #[ORM\Column(length: 50)]
    private string $dateFormat = 'd/m/Y';

    #[ORM\Column(length: 20)]
    private string $amountType = 'single';

    #[ORM\Column]
    private bool $hasHeader = true;

    /**
     * @var Collection<int, CsvImportSession>
     */
    #[ORM\OneToMany(targetEntity: CsvImportSession::class, mappedBy: 'profile')]
    private Collection $importSessions;

    public function __construct()
    {
        $this->importSessions = new ArrayCollection();
    }

    public function getId(): ?UuidInterface
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    public function setDelimiter(string $delimiter): static
    {
        $this->delimiter = $delimiter;

        return $this;
    }

    public function getEncoding(): string
    {
        return $this->encoding;
    }

    public function setEncoding(string $encoding): static
    {
        $this->encoding = $encoding;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getColumnMapping(): array
    {
        return $this->columnMapping;
    }

    /**
     * @param array<string, int> $columnMapping
     */
    public function setColumnMapping(array $columnMapping): static
    {
        $this->columnMapping = $columnMapping;

        return $this;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    public function setDateFormat(string $dateFormat): static
    {
        $this->dateFormat = $dateFormat;

        return $this;
    }

    public function getAmountType(): string
    {
        return $this->amountType;
    }

    public function setAmountType(string $amountType): static
    {
        $this->amountType = $amountType;

        return $this;
    }

    public function isHasHeader(): bool
    {
        return $this->hasHeader;
    }

    public function setHasHeader(bool $hasHeader): static
    {
        $this->hasHeader = $hasHeader;

        return $this;
    }

    /**
     * @return Collection<int, CsvImportSession>
     */
    public function getImportSessions(): Collection
    {
        return $this->importSessions;
    }

    public function addImportSession(CsvImportSession $importSession): static
    {
        if (!$this->importSessions->contains($importSession)) {
            $this->importSessions->add($importSession);
            $importSession->setProfile($this);
        }

        return $this;
    }

    public function removeImportSession(CsvImportSession $importSession): static
    {
        if ($this->importSessions->removeElement($importSession)) {
            if ($importSession->getProfile() === $this) {
                $importSession->setProfile(null);
            }
        }

        return $this;
    }
}