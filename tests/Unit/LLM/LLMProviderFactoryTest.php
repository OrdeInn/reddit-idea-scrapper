<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\AnthropicProvider;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use App\Services\LLM\OpenAIProvider;
use InvalidArgumentException;
use Tests\TestCase;

class LLMProviderFactoryTest extends TestCase
{
    public function test_can_list_available_providers(): void
    {
        $providers = LLMProviderFactory::availableProviders();

        $this->assertContains('anthropic-haiku', $providers);
        $this->assertContains('anthropic-sonnet', $providers);
        $this->assertContains('anthropic-opus', $providers);
        $this->assertContains('openai-gpt5-mini', $providers);
        $this->assertContains('openai-gpt5-2', $providers);
        $this->assertCount(5, $providers);
    }

    public function test_throws_exception_for_unknown_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("LLM provider 'unknown-provider' is not configured");

        LLMProviderFactory::make('unknown-provider');
    }

    public function test_make_anthropic_haiku_returns_anthropic_provider(): void
    {
        $provider = LLMProviderFactory::make('anthropic-haiku');

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertEquals('anthropic-haiku', $provider->getProviderName());
        $this->assertTrue($provider->supportsClassification());
        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_make_anthropic_sonnet_returns_anthropic_provider(): void
    {
        $provider = LLMProviderFactory::make('anthropic-sonnet');

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertEquals('anthropic-sonnet', $provider->getProviderName());
        $this->assertTrue($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_make_anthropic_opus_returns_anthropic_provider(): void
    {
        $provider = LLMProviderFactory::make('anthropic-opus');

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertEquals('anthropic-opus', $provider->getProviderName());
        $this->assertFalse($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_make_openai_gpt_returns_openai_provider(): void
    {
        $provider = LLMProviderFactory::make('openai-gpt5-mini');

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertEquals('openai', $provider->getProviderName()); // Must remain 'openai' for DB column mapping
        $this->assertTrue($provider->supportsClassification());
        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_make_openai_gpt52_returns_openai_provider(): void
    {
        $provider = LLMProviderFactory::make('openai-gpt5-2');

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
        $this->assertEquals('openai-gpt5-2', $provider->getProviderName());
        $this->assertFalse($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_openai_classifier_uses_gpt_5_mini_model(): void
    {
        $provider = LLMProviderFactory::make('openai-gpt5-mini');

        $this->assertEquals('gpt-5-mini-2025-08-07', $provider->getModelName());
    }

    public function test_anthropic_haiku_model_is_correct(): void
    {
        $provider = LLMProviderFactory::make('anthropic-haiku');

        $this->assertEquals('claude-haiku-4-5-20251001', $provider->getModelName());
    }

    public function test_anthropic_opus_model_is_correct(): void
    {
        $provider = LLMProviderFactory::make('anthropic-opus');

        $this->assertEquals('claude-opus-4-6', $provider->getModelName());
    }

    public function test_openai_gpt52_model_is_correct(): void
    {
        $provider = LLMProviderFactory::make('openai-gpt5-2');

        $this->assertEquals('gpt-5.2-2026-01-24', $provider->getModelName());
    }

    public function test_classification_providers_returns_array(): void
    {
        $providers = LLMProviderFactory::getClassificationProviders();

        $this->assertIsArray($providers);
        $this->assertCount(2, $providers);

        // Verify the array is keyed by config key
        $this->assertArrayHasKey('anthropic-haiku', $providers);
        $this->assertArrayHasKey('openai-gpt5-mini', $providers);

        foreach ($providers as $configKey => $provider) {
            $this->assertIsString($configKey);
            $this->assertInstanceOf(LLMProviderInterface::class, $provider);
            $this->assertTrue($provider->supportsClassification());
        }
    }

    public function test_extraction_provider_supports_extraction(): void
    {
        $provider = LLMProviderFactory::getExtractionProvider();

        $this->assertInstanceOf(LLMProviderInterface::class, $provider);
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_extraction_provider_rejects_non_extraction_provider(): void
    {
        $originalProvider = config('llm.extraction.provider');

        config(['llm.extraction.provider' => 'anthropic-haiku']); // classification-only

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support extraction');

        try {
            LLMProviderFactory::getExtractionProvider();
        } finally {
            config(['llm.extraction.provider' => $originalProvider]);
        }
    }

    public function test_classification_providers_rejects_non_classification_provider(): void
    {
        $originalProviders = config('llm.classification.providers');
        $originalConfig = config('llm.providers');

        config([
            'llm.classification.providers' => ['anthropic-opus'], // extraction-only
            'llm.providers' => array_merge($originalConfig, [
                'anthropic-opus' => [
                    'class' => AnthropicProvider::class,
                    'api_key' => 'test-key',
                    'model' => 'claude-opus-4-6',
                    'max_tokens' => 4096,
                    'temperature' => 0.5,
                    'provider_name' => 'anthropic-opus',
                    'capabilities' => ['extraction'],
                ],
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support classification');

        try {
            LLMProviderFactory::getClassificationProviders();
        } finally {
            config([
                'llm.classification.providers' => $originalProviders,
                'llm.providers' => $originalConfig,
            ]);
        }
    }

    public function test_get_classification_providers_returns_array(): void
    {
        $originalProvidersConfig = config('llm.providers');
        $originalClassificationProviders = config('llm.classification.providers');

        config([
            'llm.providers' => [
                'anthropic-haiku' => [
                    'class' => AnthropicProvider::class,
                    'api_key' => 'test-anthropic-key',
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                    'provider_name' => 'anthropic-haiku',
                    'capabilities' => ['classification'],
                ],
                'openai-gpt5-mini' => [
                    'class' => OpenAIProvider::class,
                    'api_key' => 'test-openai-key',
                    'model' => 'gpt-4o-mini',
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                    'provider_name' => 'openai',
                    'capabilities' => ['classification'],
                ],
            ],
            'llm.classification.providers' => ['anthropic-haiku', 'openai-gpt5-mini'],
        ]);

        $providers = LLMProviderFactory::getClassificationProviders();

        $this->assertCount(2, $providers);

        // Verify keyed by config key
        $this->assertArrayHasKey('anthropic-haiku', $providers);
        $this->assertArrayHasKey('openai-gpt5-mini', $providers);
        $this->assertInstanceOf(AnthropicProvider::class, $providers['anthropic-haiku']);
        $this->assertInstanceOf(OpenAIProvider::class, $providers['openai-gpt5-mini']);

        foreach ($providers as $configKey => $provider) {
            $this->assertIsString($configKey);
            $this->assertInstanceOf(LLMProviderInterface::class, $provider);
            $this->assertTrue($provider->supportsClassification());
        }

        config([
            'llm.providers' => $originalProvidersConfig,
            'llm.classification.providers' => $originalClassificationProviders,
        ]);
    }
}
