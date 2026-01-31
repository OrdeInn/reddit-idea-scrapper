<?php

namespace App\Services\LLM;

use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyntheticKimiProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.synthetic.new/openai/v1/chat/completions';

    /**
     * Get the provider name.
     * MUST return 'synthetic' (NOT 'openai') to avoid column mapping collision.
     */
    public function getProviderName(): string
    {
        return 'synthetic';
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get headers for API requests.
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
     * Classify a Reddit post using Kimi K2.5.
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
            Log::error('Synthetic API connection error', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'network-error',
                reasoning: 'Failed to connect to API',
                rawResponse: [],
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            Log::error('Synthetic API error', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Synthetic API error ({$status}): {$error}");
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            Log::warning('Synthetic API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'invalid-response',
                reasoning: 'API returned invalid response format',
                rawResponse: ['body' => $response->body()],
            );
        }

        $rawResponse = $data;

        // Check for content filter
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;
        if ($finishReason === 'content_filter') {
            Log::warning('Synthetic API content filter triggered', [
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
            Log::warning('Synthetic API returned empty content', [
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
            Log::warning('Failed to parse Synthetic API JSON response', [
                'content_length' => strlen($content),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'parse-error',
                reasoning: 'Failed to parse model response',
                rawResponse: $rawResponse,
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
            'Synthetic Kimi provider does not support extraction. ' .
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
