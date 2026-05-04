<?php

namespace App\Controller;

use App\Entity\EventImage;
use App\Entity\Post;
use App\Form\PostType;
use App\Repository\PostRepository;
use App\Service\AnnouncementTemplateService;
use App\Service\NewsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD administration des publications (posts / evenements).
 */
#[Route('/post')]
final class PostController extends AbstractController
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly NewsService $newsService,
        private readonly AnnouncementTemplateService $announcementTemplateService,
    )
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * `post.utilisateur_id` reference `employe.id_employe` (contrainte FK en base).
     */
    private function resolvePostAuthorEmployeId(Request $request, Connection $connection): int
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session !== null) {
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
            'Impossible de definir l auteur du post : aucun id employe en session (employee_id / id_employe) '
            .'et aucune ligne dans la table employe. Creez au moins un employe en base ou connectez l employe.'
        );
    }

    #[Route('/', name: 'app_post_index', methods: ['GET'])]
    public function index(Request $request, PostRepository $postRepository, PaginatorInterface $paginator): Response
    {
        $search = trim($request->query->getString('search', ''));
        $posts = $paginator->paginate(
            $postRepository->createAdminIndexQueryBuilder('' !== $search ? $search : null),
            max(1, $request->query->getInt('page', 1)),
            10
        );

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
    public function rows(Request $request, PostRepository $postRepository, PaginatorInterface $paginator): Response
    {
        $search = trim($request->query->getString('search', ''));
        $posts = $paginator->paginate(
            $postRepository->createAdminIndexQueryBuilder('' !== $search ? $search : null),
            max(1, $request->query->getInt('page', 1)),
            10
        );

        return $this->render('post/_rows.html.twig', [
            'posts' => $posts,
            'search' => $search,
        ]);
    }

    #[Route('/news-suggestions', name: 'app_post_news_suggestions', methods: ['GET'])]
    public function newsSuggestions(): JsonResponse
    {
        $news = $this->newsService->getTopNews();

        return new JsonResponse([
            'success' => true,
            'articles' => $news['articles'],
            'provider' => $news['provider'],
            'usedFallback' => $news['usedFallback'],
            'message' => $news['usedFallback']
                ? 'Les actualites en ligne ne sont pas disponibles pour le moment. Voici quelques idees locales pour vous depanner.'
                : 'Actualites chargees avec succes.',
        ]);
    }

    #[Route('/generate-template', name: 'app_post_generate_template', methods: ['POST'])]
    public function generateTemplate(Request $request): JsonResponse
    {
        try {
            $title = trim((string) $request->request->get('title', ''));
            $sourceTitle = trim((string) $request->request->get('source_title', ''));

            if ($title === '') {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Veuillez saisir un titre avant de generer un brouillon.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $draft = $this->announcementTemplateService->generateDraft($title, $sourceTitle !== '' ? $sourceTitle : null);

            return new JsonResponse([
                'success' => true,
                'content' => $draft['content'],
                'provider' => $draft['provider'],
                'usedFallback' => $draft['usedFallback'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id_post}', name: 'app_post_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id_post')] Post $post): Response
    {
        $apiKey = $_ENV['GOOGLE_MAPS_API_KEY'];

        return $this->render('post/show.html.twig', [
            'post' => $post,
            'google_maps_api_key' => $apiKey,
        ]);
    }

    #[Route('/{id_post}/edit', name: 'app_post_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[MapEntity(id: 'id_post')] Post $post, EntityManagerInterface $entityManager): Response
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

    #[Route('/{id_post}/images/{id_image}/delete', name: 'app_post_event_image_delete', methods: ['POST'])]
    public function deleteEventImage(
        Request $request,
        #[MapEntity(id: 'id_post')] Post $post,
        #[MapEntity(id: 'id_image')] EventImage $eventImage,
        EntityManagerInterface $entityManager
    ): Response {
        if ($eventImage->getPost()?->getIdPost() !== $post->getIdPost()) {
            throw $this->createNotFoundException('Image introuvable pour cette publication.');
        }

        if ($this->isCsrfTokenValid('delete_event_image_'.$eventImage->getIdImage(), $request->getPayload()->getString('_token'))) {
            $this->deleteEventImageFile($eventImage);
            $post->removeEventImage($eventImage);
            $entityManager->remove($eventImage);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_post_edit', ['id_post' => $post->getIdPost()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id_post}', name: 'app_post_delete', methods: ['POST'])]
    public function delete(Request $request, #[MapEntity(id: 'id_post')] Post $post, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$post->getIdPost(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($post);
            $entityManager->flush();
        }

        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, $request->query->getInt('page', 1));

        return $this->redirectToRoute('app_post_index', array_filter([
            'search' => $search !== '' ? $search : null,
            'page' => $page > 1 ? $page : null,
        ], static fn (mixed $value): bool => $value !== null), Response::HTTP_SEE_OTHER);
    }

    private function handleEventImageUploads(Post $post, FormInterface $form): void
    {
        if ($post->getTypePost() !== 2 || !$form->has('event_image_files')) {
            return;
        }

        $uploadedFiles = $form->get('event_image_files')->getData();
        if (!\is_iterable($uploadedFiles)) {
            return;
        }

       $uploadsDir = $this->getParameter('uploads_dir');

        if (!is_string($uploadsDir)) {
            throw new \RuntimeException('Le paramètre uploads_dir doit être une chaîne de caractères.');
        }

        $uploadDir = $uploadsDir . '/events';
        $this->ensureUploadDirectoryExists($uploadDir);

        $nextOrder = $this->resolveNextEventImageOrder($post);

        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile === null) {
                continue;
            }

            $extension = $uploadedFile->guessExtension();

            if ($extension === null || $extension === '') {
                $extension = $uploadedFile->getClientOriginalExtension();
            }

            if ($extension === '') {
                $extension = 'bin';
            }
            $filename = $this->generateUniqueFilename() . '.' . $extension;
            $uploadedFile->move($uploadDir, $filename);

            $eventImage = new EventImage();
            $eventImage->setImagePath($filename);
            $eventImage->setOrdre($nextOrder++);

            $post->addEventImage($eventImage);
        }
    }

    private function deleteEventImageFile(EventImage $eventImage): void
    {
        $imagePath = $eventImage->getImagePath();

        if ($imagePath === null || $imagePath === '') {
            return;
        }

        $uploadsDir = $this->getParameter('uploads_dir');

        if (!is_string($uploadsDir)) {
            throw new \RuntimeException('Le paramètre uploads_dir doit être une chaîne de caractères.');
        }

        $filePath = $uploadsDir . '/events/' . basename($imagePath);
        if ($this->filesystem->exists($filePath)) {
            $this->filesystem->remove($filePath);
        }
    }

    private function resolveNextEventImageOrder(Post $post): int
    {
        $maxOrder = -1;

        foreach ($post->getEventImages() as $eventImage) {
            $currentOrder = $eventImage->getOrdre();
            if ($currentOrder !== null && $currentOrder > $maxOrder) {
                $maxOrder = $currentOrder;
            }
        }

        return $maxOrder + 1;
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
