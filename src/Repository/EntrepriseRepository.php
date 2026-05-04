<?php

namespace App\Repository;

use App\Dto\GroupCountResult;
use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
    /**
     * @return array<int, Entreprise>
     */
    public function findByFilters(?string $search, ?string $status): array
    {
        return $this->createByFiltersQueryBuilder($search, $status)
            ->getQuery()
            ->getResult();
    }

    public function createByFiltersQueryBuilder(?string $search, ?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');

        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(e.nom_entreprise) LIKE :search OR LOWER(e.nom) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere('e.statut = :status')
                ->setParameter('status', $status);
        }

        return $qb->orderBy('e.date_demande', 'DESC');
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function countByStatus(): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('NEW App\\Dto\\GroupCountResult(e.statut, COUNT(e.id_entreprise))')
            ->groupBy('e.statut')
            ->orderBy('COUNT(e.id_entreprise)', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        /** @var GroupCountResult $result */
        foreach ($results as $result) {
            $grouped[(string) $result->label] = $result->getCount();
        }

        return $grouped;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function countByDateDemande(): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('NEW App\\Dto\\GroupCountResult(e.date_demande, COUNT(e.id_entreprise))')
            ->groupBy('e.date_demande')
            ->orderBy('e.date_demande', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        /** @var GroupCountResult $result */
        foreach ($results as $result) {
            $label = $result->label;
            if ($label instanceof \DateTimeInterface) {
                $label = $label->format('Y-m-d H:i:s');
            }

            $grouped[(string) $label] = $result->getCount();
        }

        return $grouped;
    }

    /**
     * @return array<int, array<string, int|string>>
     */
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

