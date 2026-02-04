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
        $payload = [
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
        ];

        $requestId = $this->getLogger()->logRequest(
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'classification',
            requestPayload: $payload,
            postId: $request->postId
        );
        $startTime = microtime(true);

        try {
            $response = $this->client()->post($this->getApiUrl(), $payload);
        } catch (ConnectionException $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: $e->getMessage(),
                postId: $request->postId
            );

            Log::error('Synthetic API connection error', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new TransientClassificationException(
                message: 'Failed to connect to Synthetic API',
                provider: $this->getProviderName(),
                previous: $e
            );
        }

        if ($response->failed()) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $status = $response->status();
            $body = $response->body();
            $error = $response->json('error.message') ?? $body;

            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: "HTTP {$status}: {$error}",
                postId: $request->postId
            );

            Log::error('Synthetic API error', [
                'status' => $status,
                'error' => $error,
                'body_length' => strlen($body),
                'body_sha256' => hash('sha256', $body),
                'body_snippet' => $this->shouldIncludeSensitiveLogData() ? substr($body, 0, 200) : null,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            // Determine if error is transient or permanent
            if ($status >= 500 || $status === 429) {
                // Server errors and rate limiting - transient
                throw new TransientClassificationException(
                    message: "Synthetic API returned status {$status}",
                    provider: $this->getProviderName()
                );
            }

            // 4xx errors (except 429) - permanent
            throw new PermanentClassificationException(
                message: "Synthetic API returned status {$status}",
                provider: $this->getProviderName()
            );
        }

        $durationMs = (microtime(true) - $startTime) * 1000;
        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response is not valid JSON',
                postId: $request->postId
            );

            Log::warning('Synthetic API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
                'status' => $response->status(),
                'body_length' => strlen($response->body()),
                'body_sha256' => hash('sha256', $response->body()),
                'body_snippet' => $this->shouldIncludeSensitiveLogData() ? substr($response->body(), 0, 200) : null,
            ]);

            throw new PermanentClassificationException(
                message: "Synthetic API returned invalid JSON response",
                provider: $this->getProviderName()
            );
        }

        $rawResponse = $data;

        // Check for content filter
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;
        if ($finishReason === 'content_filter') {
            $parsedResult = [
                'verdict' => 'skip',
                'confidence' => 0.0,
                'category' => 'content-filtered',
                'reasoning' => 'Content was filtered by the API',
            ];

            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: $parsedResult,
                durationMs: $durationMs,
                success: true,
                postId: $request->postId
            );

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
        $contentRaw = $data['choices'][0]['message']['content'] ?? null;
        $content = $this->normalizeMessageContent($contentRaw);

        if ($content === '') {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response content is empty',
                postId: $request->postId
            );

            Log::warning('Synthetic API returned empty content', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new TransientClassificationException(
                message: 'Synthetic API returned empty content',
                provider: $this->getProviderName()
            );
        }

        // Parse JSON response
        $parsed = $this->parseJsonResponse($content);

        if (empty($parsed)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'classification',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Failed to parse JSON response',
                postId: $request->postId
            );

            Log::warning('Failed to parse Synthetic API JSON response', [
                'content_length' => strlen($content),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            // Parsing failure is permanent - API is responding but with bad format
            throw new PermanentClassificationException(
                message: "Failed to parse Synthetic API response",
                provider: $this->getProviderName()
            );
        }

        $this->getLogger()->logResponse(
            requestId: $requestId,
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'classification',
            parsedResult: $parsed,
            durationMs: $durationMs,
            success: true,
            postId: $request->postId
        );

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
