<?php

namespace App\Services\LLM\DTOs;

class ClassificationResponse
{
    public function __construct(
        public readonly string $verdict,
        public readonly float $confidence,
        public readonly string $category,
        public readonly string $reasoning,
        public readonly array $rawResponse,
    ) {}

    /**
     * Create from API response JSON.
     */
    public static function fromJson(array $json, array $rawResponse = []): self
    {
        // Coerce verdict to string to avoid TypeError on non-string values
        $verdictInput = $json['verdict'] ?? 'skip';
        $verdict = is_string($verdictInput) ? strtolower($verdictInput) : 'skip';

        // Normalize verdict to only allow 'keep' or 'skip'
        if (! in_array($verdict, ['keep', 'skip'], true)) {
            $verdict = 'skip';
        }

        // Clamp confidence to [0, 1] range
        $confidence = (float) ($json['confidence'] ?? 0.0);
        $confidence = min(1.0, max(0.0, $confidence));

        // Coerce category and reasoning to string to avoid TypeError
        $category = $json['category'] ?? 'other';
        $category = is_string($category) ? $category : 'other';

        $reasoning = $json['reasoning'] ?? '';
        $reasoning = is_string($reasoning) ? $reasoning : '';

        return new self(
            verdict: $verdict,
            confidence: $confidence,
            category: $category,
            reasoning: $reasoning,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Check if the verdict is "keep".
     */
    public function isKeep(): bool
    {
        return $this->verdict === 'keep';
    }

    /**
     * Check if the verdict is "skip".
     */
    public function isSkip(): bool
    {
        return $this->verdict === 'skip';
    }

    /**
     * Convert to array for storage.
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'confidence' => $this->confidence,
            'category' => $this->category,
            'reasoning' => $this->reasoning,
            'raw_response' => $this->rawResponse,
        ];
    }
}
