<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(SessionInterface $session): Response
    {
        if ($session->get('admin_logged_in') === true) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($session->get('employe_logged_in') === true) {
            $role = mb_strtolower(trim((string) $session->get('employe_role', '')));

            return match ($role) {
                'administrateur entreprise', 'rh' => $this->redirectToRoute('app_admin_dashboard'),
                default => $this->redirectToRoute('employe_Home'),
            };
        }

        if ($session->get('visiteur_logged_in') === true) {
            return $this->redirectToRoute('app_offre_home');
        }

        return $this->redirectToRoute('app_offre_home');
    }
}
