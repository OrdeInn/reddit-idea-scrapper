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

        $this->assertContains('synthetic-kimi', $providers);
        $this->assertContains('claude-sonnet', $providers);
        $this->assertContains('openai-gpt4-mini', $providers);
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
                'synthetic-kimi' => [
                    'class' => \App\Services\LLM\SyntheticKimiProvider::class,
                    'api_key' => 'test-synthetic-key',
                    'model' => 'hf:moonshotai/Kimi-K2.5',
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                ],
                'openai-gpt4-mini' => [
                    'class' => \App\Services\LLM\OpenAIGPT4MiniProvider::class,
                    'api_key' => 'test-openai-key',
                    'model' => 'gpt-4o-mini',
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                ],
            ],
            'llm.classification.providers' => ['synthetic-kimi', 'openai-gpt4-mini'],
        ]);

        $providers = LLMProviderFactory::getClassificationProviders();

        $this->assertCount(2, $providers);
        $this->assertInstanceOf(\App\Services\LLM\SyntheticKimiProvider::class, $providers[0]);
        $this->assertInstanceOf(\App\Services\LLM\OpenAIGPT4MiniProvider::class, $providers[1]);

        foreach ($providers as $provider) {
            $this->assertInstanceOf(LLMProviderInterface::class, $provider);
            $this->assertTrue($provider->supportsClassification());
        }

        config([
            'llm.providers' => $originalProvidersConfig,
            'llm.classification.providers' => $originalClassificationProviders,
        ]);
    }
}
