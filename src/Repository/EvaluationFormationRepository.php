<?php

namespace App\Repository;

use App\Entity\EvaluationFormation;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EvaluationFormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationFormation::class);
    }

    /**
     * @return list<EvaluationFormation>
     */
    public function findByEmployeId(int $employeeId): array
    {
        return $this->createQueryBuilder('ev')
            ->join('ev.employe', 'e')
            ->andWhere('e.id_employe = :employeeId')
            ->setParameter('employeeId', $employeeId)
            ->orderBy('ev.dateEvaluation', 'DESC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();
    }

    public function findOneByFormationAndEmploye(int $formationId, int $employeeId): ?EvaluationFormation
    {
        try {
            return $this->createQueryBuilder('ev')
                ->join('ev.formation', 'f')
                ->join('ev.employe', 'e')
                ->andWhere('f.id = :formationId')
                ->andWhere('e.id_employe = :employeeId')
                ->setParameter('formationId', $formationId)
                ->setParameter('employeeId', $employeeId)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (NonUniqueResultException) {
            return null;
        }
    }

    /**
     * @return array{formation_id:int, formation_titre:string, reviews_count:int, average_note:float}|null
     */
    public function findBestReviewedFormation(): ?array
    {
        $row = $this->createQueryBuilder('ev')
            ->select('f.id AS formation_id', 'f.titre AS formation_titre', 'COUNT(ev.id) AS reviews_count', 'AVG(ev.note) AS average_note')
            ->join('ev.formation', 'f')
            ->groupBy('f.id', 'f.titre')
            ->orderBy('reviews_count', 'DESC')
            ->addOrderBy('average_note', 'DESC')
            ->addOrderBy('f.titre', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            return null;
        }

        return [
            'formation_id' => (int) $row['formation_id'],
            'formation_titre' => (string) $row['formation_titre'],
            'reviews_count' => (int) $row['reviews_count'],
            'average_note' => round((float) $row['average_note'], 2),
        ];
    }
}
