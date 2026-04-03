<?php

namespace App\Controller;

use App\Entity\Formation;
use App\Form\FormationType;
use App\Repository\FormationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/formation')]
final class FormationController extends AbstractController
{
    #[Route('/rh', name: 'app_formation_rh', methods: ['GET'])]
    public function index(FormationRepository $formationRepository, Connection $connection): Response
    {
        $pendingInscriptions = $connection->fetchAllAssociative(
            'SELECT i.id_inscription, i.id_employe, i.raison, i.statut, f.id_formation AS formation_id, f.titre AS formation_titre, COALESCE(e.prenom, "") AS prenom, COALESCE(e.nom, "") AS nom
             FROM inscription_formation i
             INNER JOIN formation f ON f.id_formation = i.id_formation
             LEFT JOIN `employé` e ON e.id_employe = i.id_employe
             WHERE i.statut = "EN_ATTENTE"
             ORDER BY i.id_inscription DESC'
        );

        $pendingByFormation = [];
        $pendingInscriptionByFormation = [];
        foreach ($pendingInscriptions as $item) {
            $formationId = (int) ($item['formation_id'] ?? 0);
            if ($formationId > 0) {
                $pendingByFormation[$formationId] = ($pendingByFormation[$formationId] ?? 0) + 1;
                $pendingInscriptionByFormation[$formationId][] = $item;
            }
        }

        return $this->render('formation/index.html.twig', [
            'formations' => $formationRepository->findBy([], ['dateDebut' => 'DESC']),
            'pending_inscriptions' => $pendingInscriptions,
            'pending_by_formation' => $pendingByFormation,
            'pending_inscriptions_by_formation' => $pendingInscriptionByFormation,
        ]);
    }

    #[Route('/new', name: 'app_formation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $formation = new Formation();
        $form = $this->createForm(FormationType::class, $formation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($formation);
            $entityManager->flush();

            $this->addFlash('success', 'Formation ajoutee avec succes.');

            return $this->redirectToRoute('app_formation_rh');
        }

        return $this->render('formation/new.html.twig', [
            'formation' => $formation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_formation_show', methods: ['GET'])]
    public function show(Formation $formation): Response
    {
        return $this->render('formation/show.html.twig', [
            'formation' => $formation,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_formation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Formation $formation, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(FormationType::class, $formation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Formation modifiee avec succes.');

            return $this->redirectToRoute('app_formation_rh');
        }

        return $this->render('formation/edit.html.twig', [
            'formation' => $formation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_formation_delete', methods: ['POST'])]
    public function delete(Request $request, Formation $formation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$formation->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($formation);
            $entityManager->flush();
            $this->addFlash('success', 'Formation supprimee avec succes.');
        }

        return $this->redirectToRoute('app_formation_rh');
    }
}
