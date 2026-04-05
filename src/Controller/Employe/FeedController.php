<?php

namespace App\Controller\Employe;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur Front Office — Fil d'actualité.
 * Agrège les compteurs et prévisualisations ; la logique métier détaillée (like, commentaire, participation)
 * peut être déléguée à des services et des routes POST dédiées (voir template employe/feed.html.twig).
 */
#[Route('/employe')]
class FeedController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $postRepository,
    ) {
    }

    #[Route('/feed', name: 'app_employe_feed', methods: ['GET'])]
    public function feed(): Response
    {
        $posts = $this->postRepository->findBy(['active' => true], ['date_creation' => 'DESC']);

        return $this->render('employe/feed.html.twig', [
            'posts' => $posts,
            'post_type_annonce' => 1,
            'post_type_evenement' => 2,
            'likes_count' => [],
            'comments_count' => [],
            'participants_count' => [],
            'places_restantes' => [],
            'comments_preview' => [],
            'user_liked_post_ids' => [],
            'user_participates_post_ids' => [],
            'current_user_id' => null,
            'google_maps_api_key' => getenv('GOOGLE_MAPS_API_KEY') ?: null,
            // Définir ces routes quand les actions POST existent :
            'feed_route_comment' => null,
            'feed_route_like' => null,
            'feed_route_participate' => null,
            'author_labels' => null,
        ]);
    }
}
