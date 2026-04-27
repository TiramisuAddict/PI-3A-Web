<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StaticController extends AbstractController
{
    #[Route('/telechargement', name: 'app_download')]
    public function download(): Response
    {
        return $this->render('static/download.html.twig');
    }

    #[Route('/pricing', name: 'app_pricing')]
    public function pricing(): Response
    {
        return $this->render('static/pricing.html.twig');
    }

    #[Route('/a-propos-de-nous', name: 'app_about_us')]
    public function aboutUs(): Response
    {
        return $this->render('static/about_us.html.twig');
    }
}
