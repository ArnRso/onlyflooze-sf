<?php

namespace App\Controller;

use App\Entity\CategorizationRule;
use App\Form\RuleEditType;
use App\Repository\CategorizationRuleRepository;
use App\Service\Catalog\RuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RuleController extends AbstractController
{
    #[Route('/rules', name: 'app_rules')]
    public function index(CategorizationRuleRepository $ruleRepository): Response
    {
        return $this->render('rule/index.html.twig', [
            'rules' => $ruleRepository->findAllOrdered(),
        ]);
    }

    #[Route('/rules/{id}/edit', name: 'app_rule_edit')]
    public function edit(CategorizationRule $rule, Request $request, RuleManager $ruleManager): Response
    {
        $form = $this->createForm(RuleEditType::class, $rule);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $ruleManager->save($rule);
            $this->addFlash('success', 'Règle mise à jour.');

            return $this->redirectToRoute('app_rules');
        }

        return $this->render('rule/edit.html.twig', [
            'rule' => $rule,
            'form' => $form,
        ]);
    }

    #[Route('/rules/{id}/delete', name: 'app_rule_delete', methods: ['POST'])]
    public function delete(CategorizationRule $rule, Request $request, RuleManager $ruleManager): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$rule->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $ruleManager->delete($rule);
        $this->addFlash('success', 'Règle supprimée.');

        return $this->redirectToRoute('app_rules');
    }
}
