<?php

namespace App\Controller;

use App\Entity\LikePost;
use App\Form\LikePostType;
use App\Repository\LikePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** CRUD des likes sur publications (schéma actuel : un like par post). */
#[Route('/like/post')]
final class LikePostController extends AbstractController
{
    #[Route(name: 'app_like_post_index', methods: ['GET'])]
    public function index(LikePostRepository $likePostRepository): Response
    {
        return $this->render('like_post/index.html.twig', [
            'like_posts' => $likePostRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_like_post_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $likePost = new LikePost();
        $likePost->setDateLike(new \DateTimeImmutable());

        $form = $this->createForm(LikePostType::class, $likePost);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($likePost);
            $entityManager->flush();

            return $this->redirectToRoute('app_like_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('like_post/new.html.twig', [
            'like_post' => $likePost,
            'form' => $form,
        ]);
    }

    #[Route('/{id_like}', name: 'app_like_post_show', methods: ['GET'])]
    public function show(LikePost $likePost): Response
    {
        return $this->render('like_post/show.html.twig', [
            'like_post' => $likePost,
        ]);
    }

    #[Route('/{id_like}/edit', name: 'app_like_post_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, LikePost $likePost, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(LikePostType::class, $likePost);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_like_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('like_post/edit.html.twig', [
            'like_post' => $likePost,
            'form' => $form,
        ]);
    }

    #[Route('/{id_like}', name: 'app_like_post_delete', methods: ['POST'])]
    public function delete(Request $request, LikePost $likePost, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$likePost->getIdLike(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($likePost);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_like_post_index', [], Response::HTTP_SEE_OTHER);
    }
}
