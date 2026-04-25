<?php

namespace App\Controller;

use App\Services\DemandeAiAssistant;
use App\Services\DemandeFormHelper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        $resolvedType = $this->formHelper->resolveCanonicalType($type);
        $fields = $this->formHelper->getFieldsForType((string) ($resolvedType ?? $type));
        return new JsonResponse($fields);
    }

    #[Route('/demande-api/types/{categorie}', name: 'demande_api_types', methods: ['GET'])]
    public function getTypesForCategory(string $categorie): JsonResponse
    {
        $resolvedCategory = $this->formHelper->resolveCanonicalCategory($categorie);
        $types = $this->formHelper->getTypesForCategory((string) ($resolvedCategory ?? $categorie));
        return new JsonResponse($types);
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
            $result = $this->aiAssistant->generateDescriptionFromTitleAdaptive(
                $title,
                (string) ($payload['typeDemande'] ?? ''),
                (string) ($payload['categorie'] ?? ''),
                $this->getLoggedInEmployeId($session)
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

    #[Route('/demande-api/autocorrect', name: 'demande_api_autocorrect', methods: ['POST'])]
    public function autocorrect(Request $request, SessionInterface $session): JsonResponse
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
            return new JsonResponse(['success' => false, 'message' => 'Ajoutez un texte avant de lancer la correction.'], 400);
        }

        try {
            return new JsonResponse([
                'success' => true,
                'correctedText' => $this->aiAssistant->autoCorrectText($rawText),
            ]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Une erreur inattendue est survenue pendant la correction IA.'], 500);
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
        $fieldPlan = is_array($payload['fieldPlan'] ?? null) ? $payload['fieldPlan'] : [];
        $userPromptAutre = trim((string) ($payload['userPromptAutre'] ?? ''));
        if ('' !== $userPromptAutre) {
            $general['aiDescriptionPrompt'] = $userPromptAutre;
        }

        $general['manualFieldPlan'] = $fieldPlan;

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
                'skipConfirmationRestriction' => true === ($result['skipConfirmationRestriction'] ?? false),
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

    private function getLoggedInEmployeId(SessionInterface $session): ?int
    {
        $employeId = $session->get('employe_id');
        return is_numeric($employeId) ? (int) $employeId : null;
    }
}
