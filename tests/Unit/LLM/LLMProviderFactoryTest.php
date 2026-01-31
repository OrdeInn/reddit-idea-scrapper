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
        // Note: This will fail until provider classes are implemented in BE-06, BE-07
        $this->markTestSkipped('Requires provider implementations');

        $providers = LLMProviderFactory::getClassificationProviders();

        $this->assertCount(2, $providers);
        foreach ($providers as $provider) {
            $this->assertInstanceOf(LLMProviderInterface::class, $provider);
        }
    }
}
