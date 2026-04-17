<?php

namespace App\Services;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DemandeAiAssistant
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 20
    ) {
    }

    /**
     * @param array<string, mixed> $generalContext
     * @param array<string, mixed> $currentDetails
     * @param array<int, array<string, mixed>> $autreFields
     * @return array<string, mixed>
     */
    public function generateAutreSuggestions(array $generalContext, array $currentDetails, array $autreFields): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('La cle API Hugging Face est manquante. Configurez HUGGINGFACE_API_KEY.');
        }

        $allowedKeys = [];
        $requiredKeys = [];
        $fieldLabels = [];
        $selectOptions = [];

        foreach ($autreFields as $field) {
            $key = (string) ($field['key'] ?? '');
            if ('' === $key) {
                continue;
            }

            $allowedKeys[] = $key;
            $requiredKeys[$key] = (bool) ($field['required'] ?? false);
            $fieldLabels[$key] = (string) ($field['label'] ?? $key);

            if (($field['type'] ?? null) === 'select' && !empty($field['options']) && is_array($field['options'])) {
                $selectOptions[$key] = array_values(array_map('strval', $field['options']));
            }
        }

        $prompt = $this->buildPrompt($generalContext, $currentDetails, $allowedKeys, $requiredKeys, $fieldLabels, $selectOptions);
        $rawText = $this->callHuggingFace($prompt);
        $parsed = $this->parseJsonResponse($rawText);

        $normalized = $this->normalizeSuggestions($parsed, $allowedKeys, $requiredKeys, $selectOptions, $currentDetails, $generalContext);

        return [
            'generatedDescription' => $normalized['generatedDescription'],
            'suggestedGeneral' => $normalized['suggestedGeneral'],
            'suggestedDetails' => $normalized['suggestedDetails'],
            'dynamicFieldPlan' => $normalized['dynamicFieldPlan'],
            'model' => $this->model,
        ];
    }

    private function buildPrompt(
        array $generalContext,
        array $currentDetails,
        array $allowedKeys,
        array $requiredKeys,
        array $fieldLabels,
        array $selectOptions
    ): string {
        $context = [
            'categorie' => (string) ($generalContext['categorie'] ?? ''),
            'typeDemande' => (string) ($generalContext['typeDemande'] ?? 'Autre'),
            'titre' => (string) ($generalContext['titre'] ?? ''),
            'descriptionGenerale' => (string) ($generalContext['description'] ?? ''),
            'priorite' => (string) ($generalContext['priorite'] ?? ''),
            'userPromptAutre' => (string) ($generalContext['aiDescriptionPrompt'] ?? ''),
            'detailsActuels' => $currentDetails,
        ];

        $schemaExample = [
            'descriptionBesoin' => 'Texte detaille et concret de la demande',
            'besoinPersonnalise' => 'Titre court specifique a la demande',
            'niveauUrgenceAutre' => $selectOptions['niveauUrgenceAutre'][1] ?? 'Normale',
            'dateSouhaiteeAutre' => '',
            'pieceOuContexte' => 'Contexte, contraintes ou references utiles',
        ];

        $rootSchemaExample = [
            'general' => [
                'titre' => 'Avance sur salaire exceptionnelle',
                'description' => 'Le collaborateur demande une avance sur salaire pour couvrir une depense urgente.',
                'priorite' => 'NORMALE',
            ],
            'details' => $schemaExample,
            'remove_fields' => ['dateSouhaiteeAutre'],
            'custom_fields' => [
                [
                    'key' => 'montantSouhaite',
                    'label' => 'Montant souhaite (TND)',
                    'type' => 'number',
                    'required' => true,
                    'value' => '1200',
                    'options' => [],
                ],
            ],
        ];

        $optionalFieldKeys = [];
        foreach ($allowedKeys as $key) {
            if (empty($requiredKeys[$key])) {
                $optionalFieldKeys[] = $key;
            }
        }

        return "Tu es un assistant RH/IT qui aide a rediger des demandes internes professionnelles en francais.\n"
            . "Renvoie STRICTEMENT un JSON valide, sans markdown, sans texte avant/apres.\n"
            . "Tu dois renvoyer un objet JSON racine avec les cles: general, details, remove_fields, custom_fields.\n"
            . "Les cles details autorisees sont: " . json_encode(array_values($allowedKeys), JSON_UNESCAPED_UNICODE) . "\n"
            . "Cles details obligatoires: " . json_encode(array_values(array_filter($allowedKeys, fn($k) => !empty($requiredKeys[$k]))), JSON_UNESCAPED_UNICODE) . "\n"
            . "Cles details optionnelles supprimables: " . json_encode($optionalFieldKeys, JSON_UNESCAPED_UNICODE) . "\n"
            . "Contraintes:\n"
            . "- general.titre: phrase courte, professionnelle et precise.\n"
            . "- general.description: resume complet de la demande (2 a 5 lignes).\n"
            . "- general.priorite: uniquement HAUTE, NORMALE ou BASSE.\n"
            . "- descriptionBesoin: texte professionnel, clair, concis, actionnable.\n"
            . "- besoinPersonnalise: intitule court.\n"
            . "- niveauUrgenceAutre: une valeur parmi " . json_encode($selectOptions['niveauUrgenceAutre'] ?? ['Faible', 'Normale', 'Urgente', 'Tres urgente'], JSON_UNESCAPED_UNICODE) . "\n"
            . "- dateSouhaiteeAutre: format YYYY-MM-DD ou chaine vide.\n"
            . "- pieceOuContexte: details complementaires utiles.\n"
            . "- remove_fields: tableau de cles optionnelles inutiles (jamais de champ obligatoire).\n"
            . "- custom_fields: 0 a 8 champs supplementaires utiles selon le texte employe.\n"
            . "- Chaque custom_field: key, label, type(text|textarea|select|number|date), required(boolean), value(string), options(array string pour select).\n"
            . "Libelles metiers des champs: " . json_encode($fieldLabels, JSON_UNESCAPED_UNICODE) . "\n"
            . "Contexte utilisateur: " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n"
            . "Exemple de forme attendue: " . json_encode($rootSchemaExample, JSON_UNESCAPED_UNICODE);
    }

    private function callHuggingFace(string $prompt): string
    {
        try {
            $model = trim($this->model);
            if ('' === $model) {
                throw new \RuntimeException('Le modele Hugging Face est manquant. Configurez HUGGINGFACE_MODEL.');
            }

            $response = $this->httpClient->request('POST', 'https://router.huggingface.co/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 420,
                    'stream' => false,
                ],
                'timeout' => $this->timeoutSeconds,
            ]);

            /** @var mixed $payload */
            $payload = $response->toArray(false);

            if (isset($payload['error']) && is_string($payload['error'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['error']);
            }

            if (isset($payload['message']) && is_string($payload['message'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['message']);
            }

            if (isset($payload['detail']) && is_string($payload['detail'])) {
                throw new \RuntimeException('Hugging Face API error: ' . $payload['detail']);
            }

            if (isset($payload['generated_text']) && is_string($payload['generated_text'])) {
                return $payload['generated_text'];
            }

            if (is_array($payload) && isset($payload[0]['generated_text']) && is_string($payload[0]['generated_text'])) {
                return $payload[0]['generated_text'];
            }

            if (
                isset($payload['choices']) &&
                is_array($payload['choices']) &&
                isset($payload['choices'][0]['message']['content']) &&
                is_string($payload['choices'][0]['message']['content'])
            ) {
                return $payload['choices'][0]['message']['content'];
            }

            return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';
        } catch (\RuntimeException $e) {
            $rawMessage = $e->getMessage();
            $this->logger->error('Erreur Hugging Face', ['exception' => $rawMessage]);
            throw new \RuntimeException($this->toUserFriendlyMessage($rawMessage));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Erreur transport Hugging Face', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Le service IA est temporairement indisponible.');
        } catch (\Throwable $e) {
            $this->logger->error('Erreur Hugging Face', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Erreur IA Hugging Face: ' . $e->getMessage());
        }
    }

    private function toUserFriendlyMessage(string $rawMessage): string
    {
        $normalized = strtolower($rawMessage);

        if (str_contains($normalized, 'sufficient permissions to call inference providers')) {
            return 'Votre token Hugging Face n a pas la permission Inference Providers. Activez cette permission dans les parametres du token puis reessayez.';
        }

        if (str_contains($normalized, 'unauthorized') || str_contains($normalized, '401')) {
            return 'Token Hugging Face invalide ou non autorise. Verifiez HUGGINGFACE_API_KEY dans .env.local.';
        }

        if (str_contains($normalized, 'rate limit')) {
            return 'Limite Hugging Face atteinte. Patientez quelques instants puis reessayez.';
        }

        if (str_contains($normalized, 'model not supported by provider hf-inference')) {
            return 'Le modele configure ne supporte pas hf-inference. Utilisez par exemple katanemo/Arch-Router-1.5B:hf-inference dans HUGGINGFACE_MODEL.';
        }

        return $rawMessage;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $rawText): array
    {
        $clean = trim($rawText);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $clean) ?? $clean;
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $clean, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<int, string> $allowedKeys
     * @param array<string, array<int, string>> $selectOptions
     * @param array<string, mixed> $currentDetails
     * @param array<string, mixed> $generalContext
     * @return array<string, mixed>
     */
    private function normalizeSuggestions(
        array $parsed,
        array $allowedKeys,
        array $requiredKeys,
        array $selectOptions,
        array $currentDetails,
        array $generalContext
    ): array {
        $generalPayload = isset($parsed['general']) && is_array($parsed['general']) ? $parsed['general'] : [];
        $detailsPayload = isset($parsed['details']) && is_array($parsed['details']) ? $parsed['details'] : $parsed;
        $removePayload = isset($parsed['remove_fields']) && is_array($parsed['remove_fields']) ? $parsed['remove_fields'] : [];
        $customPayload = isset($parsed['custom_fields']) && is_array($parsed['custom_fields']) ? $parsed['custom_fields'] : [];

        $details = [];

        foreach ($allowedKeys as $key) {
            if (isset($detailsPayload[$key])) {
                $details[$key] = $this->normalizeValue((string) $key, $detailsPayload[$key], $selectOptions[$key] ?? []);
            }
        }

        foreach ($allowedKeys as $key) {
            if (!isset($details[$key]) && isset($currentDetails[$key]) && '' !== trim((string) $currentDetails[$key])) {
                $details[$key] = (string) $currentDetails[$key];
            }
        }

        $generatedDescription = trim((string) ($details['descriptionBesoin'] ?? ''));
        if ('' === $generatedDescription) {
            $generatedDescription = trim((string) ($generalContext['aiDescriptionPrompt'] ?? ''));
        }

        if ('' === trim((string) ($details['besoinPersonnalise'] ?? ''))) {
            $details['besoinPersonnalise'] = trim((string) ($generalContext['titre'] ?? 'Demande personnalisee'));
        }

        if (isset($details['niveauUrgenceAutre']) && isset($selectOptions['niveauUrgenceAutre'])) {
            if (!in_array($details['niveauUrgenceAutre'], $selectOptions['niveauUrgenceAutre'], true)) {
                $details['niveauUrgenceAutre'] = $selectOptions['niveauUrgenceAutre'][1] ?? $selectOptions['niveauUrgenceAutre'][0] ?? 'Normale';
            }
        }

        if (isset($details['dateSouhaiteeAutre']) && '' !== $details['dateSouhaiteeAutre']) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $details['dateSouhaiteeAutre'])) {
                $details['dateSouhaiteeAutre'] = '';
            }
        }

        if (!isset($details['descriptionBesoin']) || '' === trim((string) $details['descriptionBesoin'])) {
            $details['descriptionBesoin'] = $generatedDescription;
        }

        if (!isset($details['pieceOuContexte']) || '' === trim((string) $details['pieceOuContexte'])) {
            $details['pieceOuContexte'] = trim((string) ($generalContext['description'] ?? ''));
        }

        $priorite = strtoupper(trim((string) ($generalPayload['priorite'] ?? $generalContext['priorite'] ?? 'NORMALE')));
        if (!in_array($priorite, ['HAUTE', 'NORMALE', 'BASSE'], true)) {
            $priorite = 'NORMALE';
        }

        $suggestedGeneral = [
            'titre' => $this->firstNonEmpty(
                trim((string) ($generalPayload['titre'] ?? '')),
                trim((string) ($details['besoinPersonnalise'] ?? '')),
                trim((string) ($generalContext['titre'] ?? '')),
                'Demande personnalisee'
            ),
            'description' => $this->firstNonEmpty(
                trim((string) ($generalPayload['description'] ?? '')),
                trim((string) ($details['descriptionBesoin'] ?? '')),
                trim((string) ($generalContext['description'] ?? ''))
            ),
            'priorite' => $priorite,
            'categorie' => $this->firstNonEmpty(
                $this->inferCategoryFromDescription($generalContext),
                trim((string) ($generalContext['categorie'] ?? '')),
                'Autre'
            ),
        ];

        $optionalAllowedToRemove = [];
        foreach ($allowedKeys as $baseKey) {
            if (empty($requiredKeys[$baseKey])) {
                $optionalAllowedToRemove[] = $baseKey;
            }
        }

        $removeFields = [];
        foreach ($removePayload as $removeKeyRaw) {
            $removeKey = trim((string) $removeKeyRaw);
            if (in_array($removeKey, $optionalAllowedToRemove, true)) {
                $removeFields[$removeKey] = true;
            }
        }

        $customFields = $this->normalizeCustomFields($customPayload, array_merge($allowedKeys, array_keys($removeFields)));
        $inferredCustomFields = $this->inferCustomFieldsFromDescription($generalContext, $details);
        $customFields = $this->mergeCustomFields($customFields, $inferredCustomFields);

        $inferredUrgence = $this->inferUrgenceFromDescription($generalContext);
        if (isset($selectOptions['niveauUrgenceAutre']) && in_array($inferredUrgence, $selectOptions['niveauUrgenceAutre'], true)) {
            $details['niveauUrgenceAutre'] = $inferredUrgence;
        }

        $inferredDate = $this->extractFrenchDate($generalContext);
        if ('' !== $inferredDate) {
            $details['dateSouhaiteeAutre'] = $inferredDate;
        }

        $defaultTitle = $this->inferDefaultTitle($generalContext);
        if ('' === trim((string) ($details['besoinPersonnalise'] ?? ''))) {
            $details['besoinPersonnalise'] = $defaultTitle;
        }

        if ('' === trim((string) ($details['descriptionBesoin'] ?? ''))) {
            $details['descriptionBesoin'] = $this->firstNonEmpty(
                trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                trim((string) ($generalContext['description'] ?? ''))
            );
        }

        return [
            'generatedDescription' => $generatedDescription,
            'suggestedGeneral' => $suggestedGeneral,
            'suggestedDetails' => $details,
            'dynamicFieldPlan' => [
                'add' => $customFields,
                'remove' => array_values(array_keys($removeFields)),
                'replaceBase' => [] !== $customFields,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $generalContext
     * @param array<string, string> $details
     * @return array<int, array<string, mixed>>
     */
    private function inferCustomFieldsFromDescription(array $generalContext, array $details): array
    {
        $rawText = $this->buildInferenceText($generalContext);
        $text = strtolower($rawText);
        $customFields = [];

        $amount = $this->extractAmountFromText($text);
        $date = $this->extractFrenchDate($generalContext);
        $formationType = $this->inferFormationType($text);
        $currentLocation = $this->extractCurrentLocation($rawText);
        $targetLocation = $this->extractTargetLocation($rawText);

        if ($this->containsAny($text, ['formation', 'certification', 'transport', 'deplacement', 'déplacement'])) {
            $customFields[] = [
                'key' => 'ai_type_formation',
                'label' => 'Type de formation',
                'type' => 'select',
                'required' => true,
                'value' => '' !== $formationType ? $formationType : 'Formation externe',
                'options' => ['Formation interne', 'Formation externe', 'Certification', 'Autre'],
            ];

            $customFields[] = [
                'key' => 'ai_lieu_depart_actuel',
                'label' => 'Lieu de depart actuel',
                'type' => 'text',
                'required' => true,
                'value' => $currentLocation,
            ];

            $customFields[] = [
                'key' => 'ai_lieu_souhaite',
                'label' => 'Lieu souhaite',
                'type' => 'text',
                'required' => true,
                'value' => $targetLocation,
            ];

            if ($this->containsAny($text, ['transport', 'deplacement', 'déplacement', 'moyen de transport'])) {
                $customFields[] = [
                    'key' => 'ai_type_transport',
                    'label' => 'Type de transport souhaite',
                    'type' => 'select',
                    'required' => false,
                    'value' => 'A definir',
                    'options' => ['A definir', 'Bus', 'Train', 'Voiture de service', 'Taxi'],
                ];
            }
        }

        if (str_contains($text, 'avance') && str_contains($text, 'salaire')) {
            $customFields[] = [
                'key' => 'ai_type_besoin',
                'label' => 'Type de besoin financier',
                'type' => 'select',
                'required' => true,
                'value' => 'Avance sur salaire',
                'options' => ['Avance sur salaire', 'Aide exceptionnelle', 'Pret interne'],
            ];

            $customFields[] = [
                'key' => 'ai_motif_financier',
                'label' => 'Motif financier',
                'type' => 'textarea',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    trim((string) ($details['descriptionBesoin'] ?? '')),
                    trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];
        }

        if (null !== $amount) {
            $customFields[] = [
                'key' => 'ai_montant_souhaite',
                'label' => 'Montant souhaite (TND)',
                'type' => 'number',
                'required' => true,
                'value' => (string) $amount,
            ];
        }

        if ('' !== $date) {
            $customFields[] = [
                'key' => 'ai_date_souhaitee',
                'label' => 'Date souhaitee',
                'type' => 'date',
                'required' => false,
                'value' => $date,
            ];
        }

        if ([] === $customFields) {
            $customFields[] = [
                'key' => 'ai_objet_demande',
                'label' => 'Objet de la demande',
                'type' => 'text',
                'required' => true,
                'value' => $this->inferDefaultTitle($generalContext),
            ];

            $customFields[] = [
                'key' => 'ai_details_traitement',
                'label' => 'Details a traiter',
                'type' => 'textarea',
                'required' => true,
                'value' => $this->firstNonEmpty(
                    trim((string) ($generalContext['aiDescriptionPrompt'] ?? '')),
                    trim((string) ($generalContext['description'] ?? ''))
                ),
            ];
        }

        return $this->normalizeCustomFields($customFields, []);
    }

    /**
     * @param array<int, array<string, mixed>> $primary
     * @param array<int, array<string, mixed>> $secondary
     * @return array<int, array<string, mixed>>
     */
    private function mergeCustomFields(array $primary, array $secondary): array
    {
        $merged = [];
        $used = [];

        foreach ([$primary, $secondary] as $group) {
            foreach ($group as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $key = trim((string) ($field['key'] ?? ''));
                if ('' === $key || isset($used[$key])) {
                    continue;
                }

                $used[$key] = true;
                $merged[] = $field;

                if (count($merged) >= 8) {
                    return $merged;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferDefaultTitle(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (str_contains($text, 'avance') && str_contains($text, 'salaire')) {
            return 'Avance sur salaire';
        }

        if (str_contains($text, 'remboursement')) {
            return 'Demande de remboursement';
        }

        if (str_contains($text, 'attestation')) {
            return 'Demande d attestation';
        }

        return $this->firstNonEmpty(
            trim((string) ($generalContext['titre'] ?? '')),
            'Demande personnalisee'
        );
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferUrgenceFromDescription(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (
            str_contains($text, 'urgent') ||
            str_contains($text, 'urgence') ||
            str_contains($text, 'immediat') ||
            str_contains($text, 'au plus vite')
        ) {
            return 'Urgente';
        }

        return 'Normale';
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function extractFrenchDate(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if (preg_match('/\b(\d{1,2})\s+(janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+(\d{4}))?\b/u', $text, $matches) !== 1) {
            return '';
        }

        $day = (int) ($matches[1] ?? 0);
        $monthRaw = (string) ($matches[2] ?? '');
        $year = isset($matches[3]) && '' !== $matches[3]
            ? (int) $matches[3]
            : (int) (new \DateTimeImmutable())->format('Y');

        if ($day < 1 || $day > 31) {
            return '';
        }

        $months = [
            'janvier' => 1,
            'fevrier' => 2,
            'février' => 2,
            'mars' => 3,
            'avril' => 4,
            'mai' => 5,
            'juin' => 6,
            'juillet' => 7,
            'aout' => 8,
            'août' => 8,
            'septembre' => 9,
            'octobre' => 10,
            'novembre' => 11,
            'decembre' => 12,
            'décembre' => 12,
        ];

        $month = $months[$monthRaw] ?? 0;
        if ($month < 1 || !checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function extractAmountFromText(string $text): ?float
    {
        if (preg_match('/\b(\d+(?:[\.,]\d{1,2})?)\s*(dt|tnd|dinar|dinars)\b/i', $text, $matches) === 1) {
            $value = str_replace(',', '.', (string) ($matches[1] ?? ''));
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function inferFormationType(string $text): string
    {
        if (str_contains($text, 'certification')) {
            return 'Certification';
        }

        if (str_contains($text, 'interne')) {
            return 'Formation interne';
        }

        if (str_contains($text, 'externe') || str_contains($text, 'transport') || str_contains($text, 'deplacement') || str_contains($text, 'déplacement')) {
            return 'Formation externe';
        }

        if (str_contains($text, 'formation')) {
            return 'Formation externe';
        }

        return '';
    }

    private function extractCurrentLocation(string $rawText): string
    {
        if (preg_match('/(?:actuellement|je\s+suis\s+actuellement|je\s+suis|situee?|située?)\s+(?:a|à|dans)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})/iu', $rawText, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractTargetLocation(string $rawText): string
    {
        if (preg_match('/formation\s+(?:a|à|dans)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})/iu', $rawText, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        if (preg_match('/(?:vers|destination\s*:?)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})/iu', $rawText, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        if (preg_match('/\bdans\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\'’\- ]{1,80})/iu', $rawText, $matches) === 1) {
            return $this->cleanupLocationCandidate((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function cleanupLocationCandidate(string $value): string
    {
        $clean = trim($value);
        $clean = preg_replace('/[\.,;:]+$/', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        $parts = preg_split('/\s+(?:et|mais|car|pour|puis|avec|qui|que)\s+/iu', $clean);
        if (is_array($parts) && isset($parts[0])) {
            $clean = trim((string) $parts[0]);
        }

        return substr($clean, 0, 80);
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function buildInferenceText(array $generalContext): string
    {
        return trim(implode(' ', [
            (string) ($generalContext['titre'] ?? ''),
            (string) ($generalContext['description'] ?? ''),
            (string) ($generalContext['aiDescriptionPrompt'] ?? ''),
        ]));
    }

    /**
     * @param array<string, mixed> $generalContext
     */
    private function inferCategoryFromDescription(array $generalContext): string
    {
        $text = strtolower($this->buildInferenceText($generalContext));

        if ($this->containsAny($text, ['finance', 'financier', 'financiere', 'salaire', 'avance', 'remboursement', 'budget', 'paye', 'paie', 'montant', 'facture', 'tnd', 'dt'])) {
            return 'Administrative';
        }

        if ($this->containsAny($text, ['conge', 'attestation', 'certificat', 'mutation', 'demission', 'rh'])) {
            return 'Ressources Humaines';
        }

        if ($this->containsAny($text, ['ordinateur', 'pc', 'laptop', 'logiciel', 'systeme', 'reseau', 'email', 'bug', 'informatique', 'acces'])) {
            return 'Informatique';
        }

        if ($this->containsAny($text, ['formation', 'certification', 'cours', 'training'])) {
            return 'Formation';
        }

        if ($this->containsAny($text, ['teletravail', 'horaire', 'heures supplementaires', 'organisation'])) {
            return 'Organisation du travail';
        }

        return '';
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $customFields
     * @param array<int, string> $forbiddenKeys
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCustomFields(array $customFields, array $forbiddenKeys): array
    {
        $normalized = [];
        $seenKeys = [];
        $allowedTypes = ['text', 'textarea', 'select', 'number', 'date'];

        foreach ($customFields as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $rawKey = strtolower(trim((string) ($candidate['key'] ?? '')));
            $slug = preg_replace('/[^a-z0-9_]+/', '_', $rawKey) ?? '';
            $slug = trim($slug, '_');
            if ('' === $slug) {
                continue;
            }

            if (!str_starts_with($slug, 'ai_')) {
                $slug = 'ai_' . $slug;
            }

            if (in_array($slug, $forbiddenKeys, true) || isset($seenKeys[$slug])) {
                continue;
            }

            $type = strtolower(trim((string) ($candidate['type'] ?? 'text')));
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $label = trim((string) ($candidate['label'] ?? 'Champ complementaire'));
            if ('' === $label) {
                $label = 'Champ complementaire';
            }

            $value = trim((string) ($candidate['value'] ?? ''));

            if ('date' === $type && '' !== $value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $value = '';
            }

            if ('number' === $type && '' !== $value && !is_numeric($value)) {
                $value = '';
            }

            $field = [
                'key' => $slug,
                'label' => substr($label, 0, 80),
                'type' => $type,
                'required' => (bool) ($candidate['required'] ?? false),
                'value' => substr($value, 0, 500),
            ];

            if ('select' === $type) {
                $options = [];
                $rawOptions = $candidate['options'] ?? [];
                if (is_array($rawOptions)) {
                    foreach ($rawOptions as $optionRaw) {
                        $option = trim((string) $optionRaw);
                        if ('' === $option) {
                            continue;
                        }
                        $options[$option] = substr($option, 0, 60);
                        if (count($options) >= 8) {
                            break;
                        }
                    }
                }

                $field['options'] = array_values($options);

                if ([] === $field['options']) {
                    $field['type'] = 'text';
                }
            }

            $normalized[] = $field;
            $seenKeys[$slug] = true;

            if (count($normalized) >= 8) {
                break;
            }
        }

        return $normalized;
    }

    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ('' !== trim($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     * @param array<int, string> $options
     */
    private function normalizeValue(string $key, mixed $value, array $options): string
    {
        $text = trim((string) $value);

        if ('' === $text) {
            return '';
        }

        if ('dateSouhaiteeAutre' === $key) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : '';
        }

        if ('niveauUrgenceAutre' === $key) {
            foreach ($options as $option) {
                if (strtolower($text) === strtolower($option)) {
                    return $option;
                }
            }

            return $options[1] ?? $options[0] ?? 'Normale';
        }

        return $text;
    }
}
