<?php

namespace App\Service;

use App\Dto\ReasonAnalysisResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReasonAssistantService
{
    private const LANGUAGE_TOOL_URL = 'https://api.languagetool.org/v2/check';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function correctReason(string $reason): ReasonAnalysisResult
    {
        $text = trim($reason);
        $language = $this->detectLanguage($text);
        $errors = $this->checkText($text, $language);
        $correctedText = $this->applyCorrections($text, $errors);

        $grammarMessages = [];

        foreach ($errors as $error) {
            $message = $error['shortMessage'] !== '' ? $error['shortMessage'] : $error['message'];
            $suggestionText = empty($error['suggestions']) ? 'Aucune suggestion' : implode(', ', array_slice($error['suggestions'], 0, 3));
            $grammarMessages[] = sprintf('%s → %s', $message, $suggestionText);
        }

        return new ReasonAnalysisResult(
            $language,
            $text,
            $correctedText,
            '',
            $grammarMessages,
            []
        );
    }

    public function generateReason(string $reason, string $context = ''): ReasonAnalysisResult
    {
        $text = trim($reason);
        $language = $this->detectLanguage($text);
        $generatedText = $this->generateWithHuggingFace($text, $context, $language);

        return new ReasonAnalysisResult(
            $language,
            $text,
            '',
            $generatedText,
            [],
            []
        );
    }

    private function checkText(string $text, string $language): array
    {
        if ($text === '') {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', self::LANGUAGE_TOOL_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query([
                    'text' => $text,
                    'language' => $language,
                    'enabledOnly' => 'false',
                ]),
            ]);

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $data = $response->toArray(false);
            $matches = $data['matches'] ?? [];
            $errors = [];

            foreach ($matches as $match) {
                $suggestions = [];
                foreach (array_slice($match['replacements'] ?? [], 0, 3) as $replacement) {
                    if (isset($replacement['value'])) {
                        $suggestions[] = (string) $replacement['value'];
                    }
                }

                $rule = $match['rule'] ?? [];
                $category = (string) ($rule['category']['name'] ?? '');

                $errors[] = [
                    'message' => (string) ($match['message'] ?? ''),
                    'shortMessage' => (string) ($match['shortMessage'] ?? ''),
                    'offset' => (int) ($match['offset'] ?? 0),
                    'length' => (int) ($match['length'] ?? 0),
                    'suggestions' => $suggestions,
                    'category' => $category,
                    'ruleId' => (string) ($rule['id'] ?? ''),
                ];
            }

            return $errors;
        } catch (\Throwable) {
            return [];
        }
    }

    private function applyCorrections(string $text, array $errors): string
    {
        if ($text === '' || $errors === []) {
            return $text;
        }

        usort($errors, static fn (array $left, array $right): int => $right['offset'] <=> $left['offset']);

        $corrected = $text;
        foreach ($errors as $error) {
            if (empty($error['suggestions'])) {
                continue;
            }

            $replacement = (string) $error['suggestions'][0];
            $offset = (int) $error['offset'];
            $length = (int) $error['length'];

            if ($offset >= 0 && $offset <= strlen($corrected)) {
                $corrected = substr($corrected, 0, $offset) . $replacement . substr($corrected, $offset + $length);
            }
        }

        return trim($corrected);
    }

    private function generateWithHuggingFace(string $reason, string $context, string $language): string
    {
        if ($reason === '') {
            return '';
        }

        $hfToken = trim((string) ($_ENV['HF_TOKEN'] ?? ''));
        if ($hfToken === '') {
            return '';
        }

        $prompt = $language === 'fr'
            ? 'Rédige une seule phrase professionnelle naturelle. Ne répète pas la consigne ni la phrase d origine. Raison: ' . $reason . ($context !== '' ? '. Contexte: ' . $context : '')
            : 'Write one natural professional sentence. Do not repeat the prompt or the original sentence. Reason: ' . $reason . ($context !== '' ? '. Context: ' . $context : '');

        try {
            $response = $this->httpClient->request('POST', 'https://api-inference.huggingface.co/models/google/flan-t5-base', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $hfToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $prompt,
                    'parameters' => [
                        'max_length' => 80,
                        'temperature' => 0.7,
                    ],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log('[HF] HTTP ' . $statusCode . ' response');
                return '';
            }

            $data = $response->toArray(false);
            $generated = null;

            if (isset($data['generated_text'])) {
                $generated = (string) $data['generated_text'];
            } elseif (isset($data[0]['generated_text'])) {
                $generated = (string) $data[0]['generated_text'];
            } elseif (isset($data[0][0]['generated_text'])) {
                $generated = (string) $data[0][0]['generated_text'];
            }

            if ($generated !== null && trim($generated) !== '') {
                $clean = $this->normalizeGeneratedText($generated, $reason, $context, $language);
                if ($clean === '') {
                    return '';
                }
                if (!str_ends_with($clean, '.')) {
                    $clean .= '.';
                }
                return $clean;
            }

            return '';
        } catch (\Throwable $e) {
            error_log('[HF] Exception: ' . $e->getMessage());
            return '';
        }
    }

    private function normalizeGeneratedText(string $text, string $reason, string $context, string $language): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        $clean = trim($clean, "\"'“”„` ");
        $clean = preg_replace('/\b(\pL+)(\s+\1\b)+/iu', '$1', $clean) ?? $clean;

        $segments = preg_split('/(?<=[.!?])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($segments) && $segments !== []) {
            $uniqueSegments = [];
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }

                if (!in_array(mb_strtolower($segment), $uniqueSegments, true)) {
                    $uniqueSegments[] = mb_strtolower($segment);
                }
            }

            if ($uniqueSegments !== []) {
                $clean = implode(' ', array_map(static fn (string $segment): string => $segment, array_unique(array_map('trim', $segments))));
            }
        }

        $normalizedReason = mb_strtolower(trim($reason));
        $normalizedContext = mb_strtolower(trim($context));
        $normalizedClean = mb_strtolower($clean);

        if ($normalizedClean === '' || $normalizedClean === $normalizedReason || ($normalizedContext !== '' && $normalizedClean === $normalizedContext)) {
            return '';
        }

        return trim($clean);
    }

    private function detectLanguage(string $text): string
    {
        if ($text === '') {
            return 'en-US';
        }

        $lowerText = mb_strtolower($text);
        $frenchWords = ['je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'de', 'le', 'la', 'les', 'un', 'une', 'des', 'pour', 'dans', 'avec', 'est', 'sont', 'avoir', 'être'];
        $englishWords = ['i', 'you', 'he', 'she', 'we', 'they', 'the', 'a', 'an', 'is', 'are', 'have', 'has', 'with', 'for', 'to', 'of', 'in', 'on'];

        $frenchCount = 0;
        $englishCount = 0;

        foreach ($frenchWords as $word) {
            if (str_contains($lowerText, $word)) {
                $frenchCount++;
            }
        }

        foreach ($englishWords as $word) {
            if (str_contains($lowerText, $word)) {
                $englishCount++;
            }
        }

        return $frenchCount > $englishCount ? 'fr' : 'en-US';
    }
}

