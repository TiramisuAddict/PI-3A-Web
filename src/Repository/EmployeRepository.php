<?php

namespace App\Repository;

use App\Entity\Employe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Employe>
 */
class EmployeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employe
        ::class);
    }

    //    /**
    //     * @return Employe[] Returns an array of Employe objects
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

    //    public function findOneBySomeField($value): ?Employé
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
    public function createFilteredQueryBuilder($entreprise, ?string $search, ?string $role)
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.entreprise = :entreprise')
            ->setParameter('entreprise', $entreprise);

        if ($search) {
            $qb->andWhere('LOWER(e.nom) LIKE :search OR LOWER(e.prenom) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($role) {
            $qb->andWhere('LOWER(e.role) = :role')
                ->setParameter('role', strtolower($role));
        }

        return $qb;
    }

    public function findByEntrepriseAndFilters($entreprise, ?string $search, ?string $role): array
    {
        return $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->orderBy('e.id_employe', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByEntrepriseAndFiltersPaginated($entreprise, ?string $search, ?string $role, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        return $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->orderBy('e.id_employe', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByEntrepriseAndFilters($entreprise, ?string $search, ?string $role): int
    {
        return (int) $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->select('COUNT(e.id_employe)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
