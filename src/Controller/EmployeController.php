<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\EmployeRepository;
use App\Repository\EntrepriseRepository;

final class EmployeController extends AbstractController
{
    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return $session->get('employe_logged_in') === true;
    }

    #[Route('/employe/home', name: 'employe_Home', methods: ['GET'])]
    public function home(SessionInterface $session,EmployeRepository $employeRepo,EntrepriseRepository $entrepriseRepo): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        return $this->render('employe/home.html.twig', [
            'role'  => $session->get('employe_role'),
            'email' => $session->get('employe_email'),
        ]);
    }
}