<?php

namespace App\Controller;

use App\Entity\AdministrateurSysteme;
use App\Form\LoginType;
use App\Repository\AdministrateurSystemeRepository;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AdministrateurSystemeRepository $repo): Response
    {
        $form = $this->createForm(LoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = $data['email'];
            $password = $data['password'];

            $admin = $repo->findOneBy(['e_mail' => $email]);

            if ($admin && $admin->getMot_de_passe() === $password) {
                return $this->redirectToRoute('admin_dashboard');
            } else {
                $this->addFlash('error', 'Email ou mot de passe incorrect.');
            }
        }

        return $this->render('login.html.twig', ['form' => $form,]);
    }

    #[Route('/admin/dashboard', name: 'admin_dashboard', methods: ['GET', 'POST'])]
    public function dashboard(Request $request, EntrepriseRepository $entrepriseRepo, EntityManagerInterface $em): Response
    {
        $entreprises = $entrepriseRepo->findAll();

        $search = $request->query->get('search');
        $status = $request->query->get('status');

        if ($search || $status) {
            $filtered = [];
            foreach ($entreprises as $e) {
                if ($search && stripos($e->getNomEntreprise(), $search) === false) {
                    continue;
                }
                if ($status && $e->getStatut() !== $status) {
                    continue;
                }
                $filtered[] = $e;
            }
            $entreprises = $filtered;
        }

        if ($request->isMethod('POST')) {
            $id = $request->request->get('id_entreprise');
            $action = $request->request->get('action');

            $entreprise = $entrepriseRepo->find($id);
            if ($entreprise) {
                if ($action === 'accepter') {
                    $entreprise->setStatut('acceptée');
                } elseif ($action === 'refuser') {
                    $entreprise->setStatut('refusée');
                }
                $em->flush();
            }

            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('admin.html.twig', [
            'entreprises' => $entreprises,
        ]);
    }
}