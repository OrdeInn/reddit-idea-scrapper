<?php

namespace App\Services\LLM;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAIGPT4MiniProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Get the provider name.
     * Returns 'openai' to match DB column mapping (gpt_verdict, gpt_confidence, etc.)
     */
    public function getProviderName(): string
    {
        return 'openai';
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get headers for OpenAI API requests.
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get the API endpoint URL.
     */
    protected function getApiUrl(): string
    {
        return self::API_URL;
    }

    /**
     * Classify a Reddit post using GPT-4o-mini.
     */
    public function classify(ClassificationRequest $request): ClassificationResponse
    {
        try {
            $response = $this->client()
                ->post($this->getApiUrl(), [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'temperature' => $this->temperature,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a classification assistant. Analyze Reddit posts and classify them as potential SaaS idea sources. Respond only in JSON format.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $request->getPromptContent(),
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::error('OpenAI API connection error', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new TransientClassificationException(
                message: "Failed to connect to OpenAI API",
                provider: $this->getProviderName(),
                previous: $e
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            Log::error('OpenAI API error', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            // Determine if error is transient or permanent
            if ($status >= 500 || $status === 429) {
                // Server errors and rate limiting - transient
                throw new TransientClassificationException(
                    message: "OpenAI API returned status {$status}",
                    provider: $this->getProviderName()
                );
            }

            // 4xx errors (except 429) - permanent
            throw new PermanentClassificationException(
                message: "OpenAI API returned status {$status}",
                provider: $this->getProviderName()
            );
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            Log::warning('OpenAI API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new PermanentClassificationException(
                message: "OpenAI API returned invalid JSON response",
                provider: $this->getProviderName()
            );
        }

        $rawResponse = $data;

        // Check for content filter
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;
        if ($finishReason === 'content_filter') {
            Log::warning('OpenAI content filter triggered', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'content-filtered',
                reasoning: 'Content was filtered by the API',
                rawResponse: $rawResponse,
            );
        }

        // Extract content from response
        $content = $data['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            Log::warning('OpenAI API returned empty content', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'error',
                reasoning: 'API returned empty response',
                rawResponse: $rawResponse,
            );
        }

        // Parse JSON response
        $parsed = $this->parseJsonResponse($content);

        if (empty($parsed)) {
            Log::warning('Failed to parse OpenAI JSON response', [
                'content_length' => strlen($content),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new PermanentClassificationException(
                message: "Failed to parse OpenAI API response",
                provider: $this->getProviderName()
            );
        }

        return ClassificationResponse::fromJson($parsed, $rawResponse);
    }

    /**
     * Extract SaaS ideas from a post.
     * This provider does not support extraction.
     *
     * @throws RuntimeException Always throws exception as extraction is not supported
     */
    public function extract(ExtractionRequest $request): ExtractionResponse
    {
        throw new RuntimeException(
            'OpenAI GPT-4o-mini provider does not support extraction. ' .
            'Use supportsExtraction() to check capability before calling extract().'
        );
    }

    /**
     * Check if provider supports classification.
     */
    public function supportsClassification(): bool
    {
        return true;
    }

    /**
     * Check if provider supports extraction.
     */
    public function supportsExtraction(): bool
    {
        return false;
    }
}
