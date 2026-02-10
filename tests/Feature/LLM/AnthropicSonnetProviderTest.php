<?php

namespace Tests\Feature\LLM;

use App\Services\LLM\AnthropicSonnetProvider;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ExtractionRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AnthropicSonnetProviderTest extends TestCase
{
    private array $baseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'api_key' => 'test-api-key',
            'model' => 'claude-sonnet-4-5-20250929',
            'max_tokens' => 4096,
            'temperature' => 0.5,
        ];

        Http::preventStrayRequests();
    }

    public function test_extract_returns_valid_ideas(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01Test123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            [
                                'idea_title' => 'MetricsDash',
                                'problem_statement' => 'Developers struggle to track SaaS metrics',
                                'proposed_solution' => 'Simple dashboard for indie hackers',
                                'target_audience' => 'Solo SaaS founders',
                                'why_small_team_viable' => 'Limited scope, clear market',
                                'demand_evidence' => 'Multiple commenters asking for this',
                                'monetization_model' => '$10/month subscription',
                                'branding_suggestions' => [
                                    'name_ideas' => ['MetricsDash', 'SaaSPulse', 'IndieMetrics'],
                                    'positioning' => 'The simplest SaaS metrics dashboard',
                                    'tagline' => 'Know your numbers in seconds',
                                ],
                                'marketing_channels' => ['Twitter', 'Indie Hackers', 'Reddit'],
                                'existing_competitors' => ['Baremetrics', 'ChartMogul'],
                                'scores' => [
                                    'monetization' => 4,
                                    'monetization_reasoning' => 'Clear subscription model',
                                    'market_saturation' => 3,
                                    'saturation_reasoning' => 'Some competitors but room for simpler option',
                                    'complexity' => 4,
                                    'complexity_reasoning' => 'Basic dashboard, API integrations',
                                    'demand_evidence' => 5,
                                    'demand_reasoning' => 'Multiple people asking for this',
                                    'overall' => 4,
                                    'overall_reasoning' => 'Good opportunity for solo dev',
                                ],
                                'source_quote' => 'I just want something simple to track MRR',
                            ],
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 1500,
                    'output_tokens' => 800,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'SaaS',
            postTitle: 'Need a simple metrics dashboard',
            postBody: 'All the tools are too complex...',
            comments: [
                ['author' => 'user1', 'body' => 'Same here, need something simple', 'upvotes' => 15],
            ],
            upvotes: 50,
            numComments: 20,
            classificationStatus: 'keep',
        );

        $response = $provider->extract($request);

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());

        $idea = $response->ideas->first();
        $this->assertEquals('MetricsDash', $idea->ideaTitle);
        $this->assertEquals(4, $idea->scores['overall']);
    }

    public function test_extract_returns_empty_when_no_ideas(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01Test456',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    ['type' => 'text', 'text' => '[]'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 10,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Random discussion',
            postBody: 'Just chatting...',
            comments: [],
            upvotes: 5,
            numComments: 2,
            classificationStatus: 'borderline',
        );

        $response = $provider->extract($request);

        $this->assertFalse($response->hasIdeas());
        $this->assertEquals(0, $response->count());
    }

    public function test_handles_single_idea_object(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01Test789',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'idea_title' => 'SingleIdea',
                            'problem_statement' => 'A problem',
                            'proposed_solution' => 'A solution',
                            'target_audience' => 'Someone',
                            'why_small_team_viable' => 'Because',
                            'demand_evidence' => 'Evidence',
                            'monetization_model' => 'Money',
                            'branding_suggestions' => ['name_ideas' => [], 'positioning' => '', 'tagline' => ''],
                            'marketing_channels' => [],
                            'existing_competitors' => [],
                            'scores' => ['overall' => 3],
                            'source_quote' => 'Quote',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 800,
                    'output_tokens' => 400,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $response = $provider->extract($request);

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_handles_ideas_key_in_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestABC',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'ideas' => [
                                [
                                    'idea_title' => 'NestedIdea',
                                    'problem_statement' => 'A problem',
                                    'proposed_solution' => 'A solution',
                                    'target_audience' => 'Someone',
                                    'why_small_team_viable' => 'Because',
                                    'demand_evidence' => 'Evidence',
                                    'monetization_model' => 'Money',
                                    'branding_suggestions' => ['name_ideas' => [], 'positioning' => '', 'tagline' => ''],
                                    'marketing_channels' => [],
                                    'existing_competitors' => [],
                                    'scores' => ['overall' => 3],
                                    'source_quote' => 'Quote',
                                ],
                            ],
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 800,
                    'output_tokens' => 400,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $response = $provider->extract($request);

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_handles_non_array_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestDEF',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    ['type' => 'text', 'text' => '"This is just a string"'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 20,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $response = $provider->extract($request);

        $this->assertFalse($response->hasIdeas());
        $this->assertEquals(0, $response->count());
    }

    public function test_classify_returns_valid_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestGHI',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.85,
                            'category' => 'genuine-problem',
                            'reasoning' => 'This post describes a real pain point that could be solved with a SaaS tool.',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 150,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'I need a tool to manage my freelance invoices',
            postBody: 'Currently using spreadsheets and it\'s a mess',
            comments: [],
            upvotes: 42,
            numComments: 5,
            subreddit: 'freelance',
        );

        $response = $provider->classify($request);

        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.85, $response->confidence);
        $this->assertEquals('genuine-problem', $response->category);
        $this->assertNotEmpty($response->reasoning);
    }

    public function test_classify_logs_warning(): void
    {
        // Mock Log to accept warning calls and channel() calls
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => $message === 'Using Anthropic Sonnet for classification is not cost-effective')
            ->andReturn();

        Log::shouldReceive('channel')
            ->with('llm')
            ->andReturnSelf()
            ->shouldReceive('info')
            ->andReturn()
            ->shouldReceive('error')
            ->andReturn()
            ->shouldReceive('channel')
            ->andReturnSelf();

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestJKL',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.3,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 400,
                    'output_tokens' => 50,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $provider->classify($request);

        $this->addToAssertionCount(1);
    }

    public function test_handles_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $provider = new AnthropicSonnetProvider($this->baseConfig);

        // Test classification returns safe fallback
        $classifyRequest = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($classifyRequest);

        $this->assertTrue($response->isSkip());
        $this->assertEquals('skip', $response->verdict);
        $this->assertEquals('network-error', $response->category);
        $this->assertEquals('Failed to connect to API', $response->reasoning);

        // Test extraction returns empty response
        $extractRequest = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $extractResponse = $provider->extract($extractRequest);

        $this->assertFalse($extractResponse->hasIdeas());
        $this->assertEquals(0, $extractResponse->count());
    }

    public function test_throws_exception_on_api_error(): void
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

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic Sonnet API error (401)');

        $provider->classify($request);
    }

    public function test_throws_exception_on_api_error_during_extraction(): void
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

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic Sonnet API error (429)');

        $provider->extract($request);
    }

    public function test_handles_empty_content_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestMNO',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 0,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);

        // Test classification
        $classifyRequest = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($classifyRequest);

        $this->assertTrue($response->isSkip());
        $this->assertEquals('skip', $response->verdict);
        $this->assertEquals('error', $response->category);
        $this->assertStringContainsString('empty', $response->reasoning);

        // Test extraction
        $extractRequest = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $extractResponse = $provider->extract($extractRequest);

        $this->assertFalse($extractResponse->hasIdeas());
        $this->assertEquals(0, $extractResponse->count());
    }

    public function test_handles_malformed_json_gracefully(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestPQR',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    ['type' => 'text', 'text' => 'This is not valid JSON at all'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 10,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);

        // Test classification
        $classifyRequest = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($classifyRequest);

        $this->assertTrue($response->isSkip());
        $this->assertEquals('skip', $response->verdict);
        $this->assertEquals(0.0, $response->confidence);
        $this->assertEquals('parse-error', $response->category);

        // Test extraction
        $extractRequest = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $extractResponse = $provider->extract($extractRequest);

        $this->assertFalse($extractResponse->hasIdeas());
        $this->assertEquals(0, $extractResponse->count());
    }

    public function test_supports_classification(): void
    {
        $provider = new AnthropicSonnetProvider($this->baseConfig);

        $this->assertTrue($provider->supportsClassification());
    }

    public function test_supports_extraction(): void
    {
        $provider = new AnthropicSonnetProvider($this->baseConfig);

        $this->assertTrue($provider->supportsExtraction());
    }

    public function test_get_provider_name_returns_anthropic_sonnet(): void
    {
        $provider = new AnthropicSonnetProvider($this->baseConfig);

        $this->assertEquals('anthropic-sonnet', $provider->getProviderName());
    }

    public function test_get_model_name_returns_configured_model(): void
    {
        $provider = new AnthropicSonnetProvider($this->baseConfig);

        $this->assertEquals('claude-sonnet-4-5-20250929', $provider->getModelName());
    }

    public function test_uses_correct_api_headers(): void
    {
        Http::fake(function ($request) {
            // Verify x-api-key header
            $this->assertTrue($request->hasHeader('x-api-key'));
            $this->assertEquals('test-api-key', $request->header('x-api-key')[0]);

            // Verify anthropic-version header
            $this->assertTrue($request->hasHeader('anthropic-version'));
            $this->assertEquals('2023-06-01', $request->header('anthropic-version')[0]);

            // Verify Content-Type header
            $this->assertTrue($request->hasHeader('Content-Type'));
            $this->assertEquals('application/json', $request->header('Content-Type')[0]);

            return Http::response([
                'id' => 'msg_01TestSTU',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.3,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 400,
                    'output_tokens' => 50,
                ],
            ], 200);
        });

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $provider->classify($request);
    }

    public function test_raw_response_is_included_in_classification_response(): void
    {
        $rawApiResponse = [
            'id' => 'msg_01TestVWX',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5-20250929',
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'verdict' => 'keep',
                        'confidence' => 0.9,
                        'category' => 'genuine-problem',
                        'reasoning' => 'Clear problem statement',
                    ]),
                ],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 500,
                'output_tokens' => 100,
            ],
        ];

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response($rawApiResponse, 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($request);

        // Verify raw response is included
        $this->assertArrayHasKey('id', $response->rawResponse);
        $this->assertEquals('msg_01TestVWX', $response->rawResponse['id']);
        $this->assertArrayHasKey('usage', $response->rawResponse);
    }

    public function test_raw_response_is_included_in_extraction_response(): void
    {
        $rawApiResponse = [
            'id' => 'msg_01TestYZA',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5-20250929',
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        [
                            'idea_title' => 'TestIdea',
                            'problem_statement' => 'A problem',
                            'proposed_solution' => 'A solution',
                            'target_audience' => 'Someone',
                            'why_small_team_viable' => 'Because',
                            'demand_evidence' => 'Evidence',
                            'monetization_model' => 'Money',
                            'branding_suggestions' => ['name_ideas' => [], 'positioning' => '', 'tagline' => ''],
                            'marketing_channels' => [],
                            'existing_competitors' => [],
                            'scores' => ['overall' => 3],
                            'source_quote' => 'Quote',
                        ],
                    ]),
                ],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 800,
                'output_tokens' => 400,
            ],
        ];

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response($rawApiResponse, 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $response = $provider->extract($request);

        // Verify raw response is included
        $this->assertArrayHasKey('id', $response->rawResponse);
        $this->assertEquals('msg_01TestYZA', $response->rawResponse['id']);
        $this->assertArrayHasKey('usage', $response->rawResponse);
    }

    public function test_handles_invalid_json_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                'This is not valid JSON',
                200
            ),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($request);

        $this->assertTrue($response->isSkip());
        $this->assertEquals('skip', $response->verdict);
        $this->assertEquals('invalid-response', $response->category);
        $this->assertEquals('API returned invalid response format', $response->reasoning);
    }

    public function test_uses_correct_model(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify model from config
            $this->assertEquals('claude-sonnet-4-5-20250929', $body['model']);

            return Http::response([
                'id' => 'msg_01TestBCD',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.3,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 400,
                    'output_tokens' => 50,
                ],
            ], 200);
        });

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $provider->classify($request);
    }

    public function test_uses_correct_temperature_and_max_tokens(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify temperature and max_tokens from config
            $this->assertEquals(0.5, $body['temperature']);
            $this->assertEquals(4096, $body['max_tokens']);

            return Http::response([
                'id' => 'msg_01TestEFG',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.3,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 400,
                    'output_tokens' => 50,
                ],
            ], 200);
        });

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test',
            postBody: 'Test',
            comments: [],
            upvotes: 10,
            numComments: 5,
            classificationStatus: 'keep',
        );

        $provider->extract($request);
    }

    public function test_handles_json_in_markdown_code_blocks(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestHIJ',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n" . json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.75,
                            'category' => 'tool-request',
                            'reasoning' => 'User is asking for a specific tool',
                        ]) . "\n```",
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 150,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Looking for a tool that does X',
            postBody: 'I need something that can help me with this specific task',
            comments: [],
            upvotes: 25,
            numComments: 8,
            subreddit: 'startups',
        );

        $response = $provider->classify($request);

        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.75, $response->confidence);
        $this->assertEquals('tool-request', $response->category);
    }

    public function test_request_uses_content_blocks_format(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify content is in blocks format
            $this->assertIsArray($body['messages'][0]['content']);
            $this->assertEquals('text', $body['messages'][0]['content'][0]['type']);
            $this->assertStringContainsString('Test post', $body['messages'][0]['content'][0]['text']);

            return Http::response([
                'id' => 'msg_01TestKLM',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'skip',
                            'confidence' => 0.3,
                            'category' => 'other',
                            'reasoning' => 'Not relevant',
                        ]),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 400,
                    'output_tokens' => 50,
                ],
            ], 200);
        });

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $provider->classify($request);
    }

    public function test_handles_non_array_blocks_gracefully(): void
    {
        // Test that non-array blocks are skipped without errors
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_01TestNOP',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    'string block', // Invalid block (not an array)
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.8,
                            'category' => 'genuine-problem',
                            'reasoning' => 'Valid block after invalid ones',
                        ]),
                    ],
                    null, // Another invalid block
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 500,
                    'output_tokens' => 150,
                ],
            ], 200),
        ]);

        $provider = new AnthropicSonnetProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $response = $provider->classify($request);

        // Should skip invalid blocks and parse the valid one
        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.8, $response->confidence);
    }
}
