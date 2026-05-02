<?php

namespace App\Service;

class BadWordService
{
    private array $badWords = [
        'idiot',
        'stupid',
        'dumb',
        'moron',
        'trash',
        'loser',
        'ugly',
        'bastard',
        'asshole',
        'shit',
        'fuck',
        'bitch',
        'slut',
        'whore',
        'damn',
        'merde',
        'con',
        'connard',
        'connasse',
        'pute',
        'salope',
        'batard',
        'debile',
        'imbecile',
        'encule',
        'enculee',
        'fdp',
    ];

    private array $leetMap = [
        '@' => 'a',
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '$' => 's',
        '5' => 's',
        '7' => 't',
        '!' => 'i',
        '4' => 'a',
    ];

    private function normalize(string $text): string
    {
        $text = strtolower($text);
        $text = strtr($text, $this->leetMap);
        $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text) ?? $text;
        $text = preg_replace('/(.)\1+/', '$1', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public function analyze(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));

        $score = 0;
        $foundWords = [];

        foreach ($words as $word) {
            foreach ($this->badWords as $badWord) {
                if (str_contains($word, $badWord)) {
                    $score += 10;
                    $foundWords[] = $badWord;
                    continue;
                }

                $maxLength = max(strlen($word), strlen($badWord));
                if ($maxLength === 0) {
                    continue;
                }

                $distance = levenshtein($word, $badWord);
                $similarity = 1 - ($distance / $maxLength);

                if ($similarity > 0.80) {
                    $score += 7;
                    $foundWords[] = $badWord;
                }
            }
        }

        $foundWords = array_values(array_unique($foundWords));

        return [
            'is_clean' => $score < 10,
            'score' => $score,
            'found' => $foundWords,
            'censored' => $this->censor($text, $foundWords),
        ];
    }

    public function censor(string $text, array $foundWords): string
    {
        foreach ($foundWords as $word) {
            $text = str_ireplace($word, '***', $text);
        }

        return $text;
    }
}
