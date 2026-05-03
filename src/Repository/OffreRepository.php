<?php

namespace App\Repository;

use App\Entity\Offre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offre>
 */
class OffreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offre::class);
    }

    /**
     * @return Offre[]
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->enableResultCache(300)
            ->getResult();
    }

    /**
     * @return Offre[]
     */
    public function findByFilters(?string $query, ?string $category, ?string $contract, ?string $etat, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('o');

        $query = trim((string) $query);
        $category = trim((string) $category);
        $contract = trim((string) $contract);
        $etat = trim((string) $etat);

        if ($query !== '') {
            $qb->andWhere($qb->expr()->like('LOWER(o.titre_poste)', ':query'))
                ->setParameter('query', sprintf('%%%s%%', mb_strtolower($query)));
        }

        if ($category !== '') {
            $qb->andWhere($qb->expr()->eq('o.categorie', ':category'))
                ->setParameter('category', $category);
        }

        if ($contract !== '') {
            $qb->andWhere($qb->expr()->eq('o.type_contrat', ':contract'))
                ->setParameter('contract', $contract);
        }

        if ($etat !== '') {
            $qb->andWhere($qb->expr()->eq('o.etat', ':etat'))
                ->setParameter('etat', $etat);
        }

        return $qb
            ->orderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->enableResultCache(300)
            ->getResult();
    }

    /**
     * Returns a single-query count map: [offreId => candidatCount]
     *
     * @param int[] $ids
     * @return array<int, int>
     */
    public function findCandidatCountsByOffreIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(c.offre) AS offre_id, COUNT(c.id) AS cnt
             FROM App\Entity\Candidat c
             WHERE c.offre IN (:ids)
             GROUP BY c.offre'
        )
        ->setParameter('ids', $ids)
        ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['offre_id']] = (int) $row['cnt'];
        }
        return $map;
    }

//    /**
//     * @return Offre[] Returns an array of Offre objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('o.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Offre
//    {
//        return $this->createQueryBuilder('o')
//            ->andWhere('o.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
