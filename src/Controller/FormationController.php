<?php

namespace App\Controller;

use App\Entity\Formation;
use App\Form\FormationType;
use App\Repository\EvaluationFormationRepository;
use App\Repository\FormationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/formation')]
final class FormationController extends AbstractController
{
    #[Route('/rh/{id}/details', name: 'app_formation_rh_details', methods: ['GET'])]
    public function rhDetails(int $id, FormationRepository $formationRepository, Connection $connection): JsonResponse
    {
        $formation = $formationRepository->find($id);
        if ($formation === null) {
            return new JsonResponse(['error' => 'Formation not found'], 404);
        }

        $pending = $connection->fetchOne(
            'SELECT COUNT(i.id_inscription) FROM inscription_formation i WHERE i.id_formation = ? AND i.statut = ?',
            [$id, 'EN_ATTENTE']
        ) ?? 0;

        return new JsonResponse([
            'id' => $formation->getId(),
            'titre' => $formation->getTitre(),
            'organisme' => $formation->getOrganisme(),
            'dateDebut' => $formation->getDateDebut()?->format('Y-m-d'),
            'dateFin' => $formation->getDateFin()?->format('Y-m-d'),
            'lieu' => $formation->getLieu(),
            'capacite' => $formation->getCapacite(),
            'pending' => (int) $pending,
            'showUrl' => $this->generateUrl('app_formation_show', ['id' => $formation->getId()]),
            'editUrl' => $this->generateUrl('app_formation_edit', ['id' => $formation->getId()]),
          ]);
    }

    #[Route('/rh', name: 'app_formation_rh', methods: ['GET'])]
    public function index(Request $request, FormationRepository $formationRepository, Connection $connection, EvaluationFormationRepository $evaluationFormationRepository): Response
    {
        $session = $request->getSession();
        $role = strtolower(trim((string) $session->get('employe_role', '')));
        $isLogged = $session->get('employe_logged_in') === true;
        $rhLogged = $isLogged && (
            str_contains($role, 'rh')
            || str_contains($role, 'administrateur entreprise')
        );

        $q = trim($request->query->getString('q', ''));
        $sort = (string) $request->query->get('sort', 'date_desc');
        $dateScope = (string) $request->query->get('date_scope', 'all');
        $minCapacite = max(0, (int) $request->query->get('min_capacite', 0));
        $selectedFormationId = max(0, (int) $request->query->get('formation', 0));

        $allowedSort = ['date_desc', 'date_asc', 'title_asc', 'organisme_asc', 'capacity_desc'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'date_desc';
        }

        $allowedDateScopes = ['all', 'upcoming', 'ongoing', 'finished'];
        if (!in_array($dateScope, $allowedDateScopes, true)) {
            $dateScope = 'all';
        }

        if (!$rhLogged) {
            if (!$isLogged) {
                return $this->redirectToRoute('login');
            }

            $this->addFlash('error', 'Cette page est reservee aux RH.');

            return $this->redirectToRoute('employe_Home');
        }

        $selectedFormation = $selectedFormationId > 0 ? $formationRepository->find($selectedFormationId) : null;

        $pendingInscriptions = $connection->fetchAllAssociative(
            'SELECT i.id_inscription, i.id_employe, i.raison, i.statut, f.id_formation AS formation_id, f.titre AS formation_titre, COALESCE(e.prenom, "") AS prenom, COALESCE(e.nom, "") AS nom
             FROM inscription_formation i
             INNER JOIN formation f ON f.id_formation = i.id_formation
               LEFT JOIN employe e ON e.id_employe = i.id_employe
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
            'formations' => $formationRepository->findForRhDashboard($q, $sort, $minCapacite, $dateScope),
            'pending_inscriptions' => $pendingInscriptions,
            'pending_by_formation' => $pendingByFormation,
            'pending_inscriptions_by_formation' => $pendingInscriptionByFormation,
            'rh_logged' => true,
            'best_reviewed_formation' => $evaluationFormationRepository->findBestReviewedFormation(),
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
            'q' => $q,
            'sort' => $sort,
            'date_scope' => $dateScope,
            'min_capacite' => $minCapacite,
            'selected_formation' => $selectedFormation,
        ]);
    }

    #[Route('/new', name: 'app_formation_new', methods: ['GET', 'POST'])]
        public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

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
            'email' => $request->getSession()->get('employe_email') ?? '',
            'role' => $request->getSession()->get('employe_role') ?? '',
        ]);
    }

    #[Route('/{id}', name: 'app_formation_show', methods: ['GET'])]
    public function show(Request $request, Formation $formation): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

        return $this->render('formation/show.html.twig', [
            'formation' => $formation,
            'email' => $request->getSession()->get('employe_email') ?? '',
            'role' => $request->getSession()->get('employe_role') ?? '',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_formation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Formation $formation, EntityManagerInterface $entityManager): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

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
            'email' => $request->getSession()->get('employe_email') ?? '',
            'role' => $request->getSession()->get('employe_role') ?? '',
        ]);
    }

    #[Route('/{id}', name: 'app_formation_delete', methods: ['POST'])]
    public function delete(Request $request, Formation $formation, EntityManagerInterface $entityManager): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

        if ($this->isCsrfTokenValid('delete'.$formation->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($formation);
            $entityManager->flush();
            $this->addFlash('success', 'Formation supprimee avec succes.');
        }

        return $this->redirectToRoute('app_formation_rh');
    }

    private function denyUnlessRhLogged(?Request $request): ?Response
    {
        if ($request === null) {
            return $this->redirectToRoute('app_formation_rh');
        }

        $session = $request->getSession();
        $role = strtolower(trim((string) $session->get('employe_role', '')));
        $isLogged = $session->get('employe_logged_in') === true;

        if (!$isLogged || (!str_contains($role, 'rh') && !str_contains($role, 'administrateur entreprise'))) {
            $this->addFlash('error', 'Cette page est reservee aux RH.');

            return $this->redirectToRoute($isLogged ? 'employe_Home' : 'login');
        }

        return null;
    }
}
