<?php

namespace Tests\Feature\Http;

use App\Models\Idea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderMetadataControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_configured_providers(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('providers', $data);
        $this->assertArrayHasKey('classification_providers', $data);
        $this->assertArrayHasKey('extraction_provider', $data);
        $this->assertArrayHasKey('extraction_filter_providers', $data);

        $configKeys = array_column($data['providers'], 'config_key');
        $this->assertContains('anthropic-haiku', $configKeys);
        $this->assertContains('anthropic-sonnet', $configKeys);
        $this->assertContains('anthropic-opus', $configKeys);
        $this->assertContains('openai-gpt5-mini', $configKeys);
        $this->assertContains('openai-gpt5-2', $configKeys);
        $this->assertCount(5, $data['providers']);
    }

    public function test_each_provider_has_required_fields(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $providers = $response->json('providers');

        foreach ($providers as $provider) {
            $this->assertArrayHasKey('config_key', $provider);
            $this->assertArrayHasKey('display_name', $provider);
            $this->assertArrayHasKey('model', $provider);
            $this->assertArrayHasKey('vendor', $provider);
            $this->assertArrayHasKey('color', $provider);
            $this->assertArrayHasKey('capabilities', $provider);
        }
    }

    public function test_provider_metadata_has_correct_display_names(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $providers = collect($response->json('providers'))->keyBy('config_key');

        $this->assertEquals('Claude Haiku 4.5', $providers['anthropic-haiku']['display_name']);
        $this->assertEquals('Claude Sonnet 4.5', $providers['anthropic-sonnet']['display_name']);
        $this->assertEquals('Claude Opus 4.6', $providers['anthropic-opus']['display_name']);
        $this->assertEquals('GPT-5 Mini', $providers['openai-gpt5-mini']['display_name']);
        $this->assertEquals('GPT-5.2', $providers['openai-gpt5-2']['display_name']);
    }

    public function test_classification_providers_matches_config(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();

        $classificationProviders = $response->json('classification_providers');
        $configuredProviders = config('llm.classification.providers', []);

        $this->assertEquals($configuredProviders, $classificationProviders);
    }

    public function test_extraction_provider_matches_config(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();

        $extractionProvider = $response->json('extraction_provider');
        $configuredProvider = config('llm.extraction.provider');

        $this->assertEquals($configuredProvider, $extractionProvider);
    }

    public function test_providers_have_correct_colors(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $providers = collect($response->json('providers'))->keyBy('config_key');

        $this->assertEquals('amber', $providers['anthropic-sonnet']['color']);
        $this->assertEquals('purple', $providers['anthropic-haiku']['color']);
        $this->assertEquals('red', $providers['anthropic-opus']['color']);
        $this->assertEquals('emerald', $providers['openai-gpt5-mini']['color']);
        $this->assertEquals('green', $providers['openai-gpt5-2']['color']);
    }

    public function test_extraction_filter_providers_includes_extraction_capable_providers(): void
    {
        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $keys = array_column($response->json('extraction_filter_providers'), 'config_key');

        // Extraction-capable providers (from config) must be included
        $this->assertContains('anthropic-sonnet', $keys);
        $this->assertContains('anthropic-opus', $keys);
        $this->assertContains('openai-gpt5-2', $keys);
    }

    public function test_extraction_filter_providers_includes_historical_ideas_providers(): void
    {
        // Simulate a historical idea that used openai-gpt5-mini as extraction provider
        // (which does not have extraction capability in current config)
        Idea::factory()->create(['extraction_provider' => 'openai-gpt5-mini']);

        $response = $this->getJson('/api/provider-metadata');

        $response->assertOk();
        $keys = array_column($response->json('extraction_filter_providers'), 'config_key');

        $this->assertContains('openai-gpt5-mini', $keys);
    }
}
