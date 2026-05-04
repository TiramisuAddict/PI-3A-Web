<?php

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    private const DEFAULT_LIST_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Liste admin : tri par date, recherche optionnelle sur titre / contenu (LIKE).
     *
     */
    public function createAdminIndexQueryBuilder(?string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.date_creation', 'DESC');

        if (null !== $search && '' !== $search) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
            ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }

    /**
     * @return Paginator<Post>
     */
    public function createAdminIndexPaginator(?string $search, int $page = 1, int $limit = 10): Paginator
    {
        $limit = $this->normalizeLimit($limit);
        $query = $this->createAdminIndexQueryBuilder($search)
            ->getQuery()
            ->setFirstResult((max(1, $page) - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($query, false);
    }

    /**
     * @return list<Post>
     */
    public function findActiveFeedPostsWithEventImages(string $filter = 'all', int $limit = self::DEFAULT_LIST_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);
        $idQb = $this->createQueryBuilder('p')
            ->select('p.id_post AS idPost')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.date_creation', 'DESC')
            ->setMaxResults($limit);

        if ($filter === 'annonce') {
            $idQb
                ->andWhere('p.type_post = :typePost')
                ->setParameter('typePost', 1);
        } elseif ($filter === 'evenement') {
            $idQb
                ->andWhere('p.type_post = :typePost')
                ->setParameter('typePost', 2);
        }

        $postIds = array_map('intval', array_column($idQb->getQuery()->getScalarResult(), 'idPost'));
        if ($postIds === []) {
            return [];
        }

        $posts = $this->createQueryBuilder('p')
            ->leftJoin('p.eventImages', 'ei')
            ->addSelect('ei')
            ->andWhere('p.id_post IN (:postIds)')
            ->setParameter('postIds', $postIds)
            ->getQuery()
            ->getResult();

        $postPositions = array_flip($postIds);
        usort(
            $posts,
            static fn (Post $left, Post $right): int => ($postPositions[$left->getIdPost() ?? 0] ?? PHP_INT_MAX)
                <=> ($postPositions[$right->getIdPost() ?? 0] ?? PHP_INT_MAX)
        );

        return array_values($posts);
    }

    public function findOneWithEventImages(int $postId): ?Post
    {
        $posts = $this->createQueryBuilder('p')
            ->leftJoin('p.eventImages', 'ei')
            ->addSelect('ei')
            ->andWhere('p.id_post = :postId')
            ->setParameter('postId', $postId)
            ->getQuery()
            ->getResult();

        return $posts[0] ?? null;
    }

    public function countWithTypePost(int $typePost): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT COUNT(*) as cnt FROM post WHERE type_post = :type';
        $result = $conn->executeQuery($sql, ['type' => $typePost]);
        $row = $result->fetchAssociative();
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * @return list<array{titre: string, comments: int, likes: int, engagement: int}>
     */
    public function findTopEngagementForChart(int $limit = 12): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $limit = max(1, min(50, $limit));
        $sql = <<<SQL
            SELECT p.titre AS titre,
                   (SELECT COUNT(*) FROM commentaire c WHERE c.post_id = p.id_post) AS comments,
                   (SELECT COUNT(*) FROM like_post l WHERE l.post_id = p.id_post) AS likes
            FROM post p
            ORDER BY (comments + likes) DESC, p.date_creation DESC
            LIMIT :limit
            SQL;
        $rows = $conn->executeQuery(
            $sql,
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $c = (int) ($row['comments'] ?? 0);
            $l = (int) ($row['likes'] ?? 0);
            $out[] = [
                'titre' => mb_strlen((string) $row['titre']) > 40 ? mb_substr((string) $row['titre'], 0, 37).'…' : (string) $row['titre'],
                'comments' => $c,
                'likes' => $l,
                'engagement' => $c + $l,
            ];
        }

        return $out;
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(self::DEFAULT_LIST_LIMIT, $limit));
    }
}
