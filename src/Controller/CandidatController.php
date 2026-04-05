<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Repository\CandidatRepository;
use App\Entity\Candidat;

final class CandidatController extends AbstractController
{
    #[Route('/candidat', name: 'app_candidat')]
    public function index(): Response
    {
        return $this->render('candidat/index.html.twig', [
            'controller_name' => 'CandidatController',
        ]);
    }

    //Candidatures
    #[Route('/candidature/suivre', name: 'app_suivre_candidature')]
    public function suivre_candidature(CandidatRepository $candidat_repository){
        $candidatures = $candidat_repository->findAll();
        return $this->render('candidat/suivre_candidature_page.html.twig' , [ //page
            'candidatures' => $candidatures
        ]);
    }
}
