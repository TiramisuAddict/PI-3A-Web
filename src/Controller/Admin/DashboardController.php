<?php

namespace App\Controller\Admin;

use App\Repository\CommentaireRepository;
use App\Repository\LikePostRepository;
use App\Repository\NotificationRepository;
use App\Repository\ParticipationRepository;
use App\Repository\PostRepository;
use Nucleos\DompdfBundle\Wrapper\DompdfWrapperInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
        SessionInterface $session
    ): Response {
        return $this->render('admin/dashboard.html.twig', $this->buildDashboardViewModel(
            $postRepository,
            $commentaireRepository,
            $likePostRepository,
            $participationRepository,
            $notificationRepository,
            $session
        ));
    }

    #[Route('/dashboard/export-pdf', name: 'app_admin_dashboard_export_pdf', methods: ['GET'])]
    public function exportPdf(
        PostRepository $postRepository,
        CommentaireRepository $commentaireRepository,
        LikePostRepository $likePostRepository,
        ParticipationRepository $participationRepository,
        NotificationRepository $notificationRepository,
        SessionInterface $session,
        DompdfWrapperInterface $dompdfWrapper
    ): Response {
        $viewModel = $this->buildDashboardViewModel(
            $postRepository,
            $commentaireRepository,
            $likePostRepository,
            $participationRepository,
            $notificationRepository,
            $session
        );

        $html = $this->renderView('admin/dashboard_pdf.html.twig', $viewModel + [
            'generated_at' => new \DateTimeImmutable(),
        ]);

        return $dompdfWrapper->getStreamResponse($html, 'dashboard-gestion-posts.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardViewModel(
        PostRepository $postRepository,
        CommentaireRepository $commentaireRepository,
        LikePostRepository $likePostRepository,
        ParticipationRepository $participationRepository,
        NotificationRepository $notificationRepository,
        SessionInterface $session
    ): array {
        $engagement = $postRepository->findTopEngagementForChart(12);
        $participationByDay = $participationRepository->countByDayForEventPosts(45);

        $stats = [
            'posts' => $postRepository->count([]),
            'comments' => $commentaireRepository->count([]),
            'likes' => $likePostRepository->count([]),
            'participations' => $participationRepository->count([]),
            'notifications' => $notificationRepository->count([]),
        ];

        $pieAnnonces = $postRepository->countWithTypePost(1);
        $pieEvenements = $postRepository->countWithTypePost(2);

        $engagementLabels = array_column($engagement, 'titre');
        $engagementValues = array_column($engagement, 'engagement');
        $participationLabels = array_column($participationByDay, 'day');
        $participationValues = array_column($participationByDay, 'count');

        return [
            'stats' => $stats,
            'pieAnnonces' => $pieAnnonces,
            'pieEvenements' => $pieEvenements,
            'chartEngagementLabels' => $engagementLabels,
            'chartEngagementValues' => $engagementValues,
            'chartParticipationLabels' => $participationLabels,
            'chartParticipationValues' => $participationValues,
            'engagement_rows' => $engagement,
            'participation_rows' => $participationByDay,
            'descriptions' => $this->buildDescriptions(
                $stats,
                $pieAnnonces,
                $pieEvenements,
                $engagement,
                $participationByDay
            ),
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ];
    }

    /**
     * @param list<array{titre: string, comments: int, likes: int, engagement: int}> $engagement
     * @param list<array{day: string, count: int}> $participationByDay
     *
     * @return array{overview: string, pie: string, engagement: string, participation: string}
     */
    private function buildDescriptions(
        array $stats,
        int $pieAnnonces,
        int $pieEvenements,
        array $engagement,
        array $participationByDay
    ): array {
        $totalTypedPosts = max(1, $pieAnnonces + $pieEvenements);
        $annoncePercent = (int) round(($pieAnnonces / $totalTypedPosts) * 100);
        $eventPercent = (int) round(($pieEvenements / $totalTypedPosts) * 100);

        $topEngagement = $engagement[0] ?? null;
        $engagementText = $topEngagement
            ? sprintf(
                'La publication la plus engageante est "%s" avec %d interactions cumulees (%d commentaires et %d likes).',
                $topEngagement['titre'],
                $topEngagement['engagement'],
                $topEngagement['comments'],
                $topEngagement['likes']
            )
            : 'Aucune publication n a encore genere suffisamment d interactions pour alimenter ce classement.';

        $participationTotal = array_sum(array_map(static fn (array $row): int => (int) $row['count'], $participationByDay));
        $peakDay = null;
        foreach ($participationByDay as $row) {
            if ($peakDay === null || (int) $row['count'] > (int) $peakDay['count']) {
                $peakDay = $row;
            }
        }

        $participationText = $peakDay
            ? sprintf(
                'Sur les 45 derniers jours, %d participations ont ete enregistrees. Le pic d activite a eu lieu le %s avec %d inscriptions.',
                $participationTotal,
                $peakDay['day'],
                $peakDay['count']
            )
            : 'Aucune participation evenementielle n a ete enregistree sur la periode analysee.';

        return [
            'overview' => sprintf(
                'Le module compte actuellement %d posts, %d commentaires, %d likes et %d participations. Ces indicateurs offrent une lecture rapide de l activite sociale autour des publications et des evenements.',
                $stats['posts'],
                $stats['comments'],
                $stats['likes'],
                $stats['participations']
            ),
            'pie' => sprintf(
                'Les annonces representent %d%% des publications suivies, contre %d%% pour les evenements. Cette repartition permet d evaluer l equilibre entre information interne et animation de la vie d entreprise.',
                $annoncePercent,
                $eventPercent
            ),
            'engagement' => $engagementText,
            'participation' => $participationText,
        ];
    }
}
