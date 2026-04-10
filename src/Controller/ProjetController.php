<?php

namespace App\Controller;

use App\Entity\Employe;
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
use Symfony\Component\HttpFoundation\Session\SessionInterface;


#[Route('/projet')]
final class ProjetController extends AbstractController
{
    private const MANAGER_ROLES = ['rh', 'chef projet', 'chef_projet', 'chefprojet', 'responsable', 'administrateur systeme', 'administrateur_systeme', 'administrateur système'];

    private function resolveCurrentEmploye(SessionInterface $session, EmployeRepository $employeRepository): ?Employe
    {
        $employeId = $session->get('employe_id');
        if ($employeId === null) {
            return null;
        }

        return $employeRepository->find($employeId);
    }

    private function isManagerRole(?string $role): bool
    {
        if ($role === null) {
            return false;
        }

        return in_array(mb_strtolower(trim($role)), self::MANAGER_ROLES, true);
    }

    private function buildRbacContext(SessionInterface $session, EmployeRepository $employeRepository): array
    {
        $currentEmploye = $this->resolveCurrentEmploye($session, $employeRepository);
        $role = $currentEmploye?->getRole();
        $isManager = $this->isManagerRole($role);

        return [
            'currentEmploye' => $currentEmploye,
            'currentEmployeId' => $currentEmploye?->getId_employe(),
            'canManageProjects' => $isManager,
            'canManageTasks' => $isManager,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ];
    }

