<?php

namespace App\Repository;

use App\Entity\Demande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DemandeRepository extends ServiceEntityRepository
{
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
        $qb = $this->createFilteredQueryBuilder($filters, $employeId)
            ->select('d.status AS status, COUNT(d.id_demande) as cnt')
            ->groupBy('d.status');
        
        $results = $qb->getQuery()->getResult();

        $grouped = [];
        foreach ($results as $result) {
            $status = $result['status'] ?? null;
            $count = (int) ($result['cnt'] ?? 0);
            if ($status !== null) {
                $grouped[$status] = $count;
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
            $qb->andWhere('d.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['priorite'])) {
            $qb->andWhere('d.priorite = :priorite')
               ->setParameter('priorite', $filters['priorite']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('d.titre LIKE :search OR d.description LIKE :search OR d.type_demande LIKE :search OR d.categorie LIKE :search OR d.status LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb;
    }
}
