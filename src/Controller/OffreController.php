<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Offre;
use App\Form\OffreType;

use App\Repository\OffreRepository;

use Doctrine\Persistence\ManagerRegistry;

final class OffreController extends AbstractController
{
    #[Route('/offre', name: 'app_offre')]
    public function index(): Response
    {
        return $this->render('offre/index.html.twig', [
            'controller_name' => 'OffreController',
        ]);
    }
    
    //Offres
    #[Route('/offre/home', name: 'app_offre_home')]
    public function home(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/home_page.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    //Liste des offres
    #[Route('/offre/list', name: 'app_offre_list')]
    public function listOffres(Request $request, OffreRepository $offre_repository){
        $q = $request->query->get('q');
        $category = $request->query->get('category');
        $contract = $request->query->get('contract');

        $offres = $offre_repository->findByFilters($q, $category, $contract, null);

        return $this->render('offre/index.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    // dashboard_offre_hr
    #[Route('/offre/dashboard', name: 'app_offre_dashboard')]
    public function dashboard(Request $request, OffreRepository $offre_repository): Response
    {
        $q = $request->query->get('q');
        $contract = $request->query->get('contract');
        $etat = $request->query->get('etat');
        $category = $request->query->get('category');

        $offres = $offre_repository->findByFilters($q, $category, $contract, $etat);
        $form = $this->createForm(OffreType::class, new Offre());

        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'offres' => $offres,
            'form' => $form->createView(),
            'filters' => [
                'q' => $q,
                'contract' => $contract,
                'etat' => $etat,
                'category' => $category,
            ],
        ]);
    }

    #[Route('/offre/createOffre', name: 'app_offre_create', methods: ['GET', 'POST'])]
    public function createOffreForm(Request $request, ManagerRegistry $doctrine) : Response {
        $offre = new Offre();

        $form = $this->createForm(OffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();

            $offre->setIdEmployer(139);

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre créée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $offres = $doctrine->getRepository(Offre::class)->findAll();
        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'form' => $form->createView(),
            'offres' => $offres,
        ]);
    }

    #[Route('/offre/updateOffre/{id}', name: 'app_offre_update', methods: ['GET', 'POST'])]
    public function updateOffreForm(Request $request, ManagerRegistry $doctrine, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        if (!$offre) {
            $this->addFlash('error', 'Offre non trouvée.');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $form = $this->createForm(OffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();

            $offre->setIdEmployer(139);

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre modifiée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $offres = $doctrine->getRepository(Offre::class)->findAll();
        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'form' => $form->createView(),
            'offres' => $offres,
        ]);
    }

    #[Route('/offre/deleteOffre/{id}', name: 'app_offre_delete', methods: ['POST'])]
    public function deleteOffreForm(Request $request, ManagerRegistry $doctrine, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        if ($offre) {
            $doctrine->getManager()->remove($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre supprimée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $this->addFlash('error', 'Offre non trouvée.');
        return $this->redirectToRoute('app_offre_dashboard');
    }

}
