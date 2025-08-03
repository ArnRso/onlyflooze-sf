<?php

namespace App\Controller;

use App\Entity\RecurringTransaction;
use App\Form\RecurringTransactionType;
use App\Security\Voter\RecurringTransactionVoter;
use App\Service\RecurringTransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/recurring-transactions')]
#[IsGranted('ROLE_USER')]
class RecurringTransactionController extends AbstractController
{
    public function __construct(
        private readonly RecurringTransactionService $recurringTransactionService
    )
    {
    }

    #[Route('/', name: 'app_recurring_transaction_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $recurringTransactions = $this->recurringTransactionService->getUserRecurringTransactions($user);

        return $this->render('recurring_transaction/index.html.twig', [
            'recurring_transactions' => $recurringTransactions,
        ]);
    }

    #[Route('/new', name: 'app_recurring_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $recurringTransaction = new RecurringTransaction();
        $form = $this->createForm(RecurringTransactionType::class, $recurringTransaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->recurringTransactionService->createRecurringTransaction($recurringTransaction, $this->getUser());

            $this->addFlash('success', 'Transaction récurrente créée avec succès.');

            return $this->redirectToRoute('app_recurring_transaction_index');
        }

        return $this->render('recurring_transaction/new.html.twig', [
            'recurring_transaction' => $recurringTransaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_recurring_transaction_show', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(RecurringTransaction $recurringTransaction): Response
    {
        $this->denyAccessUnlessGranted(RecurringTransactionVoter::VIEW, $recurringTransaction);

        $stats = $this->recurringTransactionService->getRecurringTransactionStats($recurringTransaction);

        return $this->render('recurring_transaction/show.html.twig', [
            'recurring_transaction' => $recurringTransaction,
            'stats' => $stats,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_recurring_transaction_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function edit(Request $request, RecurringTransaction $recurringTransaction): Response
    {
        $this->denyAccessUnlessGranted(RecurringTransactionVoter::EDIT, $recurringTransaction);

        $form = $this->createForm(RecurringTransactionType::class, $recurringTransaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->recurringTransactionService->updateRecurringTransaction($recurringTransaction);

            $this->addFlash('success', 'Transaction récurrente modifiée avec succès.');

            return $this->redirectToRoute('app_recurring_transaction_index');
        }

        return $this->render('recurring_transaction/edit.html.twig', [
            'recurring_transaction' => $recurringTransaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_recurring_transaction_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function delete(Request $request, RecurringTransaction $recurringTransaction): Response
    {
        $this->denyAccessUnlessGranted(RecurringTransactionVoter::DELETE, $recurringTransaction);

        if ($this->isCsrfTokenValid('delete' . $recurringTransaction->getId(), $request->getPayload()->getString('_token'))) {
            $this->recurringTransactionService->deleteRecurringTransaction($recurringTransaction);
            $this->addFlash('success', 'Transaction récurrente supprimée avec succès.');
        }

        return $this->redirectToRoute('app_recurring_transaction_index');
    }
}
