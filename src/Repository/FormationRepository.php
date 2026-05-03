<?php

namespace App\Repository;

use App\Entity\Formation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Formation>
 */
class FormationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Formation::class);
    }

    /**
     * @return Formation[]
     */
    public function findForRhDashboard(string $q = '', string $sort = 'date_desc', int $minCapacite = 0, string $dateScope = 'all', int $limit = 0, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('f');

        if ($q !== '') {
            $qb
                ->andWhere('LOWER(f.titre) LIKE :q OR LOWER(f.organisme) LIKE :q OR LOWER(f.lieu) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        if ($minCapacite > 0) {
            $qb
                ->andWhere('f.capacite >= :minCapacite')
                ->setParameter('minCapacite', $minCapacite);
        }

        $today = new \DateTimeImmutable('today');
        if ($dateScope === 'upcoming') {
            $qb
                ->andWhere('f.dateDebut >= :today')
                ->setParameter('today', $today);
        } elseif ($dateScope === 'ongoing') {
            $qb
                ->andWhere('f.dateDebut <= :today')
                ->andWhere('f.dateFin >= :today')
                ->setParameter('today', $today);
        } elseif ($dateScope === 'finished') {
            $qb
                ->andWhere('f.dateFin < :today')
                ->setParameter('today', $today);
        }

        switch ($sort) {
            case 'date_asc':
                $qb->orderBy('f.dateDebut', 'ASC');
                break;
            case 'title_asc':
                $qb->orderBy('f.titre', 'ASC');
                break;
            case 'organisme_asc':
                $qb->orderBy('f.organisme', 'ASC');
                break;
            case 'capacity_desc':
                $qb->orderBy('f.capacite', 'DESC');
                break;
            case 'date_desc':
            default:
                $qb->orderBy('f.dateDebut', 'DESC');
                break;
        }

        $qb->addOrderBy('f.titre', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
            if ($offset > 0) {
                $qb->setFirstResult($offset);
            }
        }

        return $qb->getQuery()->getResult();
    }
}
