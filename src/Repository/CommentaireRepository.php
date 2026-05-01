<?php

namespace App\Repository;

use App\Entity\Commentaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commentaire>
 *
 * @method Commentaire|null find($id, $lockMode = null, $lockVersion = null)
 * @method Commentaire|null findOneBy(array $criteria, array $orderBy = null)
 * @method Commentaire[]    findAll()
 * @method Commentaire[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CommentaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commentaire::class);
    }

    public function createAdminListQueryBuilder(?int $postId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.post', 'p')
            ->addSelect('p')
            ->orderBy('c.date_commentaire', 'DESC');

        if ($postId !== null) {
            $qb
                ->andWhere('c.post = :postId')
                ->setParameter('postId', $postId);
        }

        return $qb;
    }
}
