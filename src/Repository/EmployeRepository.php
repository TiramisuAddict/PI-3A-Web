<?php

namespace App\Repository;

use App\Entity\Employe;
use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Employe>
 */
class EmployeRepository extends ServiceEntityRepository
{
    private const DEMANDE_MANAGER_ROLES = ['rh', 'administrateur entreprise'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employe::class);
    }

    public function createFilteredQueryBuilder(Entreprise $entreprise, ?string $search, ?string $role)
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

    public function findByEntrepriseAndFilters(Entreprise $entreprise, ?string $search, ?string $role): array
    {
        return $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->orderBy('e.id_employe', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByEntrepriseAndFiltersPaginated(Entreprise $entreprise, ?string $search, ?string $role, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        return $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->orderBy('e.id_employe', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function findDemandeManagerEmailsByEntrepriseId(int $entrepriseId): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT e.e_mail AS email')
            ->leftJoin('e.entreprise', 'en')
            ->andWhere('en.id_entreprise = :entrepriseId')
            ->andWhere('LOWER(e.role) IN (:roles)')
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

    public function countByEntrepriseAndFilters(Entreprise $entreprise, ?string $search, ?string $role): int
    {
        return (int) $this->createFilteredQueryBuilder($entreprise, $search, $role)
            ->select('COUNT(e.id_employe)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
