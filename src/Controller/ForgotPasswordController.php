<?php

namespace App\Controller;

use App\Form\ForgotPasswordRequestType;
use App\Form\LoginType;
use App\Form\ResetPasswordType;
use App\Form\TwoFactorCodeType;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EmployeRepository;
use App\Service\TwilioVerifyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ForgotPasswordController extends AbstractController
{
    private function clearResetPasswordFlow(SessionInterface $session): void
    {
        foreach ([
            'reset_password_pending',
            'reset_password_verified',
            'reset_password_user_type',
            'reset_password_user_id',
            'reset_password_destination',
        ] as $key) {
            $session->remove($key);
        }
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request, AdministrateurSystemeRepository $adminRepo, EmployeRepository $employeRepo, SessionInterface $session, TwilioVerifyService $twilioVerifyService): Response
    {
        $cancelForgot = $request->query->getBoolean('cancel_forgot');
        if ($cancelForgot) {
            $this->clearResetPasswordFlow($session);
        }

        $loginForm = $this->createForm(LoginType::class);
        $form = $this->createForm(ForgotPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $admin = $adminRepo->findOneBy(['e_mail' => $email]);
            $userType = null;
            $userId = 0;
            $destination = '';

            if ($admin !== null) {
                $userType = 'admin';
                $userId =  $admin->getId();
                $destination = (string) $admin->getTelephone();
            }

            if ($userType === null) {
                $employe = $employeRepo->findOneBy(['e_mail' => $email]);
                if ($employe !== null) {
                    $compte = $employe->getComptes()->first();
                    if ($compte !== false) {
                        $userType = 'employe';
                        $userId =$employe->getId_employe();
                        $destination = (string) $employe->getTelephone();
                    }
                }
            }

            if ($userType !== null && $destination !== '') {
                $twilioVerifyService->sendCode($destination, 'sms');
                $this->clearResetPasswordFlow($session);
                $session->set('reset_password_pending', true);
                $session->set('reset_password_verified', false);
                $session->set('reset_password_user_type', $userType);
                $session->set('reset_password_user_id', $userId);
                $session->set('reset_password_destination', $destination);

                return $this->redirectToRoute('forgot_password_verify');
            }

            $this->addFlash('error', 'Email introuvable ou numero de telephone invalide.');
            return $this->redirectToRoute('forgot_password');
        }

        return $this->render('auth/login.html.twig', [
            'form' => $loginForm->createView(),
            'forgot_password_form' => $form->createView(),
        ]);
    }

    #[Route('/forgot-password/verify', name: 'forgot_password_verify', methods: ['GET', 'POST'])]
    public function forgotPasswordVerify(Request $request, SessionInterface $session, TwilioVerifyService $twilioVerifyService): Response
    {
        if ($session->get('reset_password_pending') !== true) {
            return $this->redirectToRoute('forgot_password');
        }

        $loginForm = $this->createForm(LoginType::class);
        $otpForm = $this->createForm(TwoFactorCodeType::class);
        $otpForm->handleRequest($request);

        if ($otpForm->isSubmitted() && $otpForm->isValid()) {
            $code =$otpForm->get('code')->getData();
            $destination =$session->get('reset_password_destination', '');

            if ($twilioVerifyService->verifyCode($destination, $code, 'sms')) {
                $session->set('reset_password_verified', true);
                return $this->redirectToRoute('forgot_password_new');
            }

            $this->addFlash('error', 'Code OTP invalide ou expiré.');
        }

        return $this->render('auth/login.html.twig', [
            'form' => $loginForm->createView(),
            'two_factor_form' => $otpForm->createView(),
            'two_factor_destination' => $session->get('reset_password_destination', ''),
            'otp_resend_remaining_seconds' => 0,
            'otp_simple_resend' => true,
            'otp_close_url' => $this->generateUrl('forgot_password', ['cancel_forgot' => 1]),
            'otp_resend_route' => 'forgot_password_resend',
            'otp_resend_csrf_id' => 'forgot_password_resend',
        ]);
    }

    #[Route('/forgot-password/resend', name: 'forgot_password_resend', methods: ['POST'])]
    public function forgotPasswordResend(Request $request, SessionInterface $session, TwilioVerifyService $twilioVerifyService): Response
    {
        if ($session->get('reset_password_pending') !== true) {
            return $this->redirectToRoute('forgot_password');
        }

        if (!$this->isCsrfTokenValid('forgot_password_resend', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('forgot_password_verify');
        }

        $destination = $session->get('reset_password_destination', '');
        if ($destination === '') {
            return $this->redirectToRoute('forgot_password');
        }

        $twilioVerifyService->sendCode($destination, 'sms');
        $this->addFlash('success', 'Nouveau code OTP envoyé par SMS.');

        return $this->redirectToRoute('forgot_password_verify');
    }

    #[Route('/forgot-password/new', name: 'forgot_password_new', methods: ['GET', 'POST'])]
    public function forgotPasswordNew(Request $request, SessionInterface $session, AdministrateurSystemeRepository $adminRepo, EmployeRepository $employeRepo, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, \App\Services\PasswordGenerator $passwordGenerator): Response
    {
        if ($session->get('reset_password_pending') !== true || $session->get('reset_password_verified') !== true) {
            return $this->redirectToRoute('forgot_password');
        }

        $loginForm = $this->createForm(LoginType::class);
        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('password')->getData();
            $confirmPassword = $form->get('confirm_password')->getData();
            $userType = $session->get('reset_password_user_type', '');
            $userId =$session->get('reset_password_user_id', 0);

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'Le mot de passe et la confirmation doivent etre identiques.');
                return $this->render('auth/login.html.twig', [
                    'form' => $loginForm->createView(),
                    'reset_password_form' => $form->createView(),
                ]);
            }

            if ($userType === 'admin') {
                $admin = $adminRepo->find($userId);
                if ($admin !== null) {
                    $admin->setMot_de_passe($passwordGenerator->hash($newPassword));
                    $entityManager->flush();
                }
            }

            if ($userType === 'employe') {
                $employe = $employeRepo->find($userId);
                if ($employe !== null) {
                    $compte = $employe->getComptes()->first();
                    if ($compte !== false) {
                        $compte->setMot_de_passe($passwordGenerator->hash($newPassword));
                        $entityManager->flush();
                    }
                }
            }

            $this->clearResetPasswordFlow($session);
            $this->addFlash('success', 'Mot de passe réinitialisé avec succès.');

            return $this->redirectToRoute('login');
        }

        return $this->render('auth/login.html.twig', [
            'form' => $loginForm->createView(),
            'reset_password_form' => $form->createView(),
        ]);
    }
}
