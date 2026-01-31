<?php

namespace App\Services\LLM;

use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ClaudeSonnetProvider extends BaseLLMProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /**
     * Get the provider name.
     */
    public function getProviderName(): string
    {
        return 'anthropic';
    }

    /**
     * Get the model name.
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Get headers for Anthropic API.
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
     * Sonnet can classify but is not optimized for it.
     */
    public function classify(ClassificationRequest $request): ClassificationResponse
    {
        Log::warning('Using Claude Sonnet for classification is not cost-effective', [
            'provider' => $this->getProviderName(),
            'model' => $this->model,
        ]);

        try {
            $response = $this->client()
                ->post($this->getApiUrl(), [
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
                ]);
        } catch (ConnectionException $e) {
            Log::error('Claude Sonnet API connection error', [
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

            Log::error('Claude Sonnet API error', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Claude Sonnet API error ({$status}): {$error}");
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            Log::warning('Claude Sonnet API returned invalid JSON response', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.0,
                category: 'invalid-response',
                reasoning: 'API returned invalid response format',
                rawResponse: ['body' => substr($response->body(), 0, 2000)],
            );
        }

        $rawResponse = $data;

        // Extract content from Anthropic response format (handles multiple blocks)
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);

        if (empty($content)) {
            Log::warning('Claude Sonnet API returned empty content', [
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
            Log::warning('Failed to parse Claude Sonnet JSON response', [
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
     * Extract SaaS ideas from a Reddit post using Claude Sonnet.
     */
    public function extract(ExtractionRequest $request): ExtractionResponse
    {
        try {
            $response = $this->client()
                ->post($this->getApiUrl(), [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'temperature' => $this->temperature,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $this->toAnthropicContent($request->getPromptContent()),
                        ],
                    ],
                    'system' => $this->getExtractionSystemPrompt(),
                ]);
        } catch (ConnectionException $e) {
            Log::error('Claude Sonnet API connection error during extraction', [
                'error' => $e->getMessage(),
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['error' => 'network-error', 'message' => 'Failed to connect to API'],
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = $response->json('error.message') ?? $response->body();

            Log::error('Claude Sonnet API error during extraction', [
                'status' => $status,
                'error' => $error,
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Claude Sonnet API error ({$status}): {$error}");
        }

        $data = $response->json();

        // Guard against non-JSON or invalid responses
        if (! is_array($data)) {
            Log::warning('Claude Sonnet API returned invalid JSON response during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['body' => substr($response->body(), 0, 2000)],
            );
        }

        $rawResponse = $data;

        // Extract content from Anthropic response format (handles multiple blocks)
        $content = $this->extractTextFromAnthropicContent($data['content'] ?? null);

        if (empty($content)) {
            Log::warning('Claude Sonnet API returned empty content during extraction', [
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);

            return new ExtractionResponse(
                ideas: collect(),
                rawResponse: $rawResponse,
            );
        }

        // Check for truncated output (max_tokens reached)
        $stopReason = $data['stop_reason'] ?? null;
        if ($stopReason === 'max_tokens') {
            Log::warning('Claude Sonnet response was truncated due to max_tokens', [
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

        return ExtractionResponse::fromJson($parsed, $rawResponse);
    }

    /**
     * Get the system prompt for extraction.
     */
    private function getExtractionSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a SaaS opportunity analyst specializing in identifying viable business ideas for solo developers and small teams. Your analysis should be:

1. PRACTICAL - Focus on ideas that can be built in weeks/months, not years
2. SPECIFIC - Provide concrete details, not vague suggestions
3. REALISTIC - Consider actual market conditions and competition
4. ACTIONABLE - Include specific next steps and channels

When scoring ideas:
- Monetization (1-5): How clear and viable is the revenue model?
- Market Saturation (1-5): 5 = wide open market, 1 = extremely crowded
- Complexity (1-5): 5 = easy to build, 1 = very complex
- Demand Evidence (1-5): How strong is the evidence of demand in the post/comments?
- Overall (1-5): Holistic assessment considering all factors

Always respond with a JSON array. Return an empty array [] if no viable ideas exist.
SYSTEM;
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
            return $content;
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
}
