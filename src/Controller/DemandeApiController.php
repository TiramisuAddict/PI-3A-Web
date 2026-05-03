<?php

namespace App\Controller;

use App\Services\DemandeAiAssistant;
use App\Services\DemandeFormHelper;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DemandeApiController extends AbstractController
{
    private const NOMINATIM_BASE_URL = 'https://nominatim.openstreetmap.org';
    private const NOMINATIM_HEADERS = [
        'Accept' => 'application/json',
        'User-Agent' => 'MomentumPiDev/1.0 demande-place-picker',
    ];

    private DemandeFormHelper $formHelper;
    private DemandeAiAssistant $aiAssistant;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
        DemandeFormHelper $formHelper,
        DemandeAiAssistant $aiAssistant,
        HttpClientInterface $httpClient,
        LoggerInterface $logger
    ) {
        $this->formHelper = $formHelper;
        $this->aiAssistant = $aiAssistant;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
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

    #[Route('/demande-api/geocode/reverse', name: 'demande_api_geocode_reverse', methods: ['GET'])]
    public function reverseGeocode(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Vous devez etre connecte.'], 401);
        }

        $lat = $this->parseCoordinate($request->query->get('lat'), -90.0, 90.0);
        $lon = $this->parseCoordinate($request->query->get('lon'), -180.0, 180.0);
        if (null === $lat || null === $lon) {
            return new JsonResponse(['success' => false, 'message' => 'Coordonnees invalides.'], 400);
        }

        $data = $this->requestNominatim('/reverse', [
            'format' => 'jsonv2',
            'lat' => (string) $lat,
            'lon' => (string) $lon,
            'zoom' => '18',
            'addressdetails' => '1',
            'accept-language' => 'fr',
        ]);

        if (null === $data) {
            return new JsonResponse(['success' => false, 'message' => 'Geocodage indisponible.'], 502);
        }

        return new JsonResponse([
            'success' => true,
            'lat' => $lat,
            'lon' => $lon,
            'name' => $this->normalizePlaceName($data, $lat, $lon),
        ]);
    }

    #[Route('/demande-api/geocode/search', name: 'demande_api_geocode_search', methods: ['GET'])]
    public function searchGeocode(Request $request, SessionInterface $session): JsonResponse
    {
        if (!$this->isEmployeLoggedIn($session)) {
            return new JsonResponse(['success' => false, 'message' => 'Vous devez etre connecte.'], 401);
        }

        $query = trim((string) $request->query->get('q', ''));
        if ('' === $query) {
            return new JsonResponse(['success' => false, 'message' => 'Recherche vide.'], 400);
        }

        $data = $this->requestNominatim('/search', [
            'format' => 'jsonv2',
            'q' => substr($query, 0, 180),
            'limit' => '1',
            'addressdetails' => '1',
            'accept-language' => 'fr',
        ]);

        if (null === $data) {
            return new JsonResponse(['success' => false, 'message' => 'Recherche indisponible.'], 502);
        }

        $firstResult = $data[0] ?? null;
        if (!is_array($firstResult)) {
            return new JsonResponse(['success' => false, 'message' => 'Aucun resultat trouve.'], 404);
        }

        $lat = $this->parseCoordinate($firstResult['lat'] ?? null, -90.0, 90.0);
        $lon = $this->parseCoordinate($firstResult['lon'] ?? null, -180.0, 180.0);
        if (null === $lat || null === $lon) {
            return new JsonResponse(['success' => false, 'message' => 'Resultat de recherche invalide.'], 502);
        }

        return new JsonResponse([
            'success' => true,
            'lat' => $lat,
            'lon' => $lon,
            'name' => $this->normalizePlaceName($firstResult, $lat, $lon),
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
        $manualFieldMode = true === ($payload['manualFieldMode'] ?? false) || true === ($fieldPlan['manualMode'] ?? false);
        $userPromptAutre = trim((string) ($payload['userPromptAutre'] ?? ''));
        if ('' !== $userPromptAutre) {
            $general['aiDescriptionPrompt'] = $userPromptAutre;
        }

        $general['manualFieldMode'] = $manualFieldMode;
        if ($manualFieldMode) {
            $general['manualFieldPlan'] = $fieldPlan;
        }

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

    private function parseCoordinate(mixed $value, float $min, float $max): ?float
    {
        if (!is_scalar($value) || '' === trim((string) $value) || !is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if ($coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }

    /**
     * @param array<string, string> $query
     * @return array<mixed>|null
     */
    private function requestNominatim(string $path, array $query): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::NOMINATIM_BASE_URL . $path, [
                'query' => $query,
                'headers' => self::NOMINATIM_HEADERS,
                'timeout' => 8,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('Nominatim geocoding request failed.', [
                    'path' => $path,
                    'status_code' => $statusCode,
                ]);
                return null;
            }

            $data = $response->toArray(false);
            return is_array($data) ? $data : null;
        } catch (\Throwable $exception) {
            $this->logger->warning('Nominatim geocoding request error.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<mixed> $place
     */
    private function normalizePlaceName(array $place, ?float $lat = null, ?float $lon = null): string
    {
        $displayName = trim((string) ($place['display_name'] ?? ''));
        if ('' !== $displayName) {
            return $displayName;
        }

        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $parts = [];
        foreach (['road', 'pedestrian', 'footway', 'neighbourhood', 'suburb', 'city', 'town', 'village', 'municipality', 'state', 'country'] as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ('' !== $value && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        if ([] !== $parts) {
            return implode(', ', $parts);
        }

        if (null !== $lat && null !== $lon) {
            return sprintf('%.5F, %.5F', $lat, $lon);
        }

        return 'Lieu selectionne';
    }
}
