<?php

namespace App\Controller;

use App\Entity\AdministrateurSysteme;
use App\Form\LoginType;
use App\Entity\Entreprise;
use App\Entity\Employe; 
use App\Entity\Compte;
use App\Services\MailerService;
use App\Services\PasswordGenerator;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminController extends AbstractController
{   
    #[Route('/admin/dashboard', name: 'admin_dashboard', methods: ['GET', 'POST'])]
    public function dashboard(Request $request, EntrepriseRepository $entrepriseRepo, EntityManagerInterface $em, SessionInterface $session, PasswordGenerator $passwordGenerator, MailerInterface $mailer, MailerService $mailerService, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($session->get('admin_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }
        $entreprises = $entrepriseRepo->findAll();

        $search = $request->query->get('search', '');
        $status = $request->query->get('status', '');

        $entreprises = $entrepriseRepo->findByFilters($search, $status);

        if ($request->isMethod('POST')) {
            $id = $request->request->get('id_entreprise');
            $action = $request->request->get('action');

            $entreprise = $entrepriseRepo->find($id);
            if ($entreprise) {
               if ($action === 'accepter') {
                    $recipientEmail = trim((string) $entreprise->getEmail());
                    $entreprise->setStatut('acceptée');
                    $employe = new Employe();
                    $employe->setNom($entreprise->getNom());
                    $employe->setPrenom($entreprise->getPrenom());
                    $employe->setTelephone($entreprise->getTelephone());
                    $employe->setEmail($recipientEmail);
                    $employe->setRole('administrateur entreprise');
                    $employe->setPoste('CEO');
                    $employe->setEntreprise($entreprise);
                    $em->persist($employe);

                    $plainPassword = $passwordGenerator->generatePlain();
                    $compte = new Compte();
                    $compte->setMot_de_passe($passwordHasher->hashPassword($compte, $plainPassword));
                    $compte->setEmploye($employe);
                    $em->persist($compte);

                    $em->flush();

                    try {
                        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                            throw new \InvalidArgumentException('Adresse e-mail invalide: ' . $recipientEmail);
                        }

                        $mailerService->sendTemporaryPassword(
                            $mailer,
                            $recipientEmail,
                            (string) $employe->getPrenom(),
                            (string) $employe->getNom(),
                            $plainPassword
                        );
                        $this->addFlash('success', 'Compte cree et mot de passe envoye par e-mail.');
                    } catch (\Throwable $exception) {
                        $this->addFlash('warning', 'Compte cree, mais l\'envoi de l\'e-mail a echoue: ' . $exception->getMessage());
                    }
                } elseif ($action === 'refuser') {
                    $entreprise->setStatut('refusée');
                    $em->flush();
                }
            }

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin.html.twig', [
            'entreprises' => $entreprises,
        ]);
    }
}