<?php

namespace App\Services;

final class FaceRecognitionService
{
    private const MIN_EMBEDDING_SIZE = 128;

    /**
     * Distance max acceptee pour considerer le visage comme correspondant.
     */
    private const MATCH_THRESHOLD = 0.60;

    /**
     * @param array<int|float|string> $candidate
     * @param array<int|float|string> $reference
     */
    public function compareEmbeddings(array $candidate, array $reference): array
    {
        $this->assertEmbedding($candidate);
        $this->assertEmbedding($reference);

        $distance = $this->euclideanDistance($candidate, $reference);

        return [
            'match' => $distance <= self::MATCH_THRESHOLD,
            'distance' => $distance,
            'threshold' => self::MATCH_THRESHOLD,
        ];
    }

    /**
     * @param array<int|float|string> $embedding
     */
    public function assertEmbedding(array $embedding): void
    {
        if (count($embedding) < self::MIN_EMBEDDING_SIZE) {
            throw new \InvalidArgumentException('Empreinte faciale invalide.');
        }

        foreach ($embedding as $value) {
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException('Empreinte faciale invalide.');
            }
        }
    }

    /**
     * @param array<int|float|string> $a
     * @param array<int|float|string> $b
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        $sum = 0.0;

        for ($i = 0; $i < $len; ++$i) {
            $diff = ((float) $a[$i]) - ((float) $b[$i]);
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}
