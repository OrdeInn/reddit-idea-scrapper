<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Classification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the dual-gate classification pipeline that filters
    | posts before expensive extraction.
    |
    | Available classification providers (require haiku_x/gpt_x DB columns):
    |   - anthropic-haiku (maps to haiku_x columns)
    |   - openai-gpt5-mini      (maps to gpt_x columns)
    |
    */

    'classification' => [
        // Which providers to use for classification (all run in parallel)
        'providers' => ['anthropic-haiku', 'openai-gpt5-mini'],

        // Consensus score thresholds
        // score = average(confidence × keep_flag) across configured providers
        'consensus_threshold_keep' => 0.6,      // >= this = KEEP
        'consensus_threshold_discard' => 0.4,   // < this = DISCARD, between = BORDERLINE

        // Shortcut rules for high-confidence agreement
        'shortcut_confidence' => 0.8,           // Both agree with this confidence = skip consensus calc

        // Number of posts per classification chunk worker job (recommended range: 5-25)
        'batch_chunk_size' => (int) env('LLM_CLASSIFICATION_CHUNK_SIZE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the idea extraction pipeline.
    |
    | Available extraction providers:
    |   - anthropic-sonnet (default, proven)
    |   - anthropic-opus   (higher quality, higher cost)
    |   - openai-gpt5-2     (GPT 5.2 alternative)
    |
    | NOTE: anthropic-opus and openai-gpt5-2 lack DB column mapping and cannot
    | be used as classification providers without a future migration + job update.
    |
    */

    'extraction' => [
        // Which provider to use for extraction
        'provider' => 'anthropic-sonnet',

        // Maximum ideas to extract per post
        'max_ideas_per_post' => 5,

        // Number of posts per extraction chunk worker job (recommended range: 5-25)
        'batch_chunk_size' => (int) env('LLM_EXTRACTION_CHUNK_SIZE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Settings for each LLM provider including API keys, model names, and
    | request parameters.
    |
    | Each entry must have:
    |   - class: The provider class (AnthropicProvider or OpenAIProvider)
    |   - provider_name: Value returned by getProviderName() — used for DB column mapping
    |   - capabilities: Array of supported operations ('classification', 'extraction')
    |
    */

    'providers' => [
        'anthropic-sonnet' => [
            'class' => App\Services\LLM\AnthropicProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'provider_name' => 'anthropic-sonnet',
            'capabilities' => ['classification', 'extraction'],
        ],

        'anthropic-haiku' => [
            'class' => App\Services\LLM\AnthropicProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'temperature' => 0.3,
            'provider_name' => 'anthropic-haiku',
            'capabilities' => ['classification'],
        ],

        'anthropic-opus' => [
            'class' => App\Services\LLM\AnthropicProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-opus-4-6',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'provider_name' => 'anthropic-opus',
            'capabilities' => ['extraction'],
        ],

        'openai-gpt5-mini' => [
            'class' => App\Services\LLM\OpenAIProvider::class,
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => 'gpt-5-mini-2025-08-07',
            'max_tokens' => 1024,
            'temperature' => 0.3,
            'provider_name' => 'openai',
            'capabilities' => ['classification'],
        ],

        'openai-gpt5-2' => [
            'class' => App\Services\LLM\OpenAIProvider::class,
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => 'gpt-5.2-2026-01-24',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'provider_name' => 'openai-gpt5-2',
            'capabilities' => ['extraction'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeouts
    |--------------------------------------------------------------------------
    |
    | HTTP timeout settings for LLM API calls.
    |
    */

    'timeouts' => [
        'connect' => 30,        // Connection timeout in seconds
        'request' => 120,       // Request timeout in seconds (extraction can be slow)
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Settings
    |--------------------------------------------------------------------------
    |
    | Conservative HTTP retry defaults for transient provider/network failures.
    | Snippets in logs are disabled by default outside local/testing.
    |
    */

    'retry' => [
        'max_attempts' => 3,          // Total attempts (initial + retries)
        'base_delay_ms' => 250,       // Exponential backoff base delay
        'max_delay_ms' => 15000,      // Cap backoff (and Retry-After) to this
        'jitter_ms' => 100,           // Random +/- jitter to avoid thundering herd
        'honor_retry_after' => true,  // If 429 includes Retry-After, respect it (within max_delay_ms)
    ],
];
