<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\EmployeRepository;
use App\Repository\AdministrateurSystemeRepository;
use App\Entity\Employe;
use App\Form\EmployeType;
use App\Repository\EntrepriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\PasswordGenerator;
use App\Entity\Compte;




final class RHController extends AbstractController
{
    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return $session->get('employe_logged_in') === true;
    }

#[Route('/RH/Home', name: 'RH_Home', methods: ['GET'])]
public function dashboard(Request $request, SessionInterface $session, EmployeRepository $employeRepo, EntrepriseRepository $entrepriseRepo): Response
{
    if (!$this->isEmployeLoggedIn($session)) {
        return $this->redirectToRoute('login');
    }

    $idEntreprise = $session->get('employe_id_entreprise');
    $entreprise = $entrepriseRepo->find($idEntreprise);

    $search = $request->query->get('search');
    $role = $request->query->get('role');
    $employes = $employeRepo->findByEntrepriseAndFilters($entreprise, $search, $role);

    $formAjout = $this->createForm(EmployeType::class, new Employe());

    return $this->render('rh/Home.html.twig', [
        'employes' => $employes,
        'form_ajout' => $formAjout->createView(),
        'role' => $session->get('employe_role'),
        'email' => $session->get('employe_email'),
        'search' => $search,
        'selected_role' => $role,
    ]);
}
    #[Route('/employe/{id}/details', name: 'employe_details', methods: ['GET', 'POST'])]
    public function details(int $id,Request $request,EmployeRepository $employeRepo,EntityManagerInterface $em,SessionInterface $session,EntrepriseRepository $entrepriseRepo): Response {
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

        $idEntreprise = $session->get('employe_id_entreprise');
        $entreprise = $entrepriseRepo->find($idEntreprise);
        $employes = $employeRepo->findBy(['entreprise' => $entreprise]);

        $formAjout = $this->createForm(EmployeType::class, new Employe());

        return $this->render('rh/Home.html.twig', [
            'employes' => $employes,
            'employe_select'=> $employe,
            'form' => $form->createView(),
            'form_ajout'=> $formAjout->createView(),
            'role'=> $session->get('employe_role'),
            'email' => $session->get('employe_email'),
            'search' => $request->query->get('search', ''),
            'selected_role'=> $request->query->get('role', ''),
        ]);
    }
    #[Route('/employe/ajouter', name: 'employe_ajouter', methods: ['GET', 'POST'])]
    public function ajouter(Request $request,EntityManagerInterface $em,SessionInterface $session,EmployeRepository $employeRepo,EntrepriseRepository $entrepriseRepo,PasswordGenerator $passwordGenerator,): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = new Employe();
        $form = $this->createForm(EmployeType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $idEntreprise = $session->get('employe_id_entreprise');
            $entreprise   = $entrepriseRepo->find($idEntreprise);
            $employe->setEntreprise($entreprise);

            $em->persist($employe);
            $compte = new Compte();
            $compte->setMot_de_passe($passwordGenerator->generate());
            $compte->setEmployé($employe);
            $em->persist($compte);
            $em->flush();
            $this->addFlash('success', 'Employé ajouté avec succès.');
            return $this->redirectToRoute('RH_Home');
        }

        $idEntreprise = $session->get('employe_id_entreprise');
        $entreprise = $entrepriseRepo->find($idEntreprise);
        $employes = $employeRepo->findBy(['entreprise' => $entreprise]);

        return $this->render('rh/Home.html.twig', [
            'employes'=> $employes,
            'form_ajout'=> $form->createView(),
            'show_ajout'=> true,
            'role' => $session->get('employe_role'),
            'email'=> $session->get('employe_email'),
            'search'=> $request->query->get('search', ''),
            'selected_role'  => $request->query->get('role', ''),
        ]);
    }
    #[Route('/employe/{id}/supprimer', name: 'employe_supprimer', methods: ['POST'])]
    public function supprimer(int $id,EmployeRepository $employeRepo,EntityManagerInterface $em,SessionInterface $session): Response {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $employe = $employeRepo->find($id);
        if (!$employe) {
            throw $this->createNotFoundException('Employé introuvable.');
        }

        foreach ($employe->getComptes() as $compte) {
            $em->remove($compte);
        }

        $em->remove($employe);
        $em->flush();

        $this->addFlash('success', 'Employé supprimé avec succès.');
        return $this->redirectToRoute('RH_Home');
    }
}