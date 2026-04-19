<?php

namespace App\Controller;

use App\Entity\Commentaire;
use App\Form\CommentaireType;
use App\Repository\CommentaireRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** CRUD des commentaires liés aux publications. */
#[Route('/commentaire')]
final class CommentaireController extends AbstractController
{
    #[Route(name: 'app_commentaire_index', methods: ['GET'])]
    public function index(Request $request, CommentaireRepository $commentaireRepository, PostRepository $postRepository): Response
    {
        $postId = $request->query->getInt('id_post', 0);
        $currentPost = null;

        if ($postId > 0) {
            $currentPost = $postRepository->find($postId);
            if ($currentPost === null) {
                throw $this->createNotFoundException('Publication introuvable.');
            }
        }

        return $this->render('commentaire/index.html.twig', [
            'commentaires' => $currentPost !== null
                ? $commentaireRepository->findForPostAdmin($currentPost->getIdPost())
                : $commentaireRepository->findBy([], ['date_commentaire' => 'DESC']),
            'current_post' => $currentPost,
        ]);
    }

    #[Route('/new', name: 'app_commentaire_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $commentaire = new Commentaire();
        $commentaire->setDateCommentaire(new \DateTimeImmutable());

        $form = $this->createForm(CommentaireType::class, $commentaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($commentaire);
            $entityManager->flush();

            return $this->redirectToRoute('app_commentaire_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('commentaire/new.html.twig', [
            'commentaire' => $commentaire,
            'form' => $form,
        ]);
    }

    #[Route('/{id_commentaire}', name: 'app_commentaire_show', methods: ['GET'])]
    public function show(Commentaire $commentaire): Response
    {
        return $this->render('commentaire/show.html.twig', [
            'commentaire' => $commentaire,
        ]);
    }

    #[Route('/{id_commentaire}/edit', name: 'app_commentaire_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Commentaire $commentaire, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CommentaireType::class, $commentaire);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_commentaire_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('commentaire/edit.html.twig', [
            'commentaire' => $commentaire,
            'form' => $form,
        ]);
    }

    #[Route('/{id_commentaire}', name: 'app_commentaire_delete', methods: ['POST'])]
    public function delete(Request $request, Commentaire $commentaire, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$commentaire->getIdCommentaire(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($commentaire);
            $entityManager->flush();
        }

        $postId = $request->query->getInt('id_post', 0);

        return $this->redirectToRoute('app_commentaire_index', $postId > 0 ? ['id_post' => $postId] : [], Response::HTTP_SEE_OTHER);
    }
}
