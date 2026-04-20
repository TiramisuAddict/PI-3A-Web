<?php

namespace App\Controller\Employe;

use App\Entity\Commentaire;
use App\Entity\Employe;
use App\Entity\Notification;
use App\Entity\Post;
use App\Repository\EmployeRepository;
use App\Repository\PostRepository;
use App\Service\BadWordService;
use App\Service\ReasonAssistantService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/employe')]
class FeedController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly EmployeRepository $employeRepository,
        private readonly EntityManagerInterface $em,
        private readonly ReasonAssistantService $reasonAssistantService,
        private readonly BadWordService $badWordService,
    ) {
    }

    private function getUserIdFromSession(SessionInterface $session): ?int
    {
        $keys = ['employe_id', 'employee_id', 'id_employe', 'user_id'];
        foreach ($keys as $key) {
            $userId = $session->get($key);
            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return null;
    }

    private function buildDisplayLabel(?Employe $employe): string
    {
        if ($employe instanceof Employe) {
            $label = trim(sprintf('%s %s', (string) $employe->getPrenom(), (string) $employe->getNom()));
            if ($label !== '') {
                return $label;
            }
        }

        return 'Employe';
    }

    /**
     * @param int[] $userIds
     *
     * @return array<int, string>
     */
    private function resolveAuthorLabels(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $employees = $this->employeRepository->findBy(['id_employe' => $userIds]);
        $labels = [];

        foreach ($employees as $employee) {
            if (!$employee instanceof Employe || $employee->getId_employe() === null) {
                continue;
            }

            $labels[$employee->getId_employe()] = $this->buildDisplayLabel($employee);
        }

        foreach ($userIds as $userId) {
            if (!isset($labels[$userId])) {
                $labels[$userId] = 'Employe';
            }
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $authorLabels
     *
     * @return array<string, mixed>
     */
    private function normalizeCommentRow(array $row, array $authorLabels, ?int $currentUserId): array
    {
        $commentUserId = (int) $row['utilisateur_id'];
        $authorName = $authorLabels[$commentUserId] ?? 'Employe';

        return [
            'id_commentaire' => (int) $row['id_commentaire'],
            'post_id' => (int) $row['post_id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'contenu' => (string) $row['contenu'],
            'date_commentaire' => $row['date_commentaire'] instanceof \DateTimeInterface
                ? $row['date_commentaire']->format('c')
                : (new \DateTimeImmutable((string) $row['date_commentaire']))->format('c'),
            'edited_at' => $row['edited_at']
                ? ($row['edited_at'] instanceof \DateTimeInterface
                    ? $row['edited_at']->format('c')
                    : (new \DateTimeImmutable((string) $row['edited_at']))->format('c'))
                : null,
            'utilisateur_id' => $commentUserId,
            'author_name' => $authorName,
            'owned_by_current_user' => $currentUserId !== null && $commentUserId === $currentUserId,
            'replies' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $authorLabels
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildCommentsTreeByPost(array $rows, array $authorLabels, ?int $currentUserId): array
    {
        $byId = [];
        $commentsByPost = [];

        foreach ($rows as $row) {
            $comment = $this->normalizeCommentRow($row, $authorLabels, $currentUserId);
            $byId[$comment['id_commentaire']] = $comment;
        }

        foreach (array_keys($byId) as $commentId) {
            $parentId = $byId[$commentId]['parent_id'];

            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['replies'][] = &$byId[$commentId];
                continue;
            }

            $postId = $byId[$commentId]['post_id'];
            $commentsByPost[$postId] ??= [];
            $commentsByPost[$postId][] = &$byId[$commentId];
        }

        foreach ($commentsByPost as &$comments) {
            usort($comments, static fn (array $left, array $right): int => strcmp($right['date_commentaire'], $left['date_commentaire']));
        }
        unset($comments);

        return $commentsByPost;
    }

    /**
     * @return array{commentsByPost: array<int, array<int, array<string, mixed>>>, commentsCount: array<int, int>, authorLabels: array<int, string>}
     */
    private function loadCommentsData(array $postIds, ?int $currentUserId): array
    {
        if ($postIds === []) {
            return [
                'commentsByPost' => [],
                'commentsCount' => [],
                'authorLabels' => [],
            ];
        }

        $rows = $this->em->getConnection()->executeQuery(
            'SELECT id_commentaire, contenu, date_commentaire, edited_at, utilisateur_id, post_id, parent_id
             FROM commentaire
             WHERE post_id IN (?)
             ORDER BY date_commentaire ASC',
            [array_values($postIds)],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();

        $commentUserIds = array_map(static fn (array $row): int => (int) $row['utilisateur_id'], $rows);
        $authorLabels = $this->resolveAuthorLabels($commentUserIds);
        $commentsByPost = $this->buildCommentsTreeByPost($rows, $authorLabels, $currentUserId);
        $commentsCount = [];

        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            $commentsCount[$postId] = ($commentsCount[$postId] ?? 0) + 1;
        }

        return [
            'commentsByPost' => $commentsByPost,
            'commentsCount' => $commentsCount,
            'authorLabels' => $authorLabels,
        ];
    }

    private function serializeComment(Commentaire $comment, array $authorLabels, ?int $currentUserId): array
    {
        $authorId = (int) $comment->getUtilisateurId();

        return [
            'id_commentaire' => $comment->getIdCommentaire(),
            'post_id' => $comment->getPost()?->getIdPost(),
            'parent_id' => $comment->getParent()?->getIdCommentaire(),
            'contenu' => $comment->getContenu(),
            'date_commentaire' => $comment->getDateCommentaire()?->format('c'),
            'edited_at' => $comment->getEditedAt()?->format('c'),
            'author_name' => $authorLabels[$authorId] ?? 'Employe',
            'owned_by_current_user' => $currentUserId !== null && $authorId === $currentUserId,
            'edit_token' => $this->container->get('security.csrf.token_manager')->getToken(sprintf('feed_comment_edit_%d', $comment->getIdCommentaire()))->getValue(),
            'delete_token' => $this->container->get('security.csrf.token_manager')->getToken(sprintf('feed_comment_delete_%d', $comment->getIdCommentaire()))->getValue(),
        ];
    }

    private function getCommentCountForPost(int $postId): int
    {
        return (int) $this->em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM commentaire WHERE post_id = ?',
            [$postId]
        )->fetchOne();
    }

    private function normalizeCommentContent(string $content): string
    {
        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            return '';
        }

        $analysisResult = $this->reasonAssistantService->correctReason($trimmedContent);
        $correctedContent = trim($analysisResult->correctedText);

        return $correctedContent !== '' ? $correctedContent : $trimmedContent;
    }

    private function createPostNotification(Post $post, int $actorUserId, string $title, string $message): void
    {
        $ownerUserId = (int) $post->getUtilisateurId();
        if ($ownerUserId <= 0 || $ownerUserId === $actorUserId) {
            return;
        }

        $notification = new Notification();
        $notification->setUserId($ownerUserId);
        $notification->setTitre($title);
        $notification->setMessage($message);
        $notification->setDateCreation(new \DateTime());
        $notification->setIsRead(false);
        $notification->setPost($post);

        $this->em->persist($notification);
        $this->em->flush();
    }

    #[Route('/feed', name: 'app_employe_feed', methods: ['GET'])]
    public function feed(Request $request, SessionInterface $session): Response
    {
        $filter = $request->query->get('filter', 'all');
        $userId = $this->getUserIdFromSession($session);

        $criteria = ['active' => true];
        if ($filter === 'annonce') {
            $criteria['type_post'] = 1;
        } elseif ($filter === 'evenement') {
            $criteria['type_post'] = 2;
        }

        $posts = $this->postRepository->findBy($criteria, ['date_creation' => 'DESC']);
        $postIds = array_map(fn ($post) => $post->getIdPost(), $posts);
        $postAuthorLabels = $this->resolveAuthorLabels(array_map(fn ($post) => (int) $post->getUtilisateurId(), $posts));
        $currentUserLabel = $userId ? ($this->resolveAuthorLabels([$userId])[$userId] ?? 'Employe') : 'Employe';

        if ($postIds === []) {
            return $this->render('employe/feed.html.twig', [
                'posts' => $posts,
                'filter' => $filter,
                'post_type_annonce' => 1,
                'post_type_evenement' => 2,
                'likes_count' => [],
                'comments_count' => [],
                'participants_count' => [],
                'places_restantes' => [],
                'comments_by_post' => [],
                'user_liked_post_ids' => [],
                'user_participates_post_ids' => [],
                'current_user_id' => $userId,
                'current_user_label' => $currentUserLabel,
                'google_maps_api_key' => $this->getParameter('google_maps_api_key'),
                'feed_route_comment' => 'app_employe_feed_comment',
                'feed_route_comment_edit' => 'app_employe_feed_comment_edit',
                'feed_route_comment_delete' => 'app_employe_feed_comment_delete',
                'feed_route_like' => 'app_employe_feed_like',
                'feed_route_participate' => 'app_employe_feed_participate',
                'author_labels' => $postAuthorLabels,
                'email' => $session->get('employe_email') ?? '',
                'role' => $session->get('employe_role') ?? '',
            ]);
        }

        $conn = $this->em->getConnection();

        $likesResult = $conn->executeQuery(
            'SELECT post_id, COUNT(*) AS cnt FROM like_post WHERE post_id IN (?) GROUP BY post_id',
            [array_values($postIds)],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        $likesCount = array_map('intval', array_column($likesResult, 'cnt', 'post_id'));

        $participationsResult = $conn->executeQuery(
            'SELECT post_id, COUNT(*) AS cnt FROM participation WHERE post_id IN (?) GROUP BY post_id',
            [array_values($postIds)],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        $participantsCount = array_map('intval', array_column($participationsResult, 'cnt', 'post_id'));

        $placesRestantes = [];
        foreach ($posts as $post) {
            $capacity = $post->getCapaciteMax();
            if ($capacity !== null) {
                $used = $participantsCount[$post->getIdPost()] ?? 0;
                $placesRestantes[$post->getIdPost()] = max(0, $capacity - $used);
            }
        }

        $commentsData = $this->loadCommentsData($postIds, $userId);

        $userLikedIds = [];
        if ($userId) {
            $userLikes = $conn->executeQuery(
                'SELECT post_id FROM like_post WHERE utilisateur_id = ? AND post_id IN (?)',
                [$userId, array_values($postIds)],
                [null, \Doctrine\DBAL\ArrayParameterType::INTEGER]
            )->fetchFirstColumn();
            $userLikedIds = array_map('intval', $userLikes);
        }

        $userParticipatIds = [];
        if ($userId) {
            $userParts = $conn->executeQuery(
                'SELECT post_id FROM participation WHERE utilisateur_id = ? AND post_id IN (?)',
                [$userId, array_values($postIds)],
                [null, \Doctrine\DBAL\ArrayParameterType::INTEGER]
            )->fetchFirstColumn();
            $userParticipatIds = array_map('intval', $userParts);
        }

        return $this->render('employe/feed.html.twig', [
            'posts' => $posts,
            'filter' => $filter,
            'post_type_annonce' => 1,
            'post_type_evenement' => 2,
            'likes_count' => $likesCount,
            'comments_count' => $commentsData['commentsCount'],
            'participants_count' => $participantsCount,
            'places_restantes' => $placesRestantes,
            'comments_by_post' => $commentsData['commentsByPost'],
            'user_liked_post_ids' => $userLikedIds,
            'user_participates_post_ids' => $userParticipatIds,
            'current_user_id' => $userId,
            'current_user_label' => $currentUserLabel,
            'google_maps_api_key' => $this->getParameter('google_maps_api_key'),
            'feed_route_comment' => 'app_employe_feed_comment',
            'feed_route_comment_edit' => 'app_employe_feed_comment_edit',
            'feed_route_comment_delete' => 'app_employe_feed_comment_delete',
            'feed_route_like' => 'app_employe_feed_like',
            'feed_route_participate' => 'app_employe_feed_participate',
            'author_labels' => $postAuthorLabels + $commentsData['authorLabels'],
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/like/{id_post}', name: 'app_employe_feed_like', methods: ['POST'])]
    public function like(int $id_post, Request $request, SessionInterface $session): JsonResponse
    {
        $userId = $this->getUserIdFromSession($session);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid("feed_like_{$id_post}", $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($id_post);
        if (!$post) {
            return new JsonResponse(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
        }

        $conn = $this->em->getConnection();
        $existing = $conn->executeQuery(
            'SELECT id_like FROM like_post WHERE utilisateur_id = ? AND post_id = ?',
            [$userId, $id_post]
        )->fetchOne();

        $liked = false;
        if ($existing) {
            $conn->executeStatement(
                'DELETE FROM like_post WHERE utilisateur_id = ? AND post_id = ?',
                [$userId, $id_post]
            );
        } else {
            $conn->executeStatement(
                'INSERT INTO like_post (utilisateur_id, post_id, date_like) VALUES (?, ?, NOW())',
                [$userId, $id_post]
            );
            $liked = true;

            $actorName = $this->resolveAuthorLabels([$userId])[$userId] ?? 'Employe';
            $this->createPostNotification(
                $post,
                $userId,
                'Nouveau j\'aime',
                sprintf('%s a aime votre publication "%s".', $actorName, (string) $post->getTitre())
            );
        }

        $count = (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM like_post WHERE post_id = ?',
            [$id_post]
        )->fetchOne();

        return new JsonResponse([
            'success' => true,
            'liked' => $liked,
            'count' => $count,
        ]);
    }

    #[Route('/comment/{id_post}', name: 'app_employe_feed_comment', methods: ['POST'])]
    public function comment(int $id_post, Request $request, SessionInterface $session): JsonResponse
    {
        $userId = $this->getUserIdFromSession($session);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid("feed_comment_{$id_post}", $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($id_post);
        if (!$post) {
            return new JsonResponse(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
        }

        $content = trim((string) $request->request->get('contenu', ''));
        if ($content === '') {
            return new JsonResponse(['success' => false, 'message' => 'Comment cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        // Check for bad words
        $analysis = $this->badWordService->analyze($content);
        if ($analysis['score'] >= 10) {
            return new JsonResponse([
                'success' => false,
                'type' => 'bad_words',
                'message' => 'Your comment contains inappropriate language.',
                'bad_words_found' => $analysis['found'],
                'censored_preview' => $analysis['censored'],
                'suggestion' => 'Please review your comment and remove or rephrase the inappropriate content.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $parentId = $request->request->get('parent_id');
        $parentComment = null;

        if ($parentId !== null && $parentId !== '') {
            $parentComment = $this->em->getRepository(Commentaire::class)->find((int) $parentId);
            if (!$parentComment instanceof Commentaire || $parentComment->getPost()?->getIdPost() !== $id_post) {
                return new JsonResponse(['success' => false, 'message' => 'Parent comment not found'], Response::HTTP_NOT_FOUND);
            }
        }

        $comment = new Commentaire();
        $comment->setContenu($this->normalizeCommentContent($content));
        $comment->setUtilisateurId($userId);
        $comment->setDateCommentaire(new \DateTime());
        $comment->setPost($post);
        $comment->setParent($parentComment);

        $this->em->persist($comment);
        $this->em->flush();

        $authorLabels = $this->resolveAuthorLabels([$userId]);
        $authorName = $authorLabels[$userId] ?? 'Employe';
        $this->createPostNotification(
            $post,
            $userId,
            'Nouveau commentaire',
            sprintf('%s a commente votre publication "%s".', $authorName, (string) $post->getTitre())
        );

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCommentCountForPost($id_post),
            'comment' => $this->serializeComment($comment, $authorLabels, $userId),
        ]);
    }

    #[Route('/comment/{id_commentaire}/edit', name: 'app_employe_feed_comment_edit', methods: ['POST'])]
    public function editComment(int $id_commentaire, Request $request, SessionInterface $session): JsonResponse
    {
        $userId = $this->getUserIdFromSession($session);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid("feed_comment_edit_{$id_commentaire}", $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], Response::HTTP_FORBIDDEN);
        }

        $comment = $this->em->getRepository(Commentaire::class)->find($id_commentaire);
        if (!$comment instanceof Commentaire) {
            return new JsonResponse(['success' => false, 'message' => 'Comment not found'], Response::HTTP_NOT_FOUND);
        }

        if ((int) $comment->getUtilisateurId() !== $userId) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $content = trim((string) $request->request->get('contenu', ''));
        if ($content === '') {
            return new JsonResponse(['success' => false, 'message' => 'Comment cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        // Check for bad words
        $analysis = $this->badWordService->analyze($content);
        if ($analysis['score'] >= 10) {
            return new JsonResponse([
                'success' => false,
                'type' => 'bad_words',
                'message' => 'Your comment contains inappropriate language.',
                'bad_words_found' => $analysis['found'],
                'censored_preview' => $analysis['censored'],
                'suggestion' => 'Please review your comment and remove or rephrase the inappropriate content.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $comment->setContenu($this->normalizeCommentContent($content));
        $comment->setEditedAt(new \DateTime());
        $this->em->flush();

        $authorLabels = $this->resolveAuthorLabels([$userId]);

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCommentCountForPost((int) $comment->getPost()?->getIdPost()),
            'comment' => $this->serializeComment($comment, $authorLabels, $userId),
        ]);
    }

    #[Route('/comment/{id_commentaire}/delete', name: 'app_employe_feed_comment_delete', methods: ['POST'])]
    public function deleteComment(int $id_commentaire, Request $request, SessionInterface $session): JsonResponse
    {
        $userId = $this->getUserIdFromSession($session);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid("feed_comment_delete_{$id_commentaire}", $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], Response::HTTP_FORBIDDEN);
        }

        $comment = $this->em->getRepository(Commentaire::class)->find($id_commentaire);
        if (!$comment instanceof Commentaire) {
            return new JsonResponse(['success' => false, 'message' => 'Comment not found'], Response::HTTP_NOT_FOUND);
        }

        if ((int) $comment->getUtilisateurId() !== $userId) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $postId = (int) $comment->getPost()?->getIdPost();
        $parentId = $comment->getParent()?->getIdCommentaire();

        $this->em->remove($comment);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'count' => $this->getCommentCountForPost($postId),
            'deleted_comment_id' => $id_commentaire,
            'parent_id' => $parentId,
            'post_id' => $postId,
        ]);
    }

    #[Route('/participate/{id_post}', name: 'app_employe_feed_participate', methods: ['POST'])]
    public function participate(int $id_post, Request $request, SessionInterface $session): JsonResponse
    {
        $userId = $this->getUserIdFromSession($session);
        if (!$userId) {
            return new JsonResponse(['success' => false, 'message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid("feed_participate_{$id_post}", $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'message' => 'CSRF token invalid'], Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($id_post);
        if (!$post) {
            return new JsonResponse(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
        }

        $conn = $this->em->getConnection();
        $existing = $conn->executeQuery(
            'SELECT id_participation FROM participation WHERE utilisateur_id = ? AND post_id = ?',
            [$userId, $id_post]
        )->fetchOne();

        $participating = false;
        if ($existing) {
            $conn->executeStatement(
                'DELETE FROM participation WHERE utilisateur_id = ? AND post_id = ?',
                [$userId, $id_post]
            );
        } else {
            $capacity = $post->getCapaciteMax();
            if ($capacity !== null) {
                $count = (int) $conn->executeQuery(
                    'SELECT COUNT(*) FROM participation WHERE post_id = ?',
                    [$id_post]
                )->fetchOne();

                if ($count >= $capacity) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Capacite maximale atteinte',
                    ], Response::HTTP_CONFLICT);
                }
            }

            $conn->executeStatement(
                'INSERT INTO participation (utilisateur_id, post_id, statut, date_action) VALUES (?, ?, ?, NOW())',
                [$userId, $id_post, 'GOING']
            );
            $participating = true;

            $actorName = $this->resolveAuthorLabels([$userId])[$userId] ?? 'Employe';
            $this->createPostNotification(
                $post,
                $userId,
                'Nouvelle participation',
                sprintf('%s participe a votre evenement "%s".', $actorName, (string) $post->getTitre())
            );
        }

        $count = (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM participation WHERE post_id = ?',
            [$id_post]
        )->fetchOne();

        $capacity = $post->getCapaciteMax();
        $percentage = null;
        $capacityStatus = null;

        if ($capacity !== null && $capacity > 0) {
            $percentage = (int) floor(($count / $capacity) * 100);

            if ($percentage < 50) {
                $capacityStatus = 'available';
            } elseif ($percentage < 80) {
                $capacityStatus = 'filling';
            } elseif ($percentage < 100) {
                $capacityStatus = 'almost_full';
            } else {
                $capacityStatus = 'full';
            }
        }

        return new JsonResponse([
            'success' => true,
            'participating' => $participating,
            'count' => $count,
            'capacity_status' => $capacityStatus,
            'capacity_percentage' => $percentage,
            'remaining_places' => $capacity !== null ? max(0, $capacity - $count) : null,
        ]);
    }
}