    private function filterProjetsForEmploye(array $projets, ?Employe $employe, bool $isManager): array
    {
        if ($isManager || $employe === null) {
            return $projets;
        }

        $employeId = $employe->getId_employe();

        return array_values(array_filter($projets, static function (Projet $p) use ($employeId): bool {
            // Responsable (chef projet) of the project
            if ($p->getResponsable()?->getId_employe() === $employeId) {
                return true;
            }

            // Member of the project team
            foreach ($p->getMembresEquipe() as $membre) {
                if ($membre->getId_employe() === $employeId) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function employeCanAccessProjet(?Employe $employe, Projet $projet, bool $isManager): bool
    {
        if ($isManager || $employe === null) {
            return true;
        }

        $employeId = $employe->getId_employe();

        if ($projet->getResponsable()?->getId_employe() === $employeId) {
            return true;
        }

        foreach ($projet->getMembresEquipe() as $membre) {
            if ($membre->getId_employe() === $employeId) {
                return true;
            }
        }

        return false;
    }

    #[Route(name: 'app_projet_index', methods: ['GET'])]
    public function index(Request $request, ProjetRepository $projetRepository, EmployeRepository $employeRepository, SessionInterface $session): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

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

        // Filter projects for regular employees
        $projets = $this->filterProjetsForEmploye($projets, $rbac['currentEmploye'], $rbac['canManageProjects']);

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

        return $this->render('projet/index.html.twig', array_merge($rbac, [
            'projets' => $projets,
            'selectedProjet' => $selectedProjet,
            'sidebarProjets' => $projets,
            'sidebarSelectedProjetId' => $selectedProjet?->getIdProjet(),
            'filters' => $filters,
            'hasActiveFilters' => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['chef_projet'] !== '',
            'chefProjets' => $this->buildChefProjetsForFilter($employeRepository),
        ]));
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, EmployeRepository $employeRepository, ProjetRepository $projetRepository, SessionInterface $session): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageProjects']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

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

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('projet/new.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'form' => $form,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => null,
        ]));
    }

    #[Route('/{id_projet}', name: 'app_projet_show', methods: ['GET'])]
    public function show(Projet $projet, ProjetRepository $projetRepository, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$this->employeCanAccessProjet($rbac['currentEmploye'], $projet, $rbac['canManageProjects'])) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('projet/show.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
        ]));
    }

    #[Route('/{id_projet}/edit', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Projet $projet, EntityManagerInterface $entityManager, EmployeRepository $employeRepository, ProjetRepository $projetRepository, SessionInterface $session): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageProjects']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

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

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('projet/edit.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'form' => $form,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
        ]));
    }

    #[Route('/{id_projet}', name: 'app_projet_delete', methods: ['POST'])]
    public function delete(Request $request, Projet $projet, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageProjects']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        if ($this->isCsrfTokenValid('delete'.$projet->getIdProjet(), (string) $request->request->get('_token'))) {
            $entityManager->remove($projet);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_projet_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id_projet}/tache/new', name: 'app_tache_new', methods: ['GET', 'POST'])]
    public function newTask(Request $request, Projet $projet, ProjetRepository $projetRepository, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageTasks']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }
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

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('tache/new.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'tache' => $tache,
            'form' => $form,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
        ]));
    }

    #[Route('/{id_projet}/tache/{id_tache}/edit', name: 'app_tache_edit', methods: ['GET', 'POST'])]
    public function editTask(Request $request, Projet $projet, Tache $tache, ProjetRepository $projetRepository, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        $rbac = $this->buildRbacContext($session, $employeRepository);

        // An employee can only edit their own task
        $isOwnTask = $rbac['currentEmploye'] !== null
            && $tache->getEmploye() !== null
            && $tache->getEmploye()->getId_employe() === $rbac['currentEmployeId'];
        $employeeSelfUpdate = !$rbac['canManageTasks'] && $isOwnTask;

        if (!$rbac['canManageTasks'] && !$isOwnTask) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        $teamChoices = $projet->getMembresEquipe()->toArray();

        $form = $this->createForm(TacheType::class, $tache, [
            'project_team_choices' => $teamChoices,
            'is_edit' => true,
            'employee_self_update' => $employeeSelfUpdate,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->synchronizeTaskProgressionAndStatus($tache);
            $entityManager->flush();

            return $this->redirectToRoute('app_projet_index', ['projet' => $projet->getIdProjet()], Response::HTTP_SEE_OTHER);
        }

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('tache/edit.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'tache' => $tache,
            'form' => $form,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
        ]));
    }

    #[Route('/{id_projet}/tache/{id_tache}', name: 'app_tache_show', methods: ['GET'])]
    public function showTask(Projet $projet, Tache $tache, ProjetRepository $projetRepository, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$this->employeCanAccessProjet($rbac['currentEmploye'], $projet, $rbac['canManageProjects'])) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        $isOwnTask = $rbac['currentEmploye'] !== null
            && $tache->getEmploye() !== null
            && $tache->getEmploye()->getId_employe() === $rbac['currentEmployeId'];

        $sidebarProjets = $this->filterProjetsForEmploye($projetRepository->findAll(), $rbac['currentEmploye'], $rbac['canManageProjects']);

        return $this->render('tache/show.html.twig', array_merge($rbac, [
            'projet' => $projet,
            'tache' => $tache,
            'sidebarProjets' => $sidebarProjets,
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
            'canEditTask' => $rbac['canManageTasks'] || $isOwnTask,
        ]));
    }

    #[Route('/{id_projet}/tache/{id_tache}/delete', name: 'app_tache_delete', methods: ['POST'])]
    public function deleteTask(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            throw $this->createNotFoundException('Tache introuvable pour ce projet.');
        }

        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageTasks']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        if ($this->isCsrfTokenValid('delete_tache_'.$tache->getIdTache(), (string) $request->request->get('_token'))) {
            $entityManager->remove($tache);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_projet_index', ['projet' => $projet->getIdProjet()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id_projet}/tache/{id_tache}/move', name: 'app_tache_move', methods: ['POST'])]
    public function moveTask(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): JsonResponse
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            return $this->json(['ok' => false, 'message' => 'Tache introuvable pour ce projet.'], Response::HTTP_NOT_FOUND);
        }

        $rbac = $this->buildRbacContext($session, $employeRepository);
        $isOwnTask = $rbac['currentEmploye'] !== null
            && $tache->getEmploye() !== null
            && $tache->getEmploye()->getId_employe() === $rbac['currentEmployeId'];

        if (!$rbac['canManageTasks'] && !$isOwnTask) {
            return $this->json(['ok' => false, 'message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
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
    public function updateTaskProgress(Request $request, Projet $projet, Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): JsonResponse
    {
        if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
            return $this->json(['ok' => false, 'message' => 'Tache introuvable pour ce projet.'], Response::HTTP_NOT_FOUND);
        }

        $rbac = $this->buildRbacContext($session, $employeRepository);
        $isOwnTask = $rbac['currentEmploye'] !== null
            && $tache->getEmploye() !== null
            && $tache->getEmploye()->getId_employe() === $rbac['currentEmployeId'];

        if (!$rbac['canManageTasks'] && !$isOwnTask) {
            return $this->json(['ok' => false, 'message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
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
