<?php

namespace App\Controller;

use App\Form\LoginType;
use App\Form\TwoFactorCodeType;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EmployeRepository;
use App\Services\TwilioVerifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class TwoFactorController extends AbstractController
{
    private function clearOtpFlow(SessionInterface $session, TwilioVerifyService $twilioVerifyService, string $flow): void
    {
        // Nettoie toutes les cles de session OTP du flow (ex: two_factor) apres validation/annulation.
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

    /*
    #[Route('/two-factor', name: 'two_factor_verify', methods: ['GET', 'POST'])]
    public function twoFactor(Request $request, SessionInterface $session, EmployeRepository $employeRepo, AdministrateurSystemeRepository $adminRepo, TwilioVerifyService $twilioVerifyService): Response
    {
        if ($session->get('two_factor_pending') !== true) {
            return $this->redirectToRoute('login');
        }

        $loginForm = $this->createForm(LoginType::class);
        $form = $this->createForm(TwoFactorCodeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $code = $form->get('code')->getData();
            $destination = $session->get('two_factor_destination', '');
            $channel = $session->get('two_factor_channel', 'sms');

            if (!$twilioVerifyService->verifyCode($destination, $code, $channel)) {
                $this->addFlash('error', 'Code OTP invalide ou expiré.');
            } else {
                $userType = $session->get('two_factor_user_type');
                $role = $session->get('two_factor_role');

                if ($userType === 'admin') {
                    $admin = $adminRepo->find($session->get('two_factor_user_id'));
                    if (!$admin) {
                        return $this->redirectToRoute('login', ['cancel_2fa' => 1]);
                    }

                    $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                    $session->set('admin_logged_in', true);
                    $session->set('admin_email', $admin->getE_mail());

                    return $this->redirectToRoute('admin_home');
                }

                $employe = $employeRepo->find($session->get('two_factor_user_id'));
                if (!$employe) {
                    return $this->redirectToRoute('login', ['cancel_2fa' => 1]);
                }

                $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
                $session->set('employe_logged_in', true);
                $session->set('employe_id', $employe->getId_employe());
                $session->set('employe_email', $employe->getEmail());
                $session->set('employe_role', $employe->getRole());
                $session->set('employe_id_entreprise', $employe->getEntreprise()->getId_entreprise());

                return $this->redirectByRole($role);
            }
        }

        return $this->render('auth/login.html.twig', [
            'form' => $loginForm->createView(),
            'two_factor_form' => $form->createView(),
            'two_factor_destination' => $session->get('two_factor_destination', ''),
            'two_factor_resend_remaining_seconds' => $twilioVerifyService->getResendRemainingSeconds((int) $session->get('two_factor_resend_available_at', 0)),
        ]);
    }

    #[Route('/two-factor/resend', name: 'two_factor_resend', methods: ['POST'])]
    public function resendTwoFactorCode(Request $request, SessionInterface $session, TwilioVerifyService $twilioVerifyService): Response
    {
        if ($session->get('two_factor_pending') !== true) {
            return $this->redirectToRoute('login');
        }

        if (!$this->isCsrfTokenValid('two_factor_resend', $request->request->get('_token'))) {
            return $this->redirectToRoute('two_factor_verify');
        }

        $availableAt = $session->get('two_factor_resend_available_at', 0);
        if (!$twilioVerifyService->canResend($availableAt)) {
            $this->addFlash('error', sprintf('Veuillez patienter %d seconde(s) avant de renvoyer un code.', $twilioVerifyService->getResendRemainingSeconds($availableAt)));
            return $this->redirectToRoute('two_factor_verify');
        }

        $destination = $session->get('two_factor_destination', '');
        if ($destination === '') {
            return $this->redirectToRoute('login', ['cancel_2fa' => 1]);
        }

        $twilioVerifyService->sendCode($destination, $session->get('two_factor_channel', 'sms'));
        $session->set('two_factor_resend_available_at', $twilioVerifyService->nextResendAvailableAt());
        $this->addFlash('success', 'Nouveau code OTP envoye par SMS.');

        return $this->redirectToRoute('two_factor_verify');
    }
    */
}