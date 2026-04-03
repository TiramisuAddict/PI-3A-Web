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
    public function index(ProjetRepository $projetRepository): Response
    {
        return $this->render('projet/index.html.twig', [
            'projets' => $projetRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_projet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, EmployéRepository $employeRepository): Response
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
        ]);
    }

    #[Route('/{id_projet}', name: 'app_projet_show', methods: ['GET'])]
    public function show(Projet $projet): Response
    {
        return $this->render('projet/show.html.twig', [
            'projet' => $projet,
        ]);
    }

    #[Route('/{id_projet}/edit', name: 'app_projet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Projet $projet, EntityManagerInterface $entityManager, EmployéRepository $employeRepository): Response
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
}
