<?php

namespace App\Repository;

use App\Entity\Demande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DemandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Demande::class);
    }

    public function findByEmploye(int $idEmploye): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.idEmploye = :idEmploye')
            ->setParameter('idEmploye', $idEmploye)
            ->orderBy('d.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.idDemande)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.idDemande)')
            ->andWhere('d.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countGroupByStatus(): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.status, COUNT(d.idDemande) as cnt')
            ->groupBy('d.status')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($result as $row) {
            $grouped[$row['status']] = (int)$row['cnt'];
        }
        return $grouped;
    }

    public function countGroupByPriorite(): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.priorite, COUNT(d.idDemande) as cnt')
            ->groupBy('d.priorite')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($result as $row) {
            $grouped[$row['priorite']] = (int)$row['cnt'];
        }
        return $grouped;
    }

    public function countGroupByType(): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.typeDemande, COUNT(d.idDemande) as cnt')
            ->groupBy('d.typeDemande')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($result as $row) {
            $grouped[$row['typeDemande']] = (int)$row['cnt'];
        }
        return $grouped;
    }

    public function countGroupByCategorie(): array
    {
        $result = $this->createQueryBuilder('d')
            ->select('d.categorie, COUNT(d.idDemande) as cnt')
            ->groupBy('d.categorie')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($result as $row) {
            $grouped[$row['categorie']] = (int)$row['cnt'];
        }
        return $grouped;
    }

    public function findWithFilters(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('d');

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

        if (!empty($filters['typeDemande'])) {
            $qb->andWhere('d.typeDemande = :typeDemande')
               ->setParameter('typeDemande', $filters['typeDemande']);
        }

        if (!empty($filters['idEmploye'])) {
            $qb->andWhere('d.idEmploye = :idEmploye')
               ->setParameter('idEmploye', $filters['idEmploye']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('d.titre LIKE :search OR d.description LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb->orderBy('d.dateCreation', 'DESC')
                  ->getQuery()
                  ->getResult();
    }
}