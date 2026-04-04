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
    #[Route('/offre/list', name: 'app_offre_list')]
    public function listOffres(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/index.html.twig' , [
            'offres' => $offres
        ]);
    }
    
}
