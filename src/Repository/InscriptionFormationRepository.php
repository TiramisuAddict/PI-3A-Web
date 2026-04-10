<?php

namespace App\Repository;

use App\Entity\InscriptionFormation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InscriptionFormation>
 */
class InscriptionFormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InscriptionFormation::class);
    }

    public function findOneByFormationAndEmployee(int $formationId, int $employeeId): ?InscriptionFormation
    {
        return $this->createQueryBuilder('i')
            ->join('i.formation', 'f')
            ->andWhere('f.id = :formationId')
            ->andWhere('i.employeeId = :employeeId')
            ->setParameter('formationId', $formationId)
            ->setParameter('employeeId', $employeeId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
