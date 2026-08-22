<?php

namespace App\Entity;

use App\Enum\Direction;
use App\Enum\TransactionType;
use App\Repository\RecurrenceDismissalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Proposition de récurrence écartée par l'utilisateur : même sens, même
 * type, même tête de libellé et montant voisin → ne plus jamais la refaire.
 * Le montant fait partie de la signature : écarter « PayPal 21,24 » ne
 * doit pas faire taire « PayPal 9,99 ».
 */
#[ORM\Entity(repositoryClass: RecurrenceDismissalRepository::class)]
class RecurrenceDismissal
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(enumType: Direction::class)]
    private Direction $direction;

    #[ORM\Column(enumType: TransactionType::class)]
    private TransactionType $type;

    #[ORM\Column(length: 150)]
    private string $headToken;

    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Direction $direction, TransactionType $type, string $headToken, int $amountCents)
    {
        $this->id = Uuid::v7();
        $this->direction = $direction;
        $this->type = $type;
        $this->headToken = $headToken;
        $this->amountCents = $amountCents;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function covers(Direction $direction, TransactionType $type, string $headToken, int $amountCents, int $tolerancePct): bool
    {
        if ($this->direction !== $direction || $this->type !== $type || $this->headToken !== $headToken) {
            return false;
        }

        $tolerance = (int) round(abs($this->amountCents) * $tolerancePct / 100);

        return abs(abs($amountCents) - abs($this->amountCents)) <= $tolerance;
    }
}
