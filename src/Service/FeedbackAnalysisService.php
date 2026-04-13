<?php

namespace App\Service;

final class FeedbackAnalysisService
{
    /**
     * @return array{label:string, score:int, positive:array, negative:array, problems:array, has_comment:bool}
     */
    public function analyzeReview(?string $commentaire, int $note = 0): array
    {
        $text = $this->normalize((string) $commentaire);
        $score = 0;

        if ($text !== '') {
            foreach ($this->positiveKeywords() as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score++;
                }
            }

            foreach ($this->negativeKeywords() as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score--;
                }
            }
        }

        if ($note >= 4) {
            $score += 2;
        } elseif ($note === 3) {
            $score += 0;
        } elseif ($note > 0) {
            $score -= 1;
        }

        $label = 'neutral';
        if ($score >= 2) {
            $label = 'positive';
        } elseif ($score <= -2) {
            $label = 'negative';
        }

        $positive = [];
        foreach ($this->positiveKeywords() as $keyword) {
            if ($text !== '' && str_contains($text, $keyword)) {
                $positive[] = $keyword;
            }
        }

        $negative = [];
        foreach ($this->negativeKeywords() as $keyword) {
            if ($text !== '' && str_contains($text, $keyword)) {
                $negative[] = $keyword;
            }
        }

        $problems = [];
        foreach ($this->problemKeywords() as $keyword) {
            if ($text !== '' && str_contains($text, $keyword)) {
                $problems[] = $keyword;
            }
        }

        return [
            'label' => $label,
            'score' => $score,
            'positive' => array_values(array_unique($positive)),
            'negative' => array_values(array_unique($negative)),
            'problems' => array_values(array_unique($problems)),
            'has_comment' => $text !== '',
        ];
    }

    /**
     * @param array<int, array{note:int, commentaire:?string}> $reviews
     * @return array{positive:int, neutral:int, negative:int, problem:int, total:int}
     */
    public function summarizeReviews(array $reviews): array
    {
        $summary = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'problem' => 0,
            'total' => 0,
        ];

        foreach ($reviews as $review) {
            $analysis = $this->analyzeReview($review['commentaire'] ?? null, (int) ($review['note'] ?? 0));
            $summary[$analysis['label']]++;
            if (!empty($analysis['problems'])) {
                $summary['problem']++;
            }
            $summary['total']++;
        }

        return $summary;
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * @return list<string>
     */
    private function positiveKeywords(): array
    {
        return [
            'good', 'great', 'useful', 'excellent', 'clear', 'helpful', 'satisfied', 'satisfaction',
            'bien', 'bonne', 'excellent', 'utile', 'claire', 'clair', 'satisfait', 'satisfaite',
            'professionnel', 'professional', 'recommend', 'recommande', 'parfait', 'positive', 'positif',
        ];
    }

    /**
     * @return list<string>
     */
    private function negativeKeywords(): array
    {
        return [
            'bad', 'poor', 'slow', 'confusing', 'problem', 'issue', 'not good', 'worst',
            'mauvais', 'mauvaise', 'lent', 'lentement', 'confus', 'confuse', 'problème', 'probleme',
            'insatisfait', 'insatisfaite', 'negative', 'negatif', 'décevant', 'decevant',
        ];
    }

    /**
     * @return list<string>
     */
    private function problemKeywords(): array
    {
        return [
            'delay', 'delayed', 'absence', 'bug', 'error', 'issue', 'problem', 'problème', 'probleme',
            'retard', 'manque', 'difficult', 'difficile', 'difficulté', 'difficulte', 'blocked', 'bloque',
        ];
    }
}