<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ToxicIntentService
{
    private const BLOCK_THRESHOLD = 0.72;
    private const REVIEW_THRESHOLD = 0.55;
    private const HARMFUL_PATTERNS = [
        'threat' => [
            '/\b(i\s+will|i\'m\s+going\s+to|im\s+going\s+to|you\s+deserve\s+to)\s+(hurt|destroy|kill|beat|ruin)\b/i',
            '/\b(wait\s+until|watch\s+out|you\s+are\s+finished)\b/i',
        ],
        'harassment' => [
            '/\b(no\s+one\s+(likes|wants|needs)\s+you)\b/i',
            '/\b(everyone\s+(hates|laughs\s+at)\s+you)\b/i',
            '/\b(you\s+should\s+(leave|disappear|quit))\b/i',
            '/\b(go\s+away\s+forever)\b/i',
        ],
        'insult' => [
            '/\b(can(?:not|\'t)\s+think\s+of\s+an\s+insult\s+good\s+enough\s+for\s+you\s+to\s+understand)\b/i',
            '/\b(not\s+(smart|clever|bright)\s+enough\s+to\s+understand)\b/i',
            '/\b(too\s+(stupid|slow|ignorant)\s+to\s+understand)\b/i',
            '/\b(you\s+are\s+(worthless|useless|pathetic|a\s+joke))\b/i',
            '/\b(your\s+(work|idea|face|life)\s+is\s+(worthless|useless|a\s+joke))\b/i',
            '/\b(nobody\s+cares\s+about\s+you)\b/i',
        ],
        'identity_hate' => [
            '/\b(people\s+like\s+you\s+are\s+(not\s+welcome|the\s+problem))\b/i',
            '/\b(your\s+kind\s+(should|must)\s+(leave|go\s+away))\b/i',
        ],
    ];
    private static ?array $exportedModel = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     engine: string,
     *     score: float,
     *     is_toxic: bool,
     *     needs_review: bool,
     *     labels: array<string, float>,
     *     error?: string
     * }
     */
    public function analyze(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return $this->emptyResult('empty');
        }

        $scriptPath = $this->projectDir . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'toxic_intent_detector.py';
        if (!is_file($scriptPath)) {
            return $this->heuristicFallbackResult($text, 'Classifier script not found.');
        }

        $payload = json_encode(['text' => $text], JSON_THROW_ON_ERROR);
        $exportedModelResult = $this->analyzeWithExportedModel($text);
        if ($exportedModelResult !== null) {
            return $this->mergeResults($exportedModelResult, $this->heuristicFallbackResult($text));
        }

        if (!$this->shouldUsePythonClassifier()) {
            return $this->heuristicFallbackResult($text);
        }

        $lastError = '';
        foreach ($this->resolvePythonBinaries() as $pythonBinary) {
            $process = new Process([$pythonBinary, $scriptPath, 'predict', '--json'], $this->projectDir, null, $payload, 12);

            try {
                $process->mustRun();
                $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                return $this->normalizeResult(is_array($decoded) ? $decoded : []);
            } catch (\Throwable $exception) {
                $lastError = $this->formatProcessError($exception);
            }
        }

        return $this->heuristicFallbackResult($text, $lastError);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array{
     *     available: bool,
     *     engine: string,
     *     score: float,
     *     is_toxic: bool,
     *     needs_review: bool,
     *     labels: array<string, float>
     * }
     */
    private function normalizeResult(array $result): array
    {
        $labels = [];
        foreach (($result['labels'] ?? []) as $label => $score) {
            if (!is_string($label) || !is_numeric($score)) {
                continue;
            }

            $labels[$label] = round(max(0.0, min(1.0, (float) $score)), 4);
        }

        arsort($labels);
        $score = isset($result['score']) && is_numeric($result['score'])
            ? round(max(0.0, min(1.0, (float) $result['score'])), 4)
            : (float) (reset($labels) ?: 0.0);

        return [
            'available' => (bool) ($result['available'] ?? true),
            'engine' => (string) ($result['engine'] ?? 'python'),
            'score' => $score,
            'is_toxic' => (bool) ($result['is_toxic'] ?? ($score >= self::BLOCK_THRESHOLD)),
            'needs_review' => (bool) ($result['needs_review'] ?? ($score >= self::REVIEW_THRESHOLD)),
            'labels' => $labels,
        ];
    }

    /**
     * @return array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>}
     */
    private function emptyResult(string $engine): array
    {
        return [
            'available' => true,
            'engine' => $engine,
            'score' => 0.0,
            'is_toxic' => false,
            'needs_review' => false,
            'labels' => [],
        ];
    }

    /**
     * @return array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>, error?: string}
     */
    private function heuristicFallbackResult(string $text, string $error = ''): array
    {
        $normalized = $this->normalizeText($text);
        $labels = [];

        foreach (self::HARMFUL_PATTERNS as $label => $patterns) {
            $hits = 0;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $normalized) === 1) {
                    ++$hits;
                }
            }

            if ($hits > 0) {
                $labels[$label] = min(0.97, 0.62 + ($hits * 0.15));
            }
        }

        $hasTarget = preg_match('/\byou\b/i', $normalized) === 1;
        $hasHostileVerb = preg_match('/\b(leave|disappear|fail|lose|cry|suffer|regret)\b/i', $normalized) === 1;
        $hasBelittlingLanguage = preg_match('/\b(always|never|nobody|everyone|worthless|useless|pathetic)\b/i', $normalized) === 1;

        if ($hasTarget && $hasHostileVerb && $hasBelittlingLanguage) {
            $labels['harassment'] = max($labels['harassment'] ?? 0.0, 0.7);
        }

        arsort($labels);
        $score = (float) (reset($labels) ?: 0.0);
        $result = [
            'available' => true,
            'engine' => 'php-heuristic-fallback',
            'score' => round($score, 4),
            'is_toxic' => $score >= self::BLOCK_THRESHOLD,
            'needs_review' => $score >= self::REVIEW_THRESHOLD,
            'labels' => array_map(static fn (float $value): float => round($value, 4), $labels),
        ];

        if ($error !== '') {
            $result['error'] = $error;
        }

        return $result;
    }

    /**
     * @return array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>}|null
     */
    private function analyzeWithExportedModel(string $text): ?array
    {
        $model = $this->loadExportedModel();
        if ($model === null) {
            return null;
        }

        $features = $model['features'] ?? null;
        if (!is_array($features)) {
            return null;
        }

        $terms = $this->extractTerms($text);
        $weightedTerms = [];
        $norm = 0.0;

        foreach ($terms as $term => $count) {
            if (!isset($features[$term]) || !is_array($features[$term]) || !isset($features[$term][0], $features[$term][1])) {
                continue;
            }

            $tfidf = (float) $count * (float) $features[$term][0];
            $weightedTerms[$term] = $tfidf;
            $norm += $tfidf * $tfidf;
        }

        $logit = isset($model['intercept']) && is_numeric($model['intercept']) ? (float) $model['intercept'] : 0.0;
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($weightedTerms as $term => $tfidf) {
                $logit += ((float) $features[$term][1]) * ($tfidf / $norm);
            }
        }

        $score = 1.0 / (1.0 + exp(-$logit));

        return [
            'available' => true,
            'engine' => (string) ($model['engine'] ?? 'sklearn-tfidf-logreg-json-v1'),
            'score' => round($score, 4),
            'is_toxic' => $score >= self::BLOCK_THRESHOLD,
            'needs_review' => $score >= self::REVIEW_THRESHOLD,
            'labels' => ['toxic_intent' => round($score, 4)],
        ];
    }

    private function loadExportedModel(): ?array
    {
        if (self::$exportedModel !== null) {
            return self::$exportedModel;
        }

        $configuredModel = $_ENV['TOXICITY_EXPORTED_MODEL_PATH'] ?? $_SERVER['TOXICITY_EXPORTED_MODEL_PATH'] ?? getenv('TOXICITY_EXPORTED_MODEL_PATH');
        $modelPath = is_string($configuredModel) && trim($configuredModel) !== ''
            ? trim($configuredModel)
            : $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'toxic_intent_model.json';

        if (!is_file($modelPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($modelPath), true);
        self::$exportedModel = is_array($decoded) ? $decoded : null;

        return self::$exportedModel;
    }

    /**
     * @return array<string, int>
     */
    private function extractTerms(string $text): array
    {
        $normalized = $this->normalizeText($text);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($transliterated)) {
                $normalized = strtolower($transliterated);
            }
        }

        preg_match_all('/\b\w\w+\b/u', $normalized, $matches);
        $tokens = $matches[0] ?? [];
        $terms = [];

        foreach ($tokens as $token) {
            $terms[$token] = ($terms[$token] ?? 0) + 1;
        }

        $tokenCount = count($tokens);
        for ($index = 0; $index < $tokenCount - 1; ++$index) {
            $bigram = $tokens[$index] . ' ' . $tokens[$index + 1];
            $terms[$bigram] = ($terms[$bigram] ?? 0) + 1;
        }

        return $terms;
    }

    /**
     * @param array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>} $primary
     * @param array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>} $secondary
     *
     * @return array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>}
     */
    private function mergeResults(array $primary, array $secondary): array
    {
        $labels = $primary['labels'];
        foreach ($secondary['labels'] as $label => $score) {
            $labels[$label] = max($labels[$label] ?? 0.0, $score);
        }

        arsort($labels);
        $score = max($primary['score'], $secondary['score'], (float) (reset($labels) ?: 0.0));

        return [
            'available' => true,
            'engine' => $primary['engine'] . '+' . $secondary['engine'],
            'score' => round($score, 4),
            'is_toxic' => $score >= self::BLOCK_THRESHOLD,
            'needs_review' => $score >= self::REVIEW_THRESHOLD,
            'labels' => $labels,
        ];
    }

    /**
     * @return array{available: bool, engine: string, score: float, is_toxic: bool, needs_review: bool, labels: array<string, float>, error: string}
     */
    private function unavailableResult(string $error): array
    {
        return [
            'available' => false,
            'engine' => 'unavailable',
            'score' => 0.0,
            'is_toxic' => false,
            'needs_review' => false,
            'labels' => [],
            'error' => $error,
        ];
    }

    /**
     * @return string[]
     */
    private function resolvePythonBinaries(): array
    {
        $configured = $_ENV['TOXICITY_PYTHON_BINARY'] ?? $_SERVER['TOXICITY_PYTHON_BINARY'] ?? getenv('TOXICITY_PYTHON_BINARY');
        $candidates = [];

        if (is_string($configured) && trim($configured) !== '') {
            $candidates[] = trim($configured);
        }

        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && trim($localAppData) !== '') {
            $pythonCorePaths = glob($localAppData . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'pythoncore-*' . DIRECTORY_SEPARATOR . 'python.exe') ?: [];
            rsort($pythonCorePaths);
            $candidates = array_merge($candidates, $pythonCorePaths);
            $candidates[] = $localAppData . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python.exe';
        }

        $candidates[] = 'python';
        $candidates[] = 'py';

        return array_values(array_unique($candidates));
    }

    private function shouldUsePythonClassifier(): bool
    {
        $forcePython = $_ENV['TOXICITY_ALWAYS_USE_PYTHON'] ?? $_SERVER['TOXICITY_ALWAYS_USE_PYTHON'] ?? getenv('TOXICITY_ALWAYS_USE_PYTHON');
        if (is_string($forcePython) && in_array(strtolower($forcePython), ['1', 'true', 'yes'], true)) {
            return true;
        }

        $configuredModel = $_ENV['TOXICITY_MODEL_PATH'] ?? $_SERVER['TOXICITY_MODEL_PATH'] ?? getenv('TOXICITY_MODEL_PATH');
        $modelPath = is_string($configuredModel) && trim($configuredModel) !== ''
            ? trim($configuredModel)
            : $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'toxic_intent_model.joblib';

        return is_file($modelPath);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtolower($text);
        $text = preg_replace('/https?:\/\/\S+|www\.\S+/i', ' URL ', $text) ?? $text;
        $text = preg_replace('/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/', ' EMAIL ', $text) ?? $text;
        $text = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', ' IPADDRESS ', $text) ?? $text;
        $text = preg_replace('/@\w+/', ' USER ', $text) ?? $text;
        $text = preg_replace('/#(\w+)/', '$1', $text) ?? $text;
        $text = strtr($text, [
            '@' => 'a',
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '$' => 's',
            '5' => 's',
            '7' => 't',
            '!' => 'i',
        ]);
        $text = preg_replace('/(.)\1{2,}/', '$1$1', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function formatProcessError(\Throwable $exception): string
    {
        if ($exception instanceof ProcessFailedException) {
            return trim($exception->getProcess()->getErrorOutput()) ?: $exception->getMessage();
        }

        return $exception->getMessage();
    }
}
