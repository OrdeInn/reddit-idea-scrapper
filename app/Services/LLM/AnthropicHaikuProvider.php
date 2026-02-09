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

class AnthropicHaikuProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    // Anthropic's current required API version header value (date-style, not model age).
    private const API_VERSION = '2023-06-01';

    /**
     * Get the provider name.
     */
    public function getProviderName(): string
    {
        return 'anthropic-haiku';
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get headers for Anthropic API requests.
     */
    protected function getHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
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
     * Classify a Reddit post using Anthropic Haiku.
     */
    public function classify(ClassificationRequest $request): ClassificationResponse
    {
        $requestPayload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->toAnthropicContent($request->getPromptContent()),
                ],
            ],
            'system' => 'You are a classification assistant. Analyze Reddit posts and classify them as potential SaaS idea sources. Respond only in valid JSON format.',
        ];

        $requestId = $this->getLogger()->logRequest(
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'classification',
            requestPayload: $requestPayload,
            postId: $request->postId
        );
        $startTime = microtime(true);

        try {
            $response = $this->client()->post($this->getApiUrl(), $requestPayload);
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

            Log::error('Anthropic Haiku API connection error', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new TransientClassificationException(
                message: 'Failed to connect to Anthropic API',
                provider: $this->getProviderName(),
                previous: $e
            );
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        if ($response->failed()) {
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

            Log::error('Anthropic Haiku API error', [
                'status' => $status,
                'error' => $error,
                'body_length' => strlen($body),
                'body_sha256' => hash('sha256', $body),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            if ($status >= 500 || $status === 429) {
                throw new TransientClassificationException(
                    message: "Anthropic API returned status {$status}: {$error}",
                    provider: $this->getProviderName()
                );
            }

            throw new PermanentClassificationException(
                message: "Anthropic API returned status {$status}: {$error}",
                provider: $this->getProviderName()
            );
        }

        $data = $response->json();

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

            Log::warning('Anthropic Haiku API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new PermanentClassificationException(
                message: 'Anthropic API returned invalid JSON response',
                provider: $this->getProviderName()
            );
        }

        $rawResponse = $data;
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);
        $refusalReason = $this->extractRefusalReason($data['content'] ?? null, $data['stop_reason'] ?? null);

        if ($content === '' && $refusalReason !== null) {
            $parsedResult = [
                'verdict' => 'skip',
                'confidence' => 0.0,
                'category' => 'refusal',
                'reasoning' => $refusalReason,
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

            Log::warning('Anthropic refusal returned (no content)', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return ClassificationResponse::fromJson($parsedResult, $rawResponse);
        }

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

            Log::warning('Anthropic Haiku API returned empty content', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
                'stop_reason' => $data['stop_reason'] ?? null,
            ]);

            throw new TransientClassificationException(
                message: 'Anthropic API returned empty content',
                provider: $this->getProviderName()
            );
        }

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

            Log::warning('Failed to parse Anthropic Haiku JSON response', [
                'content_length' => strlen($content),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new PermanentClassificationException(
                message: 'Failed to parse Anthropic API response',
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
     */
    public function extract(ExtractionRequest $request): ExtractionResponse
    {
        throw new RuntimeException(
            'Anthropic Haiku classification provider does not support extraction. ' .
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

    /**
     * Convert text to Anthropic content blocks format.
     */
    private function toAnthropicContent(string $text): array
    {
        return [['type' => 'text', 'text' => $text]];
    }

    /**
     * Extract text from Anthropic content blocks.
     */
    private function extractTextFromAnthropicContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return trim(implode("\n", $texts));
    }

    /**
     * Detect refusal/content-filter responses and extract a reason when possible.
     */
    private function extractRefusalReason(mixed $content, mixed $stopReason): ?string
    {
        if (is_array($content)) {
            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if (($block['type'] ?? null) === 'refusal') {
                    $text = $block['text'] ?? $block['refusal'] ?? null;
                    if (is_string($text) && trim($text) !== '') {
                        return trim($text);
                    }

                    return 'Content was refused by the API';
                }
            }
        }

        if (is_string($stopReason)) {
            $normalizedStopReason = strtolower($stopReason);

            if (
                str_contains($normalizedStopReason, 'refusal')
                || str_contains($normalizedStopReason, 'content_filter')
                || str_contains($normalizedStopReason, 'safety')
            ) {
                return 'Content was filtered/refused by the API';
            }
        }

        return null;
    }
}
