<?php

namespace App\Controller;

use App\Entity\Visiteur;
use App\Form\VisiteurType;
use App\Repository\VisiteurRepository;
use App\Service\OAuthGoogleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VisiteurController extends AbstractController
{
    #[Route('/visiteur/compte_visiteur', name: 'compte_visiteur', methods: ['GET', 'POST'])]
    public function ajouter(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, SessionInterface $session, VisiteurRepository $visiteurRepository, OAuthGoogleService $oauthGoogleService, FormFactoryInterface $formFactory): Response
    {
        $oauthVisiteurId = (int) $session->get('oauth_google_complete_phone_visiteur_id', 0);
        if ($oauthVisiteurId > 0) {
            $oauthVisiteur = $visiteurRepository->find($oauthVisiteurId);
            if ($oauthVisiteur === null) {
                $session->remove('oauth_google_complete_phone_visiteur_id');
                $this->addFlash('error', 'Compte Google introuvable. Veuillez recommencer.');

                return $this->redirectToRoute('login');
            }

            $googlePhoneForm = $formFactory->createNamed('google_phone', VisiteurType::class, $oauthVisiteur);
            $googlePhoneForm->remove('nom');
            $googlePhoneForm->remove('prenom');
            $googlePhoneForm->remove('e_mail');
            $googlePhoneForm->remove('mot_de_passe');
            $googlePhoneForm->handleRequest($request);

            if ($googlePhoneForm->isSubmitted() && $googlePhoneForm->isValid()) {
                $telephone = (int) $oauthVisiteur->getTelephone();
                $oauthGoogleService->updateVisiteurPhone($oauthVisiteur, $telephone, $entityManager);

                $session->remove('oauth_google_complete_phone_visiteur_id');
                $oauthGoogleService->loginVisiteur($session, $oauthVisiteur);

                return $this->redirectToRoute('app_offre_home');
            }

            $visiteur = new Visiteur();
            $form = $this->createForm(VisiteurType::class, $visiteur);

            return $this->render('visiteur/compteVisiteur.html.twig', [
                'form' => $form->createView(),
                'google_phone_form' => $googlePhoneForm->createView(),
                'google_email' => $oauthVisiteur->getEmail(),
            ]);
        }

        $visiteur = new Visiteur();
        $form = $this->createForm(VisiteurType::class, $visiteur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('mot_de_passe')->getData();
            $visiteur->setMotdepasse($passwordHasher->hashPassword($visiteur, $plainPassword));

            $entityManager->persist($visiteur);
            $entityManager->flush();

            $this->addFlash('success', 'Visiteur créé avec succès.');

            return $this->redirectToRoute('compte_visiteur');
        }

        return $this->render('visiteur/compteVisiteur.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
