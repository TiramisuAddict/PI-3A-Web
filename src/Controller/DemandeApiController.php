<?php

namespace App\Controller;

use App\Form\DemandeDetailType;
use App\Services\DemandeAiAssistant;
use App\Services\DemandeFormHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class DemandeApiController extends AbstractController
{
    private DemandeFormHelper $formHelper;
    private DemandeAiAssistant $aiAssistant;

    public function __construct(DemandeFormHelper $formHelper, DemandeAiAssistant $aiAssistant)
    {
        $this->formHelper = $formHelper;
        $this->aiAssistant = $aiAssistant;
    }

    #[Route('/demande-api/fields/{type}', name: 'demande_api_fields', methods: ['GET'])]
    public function getFields(string $type): JsonResponse
    {
        $fields = $this->formHelper->getFieldsForType($type);
        return new JsonResponse($fields);
    }

    #[Route('/demande-api/types/{categorie}', name: 'demande_api_types', methods: ['GET'])]
    public function getTypesForCategory(string $categorie): JsonResponse
    {
        $types = $this->formHelper->getTypesForCategory($categorie);
        return new JsonResponse($types);
    }

    #[Route('/demande-api/detail-form/{type}', name: 'demande_api_detail_form', methods: ['GET'])]
    public function getDetailForm(string $type): Response
    {
        $fields = $this->formHelper->getFieldsForType($type);

        if (empty($fields)) {
            return new Response('', Response::HTTP_OK);
        }

        $detailForm = $this->createForm(DemandeDetailType::class, null, [
            'fields' => $fields,
            'existing_details' => [],
        ]);

        return $this->render('demande/_detail_fields.html.twig', [
            'detailForm' => $detailForm->createView(),
            'fields' => $fields,
        ]);
    }

    #[Route('/demande-api/suggest-classification', name: 'demande_api_suggest_classification', methods: ['POST'])]
    public function suggestClassification(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Vous devez etre connecte.'], 401);
        }

        if ($this->canManageDemandes($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Les comptes RH/Admin ne peuvent pas creer de demande employe.'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Payload JSON invalide.'], 400);
        }

        $rawText = trim((string) ($payload['text'] ?? ''));
        if ('' === $rawText) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoutez une description avant de lancer la suggestion intelligente.'], 400);
        }

        try {
            $result = $this->aiAssistant->generateClassificationSuggestion(
                $rawText,
                $this->formHelper->getCategoryTypes(),
                $this->formHelper->getPriorites()
            );

            $suggestedType = (string) ($result['typeDemande'] ?? '');
            $suggestedFields = $this->formHelper->getFieldsForType($suggestedType);
            $result['suggestedDetails'] = $this->aiAssistant->extractSuggestedDetailsForType(
                (string) ($result['correctedText'] ?? $rawText),
                $suggestedType,
                $suggestedFields
            );

            return new JsonResponse([
                'success' => true,
                'suggestion' => $result,
                'model' => $result['model'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Une erreur inattendue est survenue pendant la suggestion IA.'], 500);
        }
    }

    #[Route('/demande-api/generate-description', name: 'demande_api_generate_description', methods: ['POST'])]
    public function generateDescription(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Vous devez etre connecte.'], 401);
        }

        if ($this->canManageDemandes($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Les comptes RH/Admin ne peuvent pas creer de demande employe.'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Payload JSON invalide.'], 400);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ('' === $title) {
            return new JsonResponse(['success' => false, 'message' => 'Ajoutez un titre avant de lancer la generation IA.'], 400);
        }

        try {
            $result = $this->aiAssistant->generateDescriptionFromTitle(
                $title,
                (string) ($payload['typeDemande'] ?? ''),
                (string) ($payload['categorie'] ?? '')
            );

            return new JsonResponse([
                'success' => true,
                'description' => $result['description'] ?? '',
                'model' => $result['model'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Une erreur inattendue est survenue pendant la generation IA.'], 500);
        }
    }

    #[Route('/demande-api/autre/generate', name: 'demande_api_autre_generate', methods: ['POST'])]
    public function generateAutre(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Vous devez etre connecte.'], 401);
        }

        if ($this->canManageDemandes($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Les comptes RH/Admin ne peuvent pas creer de demande employe.'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Payload JSON invalide.'], 400);
        }

        $general = is_array($payload['general'] ?? null) ? $payload['general'] : [];
        $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];

        $typeDemande = (string) ($general['typeDemande'] ?? '');
        if ('Autre' !== $typeDemande) {
            return new JsonResponse(['success' => false, 'message' => 'La generation IA est reservee au type Autre.'], 400);
        }

        $autreFields = $this->formHelper->getFieldsForType('Autre');

        try {
            $result = $this->aiAssistant->generateAutreSuggestions($general, $details, $autreFields);

            return new JsonResponse([
                'success' => true,
                'correctedText' => $result['correctedText'] ?? '',
                'generatedDescription' => $result['generatedDescription'] ?? '',
                'suggestedGeneral' => $result['suggestedGeneral'] ?? [],
                'suggestedDetails' => $result['suggestedDetails'] ?? [],
                'dynamicFieldPlan' => $result['dynamicFieldPlan'] ?? ['add' => [], 'remove' => [], 'replaceBase' => false],
                'dynamicFieldConfidence' => $result['dynamicFieldConfidence'] ?? ['score' => 0, 'label' => 'Faible', 'tone' => 'warning', 'message' => 'Aucune evaluation disponible.'],
                'model' => $result['model'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Une erreur inattendue est survenue pendant la generation IA.'], 500);
        }
    }

    private function isEmployeLoggedIn(SessionInterface $session): bool
    {
        return true === $session->get('employe_logged_in');
    }

    private function canManageDemandes(SessionInterface $session): bool
    {
        return $this->isEmployeLoggedIn($session)
            && in_array((string) $session->get('employe_role'), ['RH', 'administrateur entreprise'], true);
    }
}
