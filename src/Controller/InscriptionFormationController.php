<?php

namespace App\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Formation;
use App\Entity\Employe;
use App\Entity\EvaluationFormation;
use App\Entity\InscriptionFormation;
use App\Enum\StatutInscription;
use App\Repository\EvaluationFormationRepository;
use App\Repository\FormationRepository;
use App\Repository\InscriptionFormationRepository;
use App\Service\FeedbackAnalysisService;
use App\Service\ReasonAssistantService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/inscription')]
final class InscriptionFormationController extends AbstractController
{
    #[Route('/employe/certificat/public/{inscriptionId}/{expires}/{signature}', name: 'app_inscription_employe_certificate_public', methods: ['GET'])]
    public function certificatePublic(int $inscriptionId, int $expires, string $signature, InscriptionFormationRepository $inscriptionRepository, EntityManagerInterface $entityManager): Response
    {
        if ($inscriptionId <= 0) {
            return new Response('Certificat introuvable.', 404);
        }

        if ($expires <= time()) {
            return new Response('Lien QR expire. Veuillez generer un nouveau QR.', 410);
        }

        $inscription = $inscriptionRepository->find($inscriptionId);
        if (!$inscription instanceof InscriptionFormation) {
            return new Response('Certificat introuvable.', 404);
        }

        $formation = $inscription->getFormation();
        if (!$formation instanceof Formation) {
            return new Response('Formation introuvable.', 404);
        }

        if ($inscription->getStatut() !== StatutInscription::ACCEPTEE->value) {
            return new Response('Certificat indisponible.', 403);
        }

        $isFinished = $formation->getDateFin() !== null && $formation->getDateFin() < new \DateTimeImmutable();
        if (!$isFinished) {
            return new Response('Certificat disponible uniquement apres la fin de la formation.', 403);
        }

        $expectedSignature = $this->buildCertificateSignature(
            $inscriptionId,
            (int) $formation->getId(),
            $inscription->getEmployeeId(),
            $expires
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return new Response('Lien QR invalide.', 403);
        }

        return $this->buildCertificatePdfResponse($formation, $inscription->getEmployeeId(), $entityManager, true);
    }

    #[Route('/employe/certificat/{formationId}', name: 'app_inscription_employe_certificate', methods: ['GET'])]
    public function certificate(Request $request, int $formationId, InscriptionFormationRepository $inscriptionRepository, FormationRepository $formationRepository, EntityManagerInterface $entityManager): Response
    {
        $session = $request->getSession();
        $employeeId = (int) $session->get('employe_id', 0);
        $employeeRole = strtolower(trim((string) $session->get('employe_role', '')));
        $employeeLogged = $session->get('employe_logged_in') === true && in_array($employeeRole, ['employé', 'employe'], true);

        if (!$employeeLogged || $employeeId <= 0) {
            return $this->redirectToRoute('login');
        }

        if ($formationId <= 0) {
            $this->addFlash('error', 'Formation introuvable.');
            return $this->redirectToRoute('app_inscription_employe');
        }

        $formation = $formationRepository->find($formationId);
        if (!$formation instanceof Formation) {
            $this->addFlash('error', 'Formation introuvable.');
            return $this->redirectToRoute('app_inscription_employe');
        }

        $inscription = $inscriptionRepository->findOneByFormationAndEmployee($formationId, $employeeId);
        if ($inscription === null || $inscription->getStatut() !== StatutInscription::ACCEPTEE->value) {
            $this->addFlash('error', 'Certificat indisponible: inscription non acceptee.');
            return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
        }

        $isFinished = $formation->getDateFin() !== null && $formation->getDateFin() < new \DateTimeImmutable();
        if (!$isFinished) {
            $this->addFlash('error', 'Certificat disponible uniquement apres la fin de la formation.');
            return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
        }

        return $this->buildCertificatePdfResponse($formation, $employeeId, $entityManager, false);
    }

