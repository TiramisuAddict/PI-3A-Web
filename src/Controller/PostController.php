<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\EventImage;
use App\Form\PostType;
use App\Repository\PostRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;

/**
 * CRUD administration des publications (posts / événements).
 */
#[Route('/post')]
final class PostController extends AbstractController
{
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * `post.utilisateur_id` référence `employé.id_employe` (contrainte FK en base).
     */
    private function resolvePostAuthorEmployeId(Request $request, Connection $connection): int
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session) {
            foreach (['employee_id', 'id_employe', 'employe_id'] as $sessionKey) {
                if ($session->has($sessionKey)) {
                    $id = (int) $session->get($sessionKey);
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        foreach (['`employé`', '`employe`', 'employe'] as $tableSql) {
            try {
                $sql = 'SELECT id_employe FROM '.$tableSql.' ORDER BY id_employe ASC LIMIT 1';
                $row = $connection->fetchOne($sql);
                if (false !== $row && null !== $row) {
                    return (int) $row;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException(
            'Impossible de définir l’auteur du post : aucun id employé en session (employee_id / id_employe) '
            .'et aucune ligne dans la table employé. Créez au moins un employé en base ou connectez l’employé.'
        );
    }

    #[Route('/', name: 'app_post_index', methods: ['GET'])]
    public function index(Request $request, PostRepository $postRepository): Response
    {
        $search = $request->query->getString('search', '');
        $search = trim($search);
        $posts = $postRepository->findForAdminIndex('' !== $search ? $search : null);

        return $this->render('post/index.html.twig', [
            'posts' => $posts,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_post_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        try {
            $authorEmployeId = $this->resolvePostAuthorEmployeId($request, $entityManager->getConnection());
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
        }

        $post = new Post();
        $post->setActive(true);
        $post->setTypePost(1);
        $post->setUtilisateurId($authorEmployeId);
        $post->setDateCreation(new \DateTimeImmutable());

        // Add one empty EventImage BEFORE createForm()
        // This ensures CollectionType has entities to render
        // The template will hide/show this section based on typePost value
        $newEventImage = new EventImage();
        $post->addEventImage($newEventImage);

        $form = $this->createForm(PostType::class, $post);
        if ($request->isMethod('POST')) {
            $post->setDateCreation(new \DateTimeImmutable());
        }
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleEventImageUploads($post, $form);
            
            $entityManager->persist($post);
            $entityManager->flush();

            return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('post/new.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    /**
     * Fragment HTML (tbody) pour recherche dynamique sur la liste admin.
     */
    #[Route('/rows', name: 'app_post_rows', methods: ['GET'])]
    public function rows(Request $request, PostRepository $postRepository): Response
    {
        $search = $request->query->getString('search', '');
        $search = trim($search);
        $posts = $postRepository->findForAdminIndex('' !== $search ? $search : null);

        return $this->render('post/_rows.html.twig', [
            'posts' => $posts,
        ]);
    }

    #[Route('/{id_post}', name: 'app_post_show', methods: ['GET'])]
    public function show(Post $post): Response
    {

        $apiKey = $_ENV['GOOGLE_MAPS_API_KEY'];
        
        return $this->render('post/show.html.twig', [
            'post' => $post,
            'google_maps_api_key' => $apiKey,
        ]);

    }

    #[Route('/{id_post}/edit', name: 'app_post_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Post $post, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleEventImageUploads($post, $form);
            
            $entityManager->flush();

            return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('post/edit.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    #[Route('/{id_post}', name: 'app_post_delete', methods: ['POST'])]
    public function delete(Request $request, Post $post, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$post->getIdPost(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($post);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
    }

    private function handleEventImageUploads(Post $post, FormInterface $form): void
    {
        // Only process uploads for Événement posts (typePost = 2)
        if ($post->getTypePost() !== 2) {
            return;
        }

        $uploadDir = $this->getParameter('uploads_dir') . '/events';
        $this->ensureUploadDirectoryExists($uploadDir);

        // Get the embedded eventImages form collection
        $eventImagesForm = $form->get('eventImages');

        // Process each EventImage in the collection
        foreach ($eventImagesForm as $eventImageForm) {
            // Get the actual EventImage entity from the form (safe mapping)
            $eventImage = $eventImageForm->getData();
            
            if (!$eventImage instanceof EventImage) {
                continue;
            }

            // Retrieve the uploaded file from the form (image_path is unmapped)
            $uploadedFile = $eventImageForm->get('image_path')->getData();

            if ($uploadedFile) {
                // Generate unique filename and move file
                $filename = $this->generateUniqueFilename() . '.' . $uploadedFile->guessExtension();
                $uploadedFile->move($uploadDir, $filename);

                // Set the filename directly on the EventImage entity
                $eventImage->setImagePath($filename);
            }

            // Ensure the EventImage is associated with the Post
            $eventImage->setPost($post);
        }
    }

    private function generateUniqueFilename(): string
    {
        return bin2hex(random_bytes(12));
    }

    private function ensureUploadDirectoryExists(string $directory): void
    {
        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory, 0755);
        }
    }
}
