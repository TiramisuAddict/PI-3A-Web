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
    private const DEMANDE_MANAGER_ROLES = ['RH', 'administrateur entreprise'];

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
    public function findByEntrepriseAndFilters($entreprise, ?string $search, ?string $role): array
{
    $qb = $this->createQueryBuilder('e')
               ->andWhere('e.entreprise = :entreprise')
               ->setParameter('entreprise', $entreprise);

    if ($search) {
        $qb->andWhere('LOWER(e.nom) LIKE :search OR LOWER(e.prenom) LIKE :search')
           ->setParameter('search', '%'.strtolower($search).'%');
    }

    if ($role) {
        $qb->andWhere('LOWER(e.role) = :role')
           ->setParameter('role', strtolower($role));
    }

    return $qb->orderBy('e.nom', 'ASC')
              ->getQuery()
              ->getResult();
}

    public function findDemandeManagerEmailsByEntrepriseId(int $entrepriseId): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.e_mail AS email')
            ->leftJoin('e.entreprise', 'en')
            ->andWhere('en.id_entreprise = :entrepriseId')
            ->andWhere('e.role IN (:roles)')
            ->andWhere('e.e_mail IS NOT NULL')
            ->andWhere("TRIM(e.e_mail) <> ''")
            ->setParameter('entrepriseId', $entrepriseId)
            ->setParameter('roles', self::DEMANDE_MANAGER_ROLES)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['email'] ?? '')),
            $rows
        )));
    }
}
