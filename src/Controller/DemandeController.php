<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\DemandeDetail;
use App\Entity\HistoriqueDemande;
use App\Entity\Employe;
use App\Form\DemandeType;
use App\Repository\DemandeRepository;
use App\Service\DemandeFormHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DemandeController extends AbstractController
{
    private DemandeFormHelper $formHelper;
    private EntityManagerInterface $em;
    private DemandeRepository $demandeRepository;

    public function __construct(
        DemandeFormHelper $formHelper,
        EntityManagerInterface $em,
        DemandeRepository $demandeRepository
    ) {
        $this->formHelper = $formHelper;
        $this->em = $em;
        $this->demandeRepository = $demandeRepository;
    }

    #[Route('/demande', name: 'demande_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'categorie' => $request->query->get('categorie'),
            'status' => $request->query->get('status'),
            'priorite' => $request->query->get('priorite'),
            'search' => $request->query->get('search'),
        ];

        $demandes = method_exists($this->demandeRepository, 'findWithFilters')
            ? $this->demandeRepository->findWithFilters(array_filter($filters))
            : $this->demandeRepository->findAll();

        $stats = [
            'total' => method_exists($this->demandeRepository, 'countAll') ? $this->demandeRepository->countAll() : count($demandes),
            'byStatus' => method_exists($this->demandeRepository, 'countGroupByStatus') ? $this->demandeRepository->countGroupByStatus() : [],
            'byPriorite' => method_exists($this->demandeRepository, 'countGroupByPriorite') ? $this->demandeRepository->countGroupByPriorite() : [],
        ];

        return $this->render('demande/index.html.twig', [
            'demandes' => $demandes,
            'categories' => $this->formHelper->getCategories(),
            'statuses' => $this->formHelper->getStatuses(),
            'priorites' => $this->formHelper->getPriorites(),
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }

    #[Route('/demande/nouvelle', name: 'demande_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $demande = new Demande();
        $demande->setDateCreation(new \DateTime());
        $demande->setStatus('Nouvelle');

        $form = $this->createForm(DemandeType::class, $demande, ['is_edit' => false]);
        $form->handleRequest($request);

        $detailErrors = [];
        $submittedDetails = [];
        $submittedType = null;

        if ($form->isSubmitted()) {
            $formData = $request->request->all();
            $submittedType = $formData['demande']['typeDemande'] ?? null;
            $submittedDetails = $formData['details'] ?? [];

            if ($submittedType) {
                $detailErrors = $this->validateDetails($submittedType, $submittedDetails);
            }

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
                $historique->setActeur($this->getEmployeeName($demande->getEmploye()));
                $historique->setCommentaire('Demande creee');
                $historique->setDateAction(new \DateTime());
                $this->em->persist($historique);
                $this->em->flush();

                $this->addFlash('success', 'Demande creee avec succes.');
                return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
            }
        }

        return $this->render('demande/new.html.twig', [
            'form' => $form->createView(),
            'categories' => $this->formHelper->getCategoryTypes(),
            'detailErrors' => $detailErrors,
            'submittedDetails' => $submittedDetails,
            'submittedType' => $submittedType,
        ]);
    }

    #[Route('/demande/statistics', name: 'demande_statistics', methods: ['GET'])]
    public function statistics(): Response
    {
        $stats = [
            'total' => method_exists($this->demandeRepository, 'countAll') ? $this->demandeRepository->countAll() : 0,
            'byStatus' => method_exists($this->demandeRepository, 'countGroupByStatus') ? $this->demandeRepository->countGroupByStatus() : [],
            'byPriorite' => method_exists($this->demandeRepository, 'countGroupByPriorite') ? $this->demandeRepository->countGroupByPriorite() : [],
            'byType' => method_exists($this->demandeRepository, 'countGroupByType') ? $this->demandeRepository->countGroupByType() : [],
            'byCategorie' => method_exists($this->demandeRepository, 'countGroupByCategorie') ? $this->demandeRepository->countGroupByCategorie() : [],
        ];

        return $this->render('demande/statistics.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/demande/api/fields/{type}', name: 'demande_controller_api_fields', methods: ['GET'])]
    public function getFields(string $type): JsonResponse
    {
        return new JsonResponse($this->formHelper->getFieldsForType($type));
    }

    #[Route('/demande/action/status/{id}', name: 'demande_update_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        try {
            $demande = $this->demandeRepository->find($id);

            if (!$demande) {
                return new JsonResponse(['success' => false, 'message' => 'Demande non trouvee'], 404);
            }

            $data = json_decode($request->getContent(), true);
            $newStatus = $data['status'] ?? null;
            $commentaire = $data['commentaire'] ?? '';

            if (!$newStatus) {
                return new JsonResponse(['success' => false, 'message' => 'Statut requis'], 400);
            }

            $oldStatus = $demande->getStatus();
            $demande->setStatus($newStatus);

            $historique = new HistoriqueDemande();
            $historique->setDemande($demande);
            $historique->setAncienStatut($oldStatus);
            $historique->setNouveauStatut($newStatus);
            $historique->setActeur($this->getEmployeeName($demande->getEmploye()));
            $historique->setCommentaire($commentaire);
            $historique->setDateAction(new \DateTime());

            $this->em->persist($historique);
            $this->em->flush();

            return new JsonResponse(['success' => true, 'message' => 'Statut mis a jour']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/demande/action/delete/{id}', name: 'demande_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $demande = $this->demandeRepository->find($id);

            if (!$demande) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Demande non trouvee'
                ], 404);
            }

            foreach ($demande->getHistoriqueDemandes() as $historique) {
                $this->em->remove($historique);
            }

            foreach ($demande->getDemandeDetails() as $detail) {
                $this->em->remove($detail);
            }

            $this->em->remove($demande);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Demande supprimee'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/demande/{id}', name: 'demande_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        $detailsData = [];
        $fieldLabels = [];
        $demandeDetails = $demande->getDemandeDetails();

        if ($demandeDetails->count() > 0) {
            $firstDetail = $demandeDetails->first();
            $detailsData = json_decode($firstDetail->getDetails(), true) ?? [];

            foreach ($detailsData as $key => $value) {
                $fieldLabels[$key] = $this->formHelper->getFieldLabel($demande->getTypeDemande(), $key);
            }
        }

        return $this->render('demande/show.html.twig', [
            'demande' => $demande,
            'detailsData' => $detailsData,
            'fieldLabels' => $fieldLabels,
            'statuses' => $this->formHelper->getStatuses(),
        ]);
    }

    #[Route('/demande/{id}/edit', name: 'demande_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $demande = $this->demandeRepository->find($id);

        if (!$demande) {
            throw $this->createNotFoundException('Demande non trouvee');
        }

        $existingDetails = [];
        $demandeDetails = $demande->getDemandeDetails();
        if ($demandeDetails->count() > 0) {
            $firstDetail = $demandeDetails->first();
            $existingDetails = json_decode($firstDetail->getDetails(), true) ?? [];
        }

        $form = $this->createForm(DemandeType::class, $demande, ['is_edit' => true]);
        $form->handleRequest($request);

        $detailErrors = [];
        $submittedDetails = $existingDetails;
        $submittedType = $demande->getTypeDemande();

        if ($form->isSubmitted()) {
            $formData = $request->request->all();
            $submittedType = $formData['demande']['typeDemande'] ?? null;
            $submittedDetails = $formData['details'] ?? [];

            if ($submittedType) {
                $detailErrors = $this->validateDetails($submittedType, $submittedDetails);
            }

            if ($form->isValid() && empty($detailErrors)) {
                $oldStatus = $this->em->getUnitOfWork()->getOriginalEntityData($demande)['status'] ?? $demande->getStatus();
                $newStatus = $demande->getStatus();
                $commentaire = $form->get('commentaire')->getData();

                if ($oldStatus !== $newStatus) {
                    $historique = new HistoriqueDemande();
                    $historique->setDemande($demande);
                    $historique->setAncienStatut($oldStatus);
                    $historique->setNouveauStatut($newStatus);
                    $historique->setActeur($this->getEmployeeName($demande->getEmploye()));
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

                $this->addFlash('success', 'Demande mise a jour avec succes.');
                return $this->redirectToRoute('demande_show', ['id' => $demande->getIdDemande()]);
            }
        }

        return $this->render('demande/edit.html.twig', [
            'demande' => $demande,
            'form' => $form->createView(),
            'categories' => $this->formHelper->getCategoryTypes(),
            'statuses' => $this->formHelper->getStatuses(),
            'existingDetails' => $submittedDetails,
            'detailErrors' => $detailErrors,
            'submittedType' => $submittedType,
        ]);
    }

    private function validateDetails(string $typeDemande, array $details): array
    {
        $errors = [];
        $fields = $this->formHelper->getFieldsForType($typeDemande);
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($fields as $field) {
            $key = $field['key'];
            $label = $field['label'];
            $required = $field['required'] ?? false;
            $type = $field['type'] ?? 'text';
            $value = $details[$key] ?? null;

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
                    str_contains(strtolower($key), 'nombre') ||
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
                    $lowerKey = strtolower($key);
                    $lowerLabel = strtolower($label);

                    $mustBeFuture =
                        str_contains($lowerKey, 'datedebut') ||
                        str_contains($lowerKey, 'datesouhaitee') ||
                        str_contains($lowerKey, 'datepassage') ||
                        str_contains($lowerKey, 'dateheuressup') ||
                        str_contains($lowerLabel, 'date de debut') ||
                        str_contains($lowerLabel, 'date souhaitee') ||
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

    private function getEmployeeName(?Employe $employe = null): string
    {
        if ($employe) {
            return trim(($employe->getNom() ?? '') . ' ' . ($employe->getPrenom() ?? ''));
        }

        return 'Systeme';
    }
}