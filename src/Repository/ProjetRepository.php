<?php

namespace App\Repository;

use App\Entity\Projet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Projet>
 */
class ProjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Projet::class);
    }

    /**
     * Returns a QueryBuilder applying filters + optional employee restriction (for pagination).
     */
    public function findByFiltersQb(?string $search = null, ?string $statut = null, ?string $priorite = null, ?int $chefProjetId = null, ?int $employeId = null): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.responsable', 'r')
            ->addSelect('r')
            ->orderBy('p.date_debut', 'DESC')
            ->addOrderBy('p.id_projet', 'DESC');

        if ($search !== null && $search !== '') {
            $qb->andWhere('(LOWER(p.nom) LIKE :search OR LOWER(p.description) LIKE :search OR LOWER(r.nom) LIKE :search OR LOWER(r.prenom) LIKE :search)')
               ->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        if ($statut !== null && $statut !== '') {
            $qb->andWhere('p.statut = :statut')->setParameter('statut', $statut);
        }
        if ($priorite !== null && $priorite !== '') {
            $qb->andWhere('p.priorite = :priorite')->setParameter('priorite', $priorite);
        }
        if ($chefProjetId !== null && $chefProjetId > 0) {
            $qb->andWhere('IDENTITY(p.responsable) = :chefProjetId')->setParameter('chefProjetId', $chefProjetId);
        }
        if ($employeId !== null) {
            $qb->leftJoin('p.membresEquipe', 'm')
               ->andWhere('IDENTITY(p.responsable) = :empId OR m.id_employe = :empId')
               ->setParameter('empId', $employeId);
        }

        return $qb;
    }

    /**
     * @return Projet[]
     */
    public function findByFilters(?string $search = null, ?string $statut = null, ?string $priorite = null, ?int $chefProjetId = null, ?\DateTimeInterface $dateFrom = null, ?\DateTimeInterface $dateTo = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.responsable', 'r')
            ->addSelect('r')
            ->orderBy('p.date_debut', 'DESC')
            ->addOrderBy('p.id_projet', 'DESC');

        if ($search !== null && $search !== '') {
            $qb
                ->andWhere('(LOWER(p.nom) LIKE :search OR LOWER(p.description) LIKE :search OR LOWER(r.nom) LIKE :search OR LOWER(r.prenom) LIKE :search)')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($statut !== null && $statut !== '') {
            $qb->andWhere('p.statut = :statut')->setParameter('statut', $statut);
        }

        if ($priorite !== null && $priorite !== '') {
            $qb->andWhere('p.priorite = :priorite')->setParameter('priorite', $priorite);
        }

        if ($chefProjetId !== null && $chefProjetId > 0) {
            $qb->andWhere('IDENTITY(p.responsable) = :chefProjetId')->setParameter('chefProjetId', $chefProjetId);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('p.date_debut >= :dateFrom')->setParameter('dateFrom', $dateFrom->format('Y-m-d'));
        }

        if ($dateTo !== null) {
            $qb->andWhere('p.date_fin_prevue <= :dateTo')->setParameter('dateTo', $dateTo->format('Y-m-d'));
        }

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Projet[] Returns an array of Projet objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Projet
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
