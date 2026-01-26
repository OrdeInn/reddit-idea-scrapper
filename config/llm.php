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
        // Which providers to use for classification (both run in parallel)
        'providers' => ['claude-haiku', 'openai-gpt4-mini'],

        // Consensus score thresholds
        // score = (model1_confidence × model1_keep + model2_confidence × model2_keep) / 2
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
        'claude-haiku' => [
            'class' => App\Services\LLM\ClaudeHaikuProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 1024,
            'temperature' => 0.3,
        ],

        'claude-sonnet' => [
            'class' => App\Services\LLM\ClaudeSonnetProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 4096,
            'temperature' => 0.5,
        ],

        'openai-gpt4-mini' => [
            'class' => App\Services\LLM\OpenAIGPT4MiniProvider::class,
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => 'gpt-4o-mini',
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
        'connect' => 10,        // Connection timeout in seconds
        'request' => 120,       // Request timeout in seconds (extraction can be slow)
    ],
];
