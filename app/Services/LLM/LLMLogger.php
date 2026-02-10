<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LLMLogger
{
    private string $channel = 'llm';

    /**
     * Log an outgoing LLM request.
     *
     * @param string $provider Provider name (e.g., 'openai', 'anthropic-haiku', 'anthropic-sonnet')
     * @param string $model Model identifier (e.g., 'gpt-5-mini-2025-08-07')
     * @param string $operation Operation type ('classification' or 'extraction')
     * @param array $requestPayload The request payload (will be sanitized before logging)
     * @param int|null $postId Optional post ID for correlation
     * @return string Unique request ID for correlation
     */
    public function logRequest(
        string $provider,
        string $model,
        string $operation,
        array $requestPayload,
        ?int $postId = null
    ): string {
        $requestId = (string) Str::uuid();
        $timestamp = now()->toIso8601String();

        try {
            Log::channel($this->channel)->info('LLM Request', [
                'request_id' => $requestId,
                'provider' => $provider,
                'model' => $model,
                'operation' => $operation,
                'post_id' => $postId,
                'timestamp' => $timestamp,
                'request_payload' => $this->sanitizePayload($requestPayload),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log LLM request', [
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
        }

        return $requestId;
    }

    /**
     * Log an LLM response.
     *
     * @param string $requestId Correlation ID from the request
     * @param string $provider Provider name
     * @param string $model Model identifier
     * @param string $operation Operation type
     * @param array $parsedResult The parsed model output (e.g., verdict, scores, etc.), will be sanitized before logging
     * @param float $durationMs Request duration in milliseconds
     * @param bool $success Whether the request was successful
     * @param string|null $error Error message if unsuccessful
     */
    public function logResponse(
        string $requestId,
        string $provider,
        string $model,
        string $operation,
        array $parsedResult,
        float $durationMs,
        bool $success,
        ?string $error = null,
        ?int $postId = null
    ): void {
        $timestamp = now()->toIso8601String();

        $logData = [
            'request_id' => $requestId,
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'post_id' => $postId,
            'timestamp' => $timestamp,
            'duration_ms' => $durationMs,
            'success' => $success,
            'parsed_result' => $this->sanitizePayload($parsedResult),
        ];

        if ($error !== null) {
            $logData['error'] = $error;
        }

        try {
            $level = $success ? 'info' : 'error';
            Log::channel($this->channel)->{$level}('LLM Response', $logData);
        } catch (\Throwable $e) {
            Log::warning('Failed to log LLM response', [
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
        }
    }

    /**
     * Sanitize payload by removing sensitive data and truncating large fields.
     *
     * @param array $payload The payload to sanitize
     * @return array Sanitized copy of the payload
     */
    private function sanitizePayload(array $payload): array
    {
        return $this->sanitizeArrayRecursively($payload);
    }

    /**
     * Recursively sanitize an array by redacting sensitive keys and truncating large values.
     *
     * @param array $data The data to sanitize
     * @return array Sanitized copy
     */
    private function sanitizeArrayRecursively(array $data): array
    {
        $sanitized = [];
        $sensitiveKeys = ['api_key', 'authorization', 'x-api-key'];
        $isProduction = app()->environment('production');

        foreach ($data as $key => $value) {
            // Only lowercase if key is a string (numeric keys throw TypeError in strtolower)
            $lowerKey = is_string($key) ? strtolower($key) : null;

            // Redact sensitive keys
            if ($lowerKey !== null && in_array($lowerKey, $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            // Handle content omission in production
            if ($isProduction) {
                // Omit full messages in production
                if ($lowerKey === 'messages' && is_array($value)) {
                    $sanitized[$key] = $this->sanitizeMessages($value);
                    continue;
                }

                // Omit full system prompt in production (handle both string and array formats)
                if ($lowerKey === 'system') {
                    if (is_string($value)) {
                        $sanitized[$key] = sprintf('[SYSTEM_PROMPT_OMITTED: %d chars]', strlen($value));
                        continue;
                    } elseif (is_array($value)) {
                        // For array-based system prompts, omit content but keep structure
                        $sanitized[$key] = $this->sanitizeContentArray($value);
                        continue;
                    }
                }

                // Omit full content in production (handle common variations: input, prompt, contents, content)
                // Content fields can be strings or arrays/blocks, so we handle both
                if (in_array($lowerKey, ['input', 'prompt', 'contents', 'content'], true)) {
                    if (is_string($value)) {
                        $sanitized[$key] = sprintf('[CONTENT_OMITTED: %d chars]', strlen($value));
                        continue;
                    } elseif (is_array($value)) {
                        // For array-based content (e.g., content blocks with text fields),
                        // replace the entire subtree with a placeholder
                        $sanitized[$key] = $this->sanitizeContentArray($value);
                        continue;
                    }
                }
            }

            // Recursively process nested arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArrayRecursively($value);
                continue;
            }

            // Truncate large string values to prevent log bloat
            if (is_string($value) && strlen($value) > 5000) {
                $sanitized[$key] = substr($value, 0, 5000) . '... [TRUNCATED]';
                continue;
            }

            // Preserve the value as-is (including nulls)
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Sanitize the messages array by omitting or truncating content in production.
     *
     * @param array $messages The messages array
     * @return array Sanitized messages
     */
    private function sanitizeMessages(array $messages): array
    {
        $isProduction = app()->environment('production');

        if (!$isProduction) {
            // In local/testing, return the full messages array
            return $messages;
        }

        // In production, omit content but keep structure
        $sanitized = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                $sanitized[] = $message;
                continue;
            }

            $sanitizedMessage = [];
            foreach ($message as $key => $value) {
                $lowerKey = is_string($key) ? strtolower($key) : null;

                if ($lowerKey === 'content') {
                    if (is_string($value)) {
                        // Omit full content, but include char count
                        $sanitizedMessage[$key] = sprintf('[CONTENT_OMITTED: %d chars]', strlen($value));
                    } elseif (is_array($value)) {
                        // Handle array-based content (e.g., with images)
                        $totalChars = 0;
                        foreach ($value as $part) {
                            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                                $totalChars += strlen($part['text']);
                            } elseif (is_string($part)) {
                                $totalChars += strlen($part);
                            }
                        }
                        $sanitizedMessage[$key] = sprintf('[CONTENT_OMITTED: %d chars]', $totalChars);
                    } else {
                        // Recursive sanitization for nested structures
                        $sanitizedMessage[$key] = $this->sanitizeArrayRecursively((array) $value);
                    }
                } else {
                    // Recursively sanitize nested structures in other message fields
                    if (is_array($value)) {
                        $sanitizedMessage[$key] = $this->sanitizeArrayRecursively($value);
                    } else {
                        $sanitizedMessage[$key] = $value;
                    }
                }
            }
            $sanitized[] = $sanitizedMessage;
        }

        return $sanitized;
    }

    /**
     * Sanitize content arrays by omitting text content but computing total char count.
     *
     * Used for system prompts and other content blocks that may contain nested structures.
     * Recursively counts all text content and returns a single placeholder.
     *
     * @param array $content The content array to sanitize
     * @return string Placeholder indicating omitted content with char count
     */
    private function sanitizeContentArray(array $content): string
    {
        $totalChars = 0;

        // Recursively count all text in the content structure
        $totalChars = $this->countContentChars($content);

        return sprintf('[CONTENT_OMITTED: %d chars]', $totalChars);
    }

    /**
     * Recursively count total characters in a content structure.
     *
     * @param mixed $data The data to count
     * @return int Total character count
     */
    private function countContentChars(mixed $data): int
    {
        $total = 0;

        if (is_string($data)) {
            return strlen($data);
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                $total += $this->countContentChars($value);
            }
        }

        return $total;
    }
}
