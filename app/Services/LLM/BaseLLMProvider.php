<?php

namespace App\Services\LLM;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BaseLLMProvider implements LLMProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected float $temperature;
    protected int $connectTimeout;
    protected int $requestTimeout;

    public function __construct(array $config)
    {
        $apiKey = $config['api_key'] ?? '';
        $model = $config['model'] ?? '';

        if (! is_string($apiKey)) {
            throw new \InvalidArgumentException('Config api_key must be a string');
        }
        if (! is_string($model)) {
            throw new \InvalidArgumentException('Config model must be a string');
        }

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = (int) ($config['max_tokens'] ?? 1024);
        $this->temperature = (float) ($config['temperature'] ?? 0.5);
        $this->connectTimeout = (int) config('llm.timeouts.connect', 10);
        $this->requestTimeout = (int) config('llm.timeouts.request', 120);

        if (empty($this->apiKey)) {
            Log::warning("LLM provider {$this->getProviderName()} has no API key configured");
        }
    }

    /**
     * Create HTTP client with common settings.
     */
    protected function client(): PendingRequest
    {
        $maxAttempts = (int) config('llm.retry.max_attempts', 3);
        $baseDelayMs = (int) config('llm.retry.base_delay_ms', 250);
        $maxDelayMs = (int) config('llm.retry.max_delay_ms', 15000);
        $jitterMs = (int) config('llm.retry.jitter_ms', 100);
        $honorRetryAfter = (bool) config('llm.retry.honor_retry_after', true);

        return Http::timeout($this->requestTimeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                max(1, $maxAttempts),
                function (int $attempt, \Throwable $exception) use ($baseDelayMs, $maxDelayMs, $jitterMs, $honorRetryAfter): int {
                    $delayMs = min($maxDelayMs, $baseDelayMs * (2 ** max(0, $attempt - 1)));

                    if ($honorRetryAfter && $exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response?->status();

                        if ($status === 429) {
                            $retryAfter = $exception->response?->header('Retry-After');
                            $retryAfterMs = $this->parseRetryAfterMs($retryAfter);

                            if ($retryAfterMs !== null) {
                                $delayMs = min($maxDelayMs, max($delayMs, $retryAfterMs));
                            }
                        }
                    }

                    if ($jitterMs > 0) {
                        $delayMs = max(0, $delayMs + random_int(-$jitterMs, $jitterMs));
                    }

                    return $delayMs;
                },
                function (\Throwable $exception, PendingRequest $request, ?string $method = null): bool {
                    // Retry only transient network / server errors
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response?->status();

                        return $status === 429 || $status === 408 || ($status !== null && $status >= 500);
                    }

                    return false;
                },
                false
            )
            ->withHeaders($this->getHeaders());
    }

    /**
     * Get headers for API requests.
     */
    abstract protected function getHeaders(): array;

    /**
     * Get the API endpoint URL.
     */
    abstract protected function getApiUrl(): string;

    /**
     * Parse JSON from model response text.
     */
    protected function parseJsonResponse(string $text): array
    {
        $text = trim($text);

        // Remove markdown code block if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            $candidate = $this->extractJsonCandidate($text);

            if ($candidate !== null) {
                $decodedCandidate = json_decode($candidate, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCandidate)) {
                    return $decodedCandidate;
                }
            }

            Log::warning('Failed to parse LLM JSON response', [
                'error' => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'Decoded value is not an array',
                'provider' => $this->getProviderName(),
                'model' => $this->model,
                'text_length' => strlen($text),
                'text_sha256' => hash('sha256', $text),
                'text_snippet' => $this->shouldIncludeSensitiveLogData() ? substr($text, 0, 200) : null,
            ]);
            return [];
        }

        return $decoded;
    }

    /**
     * Normalize OpenAI-compatible message content to a string.
     *
     * Some OpenAI-compatible APIs return "content" as an array of content parts.
     */
    protected function normalizeMessageContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (is_array($content)) {
            // Common format: [{ "type": "text", "text": "..." }, ...]
            $parts = [];

            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }

                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                    continue;
                }
            }

            return trim(implode("\n", $parts));
        }

        return '';
    }

    /**
     * Whether to include potentially sensitive response snippets in logs.
     *
     * Snippets can include user-generated content; prefer hashing in non-local environments.
     */
    protected function shouldIncludeSensitiveLogData(): bool
    {
        return app()->environment(['local', 'testing']) && (bool) config('app.debug', false);
    }

    /**
     * Parse a Retry-After header value into milliseconds.
     *
     * @return int|null Milliseconds, or null if unparseable.
     */
    private function parseRetryAfterMs(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Seconds
        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }

        // HTTP-date
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $ms = ($timestamp - time()) * 1000;

        return $ms > 0 ? (int) $ms : 0;
    }

    /**
     * Attempt to extract a JSON object/array from a noisy model response.
     *
     * This also strips control characters that commonly break JSON decoding.
     */
    private function extractJsonCandidate(string $text): ?string
    {
        $text = trim($text);

        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);

            // Strip ASCII control chars (including unescaped newlines/tabs inside strings)
            $candidate = preg_replace('/[\x00-\x1F\x7F]/', '', $candidate) ?? $candidate;

            return $candidate;
        }

        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');

        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $candidate = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
            $candidate = preg_replace('/[\x00-\x1F\x7F]/', '', $candidate) ?? $candidate;

            return $candidate;
        }

        return null;
    }

    /**
     * Default: supports classification.
     */
    public function supportsClassification(): bool
    {
        return true;
    }

    /**
     * Default: supports extraction.
     */
    public function supportsExtraction(): bool
    {
        return true;
    }
}
