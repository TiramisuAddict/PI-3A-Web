<?php

namespace App\Controller;

use App\Entity\Projet;
use App\Entity\Tache;
use App\Form\ProjetType;
use App\Form\TacheType;
use App\Repository\EmployeRepository;
use App\Repository\ProjetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projet')]
final class ProjetController extends AbstractController
{
    #[Route(name: 'app_projet_index', methods: ['GET'])]
    public function index(Request $request, ProjetRepository $projetRepository, EmployeRepository $employeRepository): Response
    {
        $filters = [
            'search' => trim((string) $request->query->get('search', '')),
            'statut' => trim((string) $request->query->get('statut', '')),
            'priorite' => trim((string) $request->query->get('priorite', '')),
            'chef_projet' => trim((string) $request->query->get('chef_projet', (string) $request->query->get('responsable', ''))),
        ];

        $chefProjetId = ctype_digit($filters['chef_projet']) ? (int) $filters['chef_projet'] : null;

        $projets = $projetRepository->findByFilters(
            $filters['search'] !== '' ? $filters['search'] : null,
            $filters['statut'] !== '' ? $filters['statut'] : null,
            $filters['priorite'] !== '' ? $filters['priorite'] : null,
            $chefProjetId,
            null,
            null,
        );

        $selectedProjetId = ctype_digit((string) $request->query->get('projet', '')) ? (int) $request->query->get('projet') : null;
        $selectedProjet = null;

        if ($selectedProjetId !== null) {
            foreach ($projets as $projet) {
                if ($projet->getIdProjet() === $selectedProjetId) {
                    $selectedProjet = $projet;
                    break;
                }
            }
        }

        if ($selectedProjet === null && isset($projets[0])) {
            $selectedProjet = $projets[0];
        }

        return $this->render('projet/index.html.twig', [
            'projets' => $projets,
            'selectedProjet' => $selectedProjet,
            'sidebarProjets' => $projets,
            'sidebarSelectedProjetId' => $selectedProjet?->getIdProjet(),
            'filters' => $filters,
            'hasActiveFilters' => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['chef_projet'] !== '',
            'chefProjets' => $this->buildChefProjetsForFilter($employeRepository),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, EmployeRepository $employeRepository, ProjetRepository $projetRepository): Response
    {
        $projet = new Projet();
        $choices = $this->buildEmployeChoices($employeRepository);
        $form = $this->createForm(ProjetType::class, $projet, [
            'chef_projets_choices' => $choices['chefProjets'],
            'membres_choices' => $choices['membres'],
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($projet);
            $entityManager->flush();

            return $this->redirectToRoute('app_projet_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('projet/new.html.twig', [
            'projet' => $projet,
            'form' => $form,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => null,
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/{id_projet}', name: 'app_projet_show', methods: ['GET'])]
    public function show(Projet $projet, ProjetRepository $projetRepository): Response
    {
        return $this->render('projet/show.html.twig', [
            'projet' => $projet,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/{id_projet}/edit', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Projet $projet, EntityManagerInterface $entityManager, EmployeRepository $employeRepository, ProjetRepository $projetRepository): Response
    {
        $choices = $this->buildEmployeChoices($employeRepository);
        $form = $this->createForm(ProjetType::class, $projet, [
            'chef_projets_choices' => $choices['chefProjets'],
            'membres_choices' => $choices['membres'],
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_projet_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('projet/edit.html.twig', [
            'projet' => $projet,
            'form' => $form,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/{id_projet}', name: 'app_projet_delete', methods: ['POST'])]
    public function delete(Request $request, Projet $projet, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$projet->getIdProjet(), (string) $request->request->get('_token'))) {
            $entityManager->remove($projet);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_projet_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id_projet}/tache/new', name: 'app_tache_new', methods: ['GET', 'POST'])]
    public function newTask(Request $request, Projet $projet, ProjetRepository $projetRepository, EntityManagerInterface $entityManager): Response
    {
        $requestedStatus = mb_strtoupper(trim((string) $request->query->get('status', '')));

        $tache = new Tache();
        $tache->setProjet($projet);
        if (!in_array($requestedStatus, Tache::STATUT_VALUES, true) || $requestedStatus === Tache::STATUT_TERMINEE) {
            $requestedStatus = Tache::STATUT_A_FAIRE;
        }

        $tache->setStatutTache($requestedStatus);
        $tache->setProgression(0);

        $requestedDate = trim((string) $request->query->get('date', ''));
        if ($requestedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            try {
                $dateObj = new \DateTime($requestedDate);
                $tache->setDateDeb($dateObj);
                $tache->setDateLimite($dateObj);
            } catch (\Exception) {
                $tache->setDateDeb(new \DateTime('today'));
            }
        } else {
            $tache->setDateDeb(new \DateTime('today'));
        }

        $teamChoices = $projet->getMembresEquipe()->toArray();

        $form = $this->createForm(TacheType::class, $tache, [
            'project_team_choices' => $teamChoices,
            'allow_completed_status' => false,
            'is_edit' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->synchronizeTaskProgressionAndStatus($tache);
            $entityManager->persist($tache);
            $entityManager->flush();

            return $this->redirectToRoute('app_projet_index', ['projet' => $projet->getIdProjet()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tache/new.html.twig', [
            'projet' => $projet,
            'tache' => $tache,
            'form' => $form,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/{id_projet}/tache/{id_tache}/edit', name: 'app_tache_edit', methods: ['GET', 'POST'])]
    public function editTask(Request $request, Projet $projet, Tache $tache, ProjetRepository $projetRepository, EntityManagerInterface $entityManager): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        $teamChoices = $projet->getMembresEquipe()->toArray();

        $form = $this->createForm(TacheType::class, $tache, [
            'project_team_choices' => $teamChoices,
            'is_edit' => true,
            'employee_self_update' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->synchronizeTaskProgressionAndStatus($tache);
            $entityManager->flush();

            return $this->redirectToRoute('app_projet_index', ['projet' => $projet->getIdProjet()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tache/edit.html.twig', [
            'projet' => $projet,
            'tache' => $tache,
            'form' => $form,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
        ]);
    }

    #[Route('/{id_projet}/tache/{id_tache}', name: 'app_tache_show', methods: ['GET'])]
    public function showTask(Projet $projet, Tache $tache, ProjetRepository $projetRepository): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        return $this->render('tache/show.html.twig', [
            'projet' => $projet,
            'tache' => $tache,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'currentEmploye' => null,
            'currentEmployeId' => null,
            'canManageProjects' => true,
            'canManageTasks' => true,
            'canEditTask' => true,
        ]);
    }

    #[Route('/{id_projet}/tache/{id_tache}/delete', name: 'app_tache_delete', methods: ['POST'])]
    public function deleteTask(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        if ($this->isCsrfTokenValid('delete_tache_'.$tache->getIdTache(), (string) $request->request->get('_token'))) {
            $entityManager->remove($tache);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_projet_index', ['projet' => $projet->getIdProjet()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id_projet}/tache/{id_tache}/move', name: 'app_tache_move', methods: ['POST'])]
    public function moveTask(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager): JsonResponse
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            return $this->json(['ok' => false, 'message' => 'Tache introuvable pour ce projet.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'message' => 'Requete invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $status = (string) ($payload['status'] ?? '');
        $token = (string) ($payload['_token'] ?? '');

        if (!$this->isCsrfTokenValid('move_tache_'.$tache->getIdTache(), $token)) {
            return $this->json(['ok' => false, 'message' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($status, Tache::STATUT_VALUES, true)) {
            return $this->json(['ok' => false, 'message' => 'Statut invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $tache->setStatutTache($status);

        if ($status === Tache::STATUT_TERMINEE) {
            $tache->setProgression(100);
        } elseif ($status === Tache::STATUT_A_FAIRE) {
            $tache->setProgression(0);
        }

        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'status' => $status,
            'progression' => $tache->getProgression(),
        ]);
    }

    #[Route('/{id_projet}/tache/{id_tache}/progress', name: 'app_tache_progress', methods: ['POST'])]
    public function updateTaskProgress(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager): JsonResponse
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            return $this->json(['ok' => false, 'message' => 'Tache introuvable pour ce projet.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'message' => 'Requete invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $token = (string) ($payload['_token'] ?? '');
        if (!$this->isCsrfTokenValid('progress_tache_'.$tache->getIdTache(), $token)) {
            return $this->json(['ok' => false, 'message' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        $progression = $payload['progression'] ?? null;
        if (!is_numeric($progression)) {
            return $this->json(['ok' => false, 'message' => 'Progression invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $progressionValue = max(0, min(100, (int) $progression));
        $tache->setProgression($progressionValue);
        $this->synchronizeTaskProgressionAndStatus($tache);
        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'progression' => $tache->getProgression(),
            'status' => $tache->getStatutTache(),
        ]);
    }

    /**
     * @return array{chefProjets: array<int, \App\Entity\Employe>, membres: array<int, \App\Entity\Employe>}
     */
    private function buildEmployeChoices(EmployeRepository $employeRepository): array
    {
        $employes = $employeRepository->findAll();

        usort($employes, static function ($a, $b): int {
            $nomA = mb_strtolower((string) $a->getNom());
            $nomB = mb_strtolower((string) $b->getNom());

            return $nomA <=> $nomB;
        });

        $chefProjets = [];
        $membres = [];

        foreach ($employes as $employe) {
            if ($this->isChefProjetRole($employe->getRole())) {
                $chefProjets[] = $employe;
                continue;
            }

            $membres[] = $employe;
        }

        return [
            'chefProjets' => $chefProjets,
            'membres' => $membres,
        ];
    }

    /**
     * @return array<int, \App\Entity\Employe>
     */
    private function buildChefProjetsForFilter(EmployeRepository $employeRepository): array
    {
        $chefProjets = [];

        foreach ($employeRepository->findAll() as $employe) {
            if ($this->isChefProjetRole($employe->getRole())) {
                $chefProjets[] = $employe;
            }
        }

        usort($chefProjets, static function ($a, $b): int {
            return mb_strtolower((string) $a->getNom()) <=> mb_strtolower((string) $b->getNom());
        });

        return $chefProjets;
    }

    private function statusToProgression(?string $status): int
    {
        return match ($status) {
            Tache::STATUT_TERMINEE => 100,
            Tache::STATUT_EN_COURS => 50,
            Tache::STATUT_BLOQUEE => 50,
            default => 0,
        };
    }

    private function synchronizeTaskProgressionAndStatus(Tache $tache): void
    {
        if ($tache->getProgression() === null) {
            $tache->setProgression($this->statusToProgression($tache->getStatutTache()));
        }

        $progression = $tache->getProgression() ?? 0;
        $status = $tache->getStatutTache();

        if ($progression >= 100) {
            $tache->setProgression(100);
            $tache->setStatutTache(Tache::STATUT_TERMINEE);

            return;
        }

        if ($progression > 0) {
            $tache->setStatutTache(Tache::STATUT_EN_COURS);

            return;
        }

        if ($status !== Tache::STATUT_A_FAIRE) {
            $tache->setStatutTache(Tache::STATUT_A_FAIRE);
        }
    }

    private function isChefProjetRole(?string $role): bool
    {
        $normalizedRole = mb_strtolower(trim((string) $role));

        return in_array($normalizedRole, ['chef projet', 'chef_projet', 'chefprojet', 'responsable'], true);
    }
}
