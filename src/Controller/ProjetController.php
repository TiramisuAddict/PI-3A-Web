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
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\ProjectRiskReportService;
use App\Service\TaskDescriptionService;
use App\Service\TaskNotificationService;
use App\Repository\CompetenceEmployeRepository;
use App\Service\GoogleCalendarService;
use App\Service\GoogleTokenSessionService;


#[Route('/projet')]
final class ProjetController extends AbstractController
{
    private const MANAGER_ROLES = ['rh', 'chef projet', 'chef_projet', 'chefprojet', 'responsable', 'administrateur entreprise', 'administrateur_systeme', 'administrateur système'];

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
    public function index(Request $request, ProjetRepository $projetRepository, EmployeRepository $employeRepository, SessionInterface $session, PaginatorInterface $paginator): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        $filters = [
            'search'      => trim((string) $request->query->get('search', '')),
            'statut'      => trim((string) $request->query->get('statut', '')),
            'priorite'    => trim((string) $request->query->get('priorite', '')),
            'chef_projet' => trim((string) $request->query->get('chef_projet', (string) $request->query->get('responsable', ''))),
        ];

        $vue          = $request->query->get('vue', 'kanban');
        $chefProjetId = ctype_digit($filters['chef_projet']) ? (int) $filters['chef_projet'] : null;
        $employeId    = (!$rbac['canManageProjects'] && $rbac['currentEmploye'] !== null)
            ? $rbac['currentEmploye']->getId_employe()
            : null;

        // ── Vue liste : grille paginée de tous les projets ─────
        if ($vue === 'liste') {
            $qb = $projetRepository->findByFiltersQb(
                $filters['search']   !== '' ? $filters['search']   : null,
                $filters['statut']   !== '' ? $filters['statut']   : null,
                $filters['priorite'] !== '' ? $filters['priorite'] : null,
                $chefProjetId,
                $employeId,
            );

            $pagination = $paginator->paginate(
                $qb,
                $request->query->getInt('page', 1),
                6
            );

            $sidebarProjets = $this->filterProjetsForEmploye(
                $projetRepository->findByFilters(null, null, null, null),
                $rbac['currentEmploye'],
                $rbac['canManageProjects']
            );

            return $this->render('projet/index.html.twig', array_merge($rbac, [
                'projets'                 => [],
                'selectedProjet'          => null,
                'pagination'              => $pagination,
                'vue'                     => 'liste',
                'sidebarProjets'          => $sidebarProjets,
                'sidebarSelectedProjetId' => null,
                'filters'                 => $filters,
                'hasActiveFilters'        => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['chef_projet'] !== '',
                'chefProjets'             => $this->buildChefProjetsForFilter($employeRepository),
            ]));
        }

        // ── Vue kanban (comportement existant) ─────────────────
        $projets = $projetRepository->findByFilters(
            $filters['search']   !== '' ? $filters['search']   : null,
            $filters['statut']   !== '' ? $filters['statut']   : null,
            $filters['priorite'] !== '' ? $filters['priorite'] : null,
            $chefProjetId,
            null,
            null,
        );

        $projets = $this->filterProjetsForEmploye($projets, $rbac['currentEmploye'], $rbac['canManageProjects']);

        $selectedProjetId = ctype_digit((string) $request->query->get('projet', '')) ? (int) $request->query->get('projet') : null;
        $selectedProjet   = null;

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
            'projets'                 => $projets,
            'selectedProjet'          => $selectedProjet,
            'pagination'              => null,
            'vue'                     => 'kanban',
            'sidebarProjets'          => $projets,
            'sidebarSelectedProjetId' => $selectedProjet?->getIdProjet(),
            'filters'                 => $filters,
            'hasActiveFilters'        => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['chef_projet'] !== '',
            'chefProjets'             => $this->buildChefProjetsForFilter($employeRepository),
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
        $choices = $this->buildEmployeChoices($employeRepository, $rbac['currentEmploye']);
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
    public function show(#[MapEntity(id: 'id_projet')] Projet $projet, ProjetRepository $projetRepository, SessionInterface $session, EmployeRepository $employeRepository): Response
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
    public function edit(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, EntityManagerInterface $entityManager, EmployeRepository $employeRepository, ProjetRepository $projetRepository, SessionInterface $session): Response
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageProjects']) {
            throw $this->createAccessDeniedException('Acces refuse.');
        }

