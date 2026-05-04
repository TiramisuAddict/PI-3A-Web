<?php

namespace App\Repository;

use App\Entity\Commentaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
    private const DEFAULT_LIST_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commentaire::class);
    }

    public function createAdminListQueryBuilder(?int $postId = null, int $limit = self::DEFAULT_LIST_LIMIT): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.post', 'p')
            ->addSelect('p')
            ->orderBy('c.date_commentaire', 'DESC')
            ->setMaxResults(max(1, min(self::DEFAULT_LIST_LIMIT, $limit)));

        if ($postId !== null) {
            $qb
                ->andWhere('c.post = :postId')
                ->setParameter('postId', $postId);
        }

        return $qb;
    }

    public function reparentDirectRepliesBeforeDelete(Commentaire $commentaire): int
    {
        $commentId = $commentaire->getIdCommentaire();
        if ($commentId === null) {
            return 0;
        }

        $replacementParentId = $commentaire->getParent()?->getIdCommentaire();

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE commentaire SET parent_id = :replacementParentId WHERE parent_id = :commentId',
            [
                'replacementParentId' => $replacementParentId,
                'commentId' => $commentId,
            ],
            [
                'replacementParentId' => $replacementParentId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'commentId' => ParameterType::INTEGER,
            ]
        );
    }
}
