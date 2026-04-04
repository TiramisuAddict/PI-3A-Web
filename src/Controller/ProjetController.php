<?php

namespace App\Controller;

use App\Entity\Projet;
use App\Form\ProjetType;
use App\Repository\EmployéRepository;
use App\Repository\ProjetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
        ];

        $responsableId = ctype_digit($filters['responsable']) ? (int) $filters['responsable'] : null;

        $dateFrom = null;
        $dateTo = null;

        if ($filters['date_from'] !== '') {
            $dateFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $filters['date_from']) ?: null;
        }

        if ($filters['date_to'] !== '') {
            $dateTo = \DateTimeImmutable::createFromFormat('Y-m-d', $filters['date_to']) ?: null;
        }

        $projets = $projetRepository->findByFilters(
            $filters['search'] !== '' ? $filters['search'] : null,
            $filters['statut'] !== '' ? $filters['statut'] : null,
            $filters['priorite'] !== '' ? $filters['priorite'] : null,
            $responsableId,
            $dateFrom,
            $dateTo,
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
            'hasActiveFilters' => $filters['search'] !== '' || $filters['statut'] !== '' || $filters['priorite'] !== '' || $filters['responsable'] !== '' || $filters['date_from'] !== '' || $filters['date_to'] !== '',
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
}
