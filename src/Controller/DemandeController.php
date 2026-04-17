<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\DemandeDetail;
use App\Entity\Employe;
use App\Entity\HistoriqueDemande;
use App\Form\DemandeType;
use App\Repository\DemandeRepository;
use App\Services\DemandeMailer;
use App\Services\DemandeFormHelper;
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

    public function __construct(
        DemandeFormHelper $formHelper,
        EntityManagerInterface $em,
        DemandeRepository $demandeRepository,
        DemandeMailer $demandeMailer
    ) {
        $this->formHelper = $formHelper;
        $this->em = $em;
        $this->demandeRepository = $demandeRepository;
        $this->demandeMailer = $demandeMailer;
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
        if (!$employe) {
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

        if ($form->isSubmitted()) {
            $formData      = $request->request->all();
            $submittedType = $formData['demande']['typeDemande'] ?? null;
            $submittedDetails = $formData['details'] ?? [];

            if ($submittedType) {
                $detailErrors = $this->validateDetails($submittedType, $submittedDetails);
            }

            $demande->setEmploye($employe);

            if ($form->isValid() && empty($detailErrors)) {
                $this->em->persist($demande);
                $this->em->flush();

                if (!empty($submittedDetails)) {
                    $demandeDetail = new DemandeDetail();
                    $demandeDetail->setDemande($demande);
                    $demandeDetail->setDetails(json_encode($submittedDetails));
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

                $this->demandeMailer->notifyManagersDemandeCreated($demande);

                $this->addFlash('success', 'Demande creee avec succes.');
                return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
            }
        }

        return $this->render('demande/employe/new.html.twig', [
            'form'             => $form->createView(),
            'categories'       => $this->formHelper->getCategoryTypes(),
            'detailErrors'     => $detailErrors,
            'submittedDetails' => $submittedDetails,
            'submittedType'    => $submittedType,
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

            if (!$demande) {
                return new JsonResponse(['success' => false, 'message' => 'Demande non trouvee'], 404);
            }

            $data        = json_decode($request->getContent(), true);
            $newStatus   = $data['status'] ?? null;
            $commentaire = $data['commentaire'] ?? '';

            if (!$newStatus) {
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

        if (!$demande) {
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

        if (!$demande) {
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

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        if (!$this->canAccessDemande($demande, $session)) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas consulter cette demande.');
        }

        $detailsData    = [];
        $fieldLabels    = [];
        $demandeDetails = $demande->getDemandeDetails();

        if ($demandeDetails->count() > 0) {
            $firstDetail = $demandeDetails->first();
            $detailsData = json_decode($firstDetail->getDetails(), true) ?? [];

            foreach ($detailsData as $key => $value) {
                $fieldLabels[$key] = $this->formHelper->getFieldLabel($demande->getTypeDemande(), $key);
            }
        }

        return $this->render(
            $this->canManageDemandes($session)
                ? 'demande/adminetrh/show.html.twig'
                : 'demande/employe/employe_show.html.twig',
            [
                'demande'     => $demande,
                'detailsData' => $detailsData,
                'fieldLabels' => $fieldLabels,
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

        if (!$demande) {
            $this->addFlash('danger', 'Demande non trouvee.');
            return $this->redirectToRoute('demande_index');
        }

        $newStatus   = $request->request->get('status');
        $commentaire = trim((string) $request->request->get('commentaire', ''));

        if (!$newStatus) {
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

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        if ($demande->getStatus() === 'Annulee') {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier une demande annulee par l\'employe.');
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
            $existingDetails = json_decode($firstDetail->getDetails(), true) ?? [];
        }

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
                $detailErrors = $this->validateDetails($submittedType, $submittedDetails);
            }

            if ($form->isValid() && empty($detailErrors)) {
                $oldStatus   = $this->em->getUnitOfWork()->getOriginalEntityData($demande)['status'] ?? $demande->getStatus();
                $newStatus   = $demande->getStatus();
                $commentaire = $form->get('commentaire')->getData();
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
                    $historique->setCommentaire($commentaire ?? 'Statut modifie');
                    $historique->setDateAction(new \DateTime());
                    $this->em->persist($historique);
                }

                if (!empty($submittedDetails)) {
                    if ($demandeDetails->count() > 0) {
                        $detail = $demandeDetails->first();
                    } else {
                        $detail = new DemandeDetail();
                        $detail->setDemande($demande);
                        $this->em->persist($detail);
                    }
                    $detail->setDetails(json_encode($submittedDetails));
                }

                $this->em->flush();

                if ($statusChanged) {
                    $this->demandeMailer->notifyEmployeStatusChanged($demande, $oldStatus, $newStatus, $actorLabel, (string) $commentaire);
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

    private function validateDetails(string $typeDemande, array $details): array
    {
        $errors = [];
        $fields = $this->formHelper->getFieldsForType($typeDemande);
        $today  = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($fields as $field) {
            $key      = $field['key'];
            $label    = $field['label'];
            $required = $field['required'] ?? false;
            $type     = $field['type'] ?? 'text';
            $value    = $details[$key] ?? null;

            if ($required && ($value === null || trim((string) $value) === '')) {
                $errors[] = ['field' => $key, 'message' => 'Le champ "' . $label . '" est obligatoire.', 'type' => 'blank'];
                continue;
            }
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

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
                    $date = new \DateTime($value);
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
                            if (!empty($details[$startKey])) {
                                $startDate = new \DateTime($details[$startKey]);
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
        }

        return $errors;
    }
}