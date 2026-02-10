<?php

namespace Tests\Unit\LLM;

use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use InvalidArgumentException;
use Tests\TestCase;

class LLMProviderFactoryTest extends TestCase
{
    public function test_can_list_available_providers(): void
    {
        $providers = LLMProviderFactory::availableProviders();

        $this->assertContains('anthropic-sonnet', $providers);
        $this->assertContains('openai-gpt', $providers);
    }

    public function test_throws_exception_for_unknown_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("LLM provider 'unknown-provider' is not configured");

        LLMProviderFactory::make('unknown-provider');
    }

    public function test_get_classification_providers_returns_array(): void
    {
        $originalProvidersConfig = config('llm.providers');
        $originalClassificationProviders = config('llm.classification.providers');

        config([
            'llm.providers' => [
                'anthropic-sonnet' => [
                    'class' => \App\Services\LLM\AnthropicSonnetProvider::class,
                    'api_key' => 'test-anthropic-key',
                    'model' => 'claude-sonnet-4-5-20250929',
                    'max_tokens' => 4096,
                    'temperature' => 0.3,
                ],
                'openai-gpt' => [
                    'class' => \App\Services\LLM\OpenAIProvider::class,
                    'api_key' => 'test-openai-key',
                    'model' => 'gpt-4o-mini',
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                ],
            ],
            'llm.classification.providers' => ['anthropic-sonnet', 'openai-gpt'],
        ]);

        $providers = LLMProviderFactory::getClassificationProviders();

        $this->assertCount(2, $providers);
        $this->assertInstanceOf(\App\Services\LLM\AnthropicSonnetProvider::class, $providers[0]);
        $this->assertInstanceOf(\App\Services\LLM\OpenAIProvider::class, $providers[1]);

        foreach ($providers as $provider) {
            $this->assertInstanceOf(LLMProviderInterface::class, $provider);
            $this->assertTrue($provider->supportsClassification());
        }

        config([
            'llm.providers' => $originalProvidersConfig,
            'llm.classification.providers' => $originalClassificationProviders,
        ]);
    }

    public function test_openai_classifier_uses_gpt_5_mini_model(): void
    {
        $provider = LLMProviderFactory::make('openai-gpt');

        // Verify the model is correctly configured to GPT-5-mini
        $this->assertEquals('gpt-5-mini-2025-08-07', $provider->getModelName());
    }

    public function test_anthropic_haiku_classifier_available(): void
    {
        $provider = LLMProviderFactory::make('anthropic-haiku');

        // Verify the Haiku provider is correctly configured
        $this->assertEquals('anthropic-haiku', $provider->getProviderName());
        $this->assertEquals('claude-haiku-4-5-20251001', $provider->getModelName());
        $this->assertTrue($provider->supportsClassification());
    }
}
