<?php

namespace Tests\Feature\LLM;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Services\LLM\AnthropicHaikuProvider;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ExtractionRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicHaikuProviderTest extends TestCase
{
    private array $baseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'api_key' => 'test-api-key',
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'temperature' => 0.3,
        ];

        Http::preventStrayRequests();
    }

    public function test_classify_returns_valid_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test_haiku',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-haiku-4-5-20251001',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.87,
                            'category' => 'genuine-problem',
                            'reasoning' => 'Clear pain point with multiple validating comments.',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $response = $provider->classify($this->makeRequest());

        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.87, $response->confidence);
        $this->assertEquals('genuine-problem', $response->category);
    }

    public function test_handles_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Failed to connect to Anthropic API');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_transient_exception_on_rate_limit(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 429');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_transient_exception_on_server_error(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 500),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 500');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_permanent_exception_on_unauthorized(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'authentication_error',
                    'message' => 'Invalid API key',
                ],
            ], 401),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 401');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_transient_exception_on_empty_content(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_empty',
                'content' => [],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned empty content');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_permanent_exception_on_malformed_json(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_malformed',
                'content' => [
                    ['type' => 'text', 'text' => 'this is not valid json'],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Failed to parse Anthropic API response');

        $provider->classify($this->makeRequest());
    }

    public function test_throws_permanent_exception_on_invalid_json_body(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                'not-json',
                200
            ),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned invalid JSON response');

        $provider->classify($this->makeRequest());
    }

    public function test_handles_refusal_as_skip_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_refusal',
                'content' => [
                    ['type' => 'refusal', 'text' => 'I cannot process this content'],
                ],
                'stop_reason' => 'refusal',
            ], 200),
        ]);

        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $response = $provider->classify($this->makeRequest());

        $this->assertTrue($response->isSkip());
        $this->assertEquals('refusal', $response->category);
        $this->assertEquals(0.0, $response->confidence);
    }

    public function test_extract_throws_exception(): void
    {
        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test body',
            comments: [],
            upvotes: 12,
            numComments: 3,
            classificationStatus: 'keep',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic Haiku classification provider does not support extraction');

        $provider->extract($request);
    }

    public function test_supports_classification(): void
    {
        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $this->assertTrue($provider->supportsClassification());
    }

    public function test_does_not_support_extraction(): void
    {
        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_get_provider_name_returns_anthropic_haiku(): void
    {
        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $this->assertEquals('anthropic-haiku', $provider->getProviderName());
    }

    public function test_get_model_name_returns_configured_model(): void
    {
        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $this->assertEquals('claude-haiku-4-5-20251001', $provider->getModelName());
    }

    public function test_uses_correct_api_headers(): void
    {
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('x-api-key'));
            $this->assertEquals('test-api-key', $request->header('x-api-key')[0]);

            $this->assertTrue($request->hasHeader('anthropic-version'));
            $this->assertEquals('2023-06-01', $request->header('anthropic-version')[0]);

            return Http::response([
                'id' => 'msg_headers',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.2,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200);
        });

        $provider = new AnthropicHaikuProvider($this->baseConfig);
        $provider->classify($this->makeRequest());
    }

    private function makeRequest(): ClassificationRequest
    {
        return new ClassificationRequest(
            postTitle: 'I need better automation for client onboarding',
            postBody: 'Current flow uses spreadsheets and manual emails.',
            comments: [],
            upvotes: 25,
            numComments: 4,
            subreddit: 'smallbusiness',
        );
    }
}
