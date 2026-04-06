<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Offre;
use App\Form\CreeOffreType;

use App\Repository\OffreRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OffreController extends AbstractController
{
    #[Route('/offre', name: 'app_offre')]
    public function index(): Response
    {
        return $this->render('offre/index.html.twig', [
            'controller_name' => 'OffreController',
        ]);
    }
    
    //Offres
    #[Route('/offre/home', name: 'app_offre_home')]
    public function home(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/home_page.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    //Liste des offres
    #[Route('/offre/list', name: 'app_offre_list')]
    public function listOffres(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        return $this->render('offre/index.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    //dashboard_offre_hr
    #[Route('/offre/dashboard', name: 'app_offre_dashboard')]
    public function dashboard(OffreRepository $offre_repository){
        $offres = $offre_repository->findAll();
        $form = $this->createForm(CreeOffreType::class, new Offre());

        return $this->render('offre/dashboard_offre_hr.html.twig' , [ //
            'offres' => $offres,
            'form' => $form->createView(),
        ]);
    }

    // Save offer (create or update)
    #[Route('/offre/save', name: 'app_offre_save', methods: ['POST'])]
    public function saveOffre(Request $request, EntityManagerInterface $doctrine, OffreRepository $offre_repository){
        $payload = $request->request->all();
        $offre_id = $payload['cree_offre']['id'] ?? null;

        if ($offre_id) {
            $offre = $offre_repository->find($offre_id);
            if (!$offre) {
                $this->addFlash('error', 'Offre non trouvée.');
                return new RedirectResponse($this->generateUrl('app_offre_dashboard'));
            }
        } else {
            $offre = new Offre();
            $offre->setIdEmployer(119);
        }

        $form = $this->createForm(CreeOffreType::class, $offre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($offre->getTypeContrat() === 'CVP') {
                $offre->setTypeContrat('CIVP');
            }
            if ($offre->getTypeContrat() === 'Stage') {
                $offre->setTypeContrat('STAGE');
            }
            if ($offre->getEtat() === 'Ouvert') {
                $offre->setEtat('OUVERT');
            }
            if ($offre->getEtat() === 'Fermé') {
                $offre->setEtat('FERMÉ');
            }

            try {
                $doctrine->persist($offre);
                $doctrine->flush();

                $message = $offre_id ? 'Offre mise à jour avec succès.' : 'Offre créée avec succès.';
                $this->addFlash('success', $message);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur DB: ' . $e->getMessage());
            }
        } elseif ($form->isSubmitted()) {
            foreach ($form->getErrors(true, true) as $error) {
                $origin = $error->getOrigin();
                $field = $origin ? $origin->getName() : 'formulaire';
                $this->addFlash('error', sprintf('%s: %s', $field, $error->getMessage()));
            }
            if (!$form->getErrors(true, true)->count()) {
                $this->addFlash('error', 'Erreur lors de l\'enregistrement de l\'offre.');
            }
        } else {
            $this->addFlash('error', 'Formulaire non soumis.');
        }

        return new RedirectResponse($this->generateUrl('app_offre_dashboard'));
    }

    // Delete offer
    #[Route('/offre/delete/{id}', name: 'app_offre_delete', methods: ['POST', 'DELETE'])]
    public function deleteOffre($id, OffreRepository $offre_repository, EntityManagerInterface $doctrine){
        $offre = $offre_repository->find($id);
        
        if (!$offre) {
            $this->addFlash('error', 'Offre non trouvée.');
            return new RedirectResponse($this->generateUrl('app_offre_dashboard'));
        }

        $doctrine->remove($offre);
        $doctrine->flush();

        $this->addFlash('success', 'Offre supprimée avec succès.');
        
        return new RedirectResponse($this->generateUrl('app_offre_dashboard'));
    }
    
}
