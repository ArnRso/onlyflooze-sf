<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Form\TagType;
use App\Security\Voter\TagVoter;
use App\Service\TagService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tags')]
#[IsGranted('ROLE_USER')]
class TagController extends AbstractController
{
    public function __construct(
        private readonly TagService $tagService
    )
    {
    }

    #[Route('/', name: 'app_tag_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $tags = $this->tagService->getUserTagsWithTransactionCount($user);
        $stats = $this->tagService->getUserTagStats($user);

        return $this->render('tag/index.html.twig', [
            'tags' => $tags,
            'stats' => $stats,
        ]);
    }

    #[Route('/new', name: 'app_tag_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tag = $this->tagService->initializeNewTag($this->getUser());
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->tagService->createTag($tag, $this->getUser());

            $this->addFlash('success', 'Tag créé avec succès.');

            return $this->redirectToRoute('app_tag_index');
        }

        return $this->render('tag/new.html.twig', [
            'tag' => $tag,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_tag_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function show(Tag $tag): Response
    {
        $this->denyAccessUnlessGranted(TagVoter::VIEW, $tag);

        $stats = $this->tagService->getTagStats($tag);
        $monthlyTotals = $this->tagService->getMonthlyTotalsForTag($tag);

        return $this->render('tag/show.html.twig', [
            'tag' => $tag,
            'stats' => $stats,
            'monthly_totals' => $monthlyTotals,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tag_edit', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Tag $tag): Response
    {
        $this->denyAccessUnlessGranted(TagVoter::EDIT, $tag);

        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->tagService->updateTag($tag);

            $this->addFlash('success', 'Tag modifié avec succès.');

            return $this->redirectToRoute('app_tag_index');
        }

        return $this->render('tag/edit.html.twig', [
            'tag' => $tag,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_tag_delete', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function delete(Request $request, Tag $tag): Response
    {
        $this->denyAccessUnlessGranted(TagVoter::DELETE, $tag);

        if ($this->isCsrfTokenValid('delete' . $tag->getId(), $request->getPayload()->getString('_token'))) {
            $this->tagService->deleteTag($tag);
            $this->addFlash('success', 'Tag supprimé avec succès.');
        }

        return $this->redirectToRoute('app_tag_index');
    }
}
