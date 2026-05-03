<?php

namespace App\Controller;

use App\Entity\Employe;
use App\Repository\EmployeRepository;
use App\Services\FaceRecognitionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/face', name: 'face_')]
final class FaceController extends AbstractController
{
    public function __construct(
        private EmployeRepository $employeRepository,
        private EntityManagerInterface $entityManager,
        private FaceRecognitionService $faceRecognitionService,
    ) {}

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, SessionInterface $session): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $employeId = $session->get('employe_id', 0);
            if ($employeId <= 0) {
                return $this->json(['success' => false,'message' => 'User not authenticated.'], 401);}

            $employe = $this->employeRepository->find($employeId);
            if ($employe === null) {
                return $this->json(['success' => false,'message' => 'User not found.'], 404);}

            // Validate embedding
            $embedding = $data['embedding'] ?? null;
            $validation = $this->faceRecognitionService->validateEmbedding($embedding);
            if ($validation['valid'] !== true) {
                return $this->json(['success' => false,'message' => 'Invalid face embedding: ' . $validation['error']], 400);
            }

            // Store embedding and enable Face ID
            $employe->setFaceEmbedding($embedding);
            $employe->setFaceEnabled(true);

            $this->entityManager->flush();

            return $this->json(['success' => true,'message' => 'Face ID registered successfully.'], 200);

        } catch (\Throwable $e) {
            return $this->json(['success' => false,'message' => 'Registration failed: ' . $e->getMessage()], 500);
        }
    }
    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request, SessionInterface $session): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Get email and embedding from request
            $email = (string) ($data['email'] ?? '');
            $embedding = $data['embedding'] ?? null;

            // Validate incoming embedding first
            $validation = $this->faceRecognitionService->validateEmbedding($embedding);
            if (!$validation['valid']) {
                return $this->json([
                    'success' => false,
                    'message' => 'Invalid face embedding: ' . $validation['error']
                ], 400);
            }

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Email address is required for Face ID login.'
                ], 400);
            }

            $employe = $this->employeRepository->findOneBy(['e_mail' => $email]);
            if ($employe === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            if ($employe->getFaceEnabled() !== true || $employe->getFaceEmbedding() === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Face ID not enabled for this user.'
                ], 403);
            }

            if (!$this->faceRecognitionService->embeddingsMatch($embedding, $employe->getFaceEmbedding())) {
                return $this->json([
                    'success' => false,
                    'message' => 'Face does not match stored embedding.'
                ], 403);
            }

            // Authentication successful - set session
            $this->clearOtpFlow($session);
            $session->set('employe_logged_in', true);
            $session->set('employe_id', $employe->getId_employe());
            $session->set('employe_email', $employe->getEmail());
            $session->set('employe_role', $employe->getRole());
            $session->set('employe_id_entreprise', $employe->getEntreprise()?->getId_entreprise());

            return $this->json([
                'success' => true,
                'message' => 'Face ID authentication successful.',
                'redirect' => $this->generateUrl($this->getRedirectRoute($employe->getRole()))
            ], 200);

        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/check-email', name: 'check_email', methods: ['POST'])]
    public function checkEmail(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $email = (string) ($data['email'] ?? '');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json([
                    'success' => false,
                    'message' => 'Email address is required for Face ID login.'
                ], 400);
            }

            $employe = $this->employeRepository->findOneBy(['e_mail' => $email]);
            if ($employe === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            if ($employe->getFaceEnabled() !== true || $employe->getFaceEmbedding() === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'Face ID not enabled for this user.'
                ], 403);
            }

            return $this->json([
                'success' => true,
                'message' => 'Email valid for Face ID.'
            ], 200);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Email check failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate Face ID for current user.
     */
    #[Route('/deactivate', name: 'deactivate', methods: ['POST'])]
    public function deactivate(SessionInterface $session): JsonResponse
    {
        try {
            $employeId = (int) $session->get('employe_id', 0);
            if ($employeId <= 0) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $employe = $this->employeRepository->find($employeId);
            if ($employe === null) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            // Deactivate Face ID
            $employe->setFaceEnabled(false);
            $employe->setFaceEmbedding(null);

            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Face ID deactivated successfully.'
            ], 200);

        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Deactivation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Face ID status for current user.
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(SessionInterface $session): JsonResponse
    {
        $employeId = (int) $session->get('employe_id', 0);
        if ($employeId <= 0) {
            return $this->json([
                'enabled' => false,
                'registered_at' => null
            ], 200);
        }

        $employe = $this->employeRepository->find($employeId);
        if ($employe === null) {
            return $this->json([
                'enabled' => false,
                'registered_at' => null
            ], 200);
        }

        return $this->json([
            'enabled' => $employe->getFaceEnabled(),
            'has_embedding' => $employe->getFaceEmbedding() !== null
        ], 200);
    }

    private function clearOtpFlow(SessionInterface $session): void
    {
        foreach ([
            'two_factor_pending',
            'two_factor_verified',
            'otp_user_type',
            'otp_user_id',
            'otp_destination',
        ] as $key) {
            $session->remove($key);
        }
    }

    private function getRedirectRoute(string $role): string
    {
        return match(strtolower($role)) {
            'administrateur entreprise' => 'RH_Home',
            'rh' => 'RH_Home',
            default => 'employe_Home'
        };
    }

}
