<?php

namespace App\Dto;

final class ReasonAnalysisResult
{
    public function __construct(
        public readonly string $language,
        public readonly string $originalText,
        public readonly string $correctedText,
        public readonly string $generatedText,
        public readonly array $grammarMessages,
        public readonly array $styleSuggestions,
    ) {
    }
}
