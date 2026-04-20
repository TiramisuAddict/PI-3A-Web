<?php

namespace App\Controller;

use App\Entity\Visiteur;
use App\Repository\VisiteurRepository;
use App\Services\TwilioVerifyService;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController extends AbstractController
{
    private function clearOtpFlow(SessionInterface $session, TwilioVerifyService $twilioVerifyService, string $flow): void
    {
        foreach ($twilioVerifyService->getOtpSessionKeys($flow) as $key) {
            $session->remove($key);
        }
    }

    private function clearCurrentLoginSession(SessionInterface $session): void
    {
        foreach ([
            'admin_logged_in',
            'admin_email',
            'employe_logged_in',
            'employe_id',
            'employe_email',
            'employe_role',
            'employe_id_entreprise',
            'visiteur_logged_in',
            'visiteur_id',
            'visiteur_nom',
            'visiteur_prenom',
            'visiteur_email',
        ] as $key) {
            $session->remove($key);
        }
    }

    #[Route('/connect/google', name: 'oauth_google_start', methods: ['GET'])]
    public function connectGoogle(ClientRegistry $clientRegistry, SessionInterface $session): RedirectResponse
    {
        $session->set('oauth_google_target', 'visiteur');
        $session->set('oauth_google_mode', 'login');

        return $clientRegistry
            ->getClient('google')
            ->redirect(['openid', 'email', 'profile'], [
                'prompt' => 'select_account',
            ]);
    }

    #[Route('/connect/google/register', name: 'oauth_google_register_start', methods: ['GET'])]
    public function connectGoogleRegister(ClientRegistry $clientRegistry, SessionInterface $session): RedirectResponse
    {
        $session->set('oauth_google_target', 'visiteur');
        $session->set('oauth_google_mode', 'register');

        return $clientRegistry
            ->getClient('google')
            ->redirect(['openid', 'email', 'profile'], [
                'prompt' => 'select_account',
            ]);
    }

    #[Route('/connect/google/check', name: 'oauth_google_check', methods: ['GET'])]
    public function checkGoogle(ClientRegistry $clientRegistry,SessionInterface $session,VisiteurRepository $visiteurRepository,TwilioVerifyService $twilioVerifyService,EntityManagerInterface $entityManager,UserPasswordHasherInterface $passwordHasher): Response {
        $target = (string) $session->get('oauth_google_target', '');
        $mode = (string) $session->get('oauth_google_mode', 'login');
        $session->remove('oauth_google_target');
        $session->remove('oauth_google_mode');

        if ($target !== 'visiteur') {
            $this->addFlash('error', 'Session OAuth invalide. Veuillez recommencer.');
            return $this->redirectToRoute('login');
        }

        try {
            $googleUser = $clientRegistry->getClient('google')->fetchUser();
        } catch (IdentityProviderException $exception) {
            $this->addFlash('error', 'Connexion Google refusée: ' . $exception->getMessage());
            return $this->redirectToRoute('login');
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Erreur pendant la connexion Google.');
            return $this->redirectToRoute('login');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        if ($email === '') {
            $this->addFlash('error', 'Votre compte Google ne contient pas d\'email exploitable.');
            return $this->redirectToRoute('login');
        }

        $this->clearOtpFlow($session, $twilioVerifyService, 'two_factor');
        $this->clearCurrentLoginSession($session);

        $visiteur = $visiteurRepository->findOneBy(['e_mail' => $email]);

        if ($mode === 'register' && $visiteur) {
            $this->addFlash('error', 'Ce compte Google est deja associe a un visiteur. Choisissez un autre compte Google.');
            return $this->redirectToRoute('compte_visiteur');
        }

        if (!$visiteur) {
            $firstName = trim((string) ($googleUser->getFirstName() ?? ''));
            $lastName = trim((string) ($googleUser->getLastName() ?? ''));

            if ($firstName === '' && $lastName === '') {
                $fullName = trim((string) ($googleUser->getName() ?? ''));
                if ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName);
                    $firstName = (string) ($parts[0] ?? 'Visiteur');
                    $lastName = (string) (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Google');
                }
            }

            if ($firstName === '') {
                $firstName = 'Visiteur';
            }

            if ($lastName === '') {
                $lastName = 'Google';
            }

            $visiteur = new Visiteur();
            $visiteur->setPrenom($firstName);
            $visiteur->setNom($lastName);
            $visiteur->setEmail($email);
            $visiteur->setTelephone(0);
            $visiteur->setMotdepasse($passwordHasher->hashPassword($visiteur, bin2hex(random_bytes(24))));

            $entityManager->persist($visiteur);
            $entityManager->flush();

            $this->addFlash('success', 'Compte visiteur cree automatiquement via Google.');
        }

        $session->set('visiteur_logged_in', true);
        $session->set('visiteur_id', $visiteur->getId_visiteur());
        $session->set('visiteur_nom', $visiteur->getNom());
        $session->set('visiteur_prenom', $visiteur->getPrenom());
        $session->set('visiteur_email', $visiteur->getEmail());

        return $this->redirectToRoute('app_offre_home');
    }
}
