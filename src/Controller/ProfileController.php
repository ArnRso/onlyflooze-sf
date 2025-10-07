<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Form\ProfileType;
use App\Service\UserProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserProfileService $userProfileService,
    ) {
    }

    #[Route('', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit')]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->userProfileService->updateProfile(
                $user,
                $form->get('firstName')->getData(),
                $form->get('lastName')->getData()
            );

            $this->addFlash('success', 'Votre profil a été mis à jour avec succès.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/change-password', name: 'app_profile_change_password')]
    public function changePassword(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            $newPassword = $form->get('newPassword')->getData();

            if (!$this->userProfileService->verifyCurrentPassword($user, $currentPassword)) {
                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
            } else {
                $this->userProfileService->changePassword($user, $newPassword);
                $this->addFlash('success', 'Votre mot de passe a été changé avec succès.');

                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/change_password.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }
}
