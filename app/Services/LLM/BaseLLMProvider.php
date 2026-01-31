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
        return Http::timeout($this->requestTimeout)
            ->connectTimeout($this->connectTimeout)
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
        // Try to extract JSON from the response
        // Some models wrap JSON in markdown code blocks
        $text = trim($text);

        // Remove markdown code block if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning('Failed to parse LLM JSON response', [
                'error' => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'Decoded value is not an array',
                'provider' => $this->getProviderName(),
                'model' => $this->model,
            ]);
            return [];
        }

        return $decoded;
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
