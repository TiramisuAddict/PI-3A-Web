<?php

namespace App\Controller;

use App\Entity\Formation;
use App\Entity\InscriptionFormation;
use App\Enum\StatutInscription;
use App\Repository\FormationRepository;
use App\Repository\InscriptionFormationRepository;
use App\Service\ReasonAssistantService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/inscription')]
final class InscriptionFormationController extends AbstractController
{
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
    public function accept(int $id, InscriptionFormationRepository $inscriptionRepository, EntityManagerInterface $entityManager): Response
    {
        $inscription = $inscriptionRepository->find($id);
        if ($inscription === null) {
            $this->addFlash('error', 'Inscription introuvable.');

            return $this->redirectToRoute('app_formation_rh');
        }

        $inscription->setStatut(StatutInscription::ACCEPTEE);
        $entityManager->flush();
        $this->addFlash('success', 'Inscription acceptee.');

        return $this->redirectToRoute('app_formation_rh');
    }

    #[Route('/rh/{id}/refuser', name: 'app_inscription_rh_refuse', methods: ['POST'])]
    public function refuse(int $id, InscriptionFormationRepository $inscriptionRepository, EntityManagerInterface $entityManager): Response
    {
        $inscription = $inscriptionRepository->find($id);
        if ($inscription === null) {
            $this->addFlash('error', 'Inscription introuvable.');

            return $this->redirectToRoute('app_formation_rh');
        }

        $inscription->setStatut(StatutInscription::REFUSEE);
        $entityManager->flush();
        $this->addFlash('success', 'Inscription refusee.');

        return $this->redirectToRoute('app_formation_rh');
    }

    #[Route('/employe', name: 'app_inscription_employe', methods: ['GET', 'POST'])]
    public function employe(Request $request, Connection $connection, FormationRepository $formationRepository, EntityManagerInterface $entityManager, InscriptionFormationRepository $inscriptionRepository, ReasonAssistantService $reasonAssistantService): Response
    {
        $session = $request->getSession();
        $analysisResult = null;
        $typedReason = '';
        $assistantNotice = null;
        $selectedFormationId = (int) $request->query->get('formation', 0);

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            if ($action === 'login') {
                $employeeId = (int) $request->request->get('employee_id', 0);

                if ($employeeId > 0) {
                    $employee = $connection->fetchAssociative(
                        'SELECT id_employe, prenom, nom, role FROM `employé` WHERE id_employe = ? LIMIT 1',
                        [$employeeId]
                    );

                    if ($employee !== false) {
                        $employeeRole = strtolower(trim((string) $employee['role']));
                        if (!in_array($employeeRole, ['employé', 'employe'], true)) {
                            $this->addFlash('error', 'Cette page est reservee aux employes.');

                            return $this->redirectToRoute('app_inscription_employe');
                        }

                        $employeeName = trim((string) $employee['prenom'] . ' ' . (string) $employee['nom']);
                        $session->set('employee_id', (int) $employee['id_employe']);
                        $session->set('employee_name', $employeeName);
                        $session->set('employee_role', $employeeRole);

                        $this->addFlash('success', sprintf('Bienvenue %s.', $employeeName));

                        return $this->redirectToRoute('app_inscription_employe');
                    }
                }

                $this->addFlash('error', 'ID employe invalide.');

                return $this->redirectToRoute('app_inscription_employe');
            }

            if ($action === 'logout') {
                $session->remove('employee_id');
                $session->remove('employee_name');
                $session->remove('employee_role');

                return $this->redirectToRoute('app_inscription_employe');
            }

            if ($action === 'inscrire') {
                $employeeId = (int) $session->get('employee_id', 0);
                $employeeRole = strtolower(trim((string) $session->get('employee_role', '')));
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

                $entityManager->persist($inscription);
                $entityManager->flush();

                $this->addFlash('success', 'Inscription envoyee avec succes.');

                return $this->redirectToRoute('app_inscription_employe');
            }

            if ($action === 'annuler') {
                $employeeId = (int) $session->get('employee_id', 0);
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

                $entityManager->remove($inscription);
                $entityManager->flush();
                $this->addFlash('success', 'Inscription annulee.');

                return $this->redirectToRoute('app_inscription_employe');
            }
        }

        $employeeId = (int) $session->get('employee_id', 0);
        $employeeRole = strtolower(trim((string) $session->get('employee_role', '')));
        $employeeLogged = $employeeId > 0 && in_array($employeeRole, ['employé', 'employe'], true);
        $selectedFormation = $selectedFormationId > 0 ? $formationRepository->find($selectedFormationId) : null;

        $employees = $connection->fetchAllAssociative(
            'SELECT id_employe, prenom, nom, role FROM `employé` WHERE LOWER(role) IN (\'employé\', \'employe\') ORDER BY prenom, nom'
        );

        $formations = $formationRepository->findBy([], ['dateDebut' => 'DESC']);

        $inscriptionByFormation = [];
        if ($employeeLogged) {
            $myInscriptions = $inscriptionRepository->findBy(['employeeId' => $employeeId]);
            foreach ($myInscriptions as $inscription) {
                $formation = $inscription->getFormation();
                if ($formation !== null && $formation->getId() !== null) {
                    $inscriptionByFormation[(int) $formation->getId()] = [
                        'id' => $inscription->getId(),
                        'statut' => $inscription->getStatut(),
                    ];
                }
            }
        }

        $alreadyInscrit = false;
        if ($employeeLogged && $selectedFormation instanceof Formation) {
            $alreadyInscrit = $inscriptionRepository->findOneByFormationAndEmployee((int) $selectedFormation->getId(), $employeeId) !== null;
        }

        return $this->render('inscription/employe.html.twig', [
            'employees' => $employees,
            'formations' => $formations,
            'employee_logged' => $employeeLogged,
            'employee_id' => $employeeId,
            'selected_formation' => $selectedFormation,
            'already_inscrit' => $alreadyInscrit,
            'inscription_by_formation' => $inscriptionByFormation,
            'analysis_result' => $analysisResult,
            'typed_reason' => $typedReason,
            'assistant_notice' => $assistantNotice,
        ]);
    }

    private function handleReasonAction(Request $request, FormationRepository $formationRepository, ReasonAssistantService $reasonAssistantService, string $type): JsonResponse
    {
        $session = $request->getSession();
        $employeeId = (int) $session->get('employee_id', 0);
        $employeeRole = strtolower(trim((string) $session->get('employee_role', '')));
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

        return $this->json([
            'ok' => true,
            'message' => 'Hugging Face a genere un paragraphe complet.',
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
}
