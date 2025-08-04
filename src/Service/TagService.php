<?php

namespace App\Service;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class TagService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TagRepository          $tagRepository
    )
    {
    }

    public function updateTag(Tag $tag): Tag
    {
        $this->entityManager->flush();

        return $tag;
    }

    public function deleteTag(Tag $tag): void
    {
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }

    /**
     * @return Tag[]
     */
    public function searchUserTags(User $user, string $search): array
    {
        return $this->tagRepository->findByUserAndName($user, $search);
    }

    public function getUserTagCount(User $user): int
    {
        return $this->tagRepository->countByUser($user);
    }

    /**
     * @return array<int, array{0: Tag, transactionCount: int}>
     */
    public function getUserTagsWithTransactionCount(User $user): array
    {
        return $this->tagRepository->findByUserWithTransactionCount($user);
    }

    /**
     * @return array{total_tags: int, tags_with_transactions: int, tags_without_transactions: int, total_transactions: int, average_transactions_per_tag: float|int}
     */
    public function getUserTagStats(User $user): array
    {
        $tags = $this->getUserTags($user);
        $totalTags = count($tags);

        $tagsWithTransactions = 0;
        $totalTransactions = 0;

        foreach ($tags as $tag) {
            $transactionCount = $tag->getTransactions()->count();
            if ($transactionCount > 0) {
                $tagsWithTransactions++;
                $totalTransactions += $transactionCount;
            }
        }

        return [
            'total_tags' => $totalTags,
            'tags_with_transactions' => $tagsWithTransactions,
            'tags_without_transactions' => $totalTags - $tagsWithTransactions,
            'total_transactions' => $totalTransactions,
            'average_transactions_per_tag' => $totalTags > 0 ? $totalTransactions / $totalTags : 0,
        ];
    }

    /**
     * @return Tag[]
     */
    public function getUserTags(User $user): array
    {
        return $this->tagRepository->findByUser($user);
    }

    /**
     * Statistiques pour un tag spécifique
     * @return array{total_transactions: int, total_amount: float, average_amount: float, average_per_month: float, months_count: int}
     */
    public function getTagStats(Tag $tag): array
    {
        $transactions = $tag->getTransactions();
        $totalTransactions = $transactions->count();
        $totalAmount = 0;
        $monthlyTotals = [];

        foreach ($transactions as $transaction) {
            $totalAmount += $transaction->getAmountAsFloat();

            // Calculer les totaux par mois budgétaire
            $budgetMonth = $transaction->getBudgetMonth();
            if ($budgetMonth) {
                if (!isset($monthlyTotals[$budgetMonth])) {
                    $monthlyTotals[$budgetMonth] = 0;
                }
                $monthlyTotals[$budgetMonth] += $transaction->getAmountAsFloat();
            }
        }

        $averageAmount = $totalTransactions > 0 ? $totalAmount / $totalTransactions : 0;

        // Calculer la moyenne par mois budgétaire
        $monthsCount = count($monthlyTotals);
        $averagePerMonth = $monthsCount > 0 ? $totalAmount / $monthsCount : 0;

        return [
            'total_transactions' => $totalTransactions,
            'total_amount' => $totalAmount,
            'average_amount' => $averageAmount,
            'average_per_month' => $averagePerMonth,
            'months_count' => $monthsCount,
        ];
    }

    /**
     * Totaux mensuels pour un tag spécifique
     * @return array<string, float>
     */
    public function getMonthlyTotalsForTag(Tag $tag): array
    {
        $transactions = $tag->getTransactions();
        $monthlyTotals = [];

        foreach ($transactions as $transaction) {
            $budgetMonth = $transaction->getBudgetMonth();
            if ($budgetMonth) {
                if (!isset($monthlyTotals[$budgetMonth])) {
                    $monthlyTotals[$budgetMonth] = 0;
                }
                $monthlyTotals[$budgetMonth] += $transaction->getAmountAsFloat();
            }
        }

        ksort($monthlyTotals);

        return $monthlyTotals;
    }

    public function findOrCreateTag(User $user, string $name, ?string $color = null): Tag
    {
        $existingTags = $this->tagRepository->findBy(['user' => $user, 'name' => $name]);

        if (!empty($existingTags)) {
            return $existingTags[0];
        }

        $tag = new Tag();
        $tag->setName($name);

        if ($color) {
            $tag->setColor($color);
        }

        return $this->createTag($tag, $user);
    }

    public function createTag(Tag $tag, User $user): Tag
    {
        // Définir l'utilisateur seulement s'il n'est pas déjà défini
        if (!$tag->getUser()) {
            $tag->setUser($user);
        }

        // Générer une couleur aléatoire si aucune couleur n'est définie
        if (!$tag->getColor()) {
            $tag->setColor($this->generateRandomColor());
        }

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    /**
     * Génère une couleur hexadécimale aléatoire
     */
    private function generateRandomColor(): string
    {
        // Palette de couleurs prédéfinies pour de meilleurs contrastes
        $colors = [
            '#dc3545', // Rouge
            '#198754', // Vert
            '#0d6efd', // Bleu
            '#fd7e14', // Orange
            '#6f42c1', // Violet
            '#20c997', // Teal
            '#ffc107', // Jaune
            '#e91e63', // Rose
            '#795548', // Marron
            '#607d8b', // Bleu gris
            '#ff5722', // Orange profond
            '#9c27b0', // Violet profond
            '#2196f3', // Bleu clair
            '#4caf50', // Vert clair
            '#ff9800', // Orange ambré
            '#9e9e9e', // Gris
            '#673ab7', // Indigo
            '#3f51b5', // Bleu indigo
            '#009688', // Vert teal
            '#f44336', // Rouge profond
            '#8e24aa', // Violet pourpre
            '#5e35b1', // Violet profond
            '#3949ab', // Indigo moyen
            '#1e88e5', // Bleu électrique
            '#039be5', // Bleu cyan
            '#00acc1', // Cyan foncé
            '#00897b', // Vert émeraude
            '#43a047', // Vert prairie
            '#689f38', // Vert olive
            '#827717', // Vert lime foncé
            '#afb42b', // Lime
            '#fbc02d', // Jaune moutarde
            '#ffa000', // Ambre
            '#f57c00', // Orange foncé
            '#e64a19', // Rouge orangé
            '#d84315', // Rouge brique
            '#bf360c', // Rouge terre
            '#6d4c41', // Marron café
            '#546e7a', // Gris bleuté
            '#455a64', // Gris ardoise
            '#37474f', // Gris anthracite
            '#263238', // Gris charbon
            '#1b5e20', // Vert forêt
            '#0d47a1', // Bleu marine
            '#4a148c', // Violet royal
            '#880e4f', // Rouge bordeaux
            '#e65100', // Orange brûlé
            '#ff6f00', // Ambre profond
            '#f57f17', // Jaune or
            '#827717', // Olive
            '#33691e', // Vert mousse
            '#1565c0', // Bleu roi
            '#283593', // Indigo royal
            '#7b1fa2', // Violet magenta
            '#c2185b', // Rose fuchsia
            '#ad1457', // Rose profond
            '#8bc34a', // Vert pomme
            '#cddc39', // Lime vif
            '#ffeb3b', // Jaune citron
            '#ffc107', // Ambre doré
            '#ff5722', // Rouge tomate
            '#795548', // Terre de sienne
            '#9e9e9e'  // Gris neutre
        ];

        return $colors[array_rand($colors)];
    }

    /**
     * Initialise un nouveau tag avec une couleur aléatoire et l'utilisateur
     */
    public function initializeNewTag(User $user): Tag
    {
        $tag = new Tag();
        $tag->setUser($user);
        $tag->setColor($this->generateRandomColor());

        return $tag;
    }
}
