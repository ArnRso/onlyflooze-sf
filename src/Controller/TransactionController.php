<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Security\Voter\TransactionVoter;
use App\Service\TransactionService;
use Knp\Component\Pager\PaginatorInterface;
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
        private readonly TransactionService $transactionService,
        private readonly PaginatorInterface $paginator
    )
    {
    }

    #[Route('/', name: 'app_transaction_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();

        // Get limit from query parameter (default: 20, allowed: 10, 20, 50, 100)
        $limit = $request->query->getInt('limit', 20);
        $allowedLimits = [10, 20, 50, 100];
        if (!in_array($limit, $allowedLimits)) {
            $limit = 20;
        }

        // Get paginated transactions
        $query = $this->transactionService->getUserTransactionsQuery($user);
        $transactions = $this->paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            $limit
        );

        // Preserve the limit parameter in pagination links
        $transactions->setParam('limit', $limit);

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

    #[Route('/{id}', name: 'app_transaction_show', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::VIEW, $transaction);

        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
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

    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
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
