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
    public function findByFilters(?string $query, ?string $category, ?string $contract, ?string $etat): array
    {
        $qb = $this->createQueryBuilder('o');

        $query = trim((string) $query);
        $category = trim((string) $category);
        $contract = trim((string) $contract);
        $etat = trim((string) $etat);

        if ($query !== '') {
            $qb->andWhere('LOWER(o.titre_poste) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        if ($category !== '') {
            $qb->andWhere('o.categorie = :category')
                ->setParameter('category', $category);
        }

        if ($contract !== '') {
            $qb->andWhere('o.type_contrat = :contract')
                ->setParameter('contract', $contract);
        }

        if ($etat !== '') {
            $qb->andWhere('o.etat = :etat')
                ->setParameter('etat', $etat);
        }

        return $qb->orderBy('o.id', 'DESC')->getQuery()->getResult();
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
