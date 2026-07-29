<?php

namespace App\Entity;

use App\Repository\ImportBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $newCount = 0;

    #[ORM\Column]
    private int $duplicateCount = 0;

    #[ORM\Column]
    private int $suggestedCount = 0;

    #[ORM\Column]
    private int $toReviewCount = 0;

    public function __construct(string $filename)
    {
        $this->id = Uuid::v7();
        $this->filename = $filename;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getNewCount(): int
    {
        return $this->newCount;
    }

    public function setNewCount(int $newCount): static
    {
        $this->newCount = $newCount;

        return $this;
    }

    public function getDuplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function setDuplicateCount(int $duplicateCount): static
    {
        $this->duplicateCount = $duplicateCount;

        return $this;
    }

    public function getSuggestedCount(): int
    {
        return $this->suggestedCount;
    }

    public function setSuggestedCount(int $suggestedCount): static
    {
        $this->suggestedCount = $suggestedCount;

        return $this;
    }

    public function getToReviewCount(): int
    {
        return $this->toReviewCount;
    }

    public function setToReviewCount(int $toReviewCount): static
    {
        $this->toReviewCount = $toReviewCount;

        return $this;
    }
}
