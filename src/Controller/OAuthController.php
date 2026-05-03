<?php

namespace App\Controller;

use App\Entity\Visiteur;
use App\Form\VisiteurType;
use App\Repository\VisiteurRepository;
use App\Services\OAuthGoogleService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController extends AbstractController
{
	#[Route('/connect/google', name: 'oauth_google_start', methods: ['GET'])]
	public function connectGoogle(ClientRegistry $clientRegistry, SessionInterface $session): RedirectResponse
	{
		$session->set('oauth_google_mode', 'login');

		return $clientRegistry->getClient('google')->redirect(['openid', 'email', 'profile'], [
			'prompt' => 'select_account',
		]);
	}

	#[Route('/connect/google/register', name: 'oauth_google_register_start', methods: ['GET'])]
	public function connectGoogleRegister(ClientRegistry $clientRegistry, SessionInterface $session): RedirectResponse
	{
		$session->set('oauth_google_mode', 'register');

		return $clientRegistry->getClient('google')->redirect(['openid', 'email', 'profile'], [
			'prompt' => 'select_account',
		]);
	}

	#[Route('/connect/google/check', name: 'oauth_google_check', methods: ['GET'])]
	public function checkGoogle(ClientRegistry $clientRegistry,SessionInterface $session,VisiteurRepository $visiteurRepository,EntityManagerInterface $entityManager,UserPasswordHasherInterface $passwordHasher,OAuthGoogleService $oauthGoogleService): Response {
		$mode = $session->get('oauth_google_mode', 'login');
		$session->remove('oauth_google_mode');

		try {
			$googleUser = $clientRegistry->getClient('google')->fetchUser();
		} catch (IdentityProviderException $exception) {
			$this->addFlash('error', 'Connexion Google refusée: ' . $exception->getMessage());

			return $this->redirectToRoute('login');
		} catch (\Throwable $exception) {
			$this->addFlash('error', 'Erreur pendant la connexion Google.');

			return $this->redirectToRoute('login');
		}

		$oauthGoogleService->clearCurrentLoginSession($session);

		$result = $oauthGoogleService->findOrCreateVisiteurFromGoogle(
			$mode,
			$googleUser,
			$visiteurRepository,
			$entityManager,
			$passwordHasher
		);

		if ($result['error'] !== null) {
			$this->addFlash('error', $result['error']);

			return $this->redirectToRoute($mode === 'register' ? 'compte_visiteur' : 'login');
		}

		$visiteur = $result['visiteur'];
		if ($visiteur === null) {
			$this->addFlash('error', 'Erreur de connexion Google.');

			return $this->redirectToRoute('login');
		}

		if ($visiteur->getTelephone() <= 0) {
			$session->set('oauth_google_complete_phone_visiteur_id', $visiteur->getId_visiteur());

			return $this->redirectToRoute('compte_visiteur');
		}

		$oauthGoogleService->loginVisiteur($session, $visiteur);

		return $this->redirectToRoute('app_offre_home');
	}

	#[Route('/connect/google/phone', name: 'oauth_google_phone', methods: ['GET', 'POST'])]
	public function completePhone(): Response {
		return $this->redirectToRoute('compte_visiteur');
	}
}
