<?php

namespace App\Services\LLM\DTOs;

use Illuminate\Support\Collection;

class ExtractionResponse
{
    /**
     * @param Collection<IdeaDTO> $ideas
     */
    public function __construct(
        public readonly Collection $ideas,
        public readonly array $rawResponse,
    ) {}

    /**
     * Create from API response JSON (array of ideas).
     */
    public static function fromJson(array $ideasArray, array $rawResponse = []): self
    {
        $ideas = collect($ideasArray)
            ->map(fn ($idea) => IdeaDTO::fromJson($idea))
            ->filter(fn ($idea) => $idea !== null);

        return new self(
            ideas: $ideas,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Check if any ideas were extracted.
     */
    public function hasIdeas(): bool
    {
        return $this->ideas->isNotEmpty();
    }

    /**
     * Get the count of extracted ideas.
     */
    public function count(): int
    {
        return $this->ideas->count();
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'ideas' => $this->ideas->map(fn ($idea) => $idea->toArray())->toArray(),
            'raw_response' => $this->rawResponse,
        ];
    }
}
