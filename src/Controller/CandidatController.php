<?php

namespace App\Controller;

use App\Entity\Candidat;
use App\Entity\Offre;

use App\Form\PostulerType;
use App\Repository\CandidatRepository;
use App\Repository\OffreRepository;
use App\Repository\VisiteurRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

use App\Form\CandidatType;

final class CandidatController extends AbstractController
{
    #[Route('/candidat', name: 'app_candidat')]
    public function index(): Response
    {
        return $this->render('candidat/index.html.twig', [
            'controller_name' => 'CandidatController',
        ]);
    }
    #[Route('/candidature/suivre', name: 'app_suivre_candidature')]
    public function suivre_candidature(CandidatRepository $candidat_repository): Response
    {
        $candidatures = $candidat_repository->findAll();

        return $this->render('candidat/suivre_candidature_page.html.twig', [
            'candidatures' => $candidatures,
        ]);
    }

    #[Route('/candidats/dashboard', name: 'app_candidat_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, OffreRepository $offreRepository, CandidatRepository $candidatRepository, SessionInterface $session): Response
    {
        $offres = $offreRepository->findAll();

        $selectedOffreId = $request->query->getInt('offreId', 0);
        $selectedCandidatId = $request->query->getInt('candidatId', 0);

        $selectedOffre = null;
        foreach ($offres as $offre) {
            if ($offre->getId() === $selectedOffreId) {
                $selectedOffre = $offre;
                break;
            }
        }

        if (!$selectedOffre && !empty($offres)) {
            $selectedOffre = $offres[0];
            $selectedOffreId = $selectedOffre->getId() ?? 0;
        }

        $candidats = $selectedOffre
            ? $candidatRepository->findByOffreId((int) $selectedOffre->getId()) : [];

        $selectedCandidat = null;
        foreach ($candidats as $candidat) {
            if ($candidat->getId() === $selectedCandidatId) {
                $selectedCandidat = $candidat;
                break;
            }
        }

        if (!$selectedCandidat && !empty($candidats)) {
            $selectedCandidat = $candidats[0];
            $selectedCandidatId = $selectedCandidat->getId() ?? 0;
        }

        return $this->render('candidat/dashboard_candidat_hr.html.twig', [
            'offres' => $offres,
            'selectedOffre' => $selectedOffre,
            'selectedOffreId' => $selectedOffreId,
            'candidats' => $candidats,
            'selectedCandidat' => $selectedCandidat,
            'selectedCandidatId' => $selectedCandidatId,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/candidature/confirmation/{code}', name: 'app_candidature_confirmation', methods: ['GET'])]
    public function confirmation(CandidatRepository $candidatRepository, string $code): Response
    {
        $candidature = $candidatRepository->findOneBy(['code_candidature' => $code]);

        if (!$candidature) {
            throw $this->createNotFoundException('Candidature introuvable.');
        }

        return $this->render('candidat/confirmation_candidature.html.twig', [
            'code' => $code,
            'candidature' => $candidature,
        ]);
    }

    #[Route('/candidature/{id}/cv', name: 'app_candidature_download_cv', methods: ['GET'])]
    public function downloadCv(CandidatRepository $candidatRepository, int $id): Response
    {
        $candidat = $candidatRepository->find($id);
        $cvData = $candidat?->getCvData();

        if (!$candidat || !$cvData) {
            throw $this->createNotFoundException('CV introuvable.');
        }

        $filename = $candidat->getCvNom() ?: ('cv-candidat-' . $id . '.pdf');

        $response = new Response($cvData);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');

        return $response;
    }

    #[Route('/candidature/{id}/lettre-motivation', name: 'app_candidature_download_lettre', methods: ['GET'])]
    public function downloadLettreMotivation(CandidatRepository $candidatRepository, int $id): Response
    {
        $candidat = $candidatRepository->find($id);
        $lettreData = $candidat?->getLettreMotivationData();

        if (!$candidat || !$lettreData) {
            throw $this->createNotFoundException('Lettre de motivation introuvable.');
        }

        $filename = $candidat->getLettreMotivationNom() ?: ('lettre-motivation-candidat-' . $id . '.pdf');

        $response = new Response($lettreData);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"');

        return $response;
    }

    #[Route('/candidature/postuler/{offreId}/{visiteurId}', name: 'app_candidature_postuler', methods: ['GET', 'POST'])]
    public function postuler(
        Request $request,
        ManagerRegistry $doctrine,
        OffreRepository $offreRepository,
        VisiteurRepository $visiteurRepository,
        int $offreId,
        int $visiteurId
    ): Response {
        $offre = $offreRepository->find($offreId);
        $visiteur = $visiteurRepository->find($visiteurId);

        if (!$offre || !$visiteur) {
            return new Response('Offre ou visiteur introuvable.', Response::HTTP_NOT_FOUND);
        }

        $candidat = new Candidat();
        $form = $this->createForm(PostulerType::class, $candidat, [
            'action' => $this->generateUrl('app_candidature_postuler', [
                'offreId' => $offreId,
                'visiteurId' => $visiteurId,
            ]),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cvFile = $form->get('cv_data')->getData();
            $lettreFile = $form->get('lettre_motivation_data')->getData();

            if (!$cvFile) {
                $this->addFlash('error', 'Le CV est requis.');
                return $this->redirectToRoute('app_offre_list');
            }

            $cvContent = file_get_contents($cvFile->getPathname());
            $lettreContent = null;

            if ($lettreFile) {
                $lettreContent = file_get_contents($lettreFile->getPathname());
            }

            if ($cvContent === false || ($lettreFile && $lettreContent === false)) {
                $this->addFlash('error', 'Impossible de lire les fichiers uploades.');
                return $this->redirectToRoute('app_offre_list');
            }

            $code = strtoupper(base_convert((string) round(microtime(true) * 1000), 10, 36));

            $candidat
                ->setCodeCandidature($code)
                ->setEtat('En attente')
                ->setNote(null)
                ->setDateCandidature(new \DateTime())
                ->setCvNom($cvFile->getClientOriginalName())
                ->setCvData($cvContent)
                ->setLettreMotivationNom($lettreFile ? $lettreFile->getClientOriginalName() : null)
                ->setLettreMotivationData($lettreContent)
                ->setOffre($offre)
                ->setVisiteur($visiteur);

            $entityManager = $doctrine->getManager();
            $entityManager->persist($candidat);
            $entityManager->flush();

            return $this->redirectToRoute('app_candidature_confirmation', [
                'code' => $code,
            ]);
        }

        return $this->render('candidat/_postuler.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route ('/candidature/modifier/{id}', name: 'app_candidature_modifier')]
    public function modifierCandidature(Request $request, ManagerRegistry $doctrine, $id){
        $candidat = $doctrine->getRepository(Candidat::class)->find($id);

        if (!$candidat) {
            throw $this->createNotFoundException('Candidature introuvable.');
        }

        $form = $this->createForm(CandidatType::class, $candidat, [
            'action' => $this->generateUrl('app_candidature_modifier', [
                'id' => $id,
            ]),
            'method' => 'POST',
        ]);
       
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $candidat = $form->getData();
            $doctrine->getManager()->flush($candidat);

            $this->addFlash('success', 'Candidature modifiee avec succes.');

            $referer = $request->headers->get('referer');
            if ($referer) {
                return $this->redirect($referer);
            }

            return $this->redirectToRoute('app_candidat_dashboard');

        }

        return $this->render('candidat/_dashboard_form_candidat_hr.html.twig', [
            'form' => $form->createView(),
            'id' => $id,
        ]);
    }

    #[Route('/candidats/matching/oid={offreId}', name: 'app_candidature_matching', methods: ['GET'])]
    public function contextMatching(ManagerRegistry $doctrine, int $offreId, Request $request): Response {
        $entityManager = $doctrine->getManager();
        $matchingService = new \App\Services\Offre\ContextMatchingService();
        
        $numberOfWantedCandidats = $request->query->getInt('numberOfWantedCandidats', 10000);
        if ($numberOfWantedCandidats <= 0) { $numberOfWantedCandidats = 10000; }
        $i = 0;

        $offre = $doctrine->getRepository(Offre::class)->find($offreId);

        $offreDescription = $offre->getDescription();
        $processedOffreDescription = $matchingService->preprocessRichText($offreDescription);

        $offreCandidats = $offre->getCandidats();
        foreach ($offreCandidats as $candidat){
            $pdfcontent = $candidat->getCvData();
            $textFromPdf = $matchingService->extractTextFromPDF($pdfcontent);
            
            $matchingResult = $matchingService->match($processedOffreDescription, $textFromPdf);
            $candidat->setScore($matchingResult);
            
            if ($matchingResult >= 0.4 && $i < $numberOfWantedCandidats) {
                $candidat->setEtat("Présélectionné");
                $i++;
            } else {
                $candidat->setEtat("Refusé");
            }
        
            $entityManager->persist($candidat);
        }
        $entityManager->flush();
        
        $this->addFlash('success', $i . ' candidat(s) présélectionné(s).');
        return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId]);
    }
}
