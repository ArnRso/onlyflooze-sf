<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Form\TagType;
use App\Repository\TagRepository;
use App\Service\Catalog\TagManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TagController extends AbstractController
{
    #[Route('/tags', name: 'app_tags')]
    public function index(TagRepository $tagRepository): Response
    {
        return $this->render('tag/index.html.twig', [
            'tags' => $tagRepository->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/tags/new', name: 'app_tag_new')]
    public function new(Request $request, TagManager $tagManager): Response
    {
        $tag = new Tag('');
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tagManager->save($tag);
            $this->addFlash('success', sprintf('Tag « %s » créé.', $tag->getName()));

            return $this->redirectToRoute('app_tags');
        }

        return $this->render('tag/new.html.twig', ['form' => $form]);
    }

    #[Route('/tags/{id}/edit', name: 'app_tag_edit')]
    public function edit(Tag $tag, Request $request, TagManager $tagManager): Response
    {
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tagManager->save($tag);
            $this->addFlash('success', 'Tag renommé.');

            return $this->redirectToRoute('app_tags');
        }

        return $this->render('tag/edit.html.twig', [
            'tag' => $tag,
            'form' => $form,
        ]);
    }

    #[Route('/tags/{id}/delete', name: 'app_tag_delete', methods: ['POST'])]
    public function delete(Tag $tag, Request $request, TagManager $tagManager): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$tag->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $tagManager->delete($tag);
        $this->addFlash('success', 'Tag supprimé.');

        return $this->redirectToRoute('app_tags');
    }
}
