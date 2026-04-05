<?php

namespace App\Controller;

use App\Entity\Demande;
use App\Entity\DemandeDetail;
use App\Entity\HistoriqueDemande;
use App\Entity\Employé;
use App\Repository\DemandeRepository;
use App\Service\DemandeFormHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/demande')]
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

    #[Route('/', name: 'demande_index')]
    public function index(Request $request): Response
    {
        $filters = [
            'categorie' => $request->query->get('categorie'),
            'status' => $request->query->get('status'),
            'priorite' => $request->query->get('priorite'),
            'search' => $request->query->get('search'),
        ];

        $demandes = $this->demandeRepository->findWithFilters(array_filter($filters));

        $stats = [
            'total' => $this->demandeRepository->countAll(),
            'byStatus' => $this->demandeRepository->countGroupByStatus(),
            'byPriorite' => $this->demandeRepository->countGroupByPriorite(),
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

    #[Route('/nouvelle', name: 'demande_new')]
    public function new(): Response
    {
        return $this->render('demande/new.html.twig', [
            'categories' => $this->formHelper->getCategoryTypes(),
            'priorites' => $this->formHelper->getPriorites(),
            'employees' => $this->getEmployeesList(),
        ]);
    }

    #[Route('/api/fields/{type}', name: 'demande_api_fields', methods: ['GET'])]
    public function getFields(string $type): JsonResponse
    {
        return new JsonResponse($this->formHelper->getFieldsForType($type));
    }

    #[Route('/api/create', name: 'demande_api_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Donnees invalides'
                ], 400);
            }

            $validationErrors = $this->validateDemandePayload($data, false);
            if (!empty($validationErrors)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => implode(' ', $validationErrors)
                ], 400);
            }

            $employeeId = $data['idEmploye'] ?? $this->getEmployeeId();
            if (!$employeeId || !$this->isValidEmployeeId((int)$employeeId)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'ID employe invalide. Veuillez selectionner un employe valide.'
                ], 400);
            }

            $employe = $this->em->getRepository(Employé::class)->find((int)$employeeId);

            $demande = new Demande();
            $demande->setEmployé($employe);
            $demande->setCategorie($data['categorie'] ?? '');
            $demande->setTypeDemande($data['typeDemande'] ?? '');
            $demande->setTitre($data['titre'] ?? '');
            $demande->setDescription($data['description'] ?? '');
            $demande->setPriorite($data['priorite'] ?? 'NORMALE');
            $demande->setStatus('Nouvelle');
            $demande->setDateCreation(new \DateTime());

            $this->em->persist($demande);
            $this->em->flush();

            if (!empty($data['details']) && is_array($data['details'])) {
                $detailsEntity = new DemandeDetail();
                $detailsEntity->setDemande($demande);
                $detailsEntity->setDetails(json_encode($data['details']));
                $this->em->persist($detailsEntity);
                $this->em->flush();
            }

            $historique = new HistoriqueDemande();
            $historique->setDemande($demande);
            $historique->setNouveauStatut('Nouvelle');
            $historique->setActeur($this->getEmployeeName());
            $historique->setCommentaire('Demande creee');
            $historique->setDateAction(new \DateTime());

            $this->em->persist($historique);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'id' => $demande->getIdDemande(),
                'message' => 'Demande creee avec succes'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}', name: 'demande_show', requirements: ['id' => '\d+'])]
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
            $detailsJson = $firstDetail->getDetails();
            $detailsData = json_decode($detailsJson, true) ?? [];

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

    #[Route('/{id}/edit', name: 'demande_edit', requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
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

        return $this->render('demande/edit.html.twig', [
            'demande' => $demande,
            'categories' => $this->formHelper->getCategoryTypes(),
            'priorites' => $this->formHelper->getPriorites(),
            'statuses' => $this->formHelper->getStatuses(),
            'existingDetails' => $existingDetails,
        ]);
    }

    #[Route('/api/{id}/update', name: 'demande_api_update', methods: ['POST'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $demande = $this->demandeRepository->find($id);

            if (!$demande) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Demande non trouvee'
                ], 404);
            }

            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Donnees invalides'
                ], 400);
            }

            $validationErrors = $this->validateDemandePayload($data, true);
            if (!empty($validationErrors)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => implode(' ', $validationErrors)
                ], 400);
            }

            $oldStatus = $demande->getStatus();

            $demande->setCategorie($data['categorie'] ?? $demande->getCategorie());
            $demande->setTypeDemande($data['typeDemande'] ?? $demande->getTypeDemande());
            $demande->setTitre($data['titre'] ?? $demande->getTitre());
            $demande->setDescription($data['description'] ?? $demande->getDescription());
            $demande->setPriorite($data['priorite'] ?? $demande->getPriorite());

            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                $demande->setStatus($data['status']);

                $historique = new HistoriqueDemande();
                $historique->setDemande($demande);
                $historique->setAncienStatut($oldStatus);
                $historique->setNouveauStatut($data['status']);
                $historique->setActeur($this->getEmployeeName());
                $historique->setCommentaire($data['commentaire'] ?? 'Statut modifie');
                $historique->setDateAction(new \DateTime());

                $this->em->persist($historique);
            }

            if (isset($data['details']) && is_array($data['details'])) {
                $demandeDetails = $demande->getDemandeDetails();

                if ($demandeDetails->count() > 0) {
                    $details = $demandeDetails->first();
                } else {
                    $details = new DemandeDetail();
                    $details->setDemande($demande);
                    $this->em->persist($details);
                }

                $details->setDetails(json_encode($data['details']));
            }

            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Demande mise a jour avec succes'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/{id}/status', name: 'demande_api_status', methods: ['POST'])]
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
            $historique->setActeur($this->getEmployeeName());
            $historique->setCommentaire($commentaire);
            $historique->setDateAction(new \DateTime());

            $this->em->persist($historique);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Statut mis a jour avec succes'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/api/{id}/delete', name: 'demande_api_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $demande = $this->demandeRepository->find($id);

            if (!$demande) {
                return new JsonResponse(['success' => false, 'message' => 'Demande non trouvee'], 404);
            }

            $this->em->remove($demande);
            $this->em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Demande supprimee avec succes'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/statistics', name: 'demande_statistics')]
    public function statistics(): Response
    {
        $stats = [
            'total' => $this->demandeRepository->countAll(),
            'byStatus' => $this->demandeRepository->countGroupByStatus(),
            'byPriorite' => $this->demandeRepository->countGroupByPriorite(),
            'byType' => $this->demandeRepository->countGroupByType(),
            'byCategorie' => $this->demandeRepository->countGroupByCategorie(),
        ];

        return $this->render('demande/statistics.html.twig', [
            'stats' => $stats,
        ]);
    }

    private function validateDemandePayload(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (empty(trim($data['categorie'] ?? ''))) {
            $errors[] = 'La categorie est obligatoire.';
        }

        if (empty(trim($data['typeDemande'] ?? ''))) {
            $errors[] = 'Le type de demande est obligatoire.';
        }

        if (empty(trim($data['titre'] ?? ''))) {
            $errors[] = 'Le titre est obligatoire.';
        }

        if (empty(trim($data['priorite'] ?? ''))) {
            $errors[] = 'La priorite est obligatoire.';
        }

        if ($isUpdate && empty(trim($data['status'] ?? ''))) {
            $errors[] = 'Le statut est obligatoire.';
        }

        if (isset($data['details']) && is_array($data['details'])) {
            $detailErrors = $this->validateDetailFields($data['typeDemande'] ?? '', $data['details']);
            $errors = array_merge($errors, $detailErrors);
        }

        return $errors;
    }

    private function validateDetailFields(string $typeDemande, array $details): array
    {
        $errors = [];
        $fields = $this->formHelper->getFieldsForType($typeDemande);
        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        foreach ($fields as $field) {
            $key = $field['key'];
            $label = $field['label'];
            $required = $field['required'] ?? false;
            $value = $details[$key] ?? null;

            if ($required && ($value === null || trim((string)$value) === '')) {
                $errors[] = 'Le champ "' . $label . '" est obligatoire.';
                continue;
            }

            if ($value === null || trim((string)$value) === '') {
                continue;
            }

            if ($field['type'] === 'number') {
                if (!is_numeric($value)) {
                    $errors[] = 'Le champ "' . $label . '" doit etre numerique.';
                } elseif ((float)$value < 0) {
                    $errors[] = 'Le champ "' . $label . '" ne peut pas etre negatif.';
                } elseif (
                    str_contains(strtolower($key), 'montant') ||
                    str_contains(strtolower($key), 'nombre') ||
                    str_contains(strtolower($key), 'quantite') ||
                    str_contains(strtolower($key), 'cout')
                ) {
                    if ((float)$value <= 0) {
                        $errors[] = 'Le champ "' . $label . '" doit etre superieur a 0.';
                    }
                }
            }

            if ($field['type'] === 'date') {
                try {
                    $date = new \DateTime($value);
                    $date->setTime(0, 0, 0);
                    $lowerKey = strtolower($key);
                    $lowerLabel = strtolower($label);

                    $mustBeTodayOrFuture =
                        str_contains($lowerKey, 'datedebut') ||
                        str_contains($lowerKey, 'datesouhaitee') ||
                        str_contains($lowerKey, 'datepassage') ||
                        str_contains($lowerKey, 'datedebutteletravail') ||
                        str_contains($lowerKey, 'datedebuthoraires') ||
                        str_contains($lowerKey, 'datedebutformation') ||
                        str_contains($lowerLabel, 'date de debut') ||
                        str_contains($lowerLabel, 'date souhaitee') ||
                        str_contains($lowerLabel, 'date de passage');

                    if ($mustBeTodayOrFuture && $date < $today) {
                        $errors[] = 'Le champ "' . $label . '" ne peut pas etre inferieur a la date actuelle.';
                    }

                    $isEndDate = str_contains($lowerKey, 'datefin') ||
                        str_contains($lowerLabel, 'date de fin');

                    if ($isEndDate) {
                        $relatedStartKeys = [
                            'dateDebut',
                            'dateDebutTeletravail',
                            'dateDebutHoraires',
                            'dateSouhaiteeFormation',
                            'datePassage'
                        ];

                        $startValue = null;
                        foreach ($relatedStartKeys as $startKey) {
                            if (!empty($details[$startKey])) {
                                $startValue = $details[$startKey];
                                break;
                            }
                        }

                        if ($startValue) {
                            $startDate = new \DateTime($startValue);
                            $startDate->setTime(0, 0, 0);
                            if ($date < $startDate) {
                                $errors[] = 'Le champ "' . $label . '" doit etre superieur ou egal a la date de debut.';
                            }
                        } elseif ($date < $today) {
                            $errors[] = 'Le champ "' . $label . '" ne peut pas etre inferieur a la date actuelle.';
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Le champ "' . $label . '" contient une date invalide.';
                }
            }
        }

        return $errors;
    }

    private function getEmployeeId(): ?int
    {
        try {
            $session = $this->container->get('request_stack')->getSession();
            $id = $session->get('employee_id');
            if ($id) {
                return (int) $id;
            }
        } catch (\Exception $e) {
        }
        return $this->getFirstEmployeeId();
    }

    private function getEmployeeName(): string
    {
        try {
            $session = $this->container->get('request_stack')->getSession();
            $name = $session->get('employee_name');
            if ($name) {
                return $name;
            }
        } catch (\Exception $e) {
        }
        return 'Systeme';
    }

    private function getFirstEmployeeId(): ?int
    {
        try {
            $conn = $this->em->getConnection();
            $result = $conn->executeQuery("SELECT id_employe FROM `employé` LIMIT 1")->fetchAssociative();
            if ($result && isset($result['id_employe'])) {
                return (int) $result['id_employe'];
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    private function isValidEmployeeId(int $id): bool
    {
        try {
            $conn = $this->em->getConnection();
            $result = $conn->executeQuery(
                "SELECT id_employe FROM `employé` WHERE id_employe = ?",
                [$id]
            )->fetchAssociative();
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getEmployeesList(): array
    {
        try {
            $conn = $this->em->getConnection();
            return $conn->executeQuery(
                "SELECT id_employe, CONCAT(COALESCE(nom, ''), ' ', COALESCE(prenom, '')) as nom_complet FROM `employé` ORDER BY nom, prenom"
            )->fetchAllAssociative();
        } catch (\Exception $e) {
            return [];
        }
    }
}