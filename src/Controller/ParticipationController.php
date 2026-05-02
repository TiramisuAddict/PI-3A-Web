<?php

namespace App\Controller;

use App\Entity\Participation;
use App\Form\ParticipationType;
use App\Repository\ParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** CRUD des participations aux événements (posts). */
#[Route('/participation')]
final class ParticipationController extends AbstractController
{
    #[Route(name: 'app_participation_index', methods: ['GET'])]
    public function index(Request $request, ParticipationRepository $participationRepository, PaginatorInterface $paginator): Response
    {
        $participations = $paginator->paginate(
            $participationRepository->createAdminListQueryBuilder(),
            max(1, $request->query->getInt('page', 1)),
            10
        );

        return $this->render('participation/index.html.twig', [
            'participations' => $participations,
            'current_post_id' => null,
        ]);
    }

    #[Route('/by-post/{id_post}', name: 'app_participation_by_post', methods: ['GET'])]
    public function byPost(int $id_post, Request $request, ParticipationRepository $participationRepository, PaginatorInterface $paginator): Response
    {
        $participations = $paginator->paginate(
            $participationRepository->createAdminListQueryBuilder($id_post),
            max(1, $request->query->getInt('page', 1)),
            10
        );

        return $this->render('participation/index.html.twig', [
            'participations' => $participations,
            'current_post_id' => $id_post,
        ]);
    }

    #[Route('/new', name: 'app_participation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $participation = new Participation();
        $participation->setDateAction(new \DateTimeImmutable());
        $participation->setStatut('GOING');

        $form = $this->createForm(ParticipationType::class, $participation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($participation);
            $entityManager->flush();

            return $this->redirectToRoute('app_participation_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('participation/new.html.twig', [
            'participation' => $participation,
            'form' => $form,
        ]);
    }

    #[Route('/{id_participation}', name: 'app_participation_show', methods: ['GET'])]
    public function show(Participation $participation): Response
    {
        return $this->render('participation/show.html.twig', [
            'participation' => $participation,
        ]);
    }

    #[Route('/{id_participation}/edit', name: 'app_participation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Participation $participation, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ParticipationType::class, $participation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_participation_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('participation/edit.html.twig', [
            'participation' => $participation,
            'form' => $form,
        ]);
    }

    #[Route('/{id_participation}', name: 'app_participation_delete', methods: ['POST'])]
    public function delete(Request $request, Participation $participation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$participation->getIdParticipation(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($participation);
            $entityManager->flush();
        }

        $currentPostId = $request->query->getInt('id_post', 0);
        $page = max(1, $request->query->getInt('page', 1));

        if ($currentPostId > 0) {
            return $this->redirectToRoute('app_participation_by_post', array_filter([
                'id_post' => $currentPostId,
                'page' => $page > 1 ? $page : null,
            ], static fn (mixed $value): bool => $value !== null), Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_participation_index', array_filter([
            'page' => $page > 1 ? $page : null,
        ], static fn (mixed $value): bool => $value !== null), Response::HTTP_SEE_OTHER);
    }
}
