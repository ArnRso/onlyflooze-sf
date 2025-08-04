<?php

namespace App\Controller;

use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Entity\User;
use App\Form\TransactionType;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TagRepository;
use App\Security\Voter\TransactionVoter;
use App\Service\RecurringTransactionService;
use App\Service\TagService;
use App\Service\TransactionService;
use DateMalformedStringException;
use Exception;
use Knp\Component\Pager\PaginatorInterface;
use Ramsey\Uuid\Uuid;
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
        private readonly TransactionService             $transactionService,
        private readonly RecurringTransactionService    $recurringTransactionService,
        private readonly PaginatorInterface             $paginator,
        private readonly RecurringTransactionRepository $recurringTransactionRepository,
        private readonly TagRepository                  $tagRepository,
        private readonly TagService                     $tagService
    )
    {
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Route('/', name: 'app_transaction_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $recurringTransactions = $this->recurringTransactionService->getUserRecurringTransactionsWithCount($user);
        $tags = $this->tagRepository->findByUserWithTransactionCount($user);

        // Get limit from query parameter (default: 20, allowed: 10, 20, 50, 100, 250, 500)
        $limit = $request->query->getInt('limit', 20);
        $allowedLimits = [10, 20, 50, 100, 250, 500];
        if (!in_array($limit, $allowedLimits)) {
            $limit = 20;
        }

        // Check if search criteria are present
        $searchCriteria = [
            'label' => $request->query->get('label', ''),
            'minAmount' => $request->query->get('minAmount', ''),
            'maxAmount' => $request->query->get('maxAmount', ''),
            'startDate' => $request->query->get('startDate', ''),
            'endDate' => $request->query->get('endDate', ''),
            'budgetMonth' => $request->query->get('budgetMonth', ''),
            'hasRecurringTransaction' => $request->query->get('hasRecurringTransaction', ''),
            'specificRecurringTransaction' => $request->query->get('specificRecurringTransaction', ''),
            'specificTag' => $request->query->get('specificTag', ''),
        ];

        $searchCriteria = array_filter($searchCriteria, static fn($value) => $value !== '' && !empty($value));

        // Use search or regular query
        if (!empty($searchCriteria)) {
            $query = $this->transactionService->searchUserTransactions($user, $searchCriteria);
        } else {
            $query = $this->transactionService->getUserTransactionsQuery($user);
        }

        $transactions = $this->paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            $limit
        );

        // Preserve the limit parameter in pagination links
        if (method_exists($transactions, 'setParam')) {
            $transactions->setParam('limit', $limit);
        }

        $stats = $this->transactionService->getUserTransactionStats($user);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'stats' => $stats,
            'recurringTransactions' => $recurringTransactions,
            'tags' => $tags,
        ]);
    }

    #[Route('/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $transaction = new Transaction();
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $this->transactionService->createTransaction($transaction, $user);

            $this->addFlash('success', 'Transaction créée avec succès.');

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('transaction/new.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function show(Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::VIEW, $transaction);

        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
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

    #[Route('/{id}', name: 'app_transaction_delete', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function delete(Request $request, Transaction $transaction): Response
    {
        $this->denyAccessUnlessGranted(TransactionVoter::DELETE, $transaction);

        if ($this->isCsrfTokenValid('delete' . $transaction->getId(), $request->getPayload()->getString('_token'))) {
            $this->transactionService->deleteTransaction($transaction);
            $this->addFlash('success', 'Transaction supprimée avec succès.');
        }

        return $this->redirectToRoute('app_transaction_index');
    }


    #[Route('/assign-recurring', name: 'app_transaction_assign_recurring', methods: ['POST'])]
    public function assignRecurring(Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('assign_recurring', is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_transaction_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $transactionIds = $request->request->all('transaction_ids');
        $recurringTransactionId = $request->request->get('recurring_transaction_id');
        $newRecurringName = $request->request->get('new_recurring_name');

        if (empty($transactionIds)) {
            $this->addFlash('error', 'Aucune transaction sélectionnée.');
            return $this->redirectToRoute('app_transaction_index');
        }

        try {

            if ($recurringTransactionId && $recurringTransactionId !== 'new') {
                // Valider l'UUID
                if (!is_string($recurringTransactionId) || !Uuid::isValid($recurringTransactionId)) {
                    $this->addFlash('error', 'Identifiant de transaction récurrente invalide.');
                    return $this->redirectToRoute('app_transaction_index');
                }

                $recurringTransaction = $this->recurringTransactionRepository->findByUserAndId($user, Uuid::fromString($recurringTransactionId));
                if (!$recurringTransaction) {
                    $this->addFlash('error', 'Transaction récurrente introuvable.');
                    return $this->redirectToRoute('app_transaction_index');
                }
            } elseif ($newRecurringName) {
                $recurringTransaction = new RecurringTransaction();
                $recurringTransaction->setName(is_string($newRecurringName) ? $newRecurringName : '');
                $this->recurringTransactionService->createRecurringTransaction($recurringTransaction, $user);
            } else {
                $this->addFlash('error', 'Veuillez sélectionner ou créer une transaction récurrente.');
                return $this->redirectToRoute('app_transaction_index');
            }

            // Valider les UUIDs des transactions
            foreach ($transactionIds as $transactionId) {
                if (!Uuid::isValid($transactionId)) {
                    $this->addFlash('error', 'Identifiant de transaction invalide.');
                    return $this->redirectToRoute('app_transaction_index');
                }
            }

            $updatedCount = $this->transactionService->assignTransactionsToRecurring($transactionIds, $recurringTransaction);

            // Ajouter le flash message
            $this->addFlash('success', sprintf(
                '%d transaction(s) attribuée(s) à "%s" avec succès.',
                $updatedCount,
                $recurringTransaction->getName()
            ));

            // Rediriger vers la même recherche si des critères étaient présents
            $searchParams = [];
            foreach ($request->request->all() as $key => $value) {
                if (!empty($value) && str_starts_with($key, 'search_')) {
                    $searchParams[substr($key, 7)] = $value; // Remove 'search_' prefix
                }
            }

            if (!empty($searchParams)) {
                return $this->redirectToRoute('app_transaction_index', $searchParams);
            }

        } catch (Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue lors de l\'attribution : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_transaction_index');
    }

    #[Route('/assign-tags', name: 'app_transaction_assign_tags', methods: ['POST'])]
    public function assignTags(Request $request): Response
    {
        // Vérifier le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('assign_tags', is_string($token) ? $token : null)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_transaction_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $transactionIds = $request->request->all('transaction_ids');
        $existingTagIds = $request->request->all('existing_tags');
        $newTags = $request->request->all('new_tags');

        if (empty($transactionIds)) {
            $this->addFlash('error', 'Aucune transaction sélectionnée.');
            return $this->redirectToRoute('app_transaction_index');
        }

        if (empty($existingTagIds) && empty(array_filter($newTags, static fn($tag) => !empty($tag['name'])))) {
            $this->addFlash('error', 'Aucun tag sélectionné ou créé.');
            return $this->redirectToRoute('app_transaction_index');
        }

        try {
            // Valider les UUIDs des transactions
            foreach ($transactionIds as $transactionId) {
                if (!Uuid::isValid($transactionId)) {
                    $this->addFlash('error', 'Identifiant de transaction invalide.');
                    return $this->redirectToRoute('app_transaction_index');
                }
            }

            $tags = [];

            // Récupérer les tags existants
            foreach ($existingTagIds as $tagId) {
                if (!Uuid::isValid($tagId)) {
                    $this->addFlash('error', 'Identifiant de tag invalide.');
                    return $this->redirectToRoute('app_transaction_index');
                }

                $tag = $this->tagRepository->findOneBy(['id' => Uuid::fromString($tagId), 'user' => $user]);
                if ($tag) {
                    $tags[] = $tag;
                }
            }

            // Créer les nouveaux tags
            foreach ($newTags as $newTagData) {
                if (!empty($newTagData['name'])) {
                    $tag = new Tag();
                    $tag->setName(trim($newTagData['name']));
                    $this->tagService->createTag($tag, $user);
                    $tags[] = $tag;
                }
            }

            $updatedCount = $this->transactionService->assignTagsToTransactions($transactionIds, $tags);

            // Ajouter le flash message
            $tagNames = array_map(static fn($tag) => $tag->getName(), $tags);
            $this->addFlash('success', sprintf(
                '%d transaction(s) mise(s) à jour avec les tags : %s',
                $updatedCount,
                implode(', ', $tagNames)
            ));

            // Rediriger vers la même recherche si des critères étaient présents
            $searchParams = [];
            foreach ($request->request->all() as $key => $value) {
                if (!empty($value) && str_starts_with($key, 'search_')) {
                    $searchParams[substr($key, 7)] = $value; // Remove 'search_' prefix
                }
            }

            if (!empty($searchParams)) {
                return $this->redirectToRoute('app_transaction_index', $searchParams);
            }

        } catch (Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue lors de l\'attribution des tags : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_transaction_index');
    }

}
