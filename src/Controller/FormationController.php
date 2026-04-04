<?php

namespace App\Controller;

use App\Entity\Formation;
use App\Form\FormationType;
use App\Repository\EvaluationFormationRepository;
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
    #[Route('/rh', name: 'app_formation_rh', methods: ['GET', 'POST'])]
    public function index(Request $request, FormationRepository $formationRepository, Connection $connection, EvaluationFormationRepository $evaluationFormationRepository): Response
    {
        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            if ($action === 'login') {
                $rhId = (int) $request->request->get('rh_id', 0);

                if ($rhId > 0) {
                    $rh = $connection->fetchAssociative(
                        'SELECT id_employe, prenom, nom, role FROM `employé` WHERE id_employe = ? LIMIT 1',
                        [$rhId]
                    );

                    if ($rh !== false) {
                        $rhRole = strtolower(trim((string) $rh['role']));
                        if (!str_contains($rhRole, 'rh')) {
                            $this->addFlash('error', 'Cette page est reservee aux RH.');

                            return $this->redirectToRoute('app_formation_rh');
                        }

                        $rhName = trim((string) $rh['prenom'] . ' ' . (string) $rh['nom']);
                        $session->set('rh_id', (int) $rh['id_employe']);
                        $session->set('rh_name', $rhName);
                        $session->set('rh_role', $rhRole);

                        $this->addFlash('success', sprintf('Bienvenue %s.', $rhName));

                        return $this->redirectToRoute('app_formation_rh');
                    }
                }

                $this->addFlash('error', 'ID RH invalide.');

                return $this->redirectToRoute('app_formation_rh');
            }

            if ($action === 'logout') {
                $session->remove('rh_id');
                $session->remove('rh_name');
                $session->remove('rh_role');

                return $this->redirectToRoute('app_formation_rh');
            }
        }

        $rhId = (int) $session->get('rh_id', 0);
        $rhRole = strtolower(trim((string) $session->get('rh_role', '')));
        $rhLogged = $rhId > 0 && str_contains($rhRole, 'rh');

        $rhUsers = $connection->fetchAllAssociative(
            'SELECT id_employe, prenom, nom, role FROM `employé` WHERE LOWER(role) LIKE ? ORDER BY prenom, nom',
            ['%rh%']
        );

        if (!$rhLogged) {
            return $this->render('formation/index.html.twig', [
                'formations' => [],
                'pending_inscriptions' => [],
                'pending_by_formation' => [],
                'pending_inscriptions_by_formation' => [],
                'rh_logged' => false,
                'rh_users' => $rhUsers,
                'best_reviewed_formation' => null,
            ]);
        }

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
            'rh_logged' => true,
            'rh_users' => $rhUsers,
            'best_reviewed_formation' => $evaluationFormationRepository->findBestReviewedFormation(),
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
        $rhId = (int) $session->get('rh_id', 0);
        $rhRole = strtolower(trim((string) $session->get('rh_role', '')));

        if ($rhId <= 0 || !str_contains($rhRole, 'rh')) {
            $this->addFlash('error', 'Veuillez choisir votre ID RH avant de continuer.');

            return $this->redirectToRoute('app_formation_rh');
        }

        return null;
    }
}
