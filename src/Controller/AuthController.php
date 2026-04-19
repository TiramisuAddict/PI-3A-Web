<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Form\LoginType;
use App\Repository\EmployeRepository;
use App\Repository\AdministrateurSystemeRepository;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class AuthController extends AbstractController
{
    private function redirectByRole(string $role): Response
    {
        return match($role) {
            'administrateur entreprise' => $this->redirectToRoute('app_admin_dashboard'),
            'RH'=> $this->redirectToRoute('app_admin_dashboard'),
            default => $this->redirectToRoute('employe_Home'),
        };
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request,AdministrateurSystemeRepository $adminRepo,EmployeRepository $employeRepo,SessionInterface $session): Response {
        if ($session->get('admin_logged_in') === true) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($session->get('employe_logged_in') === true) {
            return $this->redirectByRole($session->get('employe_role'));
        }

        $form = $this->createForm(LoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = $data['email'];
            $password = $data['password'];

            // Vérification admin système
            $admin = $adminRepo->findOneBy(['e_mail' => $email]);
            if ($admin && $admin->getMot_de_passe() === $password) {
                $session->set('admin_logged_in', true);
                $session->set('admin_email', $admin->getE_mail());
                return $this->redirectToRoute('admin_dashboard');
            }

            $employe = $employeRepo->findOneBy(['e_mail' => $email]);
            if ($employe) {
                $compte = null;
                foreach ($employe->getComptes() as $c) {
                    if ($c->getMot_de_passe() === $password) {
                        $compte = $c;
                        break;
                    }
                }

                if ($compte) {
                    $session->set('employe_logged_in', true);
                    $session->set('employe_id', $employe->getId_employe());
                    $session->set('employe_email', $employe->getEmail());
                    $session->set('employe_role', $employe->getRole());
                    $session->set('employe_id_entreprise', $employe->getEntreprise()->getId_entreprise());
                    return $this->redirectByRole($employe->getRole());
                }
            }

            $this->addFlash('error', 'Email ou mot de passe incorrect.');
        }

        return $this->render('auth/login.html.twig', ['form' => $form]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(SessionInterface $session): Response
    {
        $session->clear();
        return $this->redirectToRoute('login');
    }
}
