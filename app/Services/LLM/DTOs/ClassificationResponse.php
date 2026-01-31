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
        return new self(
            verdict: strtolower($json['verdict'] ?? 'skip'),
            confidence: (float) ($json['confidence'] ?? 0.0),
            category: $json['category'] ?? 'other',
            reasoning: $json['reasoning'] ?? '',
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
