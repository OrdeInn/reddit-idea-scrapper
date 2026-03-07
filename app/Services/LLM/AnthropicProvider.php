<?php

namespace App\Services\LLM;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use App\Services\LLM\Prompts\ExtractionSystemPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AnthropicProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    // Anthropic's current required API version header value (date-style, not model age).
    private const API_VERSION = '2023-06-01';

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
     * Classify a Reddit post using the configured Anthropic model.
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

            Log::error('Anthropic API connection error', [
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

            Log::error('Anthropic API error', [
                'status' => $status,
                'error' => $error,
                'body_length' => strlen($body),
                'body_sha256' => hash('sha256', $body),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            if ($status >= 500 || $status === 429 || $status === 408) {
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

            Log::warning('Anthropic API returned invalid JSON response', [
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

            Log::warning('Anthropic API returned empty content', [
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

            Log::warning('Failed to parse Anthropic JSON response', [
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
     * Extract SaaS ideas from a Reddit post using the configured Anthropic model.
     *
     * @throws RuntimeException If this provider instance does not support extraction.
     */
    public function extract(ExtractionRequest $request): ExtractionResponse
    {
        if (! $this->supportsExtraction()) {
            throw new RuntimeException(
                "Anthropic provider ({$this->getProviderName()}) does not support extraction. " .
                'Use supportsExtraction() to check capability before calling extract().'
            );
        }

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
            'system' => ExtractionSystemPrompt::get(),
        ];

        $requestId = $this->getLogger()->logRequest(
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'extraction',
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
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: $e->getMessage(),
                postId: $request->postId
            );

            Log::error('Anthropic API connection error during extraction', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['error' => 'network-error', 'message' => 'Failed to connect to API'],
            );
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: "HTTP {$status}: {$error}",
                postId: $request->postId
            );

            Log::error('Anthropic API error during extraction', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Anthropic API error ({$status}): {$error}");
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response is not valid JSON',
                postId: $request->postId
            );

            Log::warning('Anthropic API returned invalid JSON response during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['body' => substr($response->body(), 0, 2000)],
            );
        }

        $rawResponse = $data;
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);

        if (empty($content)) {
            $this->getLogger()->logResponse(
                requestId: $requestId,
                provider: $this->getProviderName(),
                model: $this->model,
                operation: 'extraction',
                parsedResult: [],
                durationMs: $durationMs,
                success: false,
                error: 'Response content is empty',
                postId: $request->postId
            );

            Log::warning('Anthropic API returned empty content during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: $rawResponse,
            );
        }

        $stopReason = $data['stop_reason'] ?? null;
        if ($stopReason === 'max_tokens') {
            Log::warning('Anthropic response was truncated due to max_tokens', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);
        }

        $parsed = $this->parseJsonResponse($content);

        // Handle case where model returns an object with ideas array
        if (isset($parsed['ideas']) && is_array($parsed['ideas'])) {
            $parsed = $parsed['ideas'];
        }

        // If it's an associative array (single idea), wrap it
        if (! empty($parsed) && ! isset($parsed[0])) {
            $parsed = [$parsed];
        }

        $this->getLogger()->logResponse(
            requestId: $requestId,
            provider: $this->getProviderName(),
            model: $this->model,
            operation: 'extraction',
            parsedResult: $parsed,
            durationMs: $durationMs,
            success: true,
            postId: $request->postId
        );

        return ExtractionResponse::fromJson($parsed, $rawResponse);
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
