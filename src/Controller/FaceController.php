<?php

namespace App\Controller;

use App\Repository\EmployeRepository;
use App\Services\FaceRecognitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/face', name: 'face_')]
final class FaceController extends AbstractController
{
    #[Route('/register-page', name: 'register_page', methods: ['GET'])]
    public function registerPage(SessionInterface $session): Response
    {
        if ($session->get('employe_logged_in') !== true) {
            return $this->redirectToRoute('login');
        }

        return $this->render('face/register.html.twig');
    }

    #[Route('/verify-page', name: 'verify_page', methods: ['GET'])]
    public function verifyPage(Request $request): Response
    {
        return $this->render('face/verify.html.twig', [
            'prefill_email' => (string) $request->query->get('email', ''),
        ]);
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        SessionInterface $session,
        EmployeRepository $employeRepository,
        EntityManagerInterface $entityManager,
        FaceRecognitionService $faceRecognitionService
    ): JsonResponse {
        if ($session->get('employe_logged_in') !== true) {
            return $this->json(['success' => false, 'message' => 'Session invalide.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $embedding = $payload['embedding'] ?? null;
        $consent = (bool) ($payload['consent'] ?? false);
        $isLive = (bool) ($payload['isLive'] ?? false);

        if (!is_array($embedding)) {
            return $this->json(['success' => false, 'message' => 'Empreinte faciale manquante.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$consent) {
            return $this->json(['success' => false, 'message' => 'Consentement obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$isLive) {
            return $this->json(['success' => false, 'message' => 'Verification de vivacite echouee.'], Response::HTTP_BAD_REQUEST);
        }

        $employeId = $session->get('employe_id');
        $employe = is_numeric($employeId) ? $employeRepository->find((int) $employeId) : null;

        if (!$employe) {
            return $this->json(['success' => false, 'message' => 'Employe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $faceRecognitionService->assertEmbedding($embedding);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $employe
            ->setFaceEmbedding(array_values($embedding))
            ->setFaceRegisteredAt(new \DateTime())
            ->setFaceConsent(true);

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Face ID enregistre avec succes.',
        ]);
    }

    #[Route('/deactivate', name: 'deactivate', methods: ['POST'])]
    public function deactivate(
        Request $request,
        SessionInterface $session,
        EmployeRepository $employeRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        if ($session->get('employe_logged_in') !== true) {
            return $this->json(['success' => false, 'message' => 'Session invalide.'], Response::HTTP_UNAUTHORIZED);
        }

        $employeId = $session->get('employe_id');
        $employe = is_numeric($employeId) ? $employeRepository->find((int) $employeId) : null;

        if (!$employe) {
            return $this->json(['success' => false, 'message' => 'Employe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $employe
            ->setFaceEmbedding(null)
            ->setFaceRegisteredAt(null)
            ->setFaceConsent(false);

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Face ID desactive avec succes.',
        ]);
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(
        Request $request,
        SessionInterface $session,
        EmployeRepository $employeRepository,
        FaceRecognitionService $faceRecognitionService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $email = (string) ($payload['email'] ?? '');
        $embedding = $payload['embedding'] ?? null;
        $isLive = (bool) ($payload['isLive'] ?? false);

        if ($email === '' || !is_array($embedding)) {
            return $this->json(['success' => false, 'message' => 'Email ou empreinte manquant.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$isLive) {
            return $this->json(['success' => false, 'message' => 'Verification de vivacite echouee.'], Response::HTTP_BAD_REQUEST);
        }

        $employe = $employeRepository->findOneBy(['e_mail' => $email]);
        if (!$employe) {
            return $this->json(['success' => false, 'message' => 'Employe introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($employe->getFaceConsent() !== true) {
            return $this->json([
                'success' => false,
                'message' => 'Face ID est desactive pour ce compte. Activez-le dans le profil.',
            ], Response::HTTP_FORBIDDEN);
        }

        $reference = $employe->getFaceEmbedding();
        if (!is_array($reference) || $reference === []) {
            return $this->json(['success' => false, 'message' => 'Aucun Face ID enregistre pour ce compte.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $faceRecognitionService->compareEmbeddings($embedding, $reference);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        if (!$result['match']) {
            return $this->json([
                'success' => false,
                'message' => 'Visage non reconnu.',
                'distance' => $result['distance'],
                'threshold' => $result['threshold'],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $session->set('employe_logged_in', true);
        $session->set('employe_id', $employe->getId_employe());
        $session->set('employe_email', $employe->getEmail());
        $session->set('employe_role', $employe->getRole());
        if ($employe->getEntreprise()) {
            $session->set('employe_id_entreprise', $employe->getEntreprise()->getId_entreprise());
        }

        $redirectRoute = in_array((string) $employe->getRole(), ['administrateur entreprise', 'RH'], true)
            ? 'RH_Home'
            : 'employe_Home';

        return $this->json([
            'success' => true,
            'message' => 'Authentification Face ID reussie.',
            'distance' => $result['distance'],
            'threshold' => $result['threshold'],
            'redirect' => $this->generateUrl($redirectRoute),
        ]);
    }
}
