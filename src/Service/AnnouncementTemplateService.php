<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnnouncementTemplateService
{
    private const MODEL_ID = 'google/flan-t5-base';
    private const MODEL_URL = 'https://api-inference.huggingface.co/models/google/flan-t5-base';
    private const MIN_ACCEPTED_WORDS = 35;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{content: string, provider: string, usedFallback: bool}
     */
    public function generateDraft(string $title, ?string $sourceTitle = null): array
    {
        $headline = $this->normalizeText($title);
        if ($headline === '') {
            return [
                'content' => trim($title),
                'provider' => 'Assistant',
                'usedFallback' => true,
            ];
        }

        $generated = $this->generateWithHuggingFace($headline, $sourceTitle);
        if ($generated !== null) {
            return [
                'content' => $generated,
                'provider' => 'Hugging Face',
                'usedFallback' => false,
            ];
        }

        return [
            'content' => $this->buildFallbackAnnouncement($headline),
            'provider' => 'Assistant',
            'usedFallback' => true,
        ];
    }

    public function generateAnnouncement(string $title, ?string $sourceTitle = null): string
    {
        return $this->generateDraft($title, $sourceTitle)['content'];
    }

    private function generateWithHuggingFace(string $headline, ?string $sourceTitle = null): ?string
    {
        $token = $this->resolveToken();
        if ($token === '') {
            return null;
        }

        $prompt = $this->buildPrompt($headline, $sourceTitle);

        try {
            $response = $this->httpClient->request('POST', self::MODEL_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $prompt,
                    'parameters' => [
                        'max_new_tokens' => 220,
                        'return_full_text' => false,
                        'temperature' => 0.85,
                        'top_p' => 0.92,
                        'repetition_penalty' => 1.15,
                        'do_sample' => true,
                    ],
                    'options' => [
                        'wait_for_model' => true,
                        'use_cache' => false,
                    ],
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $data = $response->toArray(false);
            $text = $this->extractGeneratedText($data);
            $text = $this->sanitizeGeneratedText($text, $headline);

            return $text !== '' ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $payload
     */
    private function extractGeneratedText(mixed $payload): string
    {
        if (is_array($payload) && isset($payload['generated_text'])) {
            return trim((string) $payload['generated_text']);
        }

        if (!is_array($payload)) {
            return '';
        }

        foreach ($payload as $item) {
            if (is_array($item) && isset($item['generated_text'])) {
                return trim((string) $item['generated_text']);
            }
        }

        return '';
    }

    private function sanitizeGeneratedText(string $text, string $headline): string
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return '';
        }

        $blockedFragments = [
            'sujet du moment',
            'vous pouvez adapter',
            'ceci est un brouillon',
            'nous invitons a relayer',
            'merci pour votre attention',
            'nous avons vu aujourd hui',
            'nous vous informons de cette information pour votre connaissance',
        ];

        $lower = $this->normalizeForComparison($text);
        foreach ($blockedFragments as $fragment) {
            if (str_contains($lower, $this->normalizeForComparison($fragment))) {
                return '';
            }
        }

        $rawLower = mb_strtolower($text);
        foreach (['annonce:', 'titre:'] as $promptLabel) {
            if (str_contains($rawLower, $promptLabel)) {
                return '';
            }
        }

        $plainText = str_replace("\n", ' ', $text);
        if ($this->countWords($plainText) < self::MIN_ACCEPTED_WORDS) {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($sentences) || count($sentences) < 3) {
            return '';
        }

        $normalizedHeadline = $this->normalizeForComparison($headline);
        if ($normalizedHeadline !== '' && ($lower === $normalizedHeadline || str_contains($lower, $normalizedHeadline.' '.$normalizedHeadline))) {
            return '';
        }

        return $text;
    }

    private function buildFallbackAnnouncement(string $headline): string
    {
        if ($this->isHolidayHeadline($headline)) {
            return implode("\n\n", [
                'Bonjour a tous,',
                'A l\'occasion de ce jour ferie, les activites courantes sont suspendues aujourd\'hui.',
                'Nous vous souhaitons une excellente journee de repos.',
            ]);
        }

        if ($this->isCelebrationHeadline($headline)) {
            return implode("\n\n", [
                'Bonjour a tous,',
                'Nous avons le plaisir de vous annoncer '.$this->headlineToSentenceFragment($headline).'. Ce moment sera l occasion de nous retrouver dans une ambiance conviviale, de valoriser les temps forts de l annee et de renforcer la cohesion entre les equipes.',
                'Les informations pratiques concernant l organisation et le deroulement seront partagees tres prochainement. Nous vous invitons d ores et deja a reserver ce rendez-vous dans vos agendas.',
            ]);
        }

        if ($this->isTrainingHeadline($headline)) {
            return implode("\n\n", [
                'Bonjour a tous,',
                'Nous souhaitons vous informer '.$this->headlineToSentenceFragment($headline).'. Cette initiative s inscrit dans une dynamique de developpement des competences et vise a accompagner les equipes sur des sujets utiles a leur activite.',
                'Des precisions complementaires concernant le contenu, les modalites de participation et le calendrier seront communiquees prochainement afin de permettre a chacun de s organiser au mieux.',
            ]);
        }

        return implode("\n\n", [
            'Bonjour a tous,',
            'Nous souhaitons partager avec vous une information importante concernant '.$headline.'. Ce sujet merite une attention particuliere, car il peut avoir un impact concret sur l organisation, les equipes ou la vie de l entreprise.',
            'Nous vous invitons a prendre connaissance de cette annonce et nous reviendrons vers vous avec tout complement utile des que de nouvelles precisions seront disponibles.',
        ]);
    }

    private function isHolidayHeadline(string $headline): bool
    {
        $normalized = $this->normalizeForComparison($headline);

        return in_array($normalized, ['jour ferie', 'repos', 'fermeture'], true);
    }

    private function isCelebrationHeadline(string $headline): bool
    {
        $normalized = $this->normalizeForComparison($headline);

        foreach (['fete', 'fin d annee', 'celebration', 'anniversaire', 'ceremonie', 'soiree', 'rencontre conviviale'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isTrainingHeadline(string $headline): bool
    {
        $normalized = $this->normalizeForComparison($headline);

        foreach (['formation', 'atelier', 'seminaire', 'workshop', 'coaching', 'apprentissage'] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function headlineToSentenceFragment(string $headline): string
    {
        $fragment = trim($headline);
        $lower = $this->normalizeForComparison($fragment);

        foreach (['la ', 'le ', 'les ', 'l ', 'un ', 'une ', 'des ', 'du ', 'de '] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $fragment;
            }
        }

        return 'de '.$fragment;
    }

    private function buildPrompt(string $headline, ?string $sourceTitle = null): string
    {
        $lines = [
            'Tu es charge de communication interne dans une entreprise.',
            'Transforme le titre suivant en une annonce complete, naturelle et prete a publier en francais.',
            'Consignes:',
            '- rediger 3 paragraphes courts',
            '- viser environ 90 a 140 mots',
            '- commencer directement par le texte final',
            '- expliquer le sujet avec un peu de contexte',
            '- montrer l interet concret pour les equipes ou l entreprise',
            '- terminer par une phrase d information ou de mobilisation',
            '- ne pas recopier le titre mot pour mot plus d une fois',
            '- ne pas ecrire "Nous avons vu aujourd hui"',
            '- ne pas ecrire "Nous vous informons de cette information pour votre connaissance"',
            '- ne pas parler de brouillon, de prompt ou d intelligence artificielle',
            '- ne pas utiliser de liste',
            'Titre: '.$headline,
        ];

        $normalizedSourceTitle = $this->normalizeText((string) $sourceTitle);
        if ($normalizedSourceTitle !== '' && $this->normalizeForComparison($normalizedSourceTitle) !== $this->normalizeForComparison($headline)) {
            $lines[] = 'Angle ou source complementaire: '.$normalizedSourceTitle;
        }

        $lines[] = 'Annonce:';

        return implode("\n", $lines);
    }

    private function resolveToken(): string
    {
        foreach (['HUGGING_FACE_EVENT', 'HF_TOKEN'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?? '';
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeForComparison(string $text): string
    {
        $normalized = mb_strtolower($this->normalizeText($text));
        $normalized = preg_replace('/[[:punct:]]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\']*/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim(preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text);
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        $text = trim(preg_replace('/\n +/u', "\n", $text) ?? $text);
        $text = trim(preg_replace('/ +\n/u', "\n", $text) ?? $text);

        return trim($text);
    }
}
