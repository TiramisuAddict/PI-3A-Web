<?php

namespace App\Controller;
use App\Entity\Entreprise;
use App\Form\EntrepriseType;
use App\Form\LoginType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntrepriseController extends AbstractController
{
     #[Route('/compteEntreprise', name: 'compteEntreprise',methods: ['GET', 'POST'])]
    public function creationEntreprise(Request $request, EntityManagerInterface $em): Response
    {
        $entreprise = new Entreprise();
        $form = $this->createForm(EntrepriseType::class, $entreprise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entreprise->setDate_demande(new \DateTime());
            $entreprise->setStatut('en attente');
            $em->persist($entreprise);
            $em->flush();

            $this->addFlash('success', 'Entreprise créée avec succès !');
            return $this->redirectToRoute('compteEntreprise');
        }

        return $this->render('entreprise/formulaireEntreprise.html.twig', [
            'form' => $form,
        ]);
    }
}
