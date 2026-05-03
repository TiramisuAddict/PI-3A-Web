<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use App\Entity\Offre;
use App\Form\OffreType;

use App\Repository\EmployeRepository;
use App\Repository\OffreRepository;

use Doctrine\Persistence\ManagerRegistry;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
    #[Route('/accueil', name: 'app_offre_home')]
    public function home(OffreRepository $offre_repository) : Response {
        $offres = $offre_repository->findBy(['etat' => 'OUVERT'], ['id' => 'DESC'], 12);
        return $this->render('offre/home_page.html.twig' , [ //page
            'offres' => $offres
        ]);
    }

    //Liste des offres
    #[Route('/offres', name: 'app_offre_list')]
    public function listOffres(Request $request, OffreRepository $offre_repository) : Response {

        $qRaw = $request->query->get('q');
        $categoryRaw = $request->query->get('category');
        $contractRaw = $request->query->get('contract');

        $q = is_string($qRaw) ? $qRaw : (is_null($qRaw) ? null : (string)$qRaw);
        $category = is_string($categoryRaw) ? $categoryRaw : (is_null($categoryRaw) ? null : (string)$categoryRaw);
        $contract = is_string($contractRaw) ? $contractRaw : (is_null($contractRaw) ? null : (string)$contractRaw);

        $offres = $offre_repository->findByFilters($q, $category, $contract, 'OUVERT');

        $offreIds = array_values(array_filter(array_map(static fn(Offre $o): ?int => $o->getId(), $offres)));
        $candidatCounts = $offre_repository->findCandidatCountsByOffreIds($offreIds);

        return $this->render('offre/index.html.twig', [
            'offres' => $offres,
            'candidatCounts' => $candidatCounts,
        ]);
    }

    // dashboard_offre_hr
    #[Route('/offre/dashboard', name: 'app_offre_dashboard')]
    public function dashboard(Request $request, OffreRepository $offre_repository, EmployeRepository $employeRepository, SessionInterface $session): Response
    {
        $qRaw = $request->query->get('q');
        $contractRaw = $request->query->get('contract');
        $etatRaw = $request->query->get('etat');
        $categoryRaw = $request->query->get('category');

        $q = is_string($qRaw) ? $qRaw : (is_null($qRaw) ? null : (string)$qRaw);
        $contract = is_string($contractRaw) ? $contractRaw : (is_null($contractRaw) ? null : (string)$contractRaw);
        $etat = is_string($etatRaw) ? $etatRaw : (is_null($etatRaw) ? null : (string)$etatRaw);
        $category = is_string($categoryRaw) ? $categoryRaw : (is_null($categoryRaw) ? null : (string)$categoryRaw);

        $offres = $offre_repository->findByFilters($q, $category, $contract, $etat);
        $creatorIds = array_values(array_unique(array_filter(array_map(static fn (Offre $offre): ?int => $offre->getIdEmployer(), $offres), static fn (?int $id): bool => is_int($id) && $id > 0)));
        $creatorNames = [];

        if ($creatorIds !== []) {
            foreach ($employeRepository->findBy(['id_employe' => $creatorIds]) as $employe) {
                $fullName = trim(($employe->getPrenom() ?? '') . ' ' . ($employe->getNom() ?? ''));
                $creatorNames[$employe->getId_employe()] = $fullName !== '' ? $fullName : ('Employe #' . $employe->getId_employe());
            }
        }

        $form = $this->createForm(OffreType::class, new Offre());

        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'offres' => $offres,
            'creatorNames' => $creatorNames,
            'form' => $form->createView(),
            'filters' => [
                'q' => $q,
                'contract' => $contract,
                'etat' => $etat,
                'category' => $category,
            ],
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/offre/createOffre', name: 'app_offre_create', methods: ['GET', 'POST'])]
    public function createOffreForm(Request $request, ManagerRegistry $doctrine, SessionInterface $session) : Response {
        $offre = new Offre();

        $form = $this->createForm(OffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();
            $idEmploye = $session->get('employe_id');

            if (!is_int($idEmploye) || $idEmploye <= 0) {
                $this->addFlash('error', 'Session employe invalide. Veuillez vous reconnecter.');
                return $this->redirectToRoute('login');
            }

            $offre->setIdEmployer($idEmploye);

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre créée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $offres = $doctrine->getRepository(Offre::class)->findBy([], ['id' => 'DESC'], 50);
        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'form' => $form->createView(),
            'offres' => $offres,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/offre/updateOffre/{id}', name: 'app_offre_update', methods: ['GET', 'POST'])]
    public function updateOffreForm(Request $request, ManagerRegistry $doctrine, SessionInterface $session, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        if ($offre === null) {
            $this->addFlash('error', 'Offre non trouvée.');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $form = $this->createForm(OffreType::class, $offre);

        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()){
            $offre = $form->getData();

            if ($offre->getIdEmployer() === null) {
                $idEmploye = $session->get('employe_id');
                if (is_int($idEmploye) && $idEmploye > 0) {
                    $offre->setIdEmployer($idEmploye);
                }
            }

            $doctrine->getManager()->persist($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre modifiée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $offres = $doctrine->getRepository(Offre::class)->findBy([], ['id' => 'DESC'], 50);
        return $this->render('offre/dashboard_offre_hr.html.twig', [
            'form' => $form->createView(),
            'offres' => $offres,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/offre/deleteOffre/{id}', name: 'app_offre_delete', methods: ['POST'])]
    public function deleteOffreForm(Request $request, ManagerRegistry $doctrine, int $id) : Response {
        $offre = $doctrine->getRepository(Offre::class)->find($id);

        if ($offre !== null) {
            $doctrine->getManager()->remove($offre);
            $doctrine->getManager()->flush();

            $this->addFlash('success', 'Offre supprimée avec succès !');
            return $this->redirectToRoute('app_offre_dashboard');
        }

        $this->addFlash('error', 'Offre non trouvée.');
        return $this->redirectToRoute('app_offre_dashboard');
    }

}
