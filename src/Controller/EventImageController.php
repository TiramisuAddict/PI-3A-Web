<?php

namespace App\Controller;

use App\Entity\EventImage;
use App\Form\EventImageType;
use App\Repository\EventImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** CRUD des images associées aux posts événements. */
#[Route('/event/image')]
final class EventImageController extends AbstractController
{
    #[Route(name: 'app_event_image_index', methods: ['GET'])]
    public function index(EventImageRepository $eventImageRepository): Response
    {
        return $this->render('event_image/index.html.twig', [
            'event_images' => $eventImageRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_event_image_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $eventImage = new EventImage();
        $form = $this->createForm(EventImageType::class, $eventImage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($eventImage);
            $entityManager->flush();

            return $this->redirectToRoute('app_event_image_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event_image/new.html.twig', [
            'event_image' => $eventImage,
            'form' => $form,
        ]);
    }

    #[Route('/{id_image}', name: 'app_event_image_show', methods: ['GET'])]
    public function show(EventImage $eventImage): Response
    {
        return $this->render('event_image/show.html.twig', [
            'event_image' => $eventImage,
        ]);
    }

    #[Route('/{id_image}/edit', name: 'app_event_image_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EventImage $eventImage, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(EventImageType::class, $eventImage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_event_image_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('event_image/edit.html.twig', [
            'event_image' => $eventImage,
            'form' => $form,
        ]);
    }

    #[Route('/{id_image}', name: 'app_event_image_delete', methods: ['POST'])]
    public function delete(Request $request, EventImage $eventImage, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$eventImage->getIdImage(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($eventImage);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_event_image_index', [], Response::HTTP_SEE_OTHER);
    }
}
