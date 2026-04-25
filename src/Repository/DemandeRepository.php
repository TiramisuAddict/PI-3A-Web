<?php

namespace App\Repository;

use App\Entity\Demande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DemandeRepository extends ServiceEntityRepository
{
    private const RESOLVED_STATUS_VARIANTS = ['Resolue', 'Résolue', 'Resolu', 'Résolu'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    public function findWithFilters(array $filters, ?int $employeId = null): array
    {
        return $this->createFilteredQueryBuilder($filters, $employeId)
            ->orderBy('d.date_creation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(?int $employeId = null, array $filters = []): int
    {
        return (int) $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('COUNT(d.id_demande)')
            ->getQuery()
            ->getSingleScalarResult();
    }

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

    public function countGroupByType(?int $employeId = null, array $filters = []): array
    {
        $results = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.type_demande, COUNT(d.id_demande) as count')
            ->groupBy('d.type_demande')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result['type_demande']] = (int) $result['count'];
        }
        return $grouped;
    }

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

    public function createFilteredQueryBuilder(array $filters = [], ?int $employeId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d');

        if (null !== $employeId) {
            $qb->andWhere('IDENTITY(d.employe) = :employeId')
               ->setParameter('employeId', $employeId);
        }

        if (!empty($filters['categorie'])) {
            $qb->andWhere('d.categorie = :categorie')
               ->setParameter('categorie', $filters['categorie']);
        }

        if (!empty($filters['status'])) {
            if ($this->isResolvedStatus($filters['status'])) {
                $qb->andWhere('d.status IN (:statusVariants)')
                   ->setParameter('statusVariants', self::RESOLVED_STATUS_VARIANTS);
            } else {
                $qb->andWhere('d.status = :status')
                   ->setParameter('status', $filters['status']);
            }
        }

        if (!empty($filters['priorite'])) {
            $qb->andWhere('d.priorite = :priorite')
               ->setParameter('priorite', $filters['priorite']);
        }

        if (!empty($filters['search'])) {
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
            ->setParameter('typeDemande', $typeDemande)
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
            $typeDemande = trim((string) ($row['typeDemande'] ?? ''));
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
            $status = trim((string) ($row['status'] ?? ''));
            if ('' === $text || '' === $status) {
                continue;
            }

            $samples[] = [
                'text' => $text,
                'status' => $status,
                'priorite' => strtoupper(trim((string) ($row['priorite'] ?? 'NORMALE'))),
                'categorie' => trim((string) ($row['categorie'] ?? '')),
                'typeDemande' => trim((string) ($row['typeDemande'] ?? '')),
            ];
        }

        return $samples;
    }

    /**
     * @return array<int, array{prompt:string,general:array<string,mixed>,details:array<string,mixed>,fieldPlan:array<string,mixed>}>
     */
    public function fetchAutreFeedbackSamplesFromDatabase(int $limit = 800): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.titre AS titre, d.description AS description, d.priorite AS priorite, d.categorie AS categorie, d.type_demande AS typeDemande, d.date_creation AS createdAt, dd.details AS detailsJson')
            ->leftJoin('d.demandeDetails', 'dd')
            ->andWhere('d.type_demande = :typeDemande')
            ->setParameter('typeDemande', 'Autre')
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
            if (!$isConfirmedAiFeedback && '' === $rawPrompt) {
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

            $feedbackDetails = is_array($details)
                ? array_filter(
                    $details,
                    static fn ($detailKey): bool => !str_starts_with((string) $detailKey, '_ai_') && !str_starts_with((string) $detailKey, '__ai_'),
                    ARRAY_FILTER_USE_KEY
                )
                : [];

            $general = [
                'titre' => trim((string) ($row['titre'] ?? '')),
                'description' => $description,
                'priorite' => trim((string) ($row['priorite'] ?? '')),
                'categorie' => trim((string) ($row['categorie'] ?? '')),
                'typeDemande' => trim((string) ($row['typeDemande'] ?? 'Autre')),
            ];

            $planAdd = [];
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

            $hasSignal = '' !== $prompt || [] !== $feedbackDetails || '' !== $general['description'] || '' !== $general['titre'];
            if (!$hasSignal) {
                continue;
            }

            $samples[] = [
                'prompt' => $prompt,
                'general' => $general,
                'details' => $feedbackDetails,
                'confirmed' => $isConfirmedAiFeedback,
                'createdAt' => $row['createdAt'] instanceof \DateTimeInterface
                    ? $row['createdAt']->format(DATE_ATOM)
                    : trim((string) ($row['createdAt'] ?? '')),
                'fieldPlan' => [
                    'add' => $planAdd,
                    'remove' => [],
                    'replaceBase' => false,
                ],
            ];
        }

        return $samples;
    }

    private function isResolvedStatus(string $status): bool
    {
        return in_array($status, self::RESOLVED_STATUS_VARIANTS, true);
    }

    private function normalizeStatusKey(?string $status): ?string
    {
        if (null === $status) {
            return null;
        }

        if ($this->isResolvedStatus($status)) {
            return 'Resolue';
        }

        return $status;
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
}
