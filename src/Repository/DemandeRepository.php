<?php

namespace App\Repository;

use App\Entity\Demande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use BackedEnum;

/**
 * @extends ServiceEntityRepository<Demande>
 */
class DemandeRepository extends ServiceEntityRepository
{
    private const RESOLVED_STATUS_VARIANTS = ['Resolue', 'Résolue', 'Resolu', 'Résolu'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    /**
     * @param array<string,mixed> $filters
     * @return Demande[]
     */
    public function findWithFilters(array $filters, ?int $employeId = null): array
    {
        return $this->createFilteredQueryBuilder($filters, $employeId)
            ->orderBy('d.date_creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function countAll(?int $employeId = null, array $filters = []): int
    {
        return (int) $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('COUNT(d.id_demande)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public function countGroupByStatus(?int $employeId = null, array $filters = []): array
    {
        $results = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.status AS status, COUNT(d.id_demande) as cnt')
            ->groupBy('d.status')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $status = $this->normalizeStatusKey($result['status'] ?? null);
            $count  = (int) ($result['cnt'] ?? 0);
            if ($status !== null) {
                $grouped[$status] = ($grouped[$status] ?? 0) + $count;
            }
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public function countGroupByPriorite(?int $employeId = null, array $filters = []): array
    {
        $results = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.priorite, COUNT(d.id_demande) as count')
            ->groupBy('d.priorite')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result['priorite'] ?? 'Non definie'] = (int) $result['count'];
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public function countGroupByType(?int $employeId = null, array $filters = []): array
    {
        $results = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.type_demande, COUNT(d.id_demande) as count')
            ->groupBy('d.type_demande')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $typeDemande = $this->normalizeScalarString($result['type_demande'] ?? null);
            if ('' !== $typeDemande) {
                $grouped[$typeDemande] = (int) $result['count'];
            }
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    public function countGroupByCategorie(?int $employeId = null, array $filters = []): array
    {
        $results = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.categorie, COUNT(d.id_demande) as count')
            ->groupBy('d.categorie')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result['categorie']] = (int) $result['count'];
        }
        return $grouped;
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function createFilteredQueryBuilder(array $filters = [], ?int $employeId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d');

        if (null !== $employeId) {
            $qb->andWhere('IDENTITY(d.employe) = :employeId')
               ->setParameter('employeId', $employeId);
        }

        if (isset($filters['categorie']) && '' !== trim((string) $filters['categorie'])) {
            $qb->andWhere('d.categorie = :categorie')
               ->setParameter('categorie', $filters['categorie']);
        }

        if (isset($filters['status']) && '' !== trim((string) $filters['status'])) {
            if ($this->isResolvedStatus((string) $filters['status'])) {
                $qb->andWhere('d.status IN (:statusVariants)')
                   ->setParameter('statusVariants', self::RESOLVED_STATUS_VARIANTS);
            } else {
                $qb->andWhere('d.status = :status')
                   ->setParameter('status', $this->normalizeStatusParameter((string) $filters['status']));
            }
        }

        if (isset($filters['priorite']) && '' !== trim((string) $filters['priorite'])) {
            $qb->andWhere('d.priorite = :priorite')
               ->setParameter('priorite', $filters['priorite']);
        }

        if (isset($filters['search']) && '' !== trim((string) $filters['search'])) {
            $qb->andWhere(
                'd.titre LIKE :search OR d.description LIKE :search OR d.type_demande LIKE :search OR d.categorie LIKE :search OR d.status LIKE :search'
            )->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb;
    }

    public function countRecentSameTypeForEmploye(
        int $employeId,
        string $typeDemande,
        \DateTimeInterface $referenceDate,
        int $windowDays = 7,
        ?int $referenceDemandeId = null
    ): int {
        $startDate = (new \DateTimeImmutable($referenceDate->format('Y-m-d')))->modify('-' . max(0, $windowDays) . ' days');

        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id_demande)')
            ->andWhere('IDENTITY(d.employe) = :employeId')
            ->andWhere('d.type_demande = :typeDemande')
            ->andWhere('d.date_creation >= :startDate')
            ->setParameter('employeId', $employeId)
            ->setParameter('typeDemande', $this->normalizeTypeParameter($typeDemande))
            ->setParameter('startDate', $startDate)
            ->setParameter('referenceDate', $referenceDate);

        if (null !== $referenceDemandeId) {
            $qb->andWhere('(d.date_creation < :referenceDate OR (d.date_creation = :referenceDate AND d.id_demande < :referenceDemandeId))')
               ->setParameter('referenceDemandeId', $referenceDemandeId);
        } else {
            $qb->andWhere('d.date_creation < :referenceDate');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array{text:string,categorie:string,typeDemande:string,priorite:string}>
     */
    public function fetchClassificationTrainingSamples(int $limit = 1200): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.titre AS titre, d.description AS description, d.categorie AS categorie, d.type_demande AS typeDemande, d.priorite AS priorite')
            ->andWhere('d.categorie IS NOT NULL')
            ->andWhere('d.type_demande IS NOT NULL')
            ->andWhere('d.priorite IS NOT NULL')
            ->orderBy('d.date_creation', 'DESC')
            ->setMaxResults(max(100, $limit))
            ->getQuery()
            ->getArrayResult();

        $samples = [];
        foreach ($rows as $row) {
            $titre = trim((string) ($row['titre'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $text = trim($titre . ' ' . $description);

            if ('' === $text) {
                continue;
            }

            $categorie = trim((string) ($row['categorie'] ?? ''));
            $typeDemande = $this->normalizeScalarString($row['typeDemande'] ?? null);
            $priorite = strtoupper(trim((string) ($row['priorite'] ?? 'NORMALE')));

            if ('' === $categorie || '' === $typeDemande) {
                continue;
            }

            if (!in_array($priorite, ['HAUTE', 'NORMALE', 'BASSE'], true)) {
                $priorite = 'NORMALE';
            }


            $samples[] = [
                'text' => $text,
                'categorie' => $categorie,
                'typeDemande' => $typeDemande,
                'priorite' => $priorite,
            ];
        }

        return $samples;
    }

    /**
     * @return array<int, array{text:string,status:string,priorite:string,categorie:string,typeDemande:string}>
     */
    public function fetchDecisionTrainingSamples(int $limit = 1800): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.titre AS titre, d.description AS description, d.status AS status, d.priorite AS priorite, d.categorie AS categorie, d.type_demande AS typeDemande')
            ->andWhere('d.status IS NOT NULL')
            ->orderBy('d.date_creation', 'DESC')
            ->setMaxResults(max(100, $limit))
            ->getQuery()
            ->getArrayResult();

        $samples = [];
        foreach ($rows as $row) {
            $text = trim(trim((string) ($row['titre'] ?? '')) . ' ' . trim((string) ($row['description'] ?? '')));
            $status = $this->normalizeScalarString($row['status'] ?? null);
            if ('' === $text || '' === $status) {
                continue;
            }

            $samples[] = [
                'text' => $text,
                'status' => $status,
                'priorite' => strtoupper(trim((string) ($row['priorite'] ?? 'NORMALE'))),
                'categorie' => trim((string) ($row['categorie'] ?? '')),
                'typeDemande' => $this->normalizeScalarString($row['typeDemande'] ?? null),
            ];
        }

        return $samples;
    }

    /**
     * @return array<int, array{prompt:string,general:array<string,mixed>,details:array<string,mixed>,fieldPlan:array<string,mixed>}>
     */
    public function fetchAutreFeedbackSamplesFromDatabase(int $limit = 2500): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.titre AS titre, d.description AS description, d.priorite AS priorite, d.categorie AS categorie, d.type_demande AS typeDemande, d.date_creation AS createdAt, dd.details AS detailsJson')
            ->leftJoin('d.demandeDetails', 'dd')
            ->andWhere('dd.details LIKE :seedMarker OR dd.details LIKE :confirmedMarker OR dd.details LIKE :manualMarker')
            ->setParameter('seedMarker', '%"_ai_seed_autre_ml":true%')
            ->setParameter('confirmedMarker', '%"_ai_feedback_confirmed":true%')
            ->setParameter('manualMarker', '%"_ai_manual_fields":true%')
            ->orderBy('d.date_creation', 'DESC')
            ->setMaxResults(max(100, $limit))
            ->getQuery()
            ->getArrayResult();

        $samples = [];
        foreach ($rows as $row) {
            $detailsRaw = $row['detailsJson'] ?? null;
            $details = [];
            if (is_string($detailsRaw) && '' !== trim($detailsRaw)) {
                $decoded = json_decode($detailsRaw, true);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            } elseif (is_array($detailsRaw)) {
                $details = $detailsRaw;
            }

            $rawPrompt = trim((string) ($details['_ai_raw_prompt'] ?? $details['__ai_raw_prompt'] ?? ''));
            $isConfirmedAiFeedback = $this->toBooleanValue(
                $details['_ai_feedback_confirmed'] ?? $details['__ai_feedback_confirmed'] ?? false
            );
            $isManualFields = $this->toBooleanValue(
                $details['_ai_manual_fields'] ?? $details['__ai_manual_fields'] ?? false
            );
            if (!$isConfirmedAiFeedback && !$isManualFields) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $prompt = $rawPrompt;
            if ('' === $prompt) {
                $prompt = trim((string) ($details['descriptionBesoin'] ?? ''));
            }
            if ('' === $prompt) {
                $prompt = $description;
            }

            $feedbackDetails = array_filter(
                $details,
                static fn ($detailKey): bool => !str_starts_with((string) $detailKey, '_ai_') && !str_starts_with((string) $detailKey, '__ai_'),
                ARRAY_FILTER_USE_KEY
            );

            $general = [
                'titre' => trim((string) ($row['titre'] ?? '')),
                'description' => $description,
                'priorite' => trim((string) ($row['priorite'] ?? '')),
                'categorie' => trim((string) ($row['categorie'] ?? '')),
                'typeDemande' => $this->normalizeScalarString($row['typeDemande'] ?? null, 'Autre'),
            ];

            $storedFieldPlan = $details['_ai_field_plan'] ?? $details['__ai_field_plan'] ?? null;
            if (is_string($storedFieldPlan) && '' !== trim($storedFieldPlan)) {
                $decodedPlan = json_decode($storedFieldPlan, true);
                $storedFieldPlan = is_array($decodedPlan) ? $decodedPlan : null;
            }
            if (!is_array($storedFieldPlan)) {
                $storedFieldPlan = [];
            }

            $generatedSnapshot = $details['_ai_generation_snapshot'] ?? $details['__ai_generation_snapshot'] ?? null;
            if (is_string($generatedSnapshot) && '' !== trim($generatedSnapshot)) {
                $decodedSnapshot = json_decode($generatedSnapshot, true);
                $generatedSnapshot = is_array($decodedSnapshot) ? $decodedSnapshot : null;
            }
            if (!is_array($generatedSnapshot)) {
                $generatedSnapshot = [];
            }

            $planAdd = is_array($storedFieldPlan['add'] ?? null) ? array_values($storedFieldPlan['add']) : [];
            foreach ($planAdd as $index => $field) {
                if (!is_array($field)) {
                    unset($planAdd[$index]);
                    continue;
                }

                $key = trim((string) ($field['key'] ?? ''));
                if ('' === $key) {
                    unset($planAdd[$index]);
                    continue;
                }

                if (isset($feedbackDetails[$key]) && is_scalar($feedbackDetails[$key])) {
                    $planAdd[$index]['value'] = trim((string) $feedbackDetails[$key]);
                }
            }
            $planAdd = array_values($planAdd);
            $hadStoredPlanAdd = [] !== $planAdd;

            if ($isManualFields) {
                $planAdd = array_values(array_filter(
                    $planAdd,
                    fn (array $field): bool => !$this->isGeneratedAutreFieldSource($field['source'] ?? null)
                ));
            }

            if ([] === $planAdd && !$hadStoredPlanAdd) {
                foreach ($feedbackDetails as $detailKey => $detailValue) {
                    $key = trim((string) $detailKey);
                    if ('' === $key || 0 !== strpos($key, 'ai_')) {
                        continue;
                    }

                    $value = is_scalar($detailValue) ? trim((string) $detailValue) : '';
                    if ('' === $value) {
                        continue;
                    }

                    $type = 'text';
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 || str_contains($key, 'date')) {
                        $type = 'date';
                    } elseif (is_numeric($value)) {
                        $type = 'number';
                    } elseif (mb_strlen($value) > 80 || str_contains($key, 'justification') || str_contains($key, 'description')) {
                        $type = 'textarea';
                    }

                    $label = trim((string) preg_replace('/\s+/', ' ', str_replace('_', ' ', preg_replace('/^ai_/', '', $key) ?? $key)));
                    $label = '' !== $label ? ucfirst($label) : $key;

                    $planAdd[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                        'required' => in_array($key, ['ai_nom_formation', 'ai_lieu_depart_actuel', 'ai_lieu_souhaite'], true),
                        'value' => $value,
                    ];
                }
            }

            if ($isManualFields && [] !== $planAdd) {
                $manualDetailKeys = [];
                foreach ($planAdd as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $key = trim((string) ($field['key'] ?? ''));
                    if ('' !== $key) {
                        $manualDetailKeys[$key] = true;
                    }
                }

                $feedbackDetails = array_filter(
                    $feedbackDetails,
                    static fn ($detailKey): bool => isset($manualDetailKeys[(string) $detailKey]),
                    ARRAY_FILTER_USE_KEY
                );
            }

            $hasSignal = false;
            if ('' !== $prompt) {
                $hasSignal = true;
            }
            if (!$hasSignal && count($feedbackDetails) > 0) {
                $hasSignal = true;
            }
            if (!$hasSignal && '' !== $general['description']) {
                $hasSignal = true;
            }
            if (!$hasSignal && '' !== $general['titre']) {
                $hasSignal = true;
            }

            if (!$hasSignal) {
                continue;
            }

            $replaceBase = [] !== $planAdd;
            if (!$replaceBase && (($storedFieldPlan['replaceBase'] ?? null) === true)) {
                $replaceBase = true;
            }

            $confirmed = $isConfirmedAiFeedback || $isManualFields;

            $samples[] = [
                'prompt' => $prompt,
                'general' => $general,
                'details' => $feedbackDetails,
                'confirmed' => $confirmed,
                'manual' => $isManualFields,
                'createdAt' => $row['createdAt'] instanceof \DateTimeInterface
                    ? $row['createdAt']->format(DATE_ATOM)
                    : trim((string) ($row['createdAt'] ?? '')),
                'fieldPlan' => [
                    'add' => $planAdd,
                    'remove' => is_array($storedFieldPlan['remove'] ?? null)
                        ? array_values(array_map('strval', $storedFieldPlan['remove']))
                        : [],
                    'replaceBase' => $replaceBase,
                    'manualMode' => $isManualFields,
                ],
                'generatedSnapshot' => $generatedSnapshot,
            ];
        }

        return $samples;
    }

    private function isResolvedStatus(string $status): bool
    {
        return in_array($status, self::RESOLVED_STATUS_VARIANTS, true);
    }

    private function normalizeStatusKey(mixed $status): ?string
    {
        $normalizedStatus = $this->normalizeScalarString($status);
        if ('' === $normalizedStatus) {
            return null;
        }

        if ($this->isResolvedStatus($normalizedStatus)) {
            return 'Resolue';
        }

        return $normalizedStatus;
    }

    private function normalizeStatusParameter(string $status): string
    {
        return trim($status);
    }

    private function normalizeTypeParameter(string $typeDemande): string
    {
        return trim($typeDemande);
    }

    private function normalizeScalarString(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return is_string($value->value) ? $value->value : (string) $value->value;
        }

        if (null === $value) {
            return $default;
        }

        return trim((string) $value);
    }

    private function toBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'oui', 'on'], true);
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
}
