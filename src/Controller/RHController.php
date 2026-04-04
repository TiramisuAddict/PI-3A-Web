<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\EmployéRepository;
use App\Repository\AdministrateurSystemeRepository;
use App\Entity\Employé;
use App\Form\EmployeType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;



final class RHController extends AbstractController
{
    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return $session->get('employe_logged_in') === true;
    }

    #[Route('/RH/Home', name: 'RH_Home', methods: ['GET'])]
    public function dashboard(SessionInterface $session,EmployéRepository $employeRepo): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employes = $employeRepo->findAll();

        return $this->render('rh/Home.html.twig', [
            'employes' => $employes,
            'role'     => $session->get('employe_role'),
            'email'    => $session->get('employe_email'),
        ]);
    }
    #[Route('/employe/{id}/details', name: 'employe_details', methods: ['GET', 'POST'])]
    public function details(int $id,Request $request,EmployéRepository $employeRepo,EntityManagerInterface $em,SessionInterface $session): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = $employeRepo->find($id);
        if (!$employe) {
            throw $this->createNotFoundException('Employé introuvable.');
        }

        $form = $this->createForm(EmployeType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Employé modifié avec succès.');
            return $this->redirectToRoute('employe_details', ['id' => $id]);
        }

        $employes = $employeRepo->findAll();

        return $this->render('rh/Home.html.twig', [
            'employes'       => $employes,
            'employe_select' => $employe,
            'form'           => $form->createView(),
            'role'           => $session->get('employe_role'),
            'email'          => $session->get('employe_email'),
        ]);
    }
    #[Route('/employe/ajouter', name: 'employe_ajouter', methods: ['GET', 'POST'])]
    public function ajouter(Request $request,EntityManagerInterface $em,SessionInterface $session,EmployéRepository $employeRepo,EntrepriseRepository $entrepriseRepo): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = new Employé();
        $form = $this->createForm(EmployeType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $idEntreprise = $session->get('employe_id_entreprise');
            $entreprise   = $entrepriseRepo->find($idEntreprise);
            $employe->setEntreprise($entreprise);

            $em->persist($employe);
            $em->flush();
            $this->addFlash('success', 'Employé ajouté avec succès.');
            return $this->redirectToRoute('RH_Home');
        }

        $employes = $em->getRepository(Employé::class)->findAll();

        return $this->render('rh/Home.html.twig', [
            'employes'    => $employes,
            'form_ajout'  => $form->createView(),
            'show_ajout'  => true,
            'role'        => $session->get('employe_role'),
            'email'       => $session->get('employe_email'),
        ]);
    }
}