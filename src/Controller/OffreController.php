<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Offre;
use App\Form\CreeOffreType;

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
    public function listOffres(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/index.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    #[Route('/offre/createOffre', name: 'app_offre_create')]
    public function createOffreForm(Request $request, ManagerRegistry $doctrine) : Response {
        $offre = new Offre();

        $form = $this->createForm(CreeOffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();

            $offre->setIdEmployer(139);

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            return $this->render('offre/_offre_dashboard_form.html.twig', [
                'form' => $form->createView(),
                'message' => 'Offre créée avec succès !',
            ]);
        }

        return $this->render('offre/_offre_dashboard_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/offre/updateOffre/{id}', name: 'app_offre_update')]
    public function updateOffreForm(Request $request, ManagerRegistry $doctrine, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        $form = $this->createForm(CreeOffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();

            $offre->setIdEmployer(139);

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            return $this->render('offre/_offre_dashboard_form.html.twig', [
                'form' => $form->createView(),
                'message' => 'Offre modifiée avec succès !',
            ]);
        }

        return $this->render('offre/_offre_dashboard_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/offre/deleteOffre/{id}', name: 'app_offre_delete')]
    public function deleteOffreForm(Request $request, ManagerRegistry $doctrine, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        if ($offre) {
            $doctrine->getManager()->remove($offre);
            $doctrine->getManager()->flush();

            return $this->render('offre/_offre_dashboard_form.html.twig', [
                'message' => 'Offre supprimée avec succès !',
            ]);
        }

        return $this->render('offre/_offre_dashboard_form.html.twig', [
            'message' => 'Offre non trouvée.',
        ]);
    }

}