    private function buildCertificatePdfResponse(Formation $formation, int $employeeId, EntityManagerInterface $entityManager, bool $inline): Response
    {
        if ($formation->getId() === null) {
            return new Response('Formation introuvable.', 404);
        }

        $employee = $entityManager->find(Employe::class, $employeeId);
        $employeeName = trim((string) (($employee?->getPrenom() ?? '') . ' ' . ($employee?->getNom() ?? '')));
        if ($employeeName === '') {
            $employeeName = 'Employe #' . $employeeId;
        }

        $issuedAt = new \DateTimeImmutable();
        $certificateReference = sprintf('CERT-%d-%d-%s', (int) $formation->getId(), $employeeId, $issuedAt->format('YmdHis'));
        $qrPayload = implode(' | ', [
            'Reference: ' . $certificateReference,
            'Employe: ' . $employeeName,
            'Formation: ' . (string) $formation->getTitre(),
            'Organisme: ' . (string) $formation->getOrganisme(),
            'Date fin: ' . ($formation->getDateFin()?->format('d/m/Y') ?? '-'),
            'Delivre le: ' . $issuedAt->format('d/m/Y'),
        ]);
        $qrUrl = 'https://quickchart.io/qr?size=200&text=' . rawurlencode($qrPayload);

        $html = $this->renderView('inscription/certificate.html.twig', [
            'employee_name' => $employeeName,
            'formation' => $formation,
            'issued_at' => $issuedAt,
            'certificate_reference' => $certificateReference,
            'qr_url' => $qrUrl,
            'qr_enabled' => extension_loaded('gd'),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'certificat-formation-' . (int) $formation->getId() . '.pdf';
        $disposition = $inline ? 'inline' : 'attachment';

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
        ]);
    }

    #[Route('/employe/reviews/{id}', name: 'app_inscription_employe_reviews', methods: ['GET'])]
    public function getFormationReviews(int $id, FormationRepository $formationRepository, Connection $connection, FeedbackAnalysisService $feedbackAnalysisService): JsonResponse
    {
        $formation = $formationRepository->find($id);
        if ($formation === null) {
            return new JsonResponse(['error' => 'Formation not found'], 404);
        }

        $formationReviews = $connection->fetchAllAssociative(
            'SELECT ev.note, ev.commentaire, ev.date_evaluation, COALESCE(e.prenom, "") AS prenom, COALESCE(e.nom, "") AS nom
             FROM evaluation_formation ev
             LEFT JOIN employe e ON e.id_employe = ev.id_employe
             WHERE ev.id_formation = ?
             ORDER BY ev.date_evaluation DESC
             LIMIT 10',
            [$id]
        );

        $reviews = [];
        foreach ($formationReviews as $review) {
            $analysis = $feedbackAnalysisService->analyzeReview((string) ($review['commentaire'] ?? ''), (int) ($review['note'] ?? 0));
            $reviews[] = [
                'note' => (int) ($review['note'] ?? 0),
                'commentaire' => (string) ($review['commentaire'] ?? ''),
                'prenom' => (string) ($review['prenom'] ?? ''),
                'nom' => (string) ($review['nom'] ?? ''),
                'label' => $analysis['label'],
                'problems' => $analysis['problems'] ?? [],
            ];
        }

        $summary = $feedbackAnalysisService->summarizeReviews($formationReviews);

        return new JsonResponse([
            'formation' => [
                'id' => $formation->getId(),
                'titre' => $formation->getTitre(),
                'organisme' => $formation->getOrganisme(),
                'dateDebut' => $formation->getDateDebut()?->format('Y-m-d'),
                'dateFin' => $formation->getDateFin()?->format('Y-m-d'),
            ],
            'reviews' => $reviews,
            'summary' => $summary,
        ]);
    }

    #[Route('/employe/analyze', name: 'app_inscription_employe_analyze', methods: ['POST'])]
    public function analyzeReason(Request $request, FormationRepository $formationRepository, ReasonAssistantService $reasonAssistantService): JsonResponse
    {
        return $this->handleReasonAction($request, $formationRepository, $reasonAssistantService, 'analyze');
    }

    #[Route('/employe/generate', name: 'app_inscription_employe_generate', methods: ['POST'])]
    public function generateReason(Request $request, FormationRepository $formationRepository, ReasonAssistantService $reasonAssistantService): JsonResponse
    {
        return $this->handleReasonAction($request, $formationRepository, $reasonAssistantService, 'generate');
    }

    #[Route('/rh/{id}/accepter', name: 'app_inscription_rh_accept', methods: ['POST'])]
    public function accept(Request $request, int $id, InscriptionFormationRepository $inscriptionRepository, EntityManagerInterface $entityManager): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

        $inscription = $inscriptionRepository->find($id);
        if ($inscription === null) {
            $this->addFlash('error', 'Inscription introuvable.');

            return $this->redirectToRoute('app_formation_rh');
        }

