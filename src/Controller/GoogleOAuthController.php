<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class GoogleOAuthController extends AbstractController
{
    #[Route('/google/link', name: 'app_google_link_start', methods: ['GET'])]
    public function link(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry
            ->getClient('google_main')
            ->redirect([
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/calendar.events',
            ]);
    }

    #[Route('/google/check', name: 'app_google_oauth_check', methods: ['GET'])]
    public function check(ClientRegistry $clientRegistry, SessionInterface $session): Response
    {
        try {
            $client = $clientRegistry->getClient('google_main');
            $accessToken = $client->getAccessToken();
            $googleUser = $client->fetchUserFromToken($accessToken);

            $session->set('google_linked', true);
            $session->set('google_email', $googleUser->getEmail());
            $session->set('google_access_token', $accessToken->getToken());
            $session->set('google_refresh_token', $accessToken->getRefreshToken());
            $session->set('google_expires_at', $accessToken->getExpires());

            $this->addFlash('success', 'Compte Google lié avec succès.');
        } catch (IdentityProviderException $e) {
            $this->addFlash('error', 'Google OAuth error: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_candidat_dashboard');
    }

    #[Route('/google/unlink', name: 'app_google_unlink', methods: ['POST'])]
    public function unlink(Request $request, SessionInterface $session): Response
    {
        if (!$this->isCsrfTokenValid('google_unlink', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');
            return $this->redirectToRoute('app_candidat_dashboard');
        }

        $session->remove('google_linked');
        $session->remove('google_email');
        $session->remove('google_access_token');
        $session->remove('google_refresh_token');
        $session->remove('google_expires_at');

        $this->addFlash('success', 'Compte Google délié.');
        return $this->redirectToRoute('app_candidat_dashboard');
    }
}