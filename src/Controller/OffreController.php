<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Offre;

use App\Repository\OffreRepository;

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

    //dashboard_offre_hr
    #[Route('/offre/dashboard', name: 'app_offre_dashboard')]
    public function dashboard(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/dashboard_offre_hr.html.twig' , [ //
            'offres' => $offres
        ]);
    }
    
}
