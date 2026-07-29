<?php

namespace App\Twig;

use App\Repository\TransactionRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('to_review_count', $this->transactionRepository->countToReview(...)),
        ];
    }
}
