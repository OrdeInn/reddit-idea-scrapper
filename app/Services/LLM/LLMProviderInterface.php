<?php

namespace App\Services\LLM;

use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;

interface LLMProviderInterface
{
    /**
     * Classify a Reddit post to determine if it contains a viable SaaS idea.
     *
     * @param ClassificationRequest $request The post data to classify
     * @return ClassificationResponse The classification result
     * @throws \Exception If the API call fails
     */
    public function classify(ClassificationRequest $request): ClassificationResponse;

    /**
     * Extract SaaS ideas from a Reddit post that passed classification.
     *
     * @param ExtractionRequest $request The post data to analyze
     * @return ExtractionResponse The extracted ideas
     * @throws \Exception If the API call fails
     */
    public function extract(ExtractionRequest $request): ExtractionResponse;

    /**
     * Get the provider name (e.g., "anthropic", "openai").
     */
    public function getProviderName(): string;

    /**
     * Get the model name being used (e.g., "claude-3-5-haiku", "gpt-4o-mini").
     */
    public function getModelName(): string;

    /**
     * Check if this provider supports classification.
     */
    public function supportsClassification(): bool;

    /**
     * Check if this provider supports extraction.
     */
    public function supportsExtraction(): bool;
}
