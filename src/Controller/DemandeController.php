<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\DemandeDetail;
use App\Entity\Employe;
use App\Entity\HistoriqueDemande;
use App\Form\DemandeType;
use App\Repository\DemandeRepository;
use App\Service\DemandeMailer;
use App\Service\DemandeAiAssistant;
use App\Service\DemandeDecisionAssistant;
use App\Service\DemandeFormHelper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class DemandeController extends AbstractController
{
    private DemandeFormHelper $formHelper;
    private EntityManagerInterface $em;
    private DemandeRepository $demandeRepository;
    private DemandeMailer $demandeMailer;
    private DemandeDecisionAssistant $demandeDecisionAssistant;
    private DemandeAiAssistant $demandeAiAssistant;

    public function __construct(
        DemandeFormHelper $formHelper,
        EntityManagerInterface $em,
        DemandeRepository $demandeRepository,
        DemandeMailer $demandeMailer,
        DemandeDecisionAssistant $demandeDecisionAssistant,
        DemandeAiAssistant $demandeAiAssistant
    ) {
        $this->formHelper = $formHelper;
        $this->em = $em;
        $this->demandeRepository = $demandeRepository;
        $this->demandeMailer = $demandeMailer;
        $this->demandeDecisionAssistant = $demandeDecisionAssistant;
        $this->demandeAiAssistant = $demandeAiAssistant;
    }

    #[Route('/demande', name: 'demande_index', methods: ['GET'])]
    public function index(Request $request, SessionInterface $session, PaginatorInterface $paginator): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $filters = [
            'categorie' => $request->query->get('categorie'),
            'status'    => $request->query->get('status'),
            'priorite'  => $request->query->get('priorite'),
            'search'    => $request->query->get('search'),
        ];

        $isManager       = $this->canManageDemandes($session);
        $scopedEmployeId = $isManager ? null : $this->getLoggedInEmployeId($session);

        $activeFilters = array_filter(
            $filters,
            static fn($value) => null !== $value && '' !== $value
        );

        $page  = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);
        if ($limit < 1)   $limit = 1;
        if ($limit > 100) $limit = 100;

        $queryBuilder = $this->demandeRepository
            ->createFilteredQueryBuilder($activeFilters, $scopedEmployeId)
            ->orderBy('d.date_creation', 'DESC');

        $demandes = $paginator->paginate($queryBuilder, $page, $limit);

        $stats = [
            'total'      => $this->demandeRepository->countAll($scopedEmployeId, $activeFilters),
            'byStatus'   => $this->demandeRepository->countGroupByStatus($scopedEmployeId, $activeFilters),
            'byPriorite' => $this->demandeRepository->countGroupByPriorite($scopedEmployeId, $activeFilters),
        ];

        return $this->render(
            $isManager
                ? 'demande/adminetrh/index.html.twig'
                : 'demande/employe/employe_index.html.twig',
            [
                'demandes'   => $demandes,
                'categories' => $this->formHelper->getCategories(),
                'statuses'   => $this->formHelper->getStatuses(),
                'priorites'  => $this->formHelper->getPriorites(),
                'filters'    => $filters,
                'stats'      => $stats,
                'limit'      => $limit,
                'email'      => $session->get('employe_email') ?? '',
                'role'       => $session->get('employe_role') ?? '',
            ]
        );
    }

    #[Route('/demande/nouvelle', name: 'demande_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        if ($this->canManageDemandes($session)) {
            $this->addFlash('warning', 'Les comptes RH et administrateur entreprise ne peuvent pas creer de demande.');
            return $this->redirectToRoute('demande_index');
        }

        $employe = $this->em->getRepository(Employe::class)->find($this->getLoggedInEmployeId($session));
        if (null === $employe) {
            throw $this->createAccessDeniedException('Employe connecte introuvable.');
        }

        $demande = new Demande();
        $demande->setDateCreation(new \DateTime());
        $demande->setStatus('Nouvelle');
        $demande->setEmploye($employe);

        $form = $this->createForm(DemandeType::class, $demande, [
            'is_edit'         => false,
            'include_employe' => true,
            'employe_choices' => [$employe],
        ]);
        $form->handleRequest($request);

        $detailErrors     = [];
        $submittedDetails = [];
        $submittedType    = null;
        $submittedAiDescription = '';
        $submittedAiRawPrompt = '';
        $submittedAiFieldPlan = ['add' => [], 'remove' => [], 'replaceBase' => false];
        $submittedAiGenerationSnapshot = [];
        $aiGenerated = false;
        $aiTrustedFromLearning = false;
        $manualFieldMode = false;

        if ($form->isSubmitted()) {
            $formData      = $request->request->all();
            $submittedType = $formData['demande']['typeDemande'] ?? null;
            $submittedDetails = $formData['details'] ?? [];
            $submittedAiDescription = trim((string) $request->request->get('autre_ai_description', ''));
            $submittedAiRawPrompt = trim((string) $request->request->get('autre_ai_raw_prompt', ''));
            $aiGenerationSnapshotRaw = trim((string) $request->request->get('ai_generation_snapshot', ''));
            if ('' !== $aiGenerationSnapshotRaw) {
                $decodedSnapshot = json_decode($aiGenerationSnapshotRaw, true);
                if (is_array($decodedSnapshot)) {
                    $submittedAiGenerationSnapshot = $decodedSnapshot;
                }
            }
            $aiFieldPlanRaw = trim((string) $request->request->get('ai_field_plan', ''));
            if ('' !== $aiFieldPlanRaw) {
                $decodedPlan = json_decode($aiFieldPlanRaw, true);
                if (is_array($decodedPlan)) {
                    $submittedAiFieldPlan = [
                        'add' => is_array($decodedPlan['add'] ?? null) ? $decodedPlan['add'] : [],
                        'remove' => is_array($decodedPlan['remove'] ?? null)
                            ? array_values(array_map('strval', $decodedPlan['remove']))
                            : [],
                        'replaceBase' => true === ($decodedPlan['replaceBase'] ?? false),
                        'manualMode' => true === ($decodedPlan['manualMode'] ?? false),
                    ];
                    $manualFieldMode = true === $submittedAiFieldPlan['manualMode'];
                }
            }
            $aiGenerated = '1' === (string) $request->request->get('ai_generated', '0');
            $aiConfirmed = '1' === (string) $request->request->get('ai_confirmed', '0');
            $aiTrustedFromLearning = '1' === (string) $request->request->get('ai_trusted_learning', '0');

            if ('Autre' === $submittedType && $manualFieldMode) {
                $submittedAiFieldPlan = $this->sanitizeManualAutreFieldPlan($submittedAiFieldPlan, $submittedDetails);
                $manualFieldMode = [] !== ($submittedAiFieldPlan['add'] ?? []);
            } elseif ('Autre' === $submittedType) {
                $submittedAiFieldPlan = $this->sanitizeGeneratedAutreFieldPlan(
                    $submittedAiFieldPlan,
                    $submittedDetails,
                    '' !== $submittedAiRawPrompt ? $submittedAiRawPrompt : $submittedAiDescription
                );
            }

            if ($submittedType) {
                $detailErrors = $this->validateDetails($submittedType, $submittedDetails);
                if ('Autre' === $submittedType) {
                    $detailErrors = array_merge(
                        $detailErrors,
                        $this->validateAiCustomDetails($submittedDetails, $submittedAiFieldPlan)
                    );
                }
            }

            $demande->setEmploye($employe);

            if ($form->isValid() && [] === $detailErrors) {
                if ($this->requiresAutreAiConfirmation($submittedType, $aiGenerated, $manualFieldMode, $aiConfirmed)) {
                    $this->addFlash('warning', $manualFieldMode
                        ? 'Veuillez confirmer les champs manuels avant de creer la demande.'
                        : 'Veuillez confirmer la demande apres generation IA avant de creer la demande.'
                    );
                } else {
                    if ('Autre' === $submittedType && $manualFieldMode) {
                        $submittedDetails = $this->filterManualAutreSubmittedDetails($submittedDetails, $submittedAiFieldPlan);
                    }

                    $this->em->persist($demande);
                    $this->em->flush();

                    if ([] !== $submittedDetails) {
                        $persistedDetails = $submittedDetails;
                        $isAcceptedAutreFeedback = $this->isAcceptedAutreFeedback($submittedType, $aiGenerated, $manualFieldMode, $aiConfirmed);
                        if ($isAcceptedAutreFeedback) {
                            $trustedPrompt = trim('' !== $submittedAiRawPrompt ? $submittedAiRawPrompt : $submittedAiDescription);
                            $persistedDetails['_ai_feedback_confirmed'] = true;
                            $persistedDetails['_ai_manual_fields'] = $manualFieldMode;
                            $persistedDetails['_ai_confirmed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
                            $persistedDetails['_ai_field_plan'] = $submittedAiFieldPlan;
                            if ([] !== $submittedAiGenerationSnapshot) {
                                $persistedDetails['_ai_generation_snapshot'] = $submittedAiGenerationSnapshot;
                            }
                            if ('' !== $trustedPrompt) {
                                $persistedDetails['_ai_raw_prompt'] = $trustedPrompt;
                            }
                        }

                        $demandeDetail = new DemandeDetail();
                        $demandeDetail->setDemande($demande);
                        $demandeDetail->setDetails($this->encodeDetailsPayload($persistedDetails));
                        $this->em->persist($demandeDetail);
                    }

                    $historique = new HistoriqueDemande();
                    $historique->setDemande($demande);
                    $historique->setNouveauStatut('Nouvelle');
                    $historique->setActeur($this->getActorLabel($session));
                    $historique->setCommentaire('Demande creee');
                    $historique->setDateAction(new \DateTime());
                    $this->em->persist($historique);
                    $this->em->flush();

                    if ($aiGenerated && $aiConfirmed) {
                        $this->demandeAiAssistant->recordAcceptedDescriptionFeedback(
                            $demande->getTitre(),
                            $demande->getDescription(),
                            $demande->getTypeDemande(),
                            $demande->getCategorie(),
                            $employe->getId_employe()
                        );
                    }

                    if ($this->isAcceptedAutreFeedback($demande->getTypeDemande(), $aiGenerated, $manualFieldMode, $aiConfirmed)) {
                        $this->demandeAiAssistant->recordAcceptedAutreFeedback(
                            '' !== $submittedAiRawPrompt ? $submittedAiRawPrompt : $submittedAiDescription,
                            [
                                'titre' => $demande->getTitre(),
                                'description' => $demande->getDescription(),
                                'priorite' => $demande->getPriorite() ?? '',
                                'categorie' => $demande->getCategorie(),
                                'typeDemande' => $demande->getTypeDemande(),
                            ],
                            $submittedDetails,
                            $submittedAiFieldPlan,
                            $employe->getId_employe(),
                            $submittedAiGenerationSnapshot
                        );
                    }

                    $this->demandeMailer->notifyManagersDemandeCreated($demande);

                    $this->addFlash('success', 'Demande creee avec succes.');
                    return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
                }
            }
        }

        return $this->render('demande/employe/new.html.twig', [
            'form'             => $form->createView(),
            'categories'       => $this->formHelper->getCategoryTypes(),
            'detailErrors'     => $detailErrors,
            'submittedDetails' => $submittedDetails,
            'submittedType'    => $submittedType,
            'submittedAiDescription' => $submittedAiDescription,
            'submittedAiRawPrompt' => $submittedAiRawPrompt,
            'submittedAiFieldPlan' => $submittedAiFieldPlan,
            'aiGenerated'      => $aiGenerated,
            'aiTrustedFromLearning' => $aiTrustedFromLearning,
            'email'            => $session->get('employe_email') ?? '',
            'role'             => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/demande/statistics', name: 'demande_statistics', methods: ['GET'])]
    public function statistics(SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $scopedEmployeId = $this->canManageDemandes($session) ? null : $this->getLoggedInEmployeId($session);

        $stats = [
            'total'      => $this->demandeRepository->countAll($scopedEmployeId),
            'byStatus'   => $this->demandeRepository->countGroupByStatus($scopedEmployeId),
            'byPriorite' => $this->demandeRepository->countGroupByPriorite($scopedEmployeId),
            'byType'     => $this->demandeRepository->countGroupByType($scopedEmployeId),
            'byCategorie'=> $this->demandeRepository->countGroupByCategorie($scopedEmployeId),
        ];

        return $this->render(
            $this->canManageDemandes($session)
                ? 'demande/adminetrh/statistics.html.twig'
                : 'demande/employe/statistics.html.twig',
            [
                'stats' => $stats,
                'email' => $session->get('employe_email') ?? '',
                'role'  => $session->get('employe_role') ?? '',
            ]
        );
    }

    #[Route('/demande/api/fields/{type}', name: 'demande_controller_api_fields', methods: ['GET'])]
    public function getFields(string $type): JsonResponse
    {
        return new JsonResponse($this->formHelper->getFieldsForType($type));
    }

    #[Route('/demande/action/status/{id}', name: 'demande_update_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(int $id, Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->jsonAccessDenied('Vous devez etre connecte pour modifier le statut.', 401);
        }

        if (!$this->canManageDemandes($session)) {
            return $this->jsonAccessDenied('Seuls les comptes RH et administrateur entreprise peuvent modifier le statut.');
        }

        try {
            $demande = $this->demandeRepository->find($id);

            if (null === $demande) {
                return new JsonResponse(['success' => false, 'message' => 'Demande non trouvee'], 404);
            }

            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return new JsonResponse(['success' => false, 'message' => 'Payload JSON invalide'], 400);
            }

            $newStatus   = $this->nullableScalarString($data['status'] ?? null);
            $commentaire = $this->nullableScalarString($data['commentaire'] ?? '') ?? '';

            if (null === $newStatus || '' === $newStatus) {
                return new JsonResponse(['success' => false, 'message' => 'Statut requis'], 400);
            }

            $currentStatus = $demande->getStatus();

            if (!$this->canTransitionStatus($currentStatus, $newStatus)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => $this->getStatusTransitionBlockedMessage($currentStatus)
                ], 400);
            }

            $oldStatus = $demande->getStatus();
            $demande->setStatus($newStatus);
            $actorLabel = $this->getActorLabel($session);

            $historique = new HistoriqueDemande();
            $historique->setDemande($demande);
            $historique->setAncienStatut($oldStatus);
            $historique->setNouveauStatut($newStatus);
            $historique->setActeur($actorLabel);
            $historique->setCommentaire($commentaire);
            $historique->setDateAction(new \DateTime());

            $this->em->persist($historique);
            $this->em->flush();

            if ($oldStatus !== $newStatus) {
                $this->demandeMailer->notifyEmployeStatusChanged($demande, $oldStatus, $newStatus, $actorLabel, $commentaire);
            }

            $stats = [
                'total'    => $this->demandeRepository->countAll(null),
                'byStatus' => $this->demandeRepository->countGroupByStatus(null),
            ];

            $session->save();

            return new JsonResponse([
                'success'   => true,
                'message'   => 'Statut mis a jour',
                'newStatus' => $newStatus,
                'stats'     => $stats,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/demande/{id}/cancel', name: 'demande_cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function cancel(int $id, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        if ($this->canManageDemandes($session)) {
            $this->addFlash('danger', 'Seuls les employes peuvent annuler leurs demandes.');
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        $demande = $this->demandeRepository->find($id);

        if (null === $demande) {
            $this->addFlash('danger', 'Demande non trouvee.');
            return $this->redirectToRoute('demande_index');
        }

        if ($demande->getEmploye()?->getId_employe() !== $this->getLoggedInEmployeId($session)) {
            $this->addFlash('danger', 'Vous ne pouvez annuler que vos propres demandes.');
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        if ($demande->getStatus() !== 'Nouvelle') {
            $this->addFlash('warning', 'Vous ne pouvez annuler que les demandes avec le statut Nouvelle.');
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        $oldStatus = $demande->getStatus();
        $demande->setStatus('Annulee');

        $historique = new HistoriqueDemande();
        $historique->setDemande($demande);
        $historique->setAncienStatut($oldStatus);
        $historique->setNouveauStatut('Annulee');
        $historique->setActeur($this->getActorLabel($session));
        $historique->setCommentaire('Demande annulee par l employe');
        $historique->setDateAction(new \DateTime());

        $this->em->persist($historique);
        $this->em->flush();

        $this->demandeMailer->notifyManagersDemandeCanceled($demande);

        $this->addFlash('success', 'Demande annulee avec succes.');
        return $this->redirectToRoute('demande_show', ['id' => $id]);
    }

    #[Route('/demande/action/delete/{id}', name: 'demande_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        if (!$this->canManageDemandes($session)) {
            $this->addFlash('danger', 'Seuls les comptes RH et administrateur entreprise peuvent supprimer une demande.');
            return $this->redirectToRoute('demande_index');
        }

        $demande = $this->demandeRepository->find($id);

        if (null === $demande) {
            $this->addFlash('danger', 'Demande non trouvee.');
            return $this->redirectToRoute('demande_index');
        }

        $this->em->remove($demande);
        $this->em->flush();

        $this->addFlash('success', 'Demande supprimee avec succes.');
        return $this->redirectToRoute('demande_index');
    }

    #[Route('/demande/{id}', name: 'demande_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        $demande = $this->demandeRepository->find($id);

        if (null === $demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        if (!$this->canAccessDemande($demande, $session)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas consulter cette demande.');
        }

        $detailsData    = [];
        $decisionDetails = [];
        $fieldLabels    = [];
        $decisionAdvice = null;
        $demandeDetails = $demande->getDemandeDetails();

        if ($demandeDetails->count() > 0) {
            $firstDetail = $demandeDetails->first();
            if ($firstDetail instanceof DemandeDetail) {
                $rawDetailsData = json_decode($firstDetail->getDetails(), true) ?? [];
                $rawDetailsData = is_array($rawDetailsData) ? $rawDetailsData : [];
                $detailsData = $this->filterVisibleDetails($rawDetailsData);
                $decisionDetails = $this->filterDecisionDetails($rawDetailsData);
                $aiFieldLabels = $this->extractAiFieldLabels($rawDetailsData);

                foreach ($detailsData as $key => $value) {
                    $fieldLabels[$key] = $aiFieldLabels[$key] ?? $this->formHelper->getFieldLabel($demande->getTypeDemande(), $key);
                }
            }
        }

        $decisionAdvice = $this->demandeDecisionAssistant->analyze(
            $demande,
            $decisionDetails,
            $this->formHelper->getFieldsForType((string) $demande->getTypeDemande())
        );

        return $this->render(
            $this->canManageDemandes($session)
                ? 'demande/adminetrh/show.html.twig'
                : 'demande/employe/employe_show.html.twig',
            [
                'demande'     => $demande,
                'detailsData' => $detailsData,
                'fieldLabels' => $fieldLabels,
                'decisionAdvice' => $decisionAdvice,
                'statuses'    => $this->formHelper->getStatuses(),
                'email'       => $session->get('employe_email') ?? '',
                'role'        => $session->get('employe_role') ?? '',
            ]
        );
    }

    #[Route('/demande/action/status-form/{id}', name: 'demande_update_status_form', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatusForm(int $id, Request $request, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        if (!$this->canManageDemandes($session)) {
            $this->addFlash('danger', 'Acces refuse.');
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        $demande = $this->demandeRepository->find($id);

        if (null === $demande) {
            $this->addFlash('danger', 'Demande non trouvee.');
            return $this->redirectToRoute('demande_index');
        }

        $newStatus   = $this->nullableScalarString($request->request->get('status'));
        $commentaire = $this->nullableScalarString($request->request->get('commentaire', '')) ?? '';

        if (null === $newStatus || '' === $newStatus) {
            $this->addFlash('danger', 'Statut manquant.');
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        $currentStatus = $demande->getStatus();
        if (!$this->canTransitionStatus($currentStatus, $newStatus)) {
            $this->addFlash('warning', $this->getStatusTransitionBlockedMessage($currentStatus));
            return $this->redirectToRoute('demande_show', ['id' => $id]);
        }

        $oldStatus = $demande->getStatus();
        $demande->setStatus($newStatus);
        $actorLabel = $this->getActorLabel($session);

        $historique = new HistoriqueDemande();
        $historique->setDemande($demande);
        $historique->setAncienStatut($oldStatus);
        $historique->setNouveauStatut($newStatus);
        $historique->setActeur($actorLabel);
        $historique->setCommentaire($commentaire !== '' ? $commentaire : 'Statut mis a jour');
        $historique->setDateAction(new \DateTime());

        $this->em->persist($historique);
        $this->em->flush();

        if ($oldStatus !== $newStatus) {
            $this->demandeMailer->notifyEmployeStatusChanged($demande, $oldStatus, $newStatus, $actorLabel, $commentaire);
        }

        $this->addFlash('success', 'Statut mis a jour : ' . $newStatus . '.');
        return $this->redirectToRoute('demande_show', ['id' => $id]);
    }

    #[Route('/demande/{id}/edit', name: 'demande_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, SessionInterface $session): Response
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return $this->redirectToRoute('login');
        }

        if (!$this->canManageDemandes($session)) {
            throw $this->createAccessDeniedException('Seuls les comptes RH et administrateur entreprise peuvent modifier une demande.');
        }

        $demande = $this->demandeRepository->find($id);

        if (null === $demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        if ($demande->getStatus() === 'Annulee') {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier une demande annulee par l\'employe.');
        }

        $resolvedType = $this->formHelper->resolveCanonicalType(
            $demande->getTypeDemande(),
            $demande->getCategorie()
        );
        $resolvedCategory = $this->formHelper->resolveCanonicalCategory(
            $demande->getCategorie(),
            $resolvedType
        );

        if (null !== $resolvedCategory) {
            $demande->setCategorie($resolvedCategory);
        }
        if (null !== $resolvedType) {
            $demande->setTypeDemande($resolvedType);
        }

        $statusChoices = $demande->getStatus() === 'Rejetee'
            ? ['Rejetee', 'Reconsideration']
            : ($demande->getStatus() === 'Resolue'
                ? ['Resolue']
                : $this->formHelper->getStatuses());

        $existingDetails = [];
        $demandeDetails  = $demande->getDemandeDetails();
        if ($demandeDetails->count() > 0) {
            $firstDetail     = $demandeDetails->first();
            if ($firstDetail instanceof DemandeDetail) {
                $existingDetails = json_decode($firstDetail->getDetails(), true) ?? [];
                $existingDetails = is_array($existingDetails) ? $existingDetails : [];
            }
        }
        $existingAiFieldPlan = $this->extractStoredAiFieldPlan($existingDetails);
        $usesStoredAiSchema = [] !== $existingAiFieldPlan;

        $form = $this->createForm(DemandeType::class, $demande, [
            'is_edit'        => true,
            'status_choices' => $statusChoices,
        ]);
        $form->handleRequest($request);

        $detailErrors     = [];
        $submittedDetails = $existingDetails;
        $submittedType    = $demande->getTypeDemande();

        if ($form->isSubmitted()) {
            $formData         = $request->request->all();
            $submittedType    = $formData['demande']['typeDemande'] ?? null;
            $submittedDetails = $formData['details'] ?? [];

            if ($submittedType) {
                $detailErrors = $usesStoredAiSchema
                    ? $this->validateAiCustomDetails($submittedDetails, $existingAiFieldPlan)
                    : $this->validateDetails($submittedType, $submittedDetails);
            }

            if ($form->isValid() && [] === $detailErrors) {
                $oldStatusRaw = $this->em->getUnitOfWork()->getOriginalEntityData($demande)['status'] ?? $demande->getStatus();
                $oldStatus   = $oldStatusRaw instanceof \BackedEnum ? (string) $oldStatusRaw->value : (string) $oldStatusRaw;
                $newStatus   = $demande->getStatus();
                $commentaire = $this->nullableScalarString($form->get('commentaire')->getData()) ?? '';
                $actorLabel  = $this->getActorLabel($session);
                $statusChanged = $oldStatus !== $newStatus;

                if (!$this->canTransitionStatus($oldStatus, $newStatus)) {
                    $this->addFlash('danger', $this->getStatusTransitionBlockedMessage($oldStatus));
                    return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
                }

                if ($statusChanged) {
                    $historique = new HistoriqueDemande();
                    $historique->setDemande($demande);
                    $historique->setAncienStatut($oldStatus);
                    $historique->setNouveauStatut($newStatus);
                    $historique->setActeur($actorLabel);
                    $historique->setCommentaire('' !== $commentaire ? $commentaire : 'Statut modifie');
                    $historique->setDateAction(new \DateTime());
                    $this->em->persist($historique);
                }

                if ([] !== $submittedDetails || $usesStoredAiSchema) {
                    if ([] !== $existingDetails) {
                        foreach ($existingDetails as $detailKey => $detailValue) {
                            $detailKey = (string) $detailKey;
                            if ($this->isTechnicalDetailKey($detailKey) && !array_key_exists($detailKey, $submittedDetails)) {
                                $submittedDetails[$detailKey] = $detailValue;
                            }
                        }
                    }

                    if ($demandeDetails->count() > 0) {
                        $detail = $demandeDetails->first();
                    } else {
                        $detail = new DemandeDetail();
                        $detail->setDemande($demande);
                        $this->em->persist($detail);
                    }
                    if (!$detail instanceof DemandeDetail) {
                        $detail = new DemandeDetail();
                        $detail->setDemande($demande);
                        $this->em->persist($detail);
                    }
                    $detail->setDetails($this->encodeDetailsPayload($submittedDetails));
                }

                $this->em->flush();

                if ($statusChanged) {
                    $this->demandeMailer->notifyEmployeStatusChanged($demande, $oldStatus, $newStatus, $actorLabel, $commentaire);
                }

                $this->addFlash('success', 'Demande mise a jour avec succes.');
                return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
            }
        }

        return $this->render('demande/adminetrh/edit.html.twig', [
            'demande'          => $demande,
            'form'             => $form->createView(),
            'categories'       => $this->formHelper->getCategoryTypes(),
            'statuses'         => $this->formHelper->getStatuses(),
            'existingDetails'  => $submittedDetails,
            'detailErrors'     => $detailErrors,
            'submittedType'    => $submittedType,
            'email'            => $session->get('employe_email') ?? '',
            'role'             => $session->get('employe_role') ?? '',
        ]);
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return true === $session->get('employe_logged_in');
    }

    private function canManageDemandes(SessionInterface $session): bool
    {
        return $this->isEmployeLoggedIn($session)
            && in_array((string) $session->get('employe_role'), ['RH', 'administrateur entreprise'], true);
    }

    private function canTransitionStatus(?string $currentStatus, ?string $newStatus): bool
    {
        if (null === $currentStatus || null === $newStatus) {
            return false;
        }
        if ($currentStatus === 'Annulee' || $currentStatus === 'Resolue') {
            return $newStatus === $currentStatus;
        }
        if ($currentStatus === 'Rejetee') {
            return in_array($newStatus, ['Rejetee', 'Reconsideration'], true);
        }
        return true;
    }

    private function getStatusTransitionBlockedMessage(?string $currentStatus): string
    {
        if ($currentStatus === 'Annulee') {
            return 'Cette demande est annulee. Son statut ne peut pas etre modifie.';
        }
        if ($currentStatus === 'Resolue') {
            return 'Cette demande est deja resolue. Le statut ne peut plus etre modifie.';
        }
        if ($currentStatus === 'Rejetee') {
            return 'Cette demande est rejetee. Utilisez le bouton Reconsideration dans la page de details pour rouvrir les changements de statut.';
        }
        return 'Le statut ne peut pas etre modifie dans cet etat.';
    }

    private function getLoggedInEmployeId(SessionInterface $session): ?int
    {
        $employeId = $session->get('employe_id');
        return is_numeric($employeId) ? (int) $employeId : null;
    }

    private function canAccessDemande(Demande $demande, SessionInterface $session): bool
    {
        if ($this->canManageDemandes($session)) {
            return true;
        }
        $demandeEmployeId  = $demande->getEmploye()?->getId_employe();
        $loggedInEmployeId = $this->getLoggedInEmployeId($session);
        return null !== $demandeEmployeId
            && null !== $loggedInEmployeId
            && $demandeEmployeId === $loggedInEmployeId;
    }

    private function jsonAccessDenied(string $message, int $status = 403): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function nullableScalarString(mixed $value): ?string
    {
        if (null === $value || !is_scalar($value)) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function encodeDetailsPayload(array $details): string
    {
        return json_encode(
            $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    private function getActorLabel(SessionInterface $session): string
    {
        if (true === $session->get('admin_logged_in')) {
            return 'Administrateur systeme';
        }
        $role  = trim((string) $session->get('employe_role'));
        $email = trim((string) $session->get('employe_email'));
        if ('' !== $role && '' !== $email) {
            return $role . ' - ' . $email;
        }
        if ('' !== $email) return $email;
        return '' !== $role ? $role : 'Systeme';
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, string>
     */
    private function filterVisibleDetails(array $details): array
    {
        $visible = [];
        foreach ($details as $key => $value) {
            $detailKey = trim($key);
            if ('' === $detailKey || $this->isTechnicalDetailKey($detailKey) || str_ends_with($detailKey, 'Lat') || str_ends_with($detailKey, 'Lon')) {
                continue;
            }

            if (is_scalar($value)) {
                $clean = trim((string) $value);
                if ('' !== $clean) {
                    $visible[$detailKey] = $clean;
                }
            }
        }

        return $visible;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, string>
     */
    private function filterDecisionDetails(array $details): array
    {
        $filtered = [];
        foreach ($details as $key => $value) {
            $detailKey = trim($key);
            if ('' === $detailKey || $this->isTechnicalDetailKey($detailKey)) {
                continue;
            }

            if (is_scalar($value)) {
                $filtered[$detailKey] = trim((string) $value);
            }
        }

        return $filtered;
    }

    private function isTechnicalDetailKey(string $key): bool
    {
        return str_starts_with($key, '_ai_') || str_starts_with($key, '__ai_') || 'ai_field_plan' === $key;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, string>
     */
    private function extractAiFieldLabels(array $details): array
    {
        $plan = $details['_ai_field_plan'] ?? $details['__ai_field_plan'] ?? null;
        if (is_string($plan) && '' !== trim($plan)) {
            $decodedPlan = json_decode($plan, true);
            $plan = is_array($decodedPlan) ? $decodedPlan : null;
        }

        if (!is_array($plan) || !is_array($plan['add'] ?? null)) {
            return [];
        }

        $labels = [];
        foreach ($plan['add'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            if ('' !== $key && '' !== $label) {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function extractStoredAiFieldPlan(array $details): array
    {
        $plan = $details['_ai_field_plan'] ?? $details['__ai_field_plan'] ?? null;
        if (is_string($plan) && '' !== trim($plan)) {
            $decodedPlan = json_decode($plan, true);
            $plan = is_array($decodedPlan) ? $decodedPlan : null;
        }

        if (!is_array($plan) || !is_array($plan['add'] ?? null) || [] === $plan['add']) {
            return [];
        }

        return [
            'add' => $plan['add'],
            'remove' => is_array($plan['remove'] ?? null) ? $plan['remove'] : [],
            'replaceBase' => true === ($plan['replaceBase'] ?? false),
        ];
    }

    /**
     * Manual Autre mode is a schema decision made by the employee. Do not persist
     * stale service-generated ai_* inputs that may still be present in the form.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $fieldPlan
     * @return array<string, mixed>
     */
    private function filterManualAutreSubmittedDetails(array $details, array $fieldPlan): array
    {
        $fieldPlan = $this->sanitizeManualAutreFieldPlan($fieldPlan, $details);
        $allowedKeys = array_fill_keys([
            'besoinPersonnalise',
            'descriptionBesoin',
            'niveauUrgenceAutre',
        ], true);

        $customFields = is_array($fieldPlan['add'] ?? null) ? $fieldPlan['add'] : [];
        foreach ($customFields as $field) {
            if (!is_array($field) || $this->isGeneratedAutreFieldSource($field['source'] ?? null)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ('' !== $key) {
                $allowedKeys[$key] = true;
            }
        }

        $filtered = [];
        foreach ($details as $key => $value) {
            $detailKey = trim($key);
            if ('' === $detailKey || !isset($allowedKeys[$detailKey])) {
                continue;
            }

            $filtered[$detailKey] = $value;
        }

        return $filtered;
    }

    /**
     * Manual Autre field plans can come back with stale generated fields from a
     * previous AI pass. Keep only employee-defined fields and collapse duplicate
     * same-value entries before validation, persistence, and feedback learning.
     *
     * @param array<string, mixed> $fieldPlan
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function sanitizeManualAutreFieldPlan(array $fieldPlan, array $details): array
    {
        $add = [];
        $seenKeys = [];
        $seenValues = [];
        $rawAdd = is_array($fieldPlan['add'] ?? null) ? $fieldPlan['add'] : [];

        foreach ($rawAdd as $field) {
            if (!is_array($field) || $this->isGeneratedAutreFieldSource($field['source'] ?? null)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ('' === $key || isset($seenKeys[$key])) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? $key));
            if ('' === $label) {
                $label = $key;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (!in_array($type, ['text', 'textarea', 'select', 'number', 'date'], true)) {
                $type = 'text';
            }

            $normalizedField = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => true === ($field['required'] ?? false),
                'value' => trim((string) ($field['value'] ?? '')),
                'source' => 'manual',
            ];

            if ('select' === $type && is_array($field['options'] ?? null)) {
                $options = [];
                foreach ($field['options'] as $option) {
                    $option = trim((string) $option);
                    if ('' !== $option && !in_array($option, $options, true)) {
                        $options[] = $option;
                    }
                }
                if ([] !== $options) {
                    $normalizedField['options'] = $options;
                }
            }

            $value = $details[$key] ?? $normalizedField['value'];
            $valueKey = $this->manualAutreDuplicateValueKey($value);
            if ('' !== $valueKey) {
                    $scoreField = $normalizedField;
                    $scoreField['value'] = is_scalar($value) ? trim((string) $value) : '';
                    $score = $this->manualAutreDuplicateKeepScore($scoreField);
                if (isset($seenValues[$valueKey])) {
                    $previousIndex = $seenValues[$valueKey]['index'];
                    $previousScore = $seenValues[$valueKey]['score'];
                    if ($score > $previousScore && isset($add[$previousIndex])) {
                        $previousKey = trim($add[$previousIndex]['key']);
                        if ('' !== $previousKey) {
                            unset($seenKeys[$previousKey]);
                        }
                        $seenKeys[$key] = true;
                        $add[$previousIndex] = $normalizedField;
                        $seenValues[$valueKey] = [
                            'index' => $previousIndex,
                            'score' => $score,
                        ];
                    }

                    continue;
                }

                $seenValues[$valueKey] = [
                    'index' => count($add),
                    'score' => $score,
                ];
            }

            $seenKeys[$key] = true;
            $add[] = $normalizedField;
        }

        return [
            'add' => $add,
            'remove' => is_array($fieldPlan['remove'] ?? null)
                ? array_values(array_map('strval', $fieldPlan['remove']))
                : [],
            'replaceBase' => true === ($fieldPlan['replaceBase'] ?? false),
            'manualMode' => true,
        ];
    }

    private function manualAutreDuplicateValueKey(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $normalized = strtolower(trim((string) $value));
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (false !== $ascii) {
                $normalized = $ascii;
            }
        }

        $normalized = preg_replace('/[^a-z0-9\/\- ]+/', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        if (strlen($normalized) < 3) {
            return '';
        }

        if (in_array($normalized, ['oui', 'non', 'autre', 'a definir', 'n/a'], true)) {
            return '';
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function manualAutreDuplicateKeepScore(array $field): int
    {
        $haystack = $this->manualAutreDuplicateValueKey(
            (string) ($field['key'] ?? '') . ' ' . (string) ($field['label'] ?? '')
        );
        $score = true === ($field['required'] ?? false) ? 20 : 0;

        if ('manual' === strtolower(trim((string) ($field['source'] ?? '')))) {
            $score += 10;
        }

        if ('textarea' === strtolower(trim((string) ($field['type'] ?? 'text')))) {
            $score -= 10;
        }

        if (preg_match('/\b(type|nature|materiel|equipement|systeme|logiciel|montant|date|lieu|destination|attestation|formation|conge|horaire|shift|zone|salle)\b/', $haystack) === 1) {
            $score += 20;
        }

        if (preg_match('/\b(extra|infos?|information|description|details?|commentaire|justification|motif|custom|champ)\b/', $haystack) === 1) {
            $score -= 20;
        }

        $value = trim((string) ($field['value'] ?? ''));
        if (preg_match('/\b\d{1,2}\s*(?:h|:)\s*\d{0,2}\s*[-–]\s*\d{1,2}\s*(?:h|:)\s*\d{0,2}\b/i', $value) === 1) {
            if (preg_match('/\b(horaire|heure|creneau|shift|poste)\b/', $haystack) === 1) {
                $score += 30;
            } elseif (preg_match('/\b(type|nature|categorie|description|details?|commentaire|justification|motif|custom|champ)\b/', $haystack) === 1) {
                $score -= 30;
            }
        }

        return $score;
    }

    private function isGeneratedAutreFieldSource(mixed $source): bool
    {
        $normalized = strtolower(trim((string) $source));
        if ('' === $normalized || 'manual' === $normalized) {
            return false;
        }

        return in_array($normalized, ['generated', 'learned', 'explicit', 'seed'], true)
            || str_starts_with($normalized, 'llm')
            || str_starts_with($normalized, 'local-ml')
            || str_contains($normalized, 'fallback');
    }

    private function requiresAutreAiConfirmation(?string $typeDemande, bool $aiGenerated, bool $manualFieldMode, bool $aiConfirmed): bool
    {
        return 'Autre' === $typeDemande && ($aiGenerated || $manualFieldMode) && !$aiConfirmed;
    }

    private function isAcceptedAutreFeedback(?string $typeDemande, bool $aiGenerated, bool $manualFieldMode, bool $aiConfirmed): bool
    {
        return 'Autre' === $typeDemande && $aiConfirmed && ($aiGenerated || $manualFieldMode);
    }

    /**
     * Learned Autre AI plans may carry a "current schedule" field from a prior
     * schema even when the current prompt only contains the requested schedule.
     *
     * @param array<string, mixed> $fieldPlan
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function sanitizeGeneratedAutreFieldPlan(array $fieldPlan, array &$details, string $sourceText): array
    {
        $add = [];
        $rawAdd = is_array($fieldPlan['add'] ?? null) ? $fieldPlan['add'] : [];

        foreach ($rawAdd as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $label = trim((string) ($field['label'] ?? $key));
            $value = is_scalar($details[$key] ?? null)
                ? trim((string) $details[$key])
                : trim((string) ($field['value'] ?? ''));

            if ('' !== $key && $this->isUnsupportedCurrentAutreScheduleField($key, $label, $value, $sourceText)) {
                unset($details[$key]);
                continue;
            }

            $add[] = $field;
        }

        return [
            'add' => $add,
            'remove' => is_array($fieldPlan['remove'] ?? null)
                ? array_values(array_map('strval', $fieldPlan['remove']))
                : [],
            'replaceBase' => true === ($fieldPlan['replaceBase'] ?? false),
            'manualMode' => false,
        ];
    }

    private function isUnsupportedCurrentAutreScheduleField(string $key, string $label, string $value, string $sourceText): bool
    {
        if ('' === trim($sourceText)) {
            return false;
        }

        $haystack = $this->normalizeAutreTextForMatch($key . ' ' . $label);
        $isCurrentScheduleField =
            preg_match('/\b(horaire|heure|creneau|shift|poste)\b/', $haystack) === 1
            && preg_match('/\b(actuel|actuelle|actuels|actuelles|ancien|ancienne|anciens|anciennes)\b/', $haystack) === 1;

        if (!$isCurrentScheduleField) {
            return false;
        }

        $source = $this->normalizeAutreTextForMatch($sourceText);

        return preg_match('/\b(actuel|actuelle|actuels|actuelles|ancien|ancienne|anciens|anciennes|actuellement)\b/', $source) !== 1
            && !str_contains($source, 'au lieu de')
            && preg_match('/\bpasser\s+de\b/', $source) !== 1;
    }

    private function normalizeAutreTextForMatch(string $value): string
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', trim($value)) ?? $value;
        $normalized = strtolower(trim($normalized));
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (false !== $ascii) {
                $normalized = $ascii;
            }
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:horriaire|horraire|horairre|horiare|horairee)(actuel|actuelle|actuels|actuelles|ancien|ancienne|anciens|anciennes|souhaite|souhaitee|souhaites|souhaitees)\b/', 'horaire $1', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:horriaire|horraire|horairre|horiare|horairee)\b/', 'horaire', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * @param string $typeDemande
     * @param array<string,mixed> $details
     * @return array<int, array{field:string,message:string,type:string}>
     */
    private function validateDetails(string $typeDemande, array $details): array
    {
        $errors = [];
        $fields = $this->formHelper->getFieldsForType($typeDemande);
        $today  = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($fields as $field) {
            $key      = $field['key'];
            $label    = $field['label'];
            $required = true === ($field['required'] ?? false);
            $type     = $field['type'] ?? 'text';
            $value    = $details[$key] ?? null;

            if ($value !== null && !is_scalar($value)) {
                $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" contient un format invalide.', 'type' => 'format'];
                continue;
            }

            if ($required && ($value === null || trim((string) $value) === '')) {
                $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" est obligatoire.', 'type' => 'blank'];
                continue;
            }
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $valueString = trim((string) $value);

            if ($type === 'number') {
                if (!is_numeric($value)) {
                    $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" doit etre un nombre valide.', 'type' => 'format'];
                } elseif ((float) $value < 0) {
                    $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" ne peut pas etre negatif.', 'type' => 'format'];
                } elseif (
                    str_contains(strtolower($key), 'montant') ||
                    str_contains(strtolower($key), 'nombre')  ||
                    str_contains(strtolower($key), 'quantite') ||
                    str_contains(strtolower($key), 'cout')
                ) {
                    if ((float) $value <= 0) {
                        $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" doit etre superieur a 0.', 'type' => 'format'];
                    }
                }
            }

            if ($type === 'date') {
                try {
                    $date = new \DateTime($valueString);
                    $date->setTime(0, 0, 0);
                    $lowerKey   = strtolower($key);
                    $lowerLabel = strtolower($label);

                    $mustBeFuture =
                        str_contains($lowerKey, 'datedebut')    ||
                        str_contains($lowerKey, 'datesouhaitee') ||
                        str_contains($lowerKey, 'datepassage')   ||
                        str_contains($lowerKey, 'dateheuressup') ||
                        str_contains($lowerLabel, 'date de debut')    ||
                        str_contains($lowerLabel, 'date souhaitee')   ||
                        str_contains($lowerLabel, 'date de depart');

                    if ($mustBeFuture && $date < $today) {
                        $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" ne peut pas etre dans le passe.', 'type' => 'format'];
                    }

                    $isEndDate = str_contains($lowerKey, 'datefin') || str_contains($lowerLabel, 'date de fin');
                    if ($isEndDate) {
                        $startKeys = ['dateDebut', 'dateDebutTeletravail', 'dateDebutHoraires', 'dateDebutFormation'];
                        foreach ($startKeys as $startKey) {
                            if (isset($details[$startKey]) && '' !== trim((string) ($details[$startKey] ?? ''))) {
                                $startDate = new \DateTime((string) $details[$startKey]);
                                $startDate->setTime(0, 0, 0);
                                if ($date < $startDate) {
                                    $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" doit etre superieur ou egal a la date de debut.', 'type' => 'format'];
                                }
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" contient une date invalide.', 'type' => 'format'];
                }
            }

            if ($type === 'select') {
                $options = isset($field['options']) && is_array($field['options'])
                    ? array_values(array_map('strval', $field['options']))
                    : [];

                if ([] !== $options && !in_array($valueString, $options, true)) {
                    $errors[] = ['field' => $key, 'message' => 'La valeur choisie pour "' . $label . '" est invalide.', 'type' => 'format'];
                }
            }

            if (($type === 'text' || $type === 'textarea') && mb_strlen($valueString) > 2000) {
                $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" ne peut pas depasser 2000 caracteres.', 'type' => 'format'];
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $details
     * @param array<string,mixed> $aiFieldPlan
     * @return array<int, array{field:string,message:string,type:string}>
     */
    private function validateAiCustomDetails(array $details, array $aiFieldPlan): array
    {
        $errors = [];
        $customFields = is_array($aiFieldPlan['add'] ?? null) ? $aiFieldPlan['add'] : [];

        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ('' === $key) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? $key));
            if ('' === $label) {
                $label = $key;
            }

            $required = true === ($field['required'] ?? false);
            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            $rawValue = $details[$key] ?? '';

            if ('' !== $rawValue && !is_scalar($rawValue)) {
                $errors[] = [
                    'field' => $key,
                    'message' => 'Le champ "' . $label . '" contient un format invalide.',
                    'type' => 'format',
                ];
                continue;
            }

            $value = trim((string) $rawValue);

            if ($required && '' === $value) {
                $errors[] = [
                    'field' => $key,
                    'message' => 'Le champ "' . $label . '" est obligatoire.',
                    'type' => 'blank',
                ];
                continue;
            }

            if ('' === $value) {
                continue;
            }

            if ('number' === $type) {
                if (!is_numeric($value)) {
                    $errors[] = [
                        'field' => $key,
                        'message' => 'Le champ "' . $label . '" doit etre un nombre valide.',
                        'type' => 'format',
                    ];
                    continue;
                }

                if ((float) $value < 0) {
                    $errors[] = [
                        'field' => $key,
                        'message' => 'Le champ "' . $label . '" ne peut pas etre negatif.',
                        'type' => 'format',
                    ];
                }
            }

            if ('date' === $type) {
                try {
                    new \DateTime($value);
                } catch (\Exception) {
                    $errors[] = [
                        'field' => $key,
                        'message' => 'Le champ "' . $label . '" contient une date invalide.',
                        'type' => 'format',
                    ];
                }
            }

            if ('select' === $type) {
                $options = isset($field['options']) && is_array($field['options'])
                    ? array_values(array_map('strval', $field['options']))
                    : [];

                if ([] !== $options && !in_array($value, $options, true)) {
                    $errors[] = [
                        'field' => $key,
                        'message' => 'La valeur choisie pour "' . $label . '" est invalide.',
                        'type' => 'format',
                    ];
                }
            }

            if (($type === 'text' || $type === 'textarea') && mb_strlen($value) > 2000) {
                $errors[] = [
                    'field' => $key,
                    'message' => 'Le champ "' . $label . '" ne peut pas depasser 2000 caracteres.',
                    'type' => 'format',
                ];
            }
        }

        return $errors;
    }
}
