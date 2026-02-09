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

/**
 * OpenAI GPT classifier provider for classification tasks.
 *
 * Note: The class name is legacy from when it used GPT-4o-mini. The configured model (see config/llm.php)
 * is now GPT-5-mini (gpt-5-mini-2025-08-07) for improved instruction-following and classification accuracy.
 */
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
     * Classify a Reddit post using GPT-5-mini.
     */
    public function classify(ClassificationRequest $request): ClassificationResponse
    {
        $requestPayload = [
            'model' => $this->model,
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
        $requestPayload[$this->getMaxTokensParamName()] = $this->maxTokens;
        $temperature = $this->getTemperaturePayloadValue();
        if ($temperature !== null) {
            $requestPayload['temperature'] = $temperature;
        }

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

            Log::error('OpenAI API error', [
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
                    message: "OpenAI API returned status {$status}: {$error}",
                    provider: $this->getProviderName()
                );
            }

            // 4xx errors (except 429) - permanent
            throw new PermanentClassificationException(
                message: "OpenAI API returned status {$status}: {$error}",
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

            Log::warning('OpenAI API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
                'status' => $response->status(),
                'body_length' => strlen($response->body()),
                'body_sha256' => hash('sha256', $response->body()),
                'body_snippet' => $this->shouldIncludeSensitiveLogData() ? substr($response->body(), 0, 200) : null,
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
        $message = $data['choices'][0]['message'] ?? null;
        $contentRaw = is_array($message) ? ($message['content'] ?? null) : null;
        $content = $this->normalizeMessageContent($contentRaw);

        // Fallback 1: Some OpenAI-compatible responses may return tool/function call arguments instead of message content.
        if ($content === '' && is_array($message)) {
            $toolCalls = $message['tool_calls'] ?? null;
            if (is_array($toolCalls) && isset($toolCalls[0]['function']['arguments']) && is_string($toolCalls[0]['function']['arguments'])) {
                $content = trim($toolCalls[0]['function']['arguments']);
            } elseif (isset($message['function_call']['arguments']) && is_string($message['function_call']['arguments'])) {
                $content = trim($message['function_call']['arguments']);
            }
        }

        // Fallback 2: Refusal payload without content should be treated as a skip (not a transient provider failure).
        if ($content === '' && is_array($message) && isset($message['refusal']) && is_string($message['refusal']) && trim($message['refusal']) !== '') {
            $parsedResult = [
                'verdict' => 'skip',
                'confidence' => 0.0,
                'category' => 'refusal',
                'reasoning' => $message['refusal'],
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

            Log::warning('OpenAI refusal returned (no content)', [
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
                parsedResult: [
                    'raw_response' => $rawResponse,
                ],
                durationMs: $durationMs,
                success: false,
                error: 'Response content is empty',
                postId: $request->postId
            );

            Log::warning('OpenAI API returned empty content', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
                'finish_reason' => $finishReason,
                'content_raw_type' => gettype($contentRaw),
                'message_keys' => is_array($message) ? array_keys($message) : null,
                'status' => $response->status(),
                'body_length' => strlen($response->body()),
                'body_sha256' => hash('sha256', $response->body()),
                'body_snippet' => substr($response->body(), 0, 2000),
            ]);

            throw new TransientClassificationException(
                message: 'OpenAI API returned empty content',
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
            'OpenAI classification provider does not support extraction. ' .
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
     * Some newer OpenAI models reject `max_tokens` and require `max_completion_tokens`.
     */
    private function getMaxTokensParamName(): string
    {
        // Keep this intentionally conservative to avoid breaking older chat-completions models.
        // gpt-5* models currently require max_completion_tokens.
        return str_starts_with($this->model, 'gpt-5') ? 'max_completion_tokens' : 'max_tokens';
    }

    /**
     * Some newer OpenAI models only support the default temperature.
     *
     * Return null to omit temperature and let the API default apply.
     */
    private function getTemperaturePayloadValue(): ?float
    {
        if (str_starts_with($this->model, 'gpt-5')) {
            return null;
        }

        return $this->temperature;
    }
}
