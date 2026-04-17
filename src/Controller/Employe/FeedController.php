<?php

namespace App\Controller\Employe;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Commentaire;
use App\Entity\Participation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

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
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/feed', name: 'app_employe_feed', methods: ['GET'])]
    public function feed(Request $request, SessionInterface $session): Response
    {
        $filter = $request->query->get('filter', 'all');

        $criteria = ['active' => true];
        if ('annonce' === $filter) {
            $criteria['type_post'] = 1;
        } elseif ('evenement' === $filter) {
            $criteria['type_post'] = 2;
        }

        $posts = $this->postRepository->findBy($criteria, ['date_creation' => 'DESC']);

        return $this->render('employe/feed.html.twig', [
            'posts' => $posts,
            'filter' => $filter,
            'post_type_annonce' => 1,
            'post_type_evenement' => 2,
            'likes_count' => [],
            'comments_count' => [],
            'participants_count' => [],
            'places_restantes' => [],
            'comments_preview' => [],
            'user_liked_post_ids' => [],
            'user_participates_post_ids' => [],
            'current_user_id' => $session->get('employe_id'),
            'google_maps_api_key' => $this->getParameter('google_maps_api_key'),
            'feed_route_comment'    => 'app_employe_feed_comment',
            'feed_route_participate' => 'app_employe_feed_participate',
            'author_labels' => null,
            'email' => $session->get('employe_email') ?? '',
            'role' => $session->get('employe_role') ?? '',
        ]);
    }

    #[Route('/comment/{id_post}', name: 'app_employe_feed_comment', methods: ['POST'])]
    public function comment(int $id_post, Request $request, SessionInterface $session): Response
    {
        $userId = $session->get('employe_id');
        if (!$userId) {
            return $this->redirectToRoute('login');
        }
        
        $post = $this->postRepository->find($id_post);
        if ($post) {
            $c = new Commentaire();
            $c->setContenu($request->request->get('contenu', ''));
            $c->setUtilisateurId($userId);
            $c->setDateCommentaire(new \DateTime());
            $c->setPost($post);
            $this->em->persist($c);
            $this->em->flush();
        }
        return $this->redirectToRoute('app_employe_feed');
    }

    #[Route('/participate/{id_post}', name: 'app_employe_feed_participate', methods: ['POST'])]
    public function participate(int $id_post, SessionInterface $session): Response
    {
        $userId = $session->get('employe_id');
        if (!$userId) {
            return $this->redirectToRoute('login');
        }
        
        $post = $this->postRepository->find($id_post);
        if ($post) {
            $p = new Participation();
            $p->setUtilisateurId($userId);
            $p->setPost($post);
            $this->em->persist($p);
            $this->em->flush();
        }
        return $this->redirectToRoute('app_employe_feed');
    }
}
