<?php

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Repository\CsvImportSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CsvImportSessionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CsvImportSession
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private ?UuidInterface $id = null;

    #[ORM\ManyToOne(inversedBy: 'csvImportSessions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'importSessions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CsvImportProfile $profile = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column]
    private int $totalRows = 0;

    #[ORM\Column]
    private int $successfulImports = 0;

    #[ORM\Column]
    private int $duplicates = 0;

    #[ORM\Column]
    private int $errors = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $errorDetails = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?UuidInterface
    {
        return $this->id;
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

    public function getProfile(): ?CsvImportProfile
    {
        return $this->profile;
    }

    public function setProfile(?CsvImportProfile $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function setTotalRows(int $totalRows): static
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    public function getSuccessfulImports(): int
    {
        return $this->successfulImports;
    }

    public function setSuccessfulImports(int $successfulImports): static
    {
        $this->successfulImports = $successfulImports;

        return $this;
    }

    public function getDuplicates(): int
    {
        return $this->duplicates;
    }

    public function setDuplicates(int $duplicates): static
    {
        $this->duplicates = $duplicates;

        return $this;
    }

    public function getErrors(): int
    {
        return $this->errors;
    }

    public function setErrors(int $errors): static
    {
        $this->errors = $errors;

        return $this;
    }

    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    public function setErrorDetails(?array $errorDetails): static
    {
        $this->errorDetails = $errorDetails;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getSuccessRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return ($this->successfulImports / $this->totalRows) * 100;
    }
}
