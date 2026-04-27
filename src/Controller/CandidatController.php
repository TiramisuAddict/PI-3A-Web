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
use App\Service\GoogleMeetService;
use App\Service\GoogleTokenSessionService;
use Psr\Log\LoggerInterface;

final class CandidatController extends AbstractController
{
    #[Route('/candidat', name: 'app_candidat')]
    public function index(): Response
    {
        return $this->render('candidat/index.html.twig', [
            'controller_name' => 'CandidatController',
        ]);
    }
    #[Route('/suivre', name: 'app_suivre_candidature')]
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
        SessionInterface $session,
        int $offreId,
        int $visiteurId
    ): Response {
        $sessionVisiteurId = (int) $session->get('visiteur_id', 0);
        $visiteurLoggedIn = $session->get('visiteur_logged_in') === true;

        if (!$visiteurLoggedIn || $sessionVisiteurId <= 0) {
            return new Response('Session visiteur requise.', Response::HTTP_FORBIDDEN);
        }

        if ($sessionVisiteurId !== $visiteurId) {
            return new Response('Visiteur invalide pour cette session.', Response::HTTP_FORBIDDEN);
        }

        $offre = $offreRepository->find($offreId);
        $visiteur = $visiteurRepository->find($sessionVisiteurId);

        if (!$offre || !$visiteur) {
            return new Response('Offre ou visiteur introuvable.', Response::HTTP_NOT_FOUND);
        }

        $candidat = new Candidat();
        $form = $this->createForm(PostulerType::class, $candidat, [
            'action' => $this->generateUrl('app_candidature_postuler', [
                'offreId' => $offreId,
                'visiteurId' => $sessionVisiteurId,
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
        $matchingService = new \App\Service\ContextMatchingService();
        
        $numberOfWantedCandidats = $request->query->getInt('numberOfWantedCandidats', 10000);
        if ($numberOfWantedCandidats <= 0) { $numberOfWantedCandidats = 10000; }
        $i = 0;

        $offre = $doctrine->getRepository(Offre::class)->find($offreId);

        $offreDescription = $offre->getDescription();
        $processedOffreDescription = $matchingService->preprocessRichText($offreDescription);

        $offreCandidats = $offre->getCandidats();
        $scoredCandidats = [];

        foreach ($offreCandidats as $candidat){
            $pdfcontent = $candidat->getCvData();
            $textFromPdf = $matchingService->extractTextFromPDF($pdfcontent);
            
            $matchingResult = $matchingService->match($processedOffreDescription, $textFromPdf);
            $candidat->setScore($matchingResult);

            $scoredCandidats[] = [
                'candidat' => $candidat,
                'score' => $matchingResult,
            ];

            $entityManager->persist($candidat);
        }

        usort($scoredCandidats, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        foreach ($scoredCandidats as $scoredCandidat) {
            /** @var Candidat $candidat */
            $candidat = $scoredCandidat['candidat'];
            $matchingResult = $scoredCandidat['score'];

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

    #[Route('/candidats/meeting/create', name: 'app_candidature_create_meeting', methods: ['POST'])]
    public function createMeeting(Request $request, ManagerRegistry $doctrine, GoogleMeetService $googleMeetService, GoogleTokenSessionService $GoogleTokenSessionService, LoggerInterface $logger): Response {
        
        if (!$GoogleTokenSessionService->isLinked()) {
            $this->addFlash('error', 'Vous devez lier un compte Google pour créer une réunion Meet.');
            return $this->redirectToRoute('app_candidat_dashboard');
        }

        $offreId = $request->request->getInt('offreId', 0);
        $candidatId = $request->request->getInt('candidatId', 0);
        $meetingDate = trim((string) $request->request->get('meetingDate', ''));
        $meetingTime = trim((string) $request->request->get('meetingTime', ''));

        $entityManager = $doctrine->getManager();
        $candidat = $doctrine->getRepository(Candidat::class)->find($candidatId);

        if (!$candidat) {
            $this->addFlash('error', 'Candidat introuvable.');
            return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId]);
        }

        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $meetingTime, new \DateTimeZone('Europe/Paris'));
        if (!$start instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'Date ou heure de reunion invalide.');
            return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId, 'candidatId' => $candidatId]);
        }

        $end = $start->modify('+1 hour');

        try {
            $candidateName = trim(($candidat->getVisiteur()?->getNom() ?? '') . ' ' . ($candidat->getVisiteur()?->getPrenom() ?? ''));
            $visitorEmailRaw = $candidat->getVisiteur()?->getEmail();
            $visitorEmail = is_string($visitorEmailRaw) ? mb_strtolower(trim($visitorEmailRaw)) : null;

            if (!$visitorEmail || !filter_var($visitorEmail, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Invitation non envoyee: email candidat invalide ou manquant.');
                return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId, 'candidatId' => $candidatId]);
            }

            $meeting = $googleMeetService->createMeetEvent(
                'Entretien candidat - ' . ($candidateName !== '' ? $candidateName : 'Momentum'),
                $start,
                $end,
                'primary',
                'Europe/Paris',
                'Entretien de recrutement',
                $visitorEmail
            );

            if (!$this->appendMeetingLinkToCandidateNote($candidat, $meeting, $visitorEmail)) {
                $this->addFlash('error', 'Réunion créée sans lien exploitable.');
                return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId, 'candidatId' => $candidatId]);
            }

            $entityManager->persist($candidat);
            $entityManager->flush();

            $this->addFlash('success', 'Lien Meet ajouté à la note du candidat.');
        } catch (\Throwable $e) {
            $logger->error('Meeting creation failed', [
                'exception' => $e,
                'offreId' => $offreId,
                'candidatId' => $candidatId,
            ]);
            $this->addFlash('error', 'Impossible de créer la réunion Meet pour le moment.');
        }

        return $this->redirectToRoute('app_candidat_dashboard', ['offreId' => $offreId, 'candidatId' => $candidatId]);
    }

    private function appendMeetingLinkToCandidateNote(Candidat $candidat, array $meeting, ?string $visitorEmail): bool {
        $meetingLink = is_string($meeting['meetLink'] ?? null) && $meeting['meetLink'] !== ''
            ? $meeting['meetLink']
            : (is_string($meeting['htmlLink'] ?? null) ? $meeting['htmlLink'] : '');

        if ($meetingLink === '') { return false; }
        
        $existingNote = trim((string) ($candidat->getNote() ?? ''));
        $line = sprintf(
            'Meeting link [%s]: %s%s',
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i'),
            $meetingLink,
            is_string($visitorEmail) && $visitorEmail !== '' ? ' | invite sent to ' . $visitorEmail : ''
        );

        $candidat->setNote($existingNote === '' ? $line : ($existingNote . PHP_EOL . $line));

        return true;
    }

    #[Route('/recrutement/dashboard/statistiques', name: 'app_recrutement_statistiques')]
    public function statNav(Request $request, CandidatRepository $candidat_repository, OffreRepository $offre_repository, SessionInterface $session): Response {
        $candidats = $candidat_repository->findAll();
        $offres = $offre_repository->findAll();

        $selectedOffreId = $request->query->getInt('offreId', 0);
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

        $selectedOffreCandidats = [];
        if ($selectedOffre) {
            $selectedOffreCandidats = $candidat_repository->findByOffreId($selectedOffre->getId());
        }

        $totalOffres = count($offres);
        $offresOuvertes = 0;

        foreach ($offres as $offre) {
            $etat = strtoupper((string) $offre->getEtat());
            if ($etat === 'OUVERT') {
                $offresOuvertes++;
            }
        }

        $statsEtat = [
            'En attente' => 0,
            'Preselectionne' => 0,
            'Acceptee' => 0,
            'Refuse' => 0,
            'Autres' => 0,
        ];

        foreach ($candidats as $candidat) {
            $etat = mb_strtolower(trim((string) $candidat->getEtat()));

            if (str_contains($etat, 'attente')) {
                $statsEtat['En attente']++;
                continue;
            }

            if (str_contains($etat, 'preselection') || str_contains($etat, 'presel')) {
                $statsEtat['Preselectionne']++;
                continue;
            }

            if (str_contains($etat, 'accept')) {
                $statsEtat['Acceptee']++;
                continue;
            }

            if (str_contains($etat, 'refus')) {
                $statsEtat['Refuse']++;
                continue;
            }

            $statsEtat['Autres']++;
        }

        usort($selectedOffreCandidats, fn (Candidat $a, Candidat $b) =>
            strcmp(
                $a->getDateCandidature()?->format('Y-m-d') ?? '',
                $b->getDateCandidature()?->format('Y-m-d') ?? ''
            )
        );

        $candidaturesByDay = [];
        $scoreDistribution = [0, 0, 0, 0, 0];

        foreach ($selectedOffreCandidats as $candidat) {
            $dateLabel = $candidat->getDateCandidature()?->format('Y-m-d') ?? 'Sans date';

            if (!array_key_exists($dateLabel, $candidaturesByDay)) {
                $candidaturesByDay[$dateLabel] = 0;
            }
            $candidaturesByDay[$dateLabel]++;

            $score = $candidat->getScore();
            if ($score === null) {
                continue;
            }

            if ($score < 0.2) {
                $scoreDistribution[0]++;
            } elseif ($score < 0.4) {
                $scoreDistribution[1]++;
            } elseif ($score < 0.6) {
                $scoreDistribution[2]++;
            } elseif ($score < 0.8) {
                $scoreDistribution[3]++;
            } else {
                $scoreDistribution[4]++;
            }
        }

        return $this->render('candidat/statistics_page.html.twig', [
            'offres' => $offres,
            'candidats' => $candidats,
            'selectedOffreId' => $selectedOffreId,
            'selectedOffre' => $selectedOffre,
            'totalOffres' => $totalOffres,
            'offresOuvertes' => $offresOuvertes,
            'totalCandidats' => count($candidats),
            'statsEtat' => $statsEtat,
            'chartCandidatureLabels' => array_keys($candidaturesByDay),
            'chartCandidatureValues' => array_values($candidaturesByDay),
            'chartScoreDistributionLabels' => ['0.0-0.2', '0.2-0.4', '0.4-0.6', '0.6-0.8', '0.8-1.0'],
            'chartScoreDistributionValues' => $scoreDistribution,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

}
