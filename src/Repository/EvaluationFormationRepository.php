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
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT f.id_formation AS formation_id,
                    f.titre AS formation_titre,
                    COUNT(e.id_evaluation) AS reviews_count,
                    AVG(e.note) AS average_note
             FROM evaluation_formation e
             INNER JOIN formation f ON f.id_formation = e.id_formation
             GROUP BY f.id_formation, f.titre
             ORDER BY reviews_count DESC, average_note DESC, f.titre ASC
             LIMIT 1'
        );

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
