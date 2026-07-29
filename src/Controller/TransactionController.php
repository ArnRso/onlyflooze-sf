<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionEditType;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\Review\TransactionCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TransactionController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('/transactions', name: 'app_transactions')]
    public function index(
        Request $request,
        TransactionRepository $transactionRepository,
        CategoryRepository $categoryRepository,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $search = $request->query->getString('q');
        $categoryId = $request->query->getString('category');
        $category = $categoryId !== '' ? $categoryRepository->find($categoryId) : null;
        $onlyToReview = $request->query->getBoolean('to_review');

        $result = $transactionRepository->findForList($page, self::PER_PAGE, $search, $category, $onlyToReview);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pageCount' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'search' => $search,
            'selectedCategory' => $category,
            'onlyToReview' => $onlyToReview,
            'categories' => $categoryRepository->findAllOrdered(),
        ]);
    }

    #[Route('/transactions/{id}/edit', name: 'app_transaction_edit')]
    public function edit(
        Transaction $transaction,
        Request $request,
        TransactionCategorizer $categorizer,
        EntityManagerInterface $entityManager,
    ): Response {
        $previousCategory = $transaction->getCategory();

        $form = $this->createForm(TransactionEditType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newCategory = $transaction->getCategory();
            if ($newCategory !== null && $newCategory !== $previousCategory) {
                // La catégorisation passe par le service pour alimenter
                // l'apprentissage des règles.
                $categorizer->categorize($transaction, $newCategory, $transaction->getNature());
            } elseif ($newCategory === null && $previousCategory !== null) {
                $categorizer->resetToReview($transaction);
            } else {
                $entityManager->flush();
            }

            $this->addFlash('success', 'Transaction mise à jour.');

            return $this->redirectToRoute('app_transactions');
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }
}
