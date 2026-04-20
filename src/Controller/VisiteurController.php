<?php

namespace App\Controller;

use App\Entity\Visiteur;
use App\Form\VisiteurType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VisiteurController extends AbstractController
{
    #[Route('/visiteur/compte_visiteur', name: 'compte_visiteur', methods: ['GET', 'POST'])]
    public function ajouter(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
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