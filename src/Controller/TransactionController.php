<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Security\Voter\TransactionVoter;
use App\Service\TransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/transactions')]
#[IsGranted('ROLE_USER')]
class TransactionController extends AbstractController
{
    public function __construct(
        private TransactionService $transactionService
    )
    {
    }

    #[Route('/', name: 'app_transaction_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $transactions = $this->transactionService->getUserTransactions($user);
        $stats = $this->transactionService->getUserTransactionStats($user);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'stats' => $stats,
        ]);
    }

    #[Route('/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $transaction = new Transaction();
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->transactionService->createTransaction($transaction, $this->getUser());

            $this->addFlash('success', 'Transaction créée avec succès.');

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('transaction/new.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_show', methods: ['GET'])]
    public function show(Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::VIEW, $transaction);

        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::EDIT, $transaction);

        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->transactionService->updateTransaction($transaction);

            $this->addFlash('success', 'Transaction modifiée avec succès.');

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(Request $request, Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::DELETE, $transaction);

        if ($this->isCsrfTokenValid('delete' . $transaction->getId(), $request->getPayload()->getString('_token'))) {
            $this->transactionService->deleteTransaction($transaction);
            $this->addFlash('success', 'Transaction supprimée avec succès.');
        }

        return $this->redirectToRoute('app_transaction_index');
    }
}
