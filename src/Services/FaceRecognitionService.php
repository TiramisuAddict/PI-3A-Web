<?php

namespace App\Services;
class FaceRecognitionService
{
    private const EMBEDDING_MIN_LENGTH = 128;
    private const EMBEDDING_THRESHOLD = 0.6;

    /**
     * Validate face embedding format and content.
     *
     * @param array<int|string, float|int>|null $embedding Face embedding array (usually 128D vector)
     * @return array<string, bool|string|null> ['valid' => bool, 'error' => string|null]
     */
    public function validateEmbedding(?array $embedding): array
    {
        if ($embedding === null) {
            return ['valid' => false, 'error' => 'Embedding is null.'];
        }

        if (count($embedding) < self::EMBEDDING_MIN_LENGTH) {
            return ['valid' => false, 'error' => 'Embedding dimension too small (< ' . self::EMBEDDING_MIN_LENGTH . ').'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Compare two face embeddings using Euclidean distance.
     *
     * @param array<int|string, float|int> $embedding1 First embedding vector
     * @param array<int|string, float|int> $embedding2 Second embedding vector
     * @return float Distance score (0 = identical, 1 = completely different)
     */
    public function compareEmbeddings(array $embedding1, array $embedding2): float
    {
        if (count($embedding1) !== count($embedding2)) {
            return 1.0;
        }

        $sumSquaredDiff = 0.0;
        for ($i = 0; $i < count($embedding1); $i++) {
            $diff = (float)$embedding1[$i] - (float)$embedding2[$i];
            $sumSquaredDiff += $diff * $diff;
        }

        $distance = sqrt($sumSquaredDiff);
        return min(1.0, $distance / 4.0);
    }

    /**
     * Check if two embeddings match based on threshold.
     *
     * @param array<int|string, float|int> $embedding1 First embedding vector
     * @param array<int|string, float|int> $embedding2 Second embedding vector
     * @return bool True if embeddings match (distance < threshold)
     */
    public function embeddingsMatch(array $embedding1, array $embedding2): bool
    {
        $distance = $this->compareEmbeddings($embedding1, $embedding2);
        return $distance < self::EMBEDDING_THRESHOLD;
    }

    /**
     * Get the configured threshold for embedding matching.
     *
     * @return float Threshold value
     */
    public function getThreshold(): float
    {
        return self::EMBEDDING_THRESHOLD;
    }

    /**
     * Hash an embedding for storage (optional second layer).
     *
     * @param array<int|string, float|int> $embedding Face embedding array
     * @return string Hashed representation (for logging/audit purposes)
     */
    public function hashEmbedding(array $embedding): string
    {
        $json = json_encode($embedding);
        return hash('sha256', $json);
    }
}
