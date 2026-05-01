<?php

namespace App\Repository;

use App\Entity\Participation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Participation>
 */
class ParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participation::class);
    }

    public function createAdminListQueryBuilder(?int $postId = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('part')
            ->leftJoin('part.post', 'p')
            ->addSelect('p')
            ->orderBy('part.date_action', 'DESC');

        if ($postId !== null) {
            $qb
                ->andWhere('part.post = :postId')
                ->setParameter('postId', $postId);
        }

        return $qb;
    }

    /**
     * Participations sur des posts evenement (type_post = 2), agregees par jour (date d'action).
     * Requete compatible MySQL / MariaDB (schema projet momentum).
     *
     * @return list<array{day: string, count: int}>
     */
    public function countByDayForEventPosts(int $days = 45): array
    {
        $days = max(1, min(365, $days));
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<SQL
            SELECT DATE(part.date_action) AS day_label, COUNT(part.id_participation) AS cnt
            FROM participation part
            INNER JOIN post p ON p.id_post = part.post_id AND p.type_post = 2
            WHERE part.date_action >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            GROUP BY day_label
            ORDER BY day_label ASC
            SQL;

        $rows = $conn->executeQuery($sql)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $day = (string) ($row['day_label'] ?? '');
            if ('' === $day) {
                continue;
            }
            $out[] = ['day' => $day, 'count' => (int) ($row['cnt'] ?? 0)];
        }

        return $out;
    }
}
