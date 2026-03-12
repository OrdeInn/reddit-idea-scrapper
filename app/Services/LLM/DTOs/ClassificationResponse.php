<?php

namespace App\Services\LLM\DTOs;

class ClassificationResponse
{
    public function __construct(
        public readonly string $verdict,
        public readonly float $confidence,
        public readonly string $category,
        public readonly string $reasoning,
        public readonly array $details,
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

        $details = [
            'hard_filter_triggered' => (bool) ($json['hard_filter_triggered'] ?? false),
            'hard_filter_reason' => is_string($json['hard_filter_reason'] ?? null) ? $json['hard_filter_reason'] : null,
            'points' => is_array($json['points'] ?? null) ? $json['points'] : null,
            'evidence_type' => is_string($json['evidence_type'] ?? null) ? $json['evidence_type'] : null,
        ];

        return new self(
            verdict: $verdict,
            confidence: $confidence,
            category: $category,
            reasoning: $reasoning,
            details: $details,
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
            'details' => $this->details,
            'raw_response' => $this->rawResponse,
        ];
    }
}
