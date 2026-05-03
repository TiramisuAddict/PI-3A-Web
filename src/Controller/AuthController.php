<?php

namespace App\Controller;

use App\Service\TwilioVerifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Form\LoginType;
use Symfony\Component\Form\FormError;
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
        return match (mb_strtolower(trim($role))) {
            'administrateur entreprise' => $this->redirectToRoute('RH_Home'),
            'rh' => $this->redirectToRoute('RH_Home'),
            default => $this->redirectToRoute('employe_Home'),
        };
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request, AdministrateurSystemeRepository $adminRepo, EmployeRepository $employeRepo, VisiteurRepository $visiteurRepo, SessionInterface $session, TwilioVerifyService $twilioVerifyService, \App\Service\PasswordGenerator $passwordGenerator): Response
    {
        $cancelTwoFactor = $request->query->getBoolean('cancel_2fa');
        if ($cancelTwoFactor) {
            $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
        }

        if ($session->get('admin_logged_in') === true) {
            return $this->redirectToRoute('admin_home');
        }

        if ($session->get('employe_logged_in') === true) {
            return $this->redirectByRole((string) $session->get('employe_role'));
        }

        if ($session->get('visiteur_logged_in') === true) {
            return $this->redirectToRoute('app_offre_home');
        }

        /*
        if ($session->get('two_factor_pending') === true && !$cancelTwoFactor && $request->isMethod('GET')) {
            return $this->redirectToRoute('two_factor_verify');
        }
        */

        $form = $this->createForm(LoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = trim((string) ($data['email'] ?? ''));
            $password = $data['password'] ?? null;

            if ($email === '' || $password === null || trim((string) $password) === '') {
                if ($email === '') {
                    if ($form->has('email')) {
                        $form->get('email')->addError(new FormError('L\'email est obligatoire.'));
                    }
                }
                if ($password === null || trim((string) $password) === '') {
                    if ($form->has('password')) {
                        $form->get('password')->addError(new FormError('Le mot de passe est obligatoire.'));
                    }
                }
                return $this->render('auth/login.html.twig', ['form' => $form]);
            }

            // Vérification admin système (SHA-256)
            $admin = $adminRepo->findOneBy(['e_mail' => $email]);
            if ($admin !== null && $admin->getMot_de_passe() === $passwordGenerator->hash($password)) {
                /*
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
                */

                $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                $session->set('admin_logged_in', true);
                $session->set('admin_email', $admin->getE_mail());

                return $this->redirectToRoute('admin_home');
            }

            $employe = $employeRepo->findOneBy(['e_mail' => $email]);
            if ($employe !== null) {
                $compte = null;
                foreach ($employe->getComptes() as $c) {
                    // Accept SHA-256 hashed passwords; keep legacy checks as fallback
                    $storedHash = $c->getMot_de_passe();
                    if ($storedHash !== null && ($storedHash === $passwordGenerator->hash($password) || password_verify($password, $storedHash) || $storedHash === $password)) {
                        $compte = $c;
                        break;
                    }
                }

                if ($compte !== null) {
                    /*
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
                    */

                    $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                    $session->set('employe_logged_in', true);
                    $session->set('employe_id', $employe->getId_employe());
                    $session->set('employe_email', $employe->getEmail());
                    $session->set('employe_role', (string) $employe->getRole());
                    $session->set('employe_id_entreprise', (int) $employe->getEntreprise()?->getId_entreprise());
                    return $this->redirectByRole((string) $employe->getRole());
                }
            }

            $visiteur = $visiteurRepo->findOneBy(['e_mail' => $email]);
            if ($visiteur !== null) {
                $visiteurHash = $visiteur->getMotdepasse();
                if ($visiteurHash !== null && password_verify($password, $visiteurHash)) {
                $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                $session->set('visiteur_logged_in', true);
                $session->set('visiteur_id', $visiteur->getId_visiteur());
                $session->set('visiteur_nom', $visiteur->getNom());
                $session->set('visiteur_prenom', $visiteur->getPrenom());
                $session->set('visiteur_email', $visiteur->getEmail());

                return $this->redirectToRoute('app_offre_home');
                }
            }

            $form->addError(new FormError('Email ou mot de passe incorrect.'));
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
