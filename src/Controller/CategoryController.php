<?php

namespace App\Controller;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use App\Service\Catalog\CategoryManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CategoryController extends AbstractController
{
    #[Route('/categories', name: 'app_categories')]
    public function index(CategoryRepository $categoryRepository): Response
    {
        return $this->render('category/index.html.twig', [
            'rootCategories' => $categoryRepository->findRootCategories(),
        ]);
    }

    #[Route('/categories/new', name: 'app_category_new')]
    public function new(Request $request, CategoryManager $categoryManager): Response
    {
        $category = new Category('');
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryManager->save($category);
            $this->addFlash('success', sprintf('Catégorie « %s » créée.', $category->getName()));

            return $this->redirectToRoute('app_categories');
        }

        return $this->render('category/new.html.twig', ['form' => $form]);
    }

    #[Route('/categories/{id}/edit', name: 'app_category_edit')]
    public function edit(Category $category, Request $request, CategoryManager $categoryManager): Response
    {
        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categoryManager->save($category);
            $this->addFlash('success', 'Catégorie renommée sans casse : les règles suivent (elles pointent des id).');

            return $this->redirectToRoute('app_categories');
        }

        return $this->render('category/edit.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }

    #[Route('/categories/{id}/delete', name: 'app_category_delete', methods: ['POST'])]
    public function delete(Category $category, Request $request, CategoryManager $categoryManager): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$category->getId(), (string) $request->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $categoryManager->delete($category);
        $this->addFlash('success', 'Catégorie supprimée. Ses transactions repassent « À trier ».');

        return $this->redirectToRoute('app_categories');
    }
}
