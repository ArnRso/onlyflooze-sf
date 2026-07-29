<?php

namespace App\Controller;

use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Form\RecurrenceType;
use App\Repository\RecurrenceRepository;
use App\Repository\TransactionRepository;
use App\Service\Recurrence\RecurrenceBackfill;
use App\Service\Recurrence\RecurrenceDetector;
use App\Service\Recurrence\RecurrenceManager;
use App\Service\Recurrence\RecurrenceStatusProvider;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RecurrenceController extends AbstractController
{
    #[Route('/recurrences', name: 'app_recurrences')]
    public function index(
        RecurrenceRepository $recurrenceRepository,
        RecurrenceDetector $detector,
        RecurrenceStatusProvider $statusProvider,
    ): Response {
        $month = new \DateTimeImmutable('first day of this month');

        $statuses = [];
        foreach ($recurrenceRepository->findAllOrdered() as $recurrence) {
            $statuses[] = $statusProvider->statusFor($recurrence, $month);
        }

        return $this->render('recurrence/index.html.twig', [
            'statuses' => $statuses,
            'suggestions' => $detector->suggest(),
            'month' => $month,
        ]);
    }

    #[Route('/recurrences/{id}', name: 'app_recurrence_show', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(
        Recurrence $recurrence,
        TransactionRepository $transactionRepository,
        RecurrenceStatusProvider $statusProvider,
        RecurrenceBackfill $backfill,
    ): Response {
        return $this->render('recurrence/show.html.twig', [
            'recurrence' => $recurrence,
            'status' => $statusProvider->statusFor($recurrence, new \DateTimeImmutable('first day of this month')),
            'transactions' => $transactionRepository->findBy(
                ['recurrence' => $recurrence],
                ['operationDate' => 'DESC'],
            ),
            'candidates' => $backfill->findCandidates($recurrence),
        ]);
    }

    #[Route('/recurrences/{id}/attach/{transactionId}', name: 'app_recurrence_attach', methods: ['POST'])]
    public function attach(
        Recurrence $recurrence,
        #[MapEntity(id: 'transactionId')] Transaction $transaction,
        Request $request,
        RecurrenceBackfill $backfill,
    ): Response {
        if (!$this->isCsrfTokenValid('attach'.$transaction->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $backfill->attach($recurrence, $transaction);

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/{id}/exclude/{transactionId}', name: 'app_recurrence_exclude', methods: ['POST'])]
    public function exclude(
        Recurrence $recurrence,
        #[MapEntity(id: 'transactionId')] Transaction $transaction,
        Request $request,
        RecurrenceBackfill $backfill,
    ): Response {
        if (!$this->isCsrfTokenValid('exclude'.$transaction->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $backfill->exclude($recurrence, $transaction);

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/{id}/end', name: 'app_recurrence_end', methods: ['POST'])]
    public function end(
        Recurrence $recurrence,
        Request $request,
        TransactionRepository $transactionRepository,
        RecurrenceManager $recurrenceManager,
    ): Response {
        if (!$this->isCsrfTokenValid('end'.$recurrence->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $lastOccurrence = $transactionRepository->findLatestByRecurrence($recurrence, 1)[0] ?? null;
        $recurrence->setEndedAt($lastOccurrence?->getOperationDate() ?? new \DateTimeImmutable('today'));
        $recurrenceManager->save($recurrence);

        $this->addFlash('success', sprintf('« %s » est marquée terminée au %s : elle n\'est plus attendue les mois suivants.', $recurrence->getName(), $recurrence->getEndedAt()?->format('d/m/Y')));

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/{id}/resume', name: 'app_recurrence_resume', methods: ['POST'])]
    public function resume(Recurrence $recurrence, Request $request, RecurrenceManager $recurrenceManager): Response
    {
        if (!$this->isCsrfTokenValid('resume'.$recurrence->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $recurrence->setEndedAt(null);
        $recurrenceManager->save($recurrence);

        $this->addFlash('success', sprintf('« %s » est de nouveau suivie.', $recurrence->getName()));

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/{id}/detach/{transactionId}', name: 'app_recurrence_detach', methods: ['POST'])]
    public function detach(
        Recurrence $recurrence,
        #[MapEntity(id: 'transactionId')] Transaction $transaction,
        Request $request,
        RecurrenceBackfill $backfill,
    ): Response {
        if (!$this->isCsrfTokenValid('detach'.$transaction->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $backfill->detach($recurrence, $transaction);
        $this->addFlash('success', 'Transaction détachée : elle ne sera plus proposée pour cette récurrence.');

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/new', name: 'app_recurrence_new')]
    public function new(Request $request, RecurrenceManager $recurrenceManager): Response
    {
        $recurrence = new Recurrence('', Direction::Debit, 1, 0);
        $form = $this->createForm(RecurrenceType::class, $recurrence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recurrenceManager->save($recurrence);
            $this->addFlash('success', sprintf('Récurrence « %s » créée.', $recurrence->getName()));

            return $this->redirectToRoute('app_recurrences');
        }

        return $this->render('recurrence/new.html.twig', ['form' => $form]);
    }

    #[Route('/recurrences/{id}/edit', name: 'app_recurrence_edit')]
    public function edit(Recurrence $recurrence, Request $request, RecurrenceManager $recurrenceManager): Response
    {
        $form = $this->createForm(RecurrenceType::class, $recurrence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recurrenceManager->save($recurrence);
            $this->addFlash('success', 'Récurrence mise à jour.');

            return $this->redirectToRoute('app_recurrences');
        }

        return $this->render('recurrence/edit.html.twig', [
            'recurrence' => $recurrence,
            'form' => $form,
        ]);
    }

    #[Route('/recurrences/{id}/delete', name: 'app_recurrence_delete', methods: ['POST'])]
    public function delete(Recurrence $recurrence, Request $request, RecurrenceManager $recurrenceManager): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$recurrence->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $recurrenceManager->delete($recurrence);
        $this->addFlash('success', 'Récurrence supprimée.');

        return $this->redirectToRoute('app_recurrences');
    }

    #[Route('/recurrences/promote/{ruleId}', name: 'app_recurrence_promote', methods: ['POST'])]
    public function promote(string $ruleId, Request $request, RecurrenceDetector $detector): Response
    {
        if (!$this->isCsrfTokenValid('promote'.$ruleId, (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $suggestion = $detector->findSuggestionForRule($ruleId);
        if ($suggestion === null) {
            $this->addFlash('warning', 'Cette proposition n\'est plus valable.');

            return $this->redirectToRoute('app_recurrences');
        }

        $recurrence = $detector->promote($suggestion);
        $this->addFlash('success', sprintf('« %s » est maintenant suivie. Vérifie ci-dessous ce que le système propose de rattacher dans l\'historique.', $recurrence->getName()));

        return $this->redirectToRoute('app_recurrence_show', ['id' => $recurrence->getId()]);
    }

    #[Route('/recurrences/dismiss/{ruleId}', name: 'app_recurrence_dismiss', methods: ['POST'])]
    public function dismiss(string $ruleId, Request $request, RecurrenceDetector $detector): Response
    {
        if (!$this->isCsrfTokenValid('dismiss'.$ruleId, (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $suggestion = $detector->findSuggestionForRule($ruleId);
        if ($suggestion !== null) {
            $detector->dismiss($suggestion);
            $this->addFlash('success', 'Proposition écartée, elle ne sera plus refaite.');
        }

        return $this->redirectToRoute('app_recurrences');
    }
}
