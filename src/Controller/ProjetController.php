<?php

namespace App\Controller;

use App\Entity\Projet;
use App\Entity\Tache;
use App\Form\ProjetType;
use App\Form\TacheType;
use App\Repository\EmployéRepository;
use App\Repository\ProjetRepository;
use App\Repository\TacheRepository;
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
    public function index(Request $request, ProjetRepository $projetRepository, EmployéRepository $employeRepository): Response
    {
        $filters = [
            'search' => trim((string) $request->query->get('search', '')),
            'statut' => trim((string) $request->query->get('statut', '')),
            'priorite' => trim((string) $request->query->get('priorite', '')),
            'responsable' => trim((string) $request->query->get('responsable', '')),
        ];

        $responsableId = ctype_digit($filters['responsable']) ? (int) $filters['responsable'] : null;

        $projets = $projetRepository->findByFilters(
            $filters['search'] !== '' ? $filters['search'] : null,
            $filters['statut'] !== '' ? $filters['statut'] : null,
            $filters['priorite'] !== '' ? $filters['priorite'] : null,
            $responsableId,
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
            'hasActiveFilters' => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['responsable'] !== '',
            'responsables' => $this->buildResponsablesForFilter($employeRepository),
        ]);
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, EmployéRepository $employeRepository, ProjetRepository $projetRepository): Response
    {
        $projet = new Projet();
        $choices = $this->buildEmployeChoices($employeRepository);
        $form = $this->createForm(ProjetType::class, $projet, [
            'responsables_choices' => $choices['responsables'],
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
        ]);
    }

    #[Route('/{id_projet}', name: 'app_projet_show', methods: ['GET'])]
    public function show(Projet $projet, ProjetRepository $projetRepository): Response
    {
        return $this->render('projet/show.html.twig', [
            'projet' => $projet,
            'sidebarProjets' => $projetRepository->findAll(),
            'sidebarSelectedProjetId' => $projet->getIdProjet(),
        ]);
    }

    #[Route('/{id_projet}/edit', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Projet $projet, EntityManagerInterface $entityManager, EmployéRepository $employeRepository, ProjetRepository $projetRepository): Response
    {
        $choices = $this->buildEmployeChoices($employeRepository);
        $form = $this->createForm(ProjetType::class, $projet, [
            'responsables_choices' => $choices['responsables'],
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
        $tache->setDateDeb(new \DateTime('today'));

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

    /**
     * @return array{responsables: array<int, \App\Entity\Employé>, membres: array<int, \App\Entity\Employé>}
     */
    private function buildEmployeChoices(EmployéRepository $employeRepository): array
    {
        $employes = $employeRepository->findAll();

        usort($employes, static function ($a, $b): int {
            $nomA = mb_strtolower((string) $a->getNom());
            $nomB = mb_strtolower((string) $b->getNom());

            return $nomA <=> $nomB;
        });

        $responsables = [];
        $membres = [];

        foreach ($employes as $employe) {
            $role = mb_strtolower((string) $employe->getRole());

            if ($role === 'responsable') {
                $responsables[] = $employe;
                continue;
            }

            $membres[] = $employe;
        }

        return [
            'responsables' => $responsables,
            'membres' => $membres,
        ];
    }

    /**
     * @return array<int, \App\Entity\Employé>
     */
    private function buildResponsablesForFilter(EmployéRepository $employeRepository): array
    {
        $responsables = [];

        foreach ($employeRepository->findAll() as $employe) {
            if (mb_strtolower((string) $employe->getRole()) === 'responsable') {
                $responsables[] = $employe;
            }
        }

        usort($responsables, static function ($a, $b): int {
            return mb_strtolower((string) $a->getNom()) <=> mb_strtolower((string) $b->getNom());
        });

        return $responsables;
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
}
