<?php

namespace App\Service;

use App\Dto\ReasonAnalysisResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReasonAssistantService
{
    private const LANGUAGE_TOOL_URL = 'https://api.languagetool.org/v2/check';
    private const HF_CHAT_URL = 'https://router.huggingface.co/v1/chat/completions';
    private const HF_DEFAULT_MODEL = 'meta-llama/Llama-3.1-8B-Instruct';

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

    // =====================================================================
    // LanguageTool methods — NOT TOUCHED
    // =====================================================================

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

    // =====================================================================
    // HuggingFace generation — FIXED
    // =====================================================================

    private function generateWithHuggingFace(string $reason, string $context, string $language): string
    {
        if ($reason === '') {
            return '';
        }

        $hfToken = trim((string) ($_ENV['HF_TOKEN'] ?? $_SERVER['HF_TOKEN'] ?? getenv('HF_TOKEN') ?: ''));
        if ($hfToken === '') {
            error_log('[HF] Missing HF_TOKEN');
            return '';
        }

        $model = trim((string) ($_ENV['HF_MODEL'] ?? $_SERVER['HF_MODEL'] ?? getenv('HF_MODEL') ?: self::HF_DEFAULT_MODEL));

        $prompt = $language === 'fr'
            ? 'Redige une seule phrase professionnelle naturelle en francais a partir de la raison suivante: ' . $reason . ($context !== '' ? '. Contexte: ' . $context : '')
            : 'Write one natural professional sentence from the following reason: ' . $reason . ($context !== '' ? '. Context: ' . $context : '');

        try {
            $response = $this->httpClient->request('POST', self::HF_CHAT_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $hfToken,
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
                    'max_tokens' => 80,
                    'temperature' => 0.7,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                error_log('[HF] HTTP ' . $statusCode . ' — body: ' . $response->getContent(false));
                return '';
            }

            $data = $response->toArray(false);
            $generated = null;

            if (isset($data['choices'][0]['message']['content'])) {
                $generated = (string) $data['choices'][0]['message']['content'];
            } elseif (isset($data['generated_text'])) {
                $generated = (string) $data['generated_text'];
            }

            if ($generated === null || trim($generated) === '') {
                error_log('[HF] Empty generated text. Full response: ' . json_encode($data));
                return '';
            }

            $clean = $this->normalizeGeneratedText($generated);
            if ($clean === '') {
                return '';
            }

            if (!str_ends_with($clean, '.') && !str_ends_with($clean, '!') && !str_ends_with($clean, '?')) {
                $clean .= '.';
            }

            return $clean;
        } catch (\Throwable $e) {
            error_log('[HF] Exception: ' . $e->getMessage());
            return '';
        }
    }

    private function normalizeGeneratedText(string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $clean = trim($clean, "\"'`");

        // Remove repeated consecutive words
        $clean = preg_replace('/\b(\pL+)(\s+\1\b)+/iu', '$1', $clean) ?? $clean;

        // Take only the first sentence
        if (preg_match('/^(.+?[.!?])\s/u', $clean, $matches)) {
            $clean = $matches[1];
        }

        // Strip any leftover template tokens just in case
        $clean = preg_replace('/<\|.*?\|>|<\/s>/u', '', $clean) ?? $clean;

        return trim($clean);
    }

    // =====================================================================
    // Language detection — FIXED
    // =====================================================================

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
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lowerText)) {
                $frenchCount++;
            }
        }

        foreach ($englishWords as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lowerText)) {
                $englishCount++;
            }
        }

        return $frenchCount > $englishCount ? 'fr' : 'en-US';
    }
}
