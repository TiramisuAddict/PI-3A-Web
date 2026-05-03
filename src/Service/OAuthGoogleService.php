<?php

namespace App\Service;

use App\Entity\Visiteur;
use App\Repository\VisiteurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class OAuthGoogleService
{
    public function clearCurrentLoginSession(SessionInterface $session): void
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

    public function loginVisiteur(SessionInterface $session, Visiteur $visiteur): void
    {
        $session->set('visiteur_logged_in', true);
        $session->set('visiteur_id', $visiteur->getId_visiteur());
        $session->set('visiteur_nom', $visiteur->getNom());
        $session->set('visiteur_prenom', $visiteur->getPrenom());
        $session->set('visiteur_email', $visiteur->getEmail());
    }

    /**
     * @return array<string, Visiteur|string|null>
     */
    public function findOrCreateVisiteurFromGoogle(string $mode,object $googleUser,VisiteurRepository $visiteurRepository,EntityManagerInterface $entityManager,UserPasswordHasherInterface $passwordHasher): array {
        $email = strtolower($googleUser->getEmail());
        if ($email === '') {
            return [
                'visiteur' => null,
                'error' => 'Votre compte Google ne contient pas d\'email exploitable.',
            ];
        }

        $visiteur = $visiteurRepository->findOneBy(['e_mail' => $email]);

        if ($mode === 'register' && $visiteur !== null) {
            return [
                'visiteur' => null,
                'error' => 'Ce compte Google est deja associe a un visiteur.',
            ];
        }

        if ($visiteur === null) {
            $firstName = $googleUser->getFirstName() ?? '';
            $lastName = $googleUser->getLastName() ?? '';

            if ($firstName === '' && $lastName === '') {
                $fullName =($googleUser->getName() ?? '');
                if ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName);
                    $firstName = $parts[0] ?? 'Visiteur';
                    $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Google';
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
            $visiteur->setTelephone((int) '0');
            $visiteur->setMotdepasse($passwordHasher->hashPassword($visiteur, bin2hex(random_bytes(24))));

            $entityManager->persist($visiteur);
            $entityManager->flush();
        }

        return [
            'visiteur' => $visiteur,
            'error' => null,
        ];
    }

    public function updateVisiteurPhone(Visiteur $visiteur, int $telephone, EntityManagerInterface $entityManager): void
    {
        $visiteur->setTelephone($telephone);
        $entityManager->flush();
    }
}
