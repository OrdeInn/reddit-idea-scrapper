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
    */

    'classification' => [
        // Which providers to use for classification (all run in parallel)
        'providers' => ['anthropic-haiku', 'openai-gpt4-mini'],

        // Consensus score thresholds
        // score = average(confidence × keep_flag) across configured providers
        'consensus_threshold_keep' => 0.6,      // >= this = KEEP
        'consensus_threshold_discard' => 0.4,   // < this = DISCARD, between = BORDERLINE

        // Shortcut rules for high-confidence agreement
        'shortcut_confidence' => 0.8,           // Both agree with this confidence = skip consensus calc
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the idea extraction pipeline.
    |
    */

    'extraction' => [
        // Which provider to use for extraction
        'provider' => 'claude-sonnet',

        // Maximum ideas to extract per post
        'max_ideas_per_post' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Settings for each LLM provider including API keys, model names, and
    | request parameters.
    |
    */

    'providers' => [
        'anthropic-haiku' => [
            'class' => App\Services\LLM\AnthropicHaikuProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 1024,
            'temperature' => 0.3,
        ],

        'claude-sonnet' => [
            'class' => App\Services\LLM\AnthropicSonnetProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 4096,
            'temperature' => 0.5,
        ],

        'openai-gpt4-mini' => [
            'class' => App\Services\LLM\OpenAIProvider::class,
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => 'gpt-5-mini-2025-08-07',
            'max_tokens' => 1024,
            'temperature' => 0.3,
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