        if ($inscription->getStatut() === StatutInscription::REFUSEE->value) {
            $formation = $inscription->getFormation();
            if ($formation === null) {
                $this->addFlash('error', 'Formation introuvable.');

                return $this->redirectToRoute('app_formation_rh');
            }

            $remainingCapacity = (int) ($formation->getCapacite() ?? 0);
            if ($remainingCapacity <= 0) {
                $this->addFlash('error', 'Formation complete. Aucune place disponible.');

                return $this->redirectToRoute('app_formation_rh');
            }

            $formation->setCapacite($remainingCapacity - 1);
        }

        $inscription->setStatut(StatutInscription::ACCEPTEE);
        $entityManager->flush();
        $this->addFlash('success', 'Inscription acceptee.');

        return $this->redirectToRoute('app_formation_rh');
    }

    #[Route('/rh/{id}/refuser', name: 'app_inscription_rh_refuse', methods: ['POST'])]
    public function refuse(Request $request, int $id, InscriptionFormationRepository $inscriptionRepository, EntityManagerInterface $entityManager): Response
    {
        if (($response = $this->denyUnlessRhLogged($request)) !== null) {
            return $response;
        }

        $inscription = $inscriptionRepository->find($id);
        if ($inscription === null) {
            $this->addFlash('error', 'Inscription introuvable.');

            return $this->redirectToRoute('app_formation_rh');
        }

        if ($inscription->getStatut() === StatutInscription::EN_ATTENTE->value) {
            $formation = $inscription->getFormation();
            if ($formation !== null) {
                $formation->setCapacite((int) ($formation->getCapacite() ?? 0) + 1);
            }
        }

        $inscription->setStatut(StatutInscription::REFUSEE);
        $entityManager->flush();
        $this->addFlash('success', 'Inscription refusee.');

        return $this->redirectToRoute('app_formation_rh');
    }

    #[Route('/employe', name: 'app_inscription_employe', methods: ['GET', 'POST'])]
    public function employe(Request $request, Connection $connection, FormationRepository $formationRepository, EntityManagerInterface $entityManager, InscriptionFormationRepository $inscriptionRepository, EvaluationFormationRepository $evaluationFormationRepository, ReasonAssistantService $reasonAssistantService, FeedbackAnalysisService $feedbackAnalysisService): Response
    {
        $session = $request->getSession();
        $employeeId = (int) $session->get('employe_id', 0);
        $employeeRole = strtolower(trim((string) $session->get('employe_role', '')));
        $employeeLogged = $session->get('employe_logged_in') === true && in_array($employeeRole, ['employé', 'employe'], true);

        if (!$employeeLogged) {
            if ($session->get('employe_logged_in') === true) {
                $this->addFlash('error', 'Cette page est reservee aux employes.');

                return $this->redirectToRoute('RH_Home');
            }

            return $this->redirectToRoute('login');
        }

        $analysisResult = null;
        $typedReason = '';
        $assistantNotice = null;
        $selectedFormationId = (int) $request->query->get('formation', 0);
        $search = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'date_desc');
        $minRating = (float) $request->query->get('min_rating', 0);
        $minReviews = (int) $request->query->get('min_reviews', 0);

        $allowedSorts = ['date_desc', 'rating_desc', 'reviews_desc', 'title_asc'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'date_desc';
        }

        if ($minRating < 0) {
            $minRating = 0;
        }
        if ($minRating > 5) {
            $minRating = 5;
        }
        if ($minReviews < 0) {
            $minReviews = 0;
        }

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
//ajout, annulation, modification raison, evaluation
            if ($action === 'inscrire') {
                $employeeId = (int) $session->get('employe_id', 0);
                $employeeRole = strtolower(trim((string) $session->get('employe_role', '')));
                $formationId = (int) $request->request->get('formation_id', 0);
                $raison = trim((string) $request->request->get('raison', ''));

                if ($employeeId <= 0 || !in_array($employeeRole, ['employé', 'employe'], true)) {
                    $this->addFlash('error', 'Veuillez saisir votre ID employe d abord.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                $formation = $formationRepository->find($formationId);
                if ($formation === null) {
                    $this->addFlash('error', 'Formation introuvable.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                $remainingCapacity = (int) ($formation->getCapacite() ?? 0);
                if ($remainingCapacity <= 0) {
                    $this->addFlash('error', 'Cette formation est complete.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $existing = $inscriptionRepository->findOneByFormationAndEmployee((int) $formation->getId(), $employeeId);
                if ($existing !== null) {
                    $this->addFlash('error', 'Vous etes deja inscrit a cette formation.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                if ($raison === '') {
                    $this->addFlash('error', 'Veuillez saisir une raison avant d envoyer la demande.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $analysisResult = $reasonAssistantService->correctReason($raison);
                $reasonToStore = trim($analysisResult->correctedText) !== '' ? $analysisResult->correctedText : $raison;

                $inscription = new InscriptionFormation();
                $inscription->setFormation($formation);
                $inscription->setEmployeeId($employeeId);
                $inscription->setStatut(StatutInscription::EN_ATTENTE);
                $inscription->setRaison($reasonToStore !== '' ? $reasonToStore : null);
                $formation->setCapacite($remainingCapacity - 1);

                $entityManager->persist($inscription);
                $entityManager->flush();

                $this->addFlash('success', 'Inscription envoyee avec succes.');

                return $this->redirectToRoute('app_inscription_employe');
            }

            if ($action === 'annuler') {
                $employeeId = (int) $session->get('employe_id', 0);
                $inscriptionId = (int) $request->request->get('inscription_id', 0);

                if ($employeeId <= 0 || $inscriptionId <= 0) {
                    $this->addFlash('error', 'Annulation impossible.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                $inscription = $inscriptionRepository->find($inscriptionId);
                if ($inscription === null || $inscription->getEmployeeId() !== $employeeId) {
                    $this->addFlash('error', 'Inscription introuvable pour cet employe.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                if ($inscription->getStatut() !== StatutInscription::EN_ATTENTE->value) {
                    $this->addFlash('error', 'Seule une inscription en attente peut etre annulee.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                $formation = $inscription->getFormation();
                if ($formation !== null) {
                    $formation->setCapacite((int) ($formation->getCapacite() ?? 0) + 1);
                }

                $entityManager->remove($inscription);
                $entityManager->flush();
                $this->addFlash('success', 'Inscription annulee.');

                return $this->redirectToRoute('app_inscription_employe');
            }

            if ($action === 'modifier_raison') {
                $employeeId = (int) $session->get('employe_id', 0);
                $inscriptionId = (int) $request->request->get('inscription_id', 0);
                $formationId = (int) $request->request->get('formation_id', 0);
                $raison = trim((string) $request->request->get('raison', ''));

                if ($employeeId <= 0 || $inscriptionId <= 0 || $formationId <= 0) {
                    $this->addFlash('error', 'Modification impossible.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                if ($raison === '') {
                    $this->addFlash('error', 'Veuillez saisir une raison avant de modifier la demande.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $inscription = $inscriptionRepository->find($inscriptionId);
                if ($inscription === null || $inscription->getEmployeeId() !== $employeeId) {
                    $this->addFlash('error', 'Inscription introuvable pour cet employe.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                if ($inscription->getStatut() !== StatutInscription::EN_ATTENTE->value) {
                    $this->addFlash('error', 'Vous ne pouvez modifier la raison que pour une demande en attente.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $analysisResult = $reasonAssistantService->correctReason($raison);
                $reasonToStore = trim($analysisResult->correctedText) !== '' ? $analysisResult->correctedText : $raison;

                $inscription->setRaison($reasonToStore !== '' ? $reasonToStore : null);
                $entityManager->flush();

                $this->addFlash('success', 'Raison modifiee avec succes.');

                return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
            }

            if ($action === 'evaluer') {
                $employeeId = (int) $session->get('employe_id', 0);
                $employeeRole = strtolower(trim((string) $session->get('employe_role', '')));
                $formationId = (int) $request->request->get('formation_id', 0);
                $note = (int) $request->request->get('note', 0);
                $commentaire = trim((string) $request->request->get('commentaire', ''));

                if ($employeeId <= 0 || !in_array($employeeRole, ['employé', 'employe'], true)) {
                    $this->addFlash('error', 'Veuillez saisir votre ID employe d abord.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                if ($formationId <= 0) {
                    $this->addFlash('error', 'Formation introuvable.');

                    return $this->redirectToRoute('app_inscription_employe');
                }

                if ($note < 1 || $note > 5) {
                    $this->addFlash('error', 'La note doit etre comprise entre 1 et 5.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $inscription = $inscriptionRepository->findOneByFormationAndEmployee($formationId, $employeeId);
                if ($inscription === null || $inscription->getStatut() !== StatutInscription::ACCEPTEE->value) {
                    $this->addFlash('error', 'Vous ne pouvez evaluer qu une formation acceptee.');

                    return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
                }

                $evaluation = $evaluationFormationRepository->findOneByFormationAndEmploye($formationId, $employeeId);
                if ($evaluation === null) {
                    $evaluation = new EvaluationFormation();
                    $evaluation->setFormation($inscription->getFormation());
                    $evaluation->setEmploye($entityManager->getReference(Employe::class, $employeeId));
                    $entityManager->persist($evaluation);
                }

                $evaluation
                    ->setNote($note)
                    ->setCommentaire($commentaire !== '' ? $commentaire : null)
                    ->setDateEvaluation(new \DateTime());

                $entityManager->flush();
                $this->addFlash('success', 'Evaluation enregistree avec succes.');

                return $this->redirectToRoute('app_inscription_employe', ['formation' => $formationId]);
            }
        }
        $selectedFormation = $selectedFormationId > 0 ? $formationRepository->find($selectedFormationId) : null;

        $formations = $formationRepository->findBy([], ['dateDebut' => 'DESC']);

        $reviewStatsRows = $connection->fetchAllAssociative(
            'SELECT ev.id_formation AS formation_id, COUNT(ev.id_evaluation) AS reviews_count, ROUND(AVG(ev.note), 2) AS average_note
             FROM evaluation_formation ev
             GROUP BY ev.id_formation'
        );

        $reviewStatsByFormation = [];
        foreach ($reviewStatsRows as $row) {
            $formationId = (int) ($row['formation_id'] ?? 0);
            if ($formationId <= 0) {
                continue;
            }

            $reviewStatsByFormation[$formationId] = [
                'reviews_count' => (int) ($row['reviews_count'] ?? 0),
                'average_note' => (float) ($row['average_note'] ?? 0),
            ];
        }

        $searchLower = mb_strtolower($search);

        $formations = array_values(array_filter($formations, function (Formation $formation) use ($reviewStatsByFormation, $minRating, $minReviews, $searchLower): bool {
            $formationId = $formation->getId();
            if ($formationId === null) {
                return false;
            }

            if ($searchLower !== '') {
                $haystack = mb_strtolower(trim(implode(' ', [
                    (string) $formation->getTitre(),
                    (string) $formation->getOrganisme(),
                    (string) $formation->getLieu(),
                ])));

                if (!str_contains($haystack, $searchLower)) {
                    return false;
                }
            }

            $stats = $reviewStatsByFormation[$formationId] ?? ['reviews_count' => 0, 'average_note' => 0.0];

            if ($stats['average_note'] < $minRating) {
                return false;
            }

            return $stats['reviews_count'] >= $minReviews;
        }));

        usort($formations, function (Formation $left, Formation $right) use ($sort, $reviewStatsByFormation): int {
            $leftStats = $reviewStatsByFormation[(int) $left->getId()] ?? ['reviews_count' => 0, 'average_note' => 0.0];
            $rightStats = $reviewStatsByFormation[(int) $right->getId()] ?? ['reviews_count' => 0, 'average_note' => 0.0];

            return match ($sort) {
                'rating_desc' => ($rightStats['average_note'] <=> $leftStats['average_note'])
                    ?: ($rightStats['reviews_count'] <=> $leftStats['reviews_count'])
                    ?: strcmp((string) $left->getTitre(), (string) $right->getTitre()),
                'reviews_desc' => ($rightStats['reviews_count'] <=> $leftStats['reviews_count'])
                    ?: ($rightStats['average_note'] <=> $leftStats['average_note'])
                    ?: strcmp((string) $left->getTitre(), (string) $right->getTitre()),
                'title_asc' => strcmp((string) $left->getTitre(), (string) $right->getTitre()),
                default => (($right->getDateDebut()?->getTimestamp() ?? 0) <=> ($left->getDateDebut()?->getTimestamp() ?? 0)),
            };
        });

        $formationSummary = [
            'total' => count($formations),
            'available' => 0,
            'full' => 0,
            'finished' => 0,
        ];
        foreach ($formations as $formation) {
            $isFinished = $formation->getDateFin() !== null && $formation->getDateFin() < new \DateTimeImmutable();
            $isFull = ($formation->getCapacite() ?? 0) <= 0;

            if ($isFinished) {
                $formationSummary['finished']++;
                continue;
            }

            if ($isFull) {
                $formationSummary['full']++;
                continue;
            }

            $formationSummary['available']++;
        }

        $formationReviews = [];
        $formationReviewSummary = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'problem' => 0,
            'total' => 0,
        ];
        if ($selectedFormation instanceof Formation && $selectedFormation->getId() !== null) {
            $formationReviews = $connection->fetchAllAssociative(
                'SELECT ev.note, ev.commentaire, ev.date_evaluation, COALESCE(e.prenom, "") AS prenom, COALESCE(e.nom, "") AS nom
                 FROM evaluation_formation ev
                 LEFT JOIN employe e ON e.id_employe = ev.id_employe
                 WHERE ev.id_formation = ?
                 ORDER BY ev.date_evaluation DESC
                 LIMIT 10',
                [(int) $selectedFormation->getId()]
            );

            foreach ($formationReviews as $index => $review) {
                $analysis = $feedbackAnalysisService->analyzeReview((string) ($review['commentaire'] ?? ''), (int) ($review['note'] ?? 0));
                $formationReviews[$index]['analysis'] = $analysis;
            }

            $formationReviewSummary = $feedbackAnalysisService->summarizeReviews($formationReviews);
        }

        $inscriptionByFormation = [];
        $evaluationByFormation = [];
        $certificateQrPathByFormation = [];
        if ($employeeLogged) {
            $myInscriptions = $inscriptionRepository->findBy(['employeeId' => $employeeId]);
            foreach ($myInscriptions as $inscription) {
                try {
                    $formation = $inscription->getFormation();
                } catch (EntityNotFoundException $exception) {
                    // The related formation was removed; skip this orphan inscription safely.
                    continue;
                }

                try {
                    if ($formation !== null && $formation->getId() !== null) {
                        $formationId = (int) $formation->getId();
                        $inscriptionId = (int) ($inscription->getId() ?? 0);

                        $inscriptionByFormation[$formationId] = [
                            'id' => $inscription->getId(),
                            'statut' => $inscription->getStatut(),
                            'raison' => $inscription->getRaison(),
                        ];

                        $isFinished = $formation->getDateFin() !== null && $formation->getDateFin() < new \DateTimeImmutable();
                        if ($inscriptionId > 0 && $inscription->getStatut() === StatutInscription::ACCEPTEE->value && $isFinished) {
                            $expires = (new \DateTimeImmutable('+30 days'))->getTimestamp();
                            $signature = $this->buildCertificateSignature($inscriptionId, $formationId, $inscription->getEmployeeId(), $expires);
                            $certificateQrPathByFormation[$formationId] = $this->generateUrl('app_inscription_employe_certificate_public', [
                                'inscriptionId' => $inscriptionId,
                                'expires' => $expires,
                                'signature' => $signature,
                            ]);
                        }
                    }
                } catch (EntityNotFoundException $exception) {
                    continue;
                }
            }

            $myEvaluations = $evaluationFormationRepository->findByEmployeId($employeeId);
            foreach ($myEvaluations as $evaluation) {
                try {
                    $formation = $evaluation->getFormation();
                } catch (EntityNotFoundException $exception) {
                    continue;
                }

                if ($formation !== null && $formation->getId() !== null) {
                    $evaluationByFormation[(int) $formation->getId()] = [
                        'id' => $evaluation->getId(),
                        'note' => $evaluation->getNote(),
                        'commentaire' => $evaluation->getCommentaire(),
                    ];
                }
            }
        }

        $alreadyInscrit = false;
        if ($employeeLogged && $selectedFormation instanceof Formation) {
            $alreadyInscrit = $inscriptionRepository->findOneByFormationAndEmployee((int) $selectedFormation->getId(), $employeeId) !== null;
        }

        $qrPublicBaseUrl = trim((string) ($_ENV['QR_PUBLIC_BASE_URL'] ?? $_SERVER['QR_PUBLIC_BASE_URL'] ?? getenv('QR_PUBLIC_BASE_URL') ?: ''));
        if ($qrPublicBaseUrl !== '') {
            $qrPublicBaseUrl = rtrim($qrPublicBaseUrl, '/');
        }

        return $this->render('inscription/employe.html.twig', [
            'formations' => $formations,
            'employee_logged' => $employeeLogged,
            'employee_id' => $employeeId,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
            'formation_summary' => $formationSummary,
            'formation_review_summary' => $formationReviewSummary,
            'selected_formation' => $selectedFormation,
            'already_inscrit' => $alreadyInscrit,
            'inscription_by_formation' => $inscriptionByFormation,
            'evaluation_by_formation' => $evaluationByFormation,
            'review_stats_by_formation' => $reviewStatsByFormation,
            'formation_reviews' => $formationReviews,
            'q' => $search,
            'sort' => $sort,
            'min_rating' => $minRating,
            'min_reviews' => $minReviews,
            'analysis_result' => $analysisResult,
            'typed_reason' => $typedReason,
            'assistant_notice' => $assistantNotice,
            'qr_public_base_url' => $qrPublicBaseUrl,
            'certificate_qr_path_by_formation' => $certificateQrPathByFormation,
        ]);
    }

    private function buildCertificateSignature(int $inscriptionId, int $formationId, int $employeeId, int $expires): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET') ?: 'change-me-app-secret');
        $payload = $inscriptionId . '|' . $formationId . '|' . $employeeId . '|' . $expires;

        return hash_hmac('sha256', $payload, $secret);
    }

    private function handleReasonAction(Request $request, FormationRepository $formationRepository, ReasonAssistantService $reasonAssistantService, string $type): JsonResponse
    {
        $session = $request->getSession();
        $employeeId = (int) $session->get('employe_id', 0);
        $employeeRole = strtolower(trim((string) $session->get('employe_role', '')));
        $formationId = (int) $request->request->get('formation_id', 0);
        $reason = trim((string) $request->request->get('raison', ''));

        if ($employeeId <= 0 || !in_array($employeeRole, ['employé', 'employe'], true)) {
            return $this->json([
                'ok' => false,
                'message' => 'Veuillez saisir votre ID employe d abord.',
            ], 403);
        }

        if ($formationId <= 0) {
            return $this->json([
                'ok' => false,
                'message' => 'Formation introuvable.',
            ], 404);
        }

        $formation = $formationRepository->find($formationId);
        if ($formation === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Formation introuvable.',
            ], 404);
        }

        if ($reason === '') {
            return $this->json([
                'ok' => false,
                'message' => 'Veuillez saisir une raison avant de lancer l action.',
            ], 422);
        }

        if ($type === 'analyze') {
            $analysisResult = $reasonAssistantService->correctReason($reason);
            $text = $analysisResult->correctedText !== '' ? $analysisResult->correctedText : $reason;

            return $this->json([
                'ok' => true,
                'message' => 'LanguageTool a propose une version corrigee.',
                'text' => $text,
                'analysis' => [
                    'language' => $analysisResult->language,
                    'grammarMessages' => $analysisResult->grammarMessages,
                    'originalText' => $analysisResult->originalText,
                    'correctedText' => $analysisResult->correctedText,
                    'generatedText' => $analysisResult->generatedText,
                ],
            ]);
        }

        $analysisResult = $reasonAssistantService->generateReason($reason, (string) $formation->getTitre());
        $text = $analysisResult->generatedText !== '' ? $analysisResult->generatedText : $reason;
        $message = $analysisResult->generatedText !== ''
            ? 'Hugging Face a genere un paragraphe complet.'
            : 'Hugging Face est indisponible ou le token est invalide. Texte original conserve.';

        return $this->json([
            'ok' => true,
            'message' => $message,
            'text' => $text,
            'analysis' => [
                'language' => $analysisResult->language,
                'grammarMessages' => $analysisResult->grammarMessages,
                'originalText' => $analysisResult->originalText,
                'correctedText' => $analysisResult->correctedText,
                'generatedText' => $analysisResult->generatedText,
            ],
        ]);
    }

    private function denyUnlessRhLogged(Request $request): ?Response
    {
        $session = $request->getSession();
        $role = strtolower(trim((string) $session->get('employe_role', '')));
        $isLogged = $session->get('employe_logged_in') === true;

        if (!$isLogged || (!str_contains($role, 'rh') && !str_contains($role, 'administrateur entreprise'))) {
            $this->addFlash('error', 'Cette action est reservee aux RH.');

            return $this->redirectToRoute($isLogged ? 'employe_Home' : 'login');
        }

        return null;
    }
}
