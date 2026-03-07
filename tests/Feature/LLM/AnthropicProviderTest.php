<?php

namespace Tests\Feature\LLM;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Services\LLM\AnthropicProvider;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    private array $haikuConfig;
    private array $sonnetConfig;
    private array $opusConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->haikuConfig = [
            'api_key' => 'test-api-key',
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'temperature' => 0.3,
            'provider_name' => 'anthropic-haiku',
            'capabilities' => ['classification'],
        ];

        $this->sonnetConfig = [
            'api_key' => 'test-api-key',
            'model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'provider_name' => 'anthropic-sonnet',
            'capabilities' => ['classification', 'extraction'],
        ];

        $this->opusConfig = [
            'api_key' => 'test-api-key',
            'model' => 'claude-opus-4-6',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'provider_name' => 'anthropic-opus',
            'capabilities' => ['extraction'],
        ];

        Http::preventStrayRequests();
    }

    // =========================================================================
    // Classification Tests
    // =========================================================================

    public function test_classify_returns_valid_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test_haiku',
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

        $provider = new AnthropicProvider($this->haikuConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.87, $response->confidence);
        $this->assertEquals('genuine-problem', $response->category);
    }

    public function test_classify_sends_correct_headers(): void
    {
        Http::fake(function ($request) {
            $this->assertEquals('test-api-key', $request->header('x-api-key')[0]);
            $this->assertEquals('2023-06-01', $request->header('anthropic-version')[0]);
            $this->assertEquals('application/json', $request->header('Content-Type')[0]);

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['verdict' => 'skip', 'confidence' => 0.2, 'category' => 'other', 'reasoning' => 'x'])]],
                'stop_reason' => 'end_turn',
            ], 200);
        });

        $provider = new AnthropicProvider($this->haikuConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_classify_sends_correct_model(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertEquals('claude-haiku-4-5-20251001', $body['model']);

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['verdict' => 'skip', 'confidence' => 0.2, 'category' => 'other', 'reasoning' => 'x'])]],
                'stop_reason' => 'end_turn',
            ]);
        });

        $provider = new AnthropicProvider($this->haikuConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_classify_uses_content_blocks_format(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $userMessage = $body['messages'][0];
            $this->assertEquals('user', $userMessage['role']);
            $this->assertIsArray($userMessage['content']);
            $this->assertEquals('text', $userMessage['content'][0]['type']);
            $this->assertIsString($userMessage['content'][0]['text']);

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['verdict' => 'skip', 'confidence' => 0.2, 'category' => 'other', 'reasoning' => 'x'])]],
                'stop_reason' => 'end_turn',
            ]);
        });

        $provider = new AnthropicProvider($this->haikuConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_connection_exception_as_transient(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Failed to connect to Anthropic API');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_rate_limit_as_transient(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
            ], 429),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 429');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_server_error_as_transient(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'api_error', 'message' => 'Internal server error'],
            ], 500),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 500');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_auth_error_as_permanent(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key'],
            ], 401),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned status 401');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_empty_content_as_transient(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_empty',
                'content' => [],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned empty content');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_malformed_json_as_permanent(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'this is not valid json']],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Failed to parse Anthropic API response');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_invalid_json_body_as_permanent(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('not-json', 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Anthropic API returned invalid JSON response');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_refusal_as_skip(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'refusal', 'text' => 'I cannot process this content'],
                ],
                'stop_reason' => 'refusal',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isSkip());
        $this->assertEquals('refusal', $response->category);
        $this->assertEquals(0.0, $response->confidence);
    }

    public function test_handles_content_filter_stop_reason(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [],
                'stop_reason' => 'content_filter',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);

        // Content filter with empty content and content_filter stop_reason
        // The extractRefusalReason detects content_filter in stop_reason → returns skip
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isSkip());
        $this->assertEquals('refusal', $response->category);
    }

    public function test_handles_json_in_markdown_code_blocks(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n" . json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.8,
                            'category' => 'genuine-problem',
                            'reasoning' => 'Valid problem',
                        ]) . "\n```",
                    ],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isKeep());
        $this->assertEquals(0.8, $response->confidence);
    }

    public function test_handles_non_array_content_blocks(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    'not-an-array',
                    null,
                    ['type' => 'text', 'text' => json_encode(['verdict' => 'skip', 'confidence' => 0.2, 'category' => 'other', 'reasoning' => 'x'])],
                ],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new AnthropicProvider($this->haikuConfig);
        // Non-array blocks are skipped; only the valid text block is extracted
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isSkip());
    }

    // =========================================================================
    // Extraction Tests
    // =========================================================================

    public function test_extract_returns_valid_ideas(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            ['idea_title' => 'Test SaaS', 'problem_statement' => 'A real problem', 'overall' => 4],
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertInstanceOf(ExtractionResponse::class, $response);
        $this->assertTrue($response->hasIdeas());
    }

    public function test_extract_returns_empty_for_no_ideas(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '[]']],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_handles_ideas_wrapper(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'ideas' => [
                                ['idea_title' => 'Wrapped Idea', 'problem_statement' => 'Problem', 'overall' => 3],
                            ],
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_extract_handles_single_idea_object(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'idea_title' => 'Single Idea',
                            'problem_statement' => 'A problem',
                            'overall' => 4,
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_extract_handles_connection_exception_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertInstanceOf(ExtractionResponse::class, $response);
        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_throws_on_api_error(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'error',
                'error' => ['message' => 'Server error'],
            ], 500),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API error (500)');

        $provider->extract($this->makeExtractionRequest());
    }

    public function test_extract_handles_empty_content_gracefully(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_handles_truncated_output(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => json_encode([['idea_title' => 'Truncated Idea', 'problem_statement' => 'Problem', 'overall' => 2]])],
                ],
                'stop_reason' => 'max_tokens',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        // Should log warning and attempt parse — not throw
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertInstanceOf(ExtractionResponse::class, $response);
    }

    public function test_extract_includes_raw_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_extract_123',
                'content' => [['type' => 'text', 'text' => '[]']],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $provider = new AnthropicProvider($this->sonnetConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertArrayHasKey('id', $response->rawResponse);
        $this->assertEquals('msg_extract_123', $response->rawResponse['id']);
    }

    // =========================================================================
    // Capability Guard Tests
    // =========================================================================

    public function test_haiku_config_supports_classification(): void
    {
        $provider = new AnthropicProvider($this->haikuConfig);
        $this->assertTrue($provider->supportsClassification());
    }

    public function test_haiku_config_does_not_support_extraction(): void
    {
        $provider = new AnthropicProvider($this->haikuConfig);
        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_sonnet_config_supports_both(): void
    {
        $provider = new AnthropicProvider($this->sonnetConfig);
        $this->assertTrue($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_opus_config_supports_extraction_only(): void
    {
        $provider = new AnthropicProvider($this->opusConfig);
        $this->assertFalse($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_extract_throws_when_not_supported(): void
    {
        $provider = new AnthropicProvider($this->haikuConfig); // classification-only

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support extraction');

        $provider->extract($this->makeExtractionRequest());
    }

    // =========================================================================
    // Config-Driven Identity Tests
    // =========================================================================

    public function test_provider_name_from_config_haiku(): void
    {
        $provider = new AnthropicProvider($this->haikuConfig);
        $this->assertEquals('anthropic-haiku', $provider->getProviderName());
    }

    public function test_provider_name_from_config_sonnet(): void
    {
        $provider = new AnthropicProvider($this->sonnetConfig);
        $this->assertEquals('anthropic-sonnet', $provider->getProviderName());
    }

    public function test_provider_name_from_config_opus(): void
    {
        $provider = new AnthropicProvider($this->opusConfig);
        $this->assertEquals('anthropic-opus', $provider->getProviderName());
    }

    public function test_model_name_returns_configured_model(): void
    {
        $provider = new AnthropicProvider($this->haikuConfig);
        $this->assertEquals('claude-haiku-4-5-20251001', $provider->getModelName());
    }

    public function test_missing_provider_name_throws_exception(): void
    {
        $config = $this->haikuConfig;
        unset($config['provider_name']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_name');

        new AnthropicProvider($config);
    }

    public function test_missing_capabilities_defaults_to_both(): void
    {
        $config = $this->haikuConfig;
        unset($config['capabilities']);

        $provider = new AnthropicProvider($config);

        $this->assertTrue($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeClassificationRequest(): ClassificationRequest
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

    private function makeExtractionRequest(): ExtractionRequest
    {
        return new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test post for extraction',
            postBody: 'Test body content',
            comments: [],
            upvotes: 15,
            numComments: 3,
            classificationStatus: 'keep',
        );
    }
}