        $choices = $this->buildEmployeChoices($employeRepository, $rbac['currentEmploye']);
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
    public function delete(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
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
    public function newTask(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, ProjetRepository $projetRepository, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository, TaskNotificationService $notificationService): Response
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

            // Notifier l'employe assigne par email
            $notificationService->notifyNewTask($tache);

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
    public function editTask(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, #[MapEntity(id: 'id_tache')] Tache $tache, ProjetRepository $projetRepository, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
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
    public function showTask(#[MapEntity(id: 'id_projet')] Projet $projet, #[MapEntity(id: 'id_tache')] Tache $tache, ProjetRepository $projetRepository, SessionInterface $session, EmployeRepository $employeRepository): Response
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
    public function deleteTask(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, #[MapEntity(id: 'id_tache')] Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): Response
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
    public function moveTask(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, #[MapEntity(id: 'id_tache')] Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): JsonResponse
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
    public function updateTaskProgress(Request $request, #[MapEntity(id: 'id_projet')] Projet $projet, #[MapEntity(id: 'id_tache')] Tache $tache, EntityManagerInterface $entityManager, SessionInterface $session, EmployeRepository $employeRepository): JsonResponse
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
     * Soft recall with fuzzy token matching.
     * For each required task token, award credit if any profile token matches:
     *  - exactly           → 1.0
     *  - substring of each other → 0.7
     *  - similar_text >= 75 % → proportional credit
     * Returns a value in [0.0, 1.0]: fraction of task requirements covered.
     *
     * @param string[] $taskTokens
     * @param string[] $profileTokens
     */
    private function computeSkillsScore(array $taskTokens, array $profileTokens): float
    {
        if ($taskTokens === [] || $profileTokens === []) {
            return 0.0;
        }

        $totalCredit = 0.0;
        foreach ($taskTokens as $need) {
            $best = 0.0;
            foreach ($profileTokens as $have) {
                if ($need === $have) {
                    $best = 1.0;
                    break;
                }
                if (mb_strpos($have, $need) !== false || mb_strpos($need, $have) !== false) {
                    $best = max($best, 0.7);
                    continue;
                }
                similar_text($need, $have, $pct);
                if ($pct >= 75.0) {
                    $best = max($best, $pct / 100.0);
                }
            }
            $totalCredit += $best;
        }

        return $totalCredit / count($taskTokens);
    }

    /**
     * Normalises text and returns a lowercased, stopword-filtered string for comparison.
     */
    private function tokenizeSkills(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/[^\pL\pN+#\s]/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Converts a normalised string into a set of unique keyword tokens (stopwords removed).
     *
     * @return array<string, true>
     */
    private function buildTokenSet(string $text): array
    {
        static $stopwords = ['le','la','les','de','du','des','un','une','et','en',
            'au','aux','ce','se','sa','son','ses','je','tu','il','elle','nous',
            'vous','ils','elles','par','sur','sous','dans','avec','pour','que',
            'qui','est','sont','pas','plus','ou','on','mais','donc','ni','car',
            'the','a','an','of','to','in','is','are','for','and','or','not',
            'be','it','at','by','this','that','was','with','as'];

        $words = (array) preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($words as $word) {
            if (mb_strlen((string) $word) >= 2 && !in_array($word, $stopwords, true)) {
                $tokens[(string) $word] = true;
            }
        }
        return $tokens;
    }

    /**
     * @return array{chefProjets: array<int, \App\Entity\Employe>, membres: array<int, \App\Entity\Employe>}
     */
    private function buildEmployeChoices(EmployeRepository $employeRepository, ?Employe $currentEmploye = null): array
    {
        $entreprise = $currentEmploye?->getEntreprise();
        $employes = $entreprise !== null
            ? $employeRepository->findByEntrepriseAndFilters($entreprise, null, null)
            : $employeRepository->findAll();

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

    #[Route('/{id_projet}/workload', name: 'app_projet_workload', methods: ['GET'])]
    public function workloadAnalysis(#[MapEntity(id: 'id_projet')] Projet $projet, Request $request, SessionInterface $session, EmployeRepository $employeRepository, CompetenceEmployeRepository $competenceRepo): JsonResponse
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageTasks']) {
            return $this->json(['ok' => false, 'message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
        }

        // Build task text for skills matching from optional query params
        $titre       = trim((string) $request->query->get('titre', ''));
        $description = trim((string) $request->query->get('description', ''));
        $taskText    = '';
        if ($titre !== '' || $description !== '') {
            $taskText = $this->tokenizeSkills($titre . ' ' . $description);
        }

        $today           = new \DateTime('today');
        $results         = [];
        $competenceTexts = [];

        foreach ($projet->getMembresEquipe() as $employe) {
            $score = 0.0;
            $urgentCount = 0;
            $activeTasks = 0;

            foreach ($employe->getTaches() as $tache) {
                // Only tasks belonging to this project
                if ($tache->getProjet()?->getId_projet() !== $projet->getId_projet()) {
                    continue;
                }

                // Skip completed tasks — not consuming active effort
                if ($tache->getStatutTache() === Tache::STATUT_TERMINEE) {
                    continue;
                }

                ++$activeTasks;

                // Priority score
                $score += match ($tache->getPriorite()) {
                    Tache::PRIORITE_HAUTE   => 3.0,
                    Tache::PRIORITE_MOYENNE => 2.0,
                    Tache::PRIORITE_BASSE   => 1.0,
                    default                 => 0.0,
                };

                // Due date urgency bonus
                $dateLimite = $tache->getDateLimite();
                if ($dateLimite !== null) {
                    $diff = $today->diff($dateLimite);
                    $daysLeft = (int) $diff->days;
                    $isOverdue = $diff->invert === 1;

                    if ($isOverdue || $daysLeft <= 3) {
                        $score += 2.0;
                        ++$urgentCount;
                    } elseif ($daysLeft <= 7) {
                        $score += 1.0;
                    }
                }

                // Blocked tasks are not consuming active effort — small deduction
                if ($tache->getStatutTache() === Tache::STATUT_BLOQUEE) {
                    $score -= 0.5;
                }
            }

            // Availability thresholds
            if ($score >= 15 || $urgentCount >= 3) {
                $status = 'surcharge';
                $statusLabel = 'Surchargé';
                $dot = '🔴';
            } elseif ($score >= 8 || $urgentCount >= 1) {
                $status = 'occupe';
                $statusLabel = 'Occupé';
                $dot = '🟡';
            } else {
                $status = 'disponible';
                $statusLabel = 'Disponible';
                $dot = '🟢';
            }

            // Read competence profile directly from the database
            $competence  = $competenceRepo->findOneBy(['employe' => $employe]);
            $profileText = '';
            if ($competence !== null) {
                $parts = array_filter([
                    $competence->getSkills()     ?? '',
                    $competence->getFormations() ?? '',
                    $competence->getExperience() ?? '',
                ], static fn (string $p): bool => trim($p) !== '');
                if ($parts !== []) {
                    $profileText = $this->tokenizeSkills(implode(' ', $parts));
                }
            }
            $competenceTexts[] = $profileText;

            $results[] = [
                'id'          => $employe->getId_employe(),
                'nom'         => $employe->getNom(),
                'prenom'      => $employe->getPrenom(),
                'score'       => round($score, 1),
                'activeTasks' => $activeTasks,
                'urgentTasks' => $urgentCount,
                'status'      => $status,
                'statusLabel' => $dot . ' ' . $statusLabel,
                'skillsScore' => null,
                'skillsLabel' => null,
            ];
        }

        // Skills matching: soft recall with fuzzy token matching
        // Measures what fraction of the task's required skills the employee covers.
        $hasSkillsData = $taskText !== '' && array_filter($competenceTexts, static fn (string $t): bool => $t !== '') !== [];
        if ($hasSkillsData) {
            $taskTokens = array_keys($this->buildTokenSet($taskText));
            foreach ($results as $i => $row) {
                $raw = 0.0;
                if ($competenceTexts[$i] !== '' && count($taskTokens) > 0) {
                    $profileTokens = array_keys($this->buildTokenSet($competenceTexts[$i]));
                    $raw = $this->computeSkillsScore($taskTokens, $profileTokens);
                }
                $pct = (int) round($raw * 100);
                $results[$i]['skillsScore'] = $pct;
                $results[$i]['skillsLabel'] = match (true) {
                    $pct >= 70 => 'Excellent',
                    $pct >= 40 => 'Bon',
                    $pct >= 20 => 'Partiel',
                    default    => 'Faible',
                };
            }
            // Combined ranking: skills match (primary) + availability (modifier)
            usort($results, static function (array $a, array $b): int {
                $aRank = ($a['skillsScore'] / 10.0) - ($a['score'] * 0.5);
                $bRank = ($b['skillsScore'] / 10.0) - ($b['score'] * 0.5);
                return $bRank <=> $aRank;
            });
        } else {
            // Sort ascending: lowest workload score = most available
            usort($results, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        }

        // Build the top suggestion with a human-readable reason
        $suggestion = null;
        if (!empty($results)) {
            $top = $results[0];
            if ($hasSkillsData && $top['skillsScore'] !== null) {
                $reason = sprintf(
                    'Meilleure correspondance compétences (%d%%) avec un score de charge de %.1f.',
                    $top['skillsScore'],
                    $top['score']
                );
            } elseif ($top['activeTasks'] === 0) {
                $reason = 'Aucune tâche active — complètement disponible.';
            } elseif ($top['status'] === 'disponible') {
                $reason = sprintf(
                    'Score de charge %.1f sur %d tâche(s) active(s) — le plus disponible de l\'équipe.',
                    $top['score'],
                    $top['activeTasks']
                );
            } else {
                $reason = sprintf(
                    'Score de charge %.1f — le moins chargé de l\'équipe malgré %d tâche(s) active(s).',
                    $top['score'],
                    $top['activeTasks']
                );
            }
            $suggestion = array_merge($top, ['reason' => $reason]);
        }

        return $this->json([
            'ok'         => true,
            'team'       => $results,
            'suggestion' => $suggestion,
        ]);
    }

    #[Route('/tache/generate-description', name: 'app_tache_generate_description', methods: ['POST'])]
    public function generateTaskDescription(Request $request, SessionInterface $session, EmployeRepository $employeRepository, TaskDescriptionService $taskDescriptionService): JsonResponse
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);
        if (!$rbac['canManageTasks']) {
            return $this->json(['ok' => false, 'message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'message' => 'Corps de requete invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $taskTitle   = trim((string) ($payload['taskTitle'] ?? ''));
        $projectName = trim((string) ($payload['projectName'] ?? ''));

        if ($taskTitle === '' || $projectName === '') {
            return $this->json(['ok' => false, 'message' => 'taskTitle et projectName sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Basic length guard — prevent prompt injection via oversized inputs
        if (mb_strlen($taskTitle) > 200 || mb_strlen($projectName) > 200) {
            return $this->json(['ok' => false, 'message' => 'Titre ou nom de projet trop long.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $description = $taskDescriptionService->generateTaskDescription($taskTitle, $projectName);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['ok' => true, 'description' => $description]);
    }

    #[Route('/{id_projet}/risk-report', name: 'app_projet_risk_report', methods: ['GET'])]
    public function riskReport(#[MapEntity(id: 'id_projet')] Projet $projet, SessionInterface $session, EmployeRepository $employeRepository, ProjectRiskReportService $riskReportService): JsonResponse
    {
        $rbac = $this->buildRbacContext($session, $employeRepository);

        if (!$rbac['canManageProjects'] && !$rbac['canManageTasks']) {
            return $this->json(['ok' => false, 'message' => 'Acces refuse.'], Response::HTTP_FORBIDDEN);
        }

        $today          = new \DateTime('today');
        $totalTasks     = 0;
        $termineesCount = 0;
        $enCoursCount   = 0;
        $aFaireCount    = 0;
        $bloqueeCount   = 0;
        $retardCount    = 0;

        foreach ($projet->getTaches() as $tache) {
            ++$totalTasks;
            $statut = $tache->getStatutTache();

            match ($statut) {
                Tache::STATUT_TERMINEE => ++$termineesCount,
                Tache::STATUT_EN_COURS => ++$enCoursCount,
                Tache::STATUT_BLOQUEE  => ++$bloqueeCount,
                default                => ++$aFaireCount,
            };

            if ($statut !== Tache::STATUT_TERMINEE) {
                $dateLimite = $tache->getDateLimite();
                if ($dateLimite !== null && $dateLimite < $today) {
                    ++$retardCount;
                }
            }
        }

        $completionPct = $totalTasks > 0 ? (int) round(($termineesCount / $totalTasks) * 100) : 0;

        $dateFinPrevue = $projet->getDateFinPrevue();
        $daysLeft      = null;
        if ($dateFinPrevue !== null) {
            $diff     = $today->diff($dateFinPrevue);
            $daysLeft = $diff->invert === 1 ? -(int) $diff->days : (int) $diff->days;
        }

        // Build structured team workload array
        $teamData  = [];
        $teamParts = [];
        foreach ($projet->getMembresEquipe() as $employe) {
            $score       = 0.0;
            $urgentCount = 0;
            $activeTasks = 0;

            foreach ($employe->getTaches() as $tache) {
                if ($tache->getProjet()?->getId_projet() !== $projet->getId_projet()) {
                    continue;
                }
                if ($tache->getStatutTache() === Tache::STATUT_TERMINEE) {
                    continue;
                }

                ++$activeTasks;
                $score += match ($tache->getPriorite()) {
                    Tache::PRIORITE_HAUTE   => 3.0,
                    Tache::PRIORITE_MOYENNE => 2.0,
                    Tache::PRIORITE_BASSE   => 1.0,
                    default                 => 0.0,
                };

                $dateLimite = $tache->getDateLimite();
                if ($dateLimite !== null) {
                    $diff = $today->diff($dateLimite);
                    if ($diff->invert === 1 || (int) $diff->days <= 3) {
                        $score += 2.0;
                        ++$urgentCount;
                    }
                }
            }

            $statusKey = match (true) {
                $score >= 15 || $urgentCount >= 3 => 'surcharge',
                $score >= 8  || $urgentCount >= 1 => 'occupe',
                default                           => 'disponible',
            };
            $statusLabel = match ($statusKey) {
                'surcharge' => 'Surchargé',
                'occupe'    => 'Occupé',
                default     => 'Disponible',
            };

            $teamData[] = [
                'nom'         => $employe->getNom(),
                'prenom'      => $employe->getPrenom(),
                'score'       => round($score, 1),
                'statusKey'   => $statusKey,
                'statusLabel' => $statusLabel,
                'activeTasks' => $activeTasks,
                'urgentTasks' => $urgentCount,
            ];
            $teamParts[] = sprintf('%s %s (%s, charge %.1f)',
                $employe->getNom(), $employe->getPrenom(), $statusLabel, $score
            );
        }

        $conclusionData = [
            'name'           => $projet->getNom(),
            'statut'         => $projet->getStatut() ?? 'inconnu',
            'priorite'       => $projet->getPriorite(),
            'dateDebut'      => $projet->getDateDebut()?->format('d/m/Y'),
            'dateFinPrevue'  => $dateFinPrevue?->format('d/m/Y'),
            'daysLeft'       => $daysLeft,
            'totalTasks'     => $totalTasks,
            'termineesCount' => $termineesCount,
            'enCoursCount'   => $enCoursCount,
            'aFaireCount'    => $aFaireCount,
            'bloqueeCount'   => $bloqueeCount,
            'retardCount'    => $retardCount,
            'completionPct'  => $completionPct,
            'teamSummary'    => implode(', ', $teamParts),
        ];

        try {
            $conclusion = $riskReportService->generateConclusion($conclusionData);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok'         => true,
            'stats'      => [
                'total'         => $totalTasks,
                'terminees'     => $termineesCount,
                'enCours'       => $enCoursCount,
                'aFaire'        => $aFaireCount,
                'bloquee'       => $bloqueeCount,
                'retard'        => $retardCount,
                'completionPct' => $completionPct,
                'daysLeft'      => $daysLeft,
                'dateFinPrevue' => $dateFinPrevue?->format('d/m/Y'),
                'projectName'   => $projet->getNom(),
                'statut'        => $projet->getStatut() ?? 'inconnu',
            ],
            'team'       => $teamData,
            'conclusion' => $conclusion,
        ]);
    }

    private function isChefProjetRole(?string $role): bool
    {
        $normalizedRole = mb_strtolower(trim((string) $role));

        return in_array($normalizedRole, ['chef projet', 'chef_projet', 'chefprojet', 'responsable'], true);
    }

    // ── Google Calendar sync ──────────────────────────────────────────────────

    /**
     * Returns the list of projects the logged-in employee belongs to (as member or responsable).
     * Used to populate the project picker in the sync modal.
     */
    #[Route('/calendar/projects', name: 'app_calendar_projects', methods: ['GET'])]
    public function calendarProjects(SessionInterface $session, EmployeRepository $employeRepository): JsonResponse
    {
        $employe = $this->resolveCurrentEmploye($session, $employeRepository);
        if ($employe === null) {
            return $this->json(['ok' => false, 'message' => 'Non connecté.'], Response::HTTP_UNAUTHORIZED);
        }

        $projects = [];

        foreach ($employe->getProjetsEquipe() as $projet) {
            $projects[] = ['id' => $projet->getIdProjet(), 'nom' => $projet->getNom()];
        }

        foreach ($employe->getProjetsResponsables() as $projet) {
            // avoid duplicates
            $ids = array_column($projects, 'id');
            if (!in_array($projet->getIdProjet(), $ids, true)) {
                $projects[] = ['id' => $projet->getIdProjet(), 'nom' => $projet->getNom()];
            }
        }

        usort($projects, static fn (array $a, array $b): int => $a['nom'] <=> $b['nom']);

        return $this->json(['ok' => true, 'projects' => $projects]);
    }

    /**
     * Syncs the authenticated employee's tasks in the given project to Google Calendar.
     */
    #[Route('/calendar/sync/{id_projet}', name: 'app_calendar_sync', methods: ['POST'])]
    public function syncToCalendar(
        #[MapEntity(id: 'id_projet')] Projet $projet,
        SessionInterface $session,
        EmployeRepository $employeRepository,
        GoogleCalendarService $calendarService,
        GoogleTokenSessionService $googleToken
    ): JsonResponse {
        $employe = $this->resolveCurrentEmploye($session, $employeRepository);
        if ($employe === null) {
            return $this->json(['ok' => false, 'message' => 'Non connecté.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$googleToken->isLinked()) {
            return $this->json(['ok' => false, 'message' => 'Compte Google non lié.'], Response::HTTP_FORBIDDEN);
        }

        $synced  = [];
        $skipped = [];

        foreach ($employe->getTaches() as $tache) {
            if ($tache->getProjet()?->getIdProjet() !== $projet->getIdProjet()) {
                continue;
            }

            $dateDebut  = $tache->getDateDeb();
            $dateLimite = $tache->getDateLimite();

            if ($dateDebut === null || $dateLimite === null) {
                $skipped[] = $tache->getTitre();
                continue;
            }

            // Use start-of-day for date-only fields so the Calendar API gets a valid dateTime
            $start = \DateTime::createFromInterface($dateDebut)->setTime(8, 0, 0);
            $end   = \DateTime::createFromInterface($dateLimite)->setTime(18, 0, 0);

            $prioriteLabel = match ($tache->getPriorite()) {
                Tache::PRIORITE_HAUTE   => '🔴 Haute',
                Tache::PRIORITE_MOYENNE => '🟡 Moyenne',
                Tache::PRIORITE_BASSE   => '🟢 Basse',
                default                 => $tache->getPriorite() ?? '',
            };

            $description = sprintf(
                "Projet : %s\nPriorité : %s\nStatut : %s\n\n%s",
                $projet->getNom(),
                $prioriteLabel,
                $tache->getStatutTache() ?? '',
                $tache->getDescription() ?? ''
            );

            try {
                $event = $calendarService->createEvent(
                    '[' . $projet->getNom() . '] ' . ($tache->getTitre() ?? ''),
                    $start,
                    $end,
                    'primary',
                    'Africa/Tunis',
                    $description
                );
                $synced[] = ['titre' => $tache->getTitre(), 'link' => $event['htmlLink']];
            } catch (\Throwable $e) {
                $skipped[] = $tache->getTitre();
            }
        }

        return $this->json([
            'ok'      => true,
            'synced'  => $synced,
            'skipped' => $skipped,
        ]);
    }
}
