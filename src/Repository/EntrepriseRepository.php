<?php

namespace App\Repository;

use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entreprise>
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entreprise::class);
    }

    //    /**
    //     * @return Entreprise[] Returns an array of Entreprise objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Entreprise
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
    public function findByFilters($search, $status)
    {
        return $this->createByFiltersQueryBuilder($search, $status)
            ->getQuery()
            ->getResult();
    }

    public function createByFiltersQueryBuilder($search, $status)
    {
        $qb = $this->createQueryBuilder('e');

        if ($search) {
            $qb->andWhere('LOWER(e.nom_entreprise) LIKE :search OR LOWER(e.nom) LIKE :search')
                ->setParameter('search', '%' . strtolower((string) $search) . '%');
        }

        if ($status) {
            $qb->andWhere('e.statut = :status')
                ->setParameter('status', $status);
        }

        return $qb->orderBy('e.date_demande', 'DESC');
    }

    public function countByStatus(): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.statut AS statut, COUNT(e.id_entreprise) AS total')
            ->groupBy('e.statut')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    public function countByDateDemande(): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.date_demande AS date_demande, COUNT(e.id_entreprise) AS total')
            ->groupBy('e.date_demande')
            ->orderBy('e.date_demande', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function countByCountry(int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.pays AS pays, COUNT(e.id_entreprise) AS total')
            ->groupBy('e.pays')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }
}

