<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\Review\ClassifiedLookup;
use App\Service\Review\RuleReapplier;
use App\Service\Review\TransactionCategorizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

class ReviewController extends AbstractController
{
    #[Route('/review', name: 'app_review')]
    public function index(TransactionRepository $transactionRepository, CategoryRepository $categoryRepository): Response
    {
        return $this->render('review/index.html.twig', [
            'transactions' => $transactionRepository->findToReview(100),
            'toReviewCount' => $transactionRepository->countToReview(),
            'categories' => $categoryRepository->findAllOrdered(),
        ]);
    }

    #[Route('/review/lookup', name: 'app_review_lookup', methods: ['GET'])]
    public function lookup(Request $request, ClassifiedLookup $lookup): Response
    {
        return $this->render('review/_lookup.html.twig', [
            'result' => $lookup->lookup((string) $request->query->get('q', '')),
        ]);
    }

    #[Route('/review/{id}/categorize', name: 'app_review_categorize', methods: ['POST'])]
    public function categorize(
        Transaction $transaction,
        Request $request,
        TransactionCategorizer $categorizer,
        TransactionRepository $transactionRepository,
        CategoryRepository $categoryRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('categorize'.$transaction->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $category = $categoryRepository->find((string) $request->getPayload()->get('category'));
        if (!$category instanceof Category) {
            $this->addFlash('danger', 'Choisis une catégorie.');

            return $this->redirectToRoute('app_review');
        }

        $nature = TransactionNature::tryFrom((string) $request->getPayload()->get('nature'));

        $resuggested = $categorizer->categorize($transaction, $category, $nature);

        if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            // Seules la ligne validée et celles dont la suggestion vient de
            // changer sont touchées : le reste de la page ne bouge pas.
            return $this->render('review/categorize.stream.html.twig', [
                'transaction' => $transaction,
                'resuggested' => $resuggested,
                'toReviewCount' => $transactionRepository->countToReview(),
                'categories' => $categoryRepository->findAllOrdered(),
            ]);
        }

        return $this->redirectToRoute('app_review');
    }

    #[Route('/review/reapply', name: 'app_review_reapply', methods: ['POST'])]
    public function reapply(Request $request, RuleReapplier $ruleReapplier): Response
    {
        if (!$this->isCsrfTokenValid('reapply', (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $updated = \count($ruleReapplier->reapply());
        $this->addFlash('success', sprintf('%d suggestion(s) posée(s) ou mise(s) à jour.', $updated));

        return $this->redirectToRoute('app_review');
    }
}
