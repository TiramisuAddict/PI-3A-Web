<?php

namespace App\Controller\Admin;

use App\Repository\CommentaireRepository;
use App\Repository\LikePostRepository;
use App\Repository\NotificationRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'app_admin_root')]
    public function root(): Response
    {
        return $this->redirectToRoute('app_admin_dashboard', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/dashboard', name: 'app_admin_dashboard')]
    public function dashboard(
        PostRepository $postRepository,
        CommentaireRepository $commentaireRepository,
        LikePostRepository $likePostRepository,
        ParticipationRepository $participationRepository,
        NotificationRepository $notificationRepository,
    ): Response {
        $engagement = $postRepository->findTopEngagementForChart(12);
        $participationByDay = $participationRepository->countByDayForEventPosts(45);

        $pieAnnonces = $postRepository->countWithTypePost(1);
        $pieEvenements = $postRepository->countWithTypePost(2);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'posts' => $postRepository->count([]),
                'comments' => $commentaireRepository->count([]),
                'likes' => $likePostRepository->count([]),
                'participations' => $participationRepository->count([]),
                'notifications' => $notificationRepository->count([]),
            ],
            'pieAnnonces' => $pieAnnonces,
            'pieEvenements' => $pieEvenements,
            'chartEngagementLabels' => array_column($engagement, 'titre'),
            'chartEngagementValues' => array_column($engagement, 'engagement'),
            'chartParticipationLabels' => array_column($participationByDay, 'day'),
            'chartParticipationValues' => array_column($participationByDay, 'count'),
        ]);
    }
}
