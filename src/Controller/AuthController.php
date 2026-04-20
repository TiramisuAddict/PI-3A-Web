<?php

namespace App\Controller;

use App\Services\TwilioVerifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\LoginType;
use App\Repository\EmployeRepository;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\VisiteurRepository;

final class AuthController extends AbstractController
{
    private function clearOtpFlow(SessionInterface $session, TwilioVerifyService $twilioVerifyService, string $flow): void
    {
        foreach ($twilioVerifyService->getOtpSessionKeys($flow) as $key) {
            $session->remove($key);
        }
    }

    private function redirectByRole(string $role): Response
    {
        return match($role) {
            'administrateur entreprise' => $this->redirectToRoute('RH_Home'),
            'RH' => $this->redirectToRoute('RH_Home'),
            default => $this->redirectToRoute('employe_Home'),
        };
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request, AdministrateurSystemeRepository $adminRepo, EmployeRepository $employeRepo, VisiteurRepository $visiteurRepo, SessionInterface $session, TwilioVerifyService $twilioVerifyService): Response
    {
        $cancelTwoFactor = $request->query->getBoolean('cancel_2fa');
        if ($cancelTwoFactor) {
            $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
        }

        if ($session->get('admin_logged_in') === true) {
            return $this->redirectToRoute('admin_home');
        }

        if ($session->get('employe_logged_in') === true) {
            return $this->redirectByRole($session->get('employe_role'));
        }

        if ($session->get('visiteur_logged_in') === true) {
            return $this->redirectToRoute('app_offre_home');
        }

        if ($session->get('two_factor_pending') === true && !$cancelTwoFactor && $request->isMethod('GET')) {
            return $this->redirectToRoute('two_factor_verify');
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
                $destination = $admin->getTelephone();
                if ($destination === '') {
                    $this->addFlash('error', 'Numéro de téléphone invalide.');
                    return $this->render('auth/login.html.twig', ['form' => $form]);
                }

                $twilioVerifyService->sendCode($destination, 'sms');
                $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                foreach ($twilioVerifyService->buildOtpSessionData('two_factor', 'admin', (int) $admin->getId(), (string) $admin->getE_mail(), $destination) as $key => $value) {
                    $session->set($key, $value);
                }

                $this->addFlash('success', 'Code OTP envoyé par SMS.');
                return $this->redirectToRoute('two_factor_verify');
            }

            $employe = $employeRepo->findOneBy(['e_mail' => $email]);
            if ($employe) {
                $compte = null;
                foreach ($employe->getComptes() as $c) {
                    if (password_verify($password, $c->getMot_de_passe()) || $c->getMot_de_passe() === $password) {
                        $compte = $c;
                        break;
                    }
                }

                if ($compte) {
                    $destination =$employe->getTelephone();
                    if ($destination === '') {
                        $this->addFlash('error', 'Numéro de téléphone invalide.');
                        return $this->render('auth/login.html.twig', ['form' => $form]);
                    }

                    $twilioVerifyService->sendCode($destination, 'sms');
                    $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                    foreach ($twilioVerifyService->buildOtpSessionData('two_factor', 'employe', (int) $employe->getId_employe(), (string) $employe->getEmail(), $destination, (string) $employe->getRole()) as $key => $value) {
                        $session->set($key, $value);
                    }

                    $this->addFlash('success', 'Nous vous avons envoyé un code par SMS.');
                    return $this->redirectToRoute('two_factor_verify');
                }
            }

            $visiteur = $visiteurRepo->findOneBy(['e_mail' => $email]);
            if ($visiteur && password_verify($password,$visiteur->getMotdepasse())) {
                $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                $session->set('visiteur_logged_in', true);
                $session->set('visiteur_id', $visiteur->getId_visiteur());
                $session->set('visiteur_nom', $visiteur->getNom());
                $session->set('visiteur_prenom', $visiteur->getPrenom());
                $session->set('visiteur_email', $visiteur->getEmail());

                return $this->redirectToRoute('app_offre_home');
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