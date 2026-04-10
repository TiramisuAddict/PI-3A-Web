<?php

namespace App\Repository;

use App\Entity\Post;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Liste admin : tri par date, recherche optionnelle sur titre / contenu (LIKE).
     *
     * @return Post[]
     */
    public function findForAdminIndex(?string $search): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.date_creation', 'DESC');

        if (null !== $search && '' !== $search) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
            ->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
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
            LIMIT {$limit}
            SQL;
        $rows = $conn->executeQuery($sql)->fetchAllAssociative();
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
}