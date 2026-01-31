<?php

namespace Tests\Feature\LLM;

use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\SyntheticKimiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SyntheticKimiProviderTest extends TestCase
{
    private array $baseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'api_key' => 'test-api-key',
            'model' => 'hf:moonshotai/Kimi-K2.5',
            'max_tokens' => 1024,
            'temperature' => 0.3,
        ];

        Http::preventStrayRequests();
    }

    public function test_classify_returns_valid_response(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'keep',
                                'confidence' => 0.85,
                                'category' => 'genuine-problem',
                                'reasoning' => 'This post describes a real pain point that could be solved with a SaaS tool.',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 150,
                    'total_tokens' => 650,
                ],
            ]),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_handles_malformed_json_gracefully(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'This is not valid JSON at all',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 10,
                    'total_tokens' => 510,
                ],
            ]),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
        $this->assertEquals(0.0, $response->confidence);
        $this->assertEquals('parse-error', $response->category);
    }

    public function test_handles_content_filter(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                        ],
                        'finish_reason' => 'content_filter',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 0,
                    'total_tokens' => 500,
                ],
            ]),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
        $this->assertEquals('content-filtered', $response->category);
        $this->assertStringContainsString('filtered', $response->reasoning);
    }

    public function test_uses_json_mode(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify JSON mode is enabled
            $this->assertArrayHasKey('response_format', $body);
            $this->assertEquals(['type' => 'json_object'], $body['response_format']);

            return Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'skip',
                                'confidence' => 0.3,
                                'category' => 'other',
                                'reasoning' => 'Not relevant',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 50,
                    'total_tokens' => 550,
                ],
            ]);
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_extract_throws_exception(): void
    {
        $provider = new SyntheticKimiProvider($this->baseConfig);
        $request = new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            classificationStatus: 'keep',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic Kimi provider does not support extraction');

        $provider->extract($request);
    }

    public function test_supports_classification(): void
    {
        $provider = new SyntheticKimiProvider($this->baseConfig);

        $this->assertTrue($provider->supportsClassification());
    }

    public function test_does_not_support_extraction(): void
    {
        $provider = new SyntheticKimiProvider($this->baseConfig);

        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_uses_correct_model_prefix(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify model includes hf: prefix
            $this->assertStringStartsWith('hf:', $body['model']);
            $this->assertEquals('hf:moonshotai/Kimi-K2.5', $body['model']);

            return Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'skip',
                                'confidence' => 0.3,
                                'category' => 'other',
                                'reasoning' => 'Not relevant',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 50,
                    'total_tokens' => 550,
                ],
            ]);
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_get_provider_name_returns_synthetic(): void
    {
        $provider = new SyntheticKimiProvider($this->baseConfig);

        $this->assertEquals('synthetic', $provider->getProviderName());
        $this->assertNotEquals('openai', $provider->getProviderName());
    }

    public function test_throws_exception_on_api_error(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'authentication_error',
                ],
            ], 401),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
        $request = new ClassificationRequest(
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            subreddit: 'test',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Synthetic API error (401)');

        $provider->classify($request);
    }

    public function test_uses_correct_authorization_header(): void
    {
        Http::fake(function ($request) {
            // Verify Authorization header with Bearer token
            $this->assertTrue($request->hasHeader('Authorization'));
            $this->assertEquals('Bearer test-api-key', $request->header('Authorization')[0]);

            return Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'skip',
                                'confidence' => 0.3,
                                'category' => 'other',
                                'reasoning' => 'Not relevant',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 50,
                    'total_tokens' => 550,
                ],
            ]);
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_handles_json_in_markdown_code_blocks(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "```json\n" . json_encode([
                                'verdict' => 'keep',
                                'confidence' => 0.75,
                                'category' => 'tool-request',
                                'reasoning' => 'User is asking for a specific tool',
                            ]) . "\n```",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 150,
                    'total_tokens' => 650,
                ],
            ]),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_handles_empty_content_response(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 0,
                    'total_tokens' => 500,
                ],
            ]),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
        $this->assertEquals('error', $response->category);
        $this->assertStringContainsString('empty', $response->reasoning);
    }

    public function test_get_model_name_returns_configured_model(): void
    {
        $provider = new SyntheticKimiProvider($this->baseConfig);

        $this->assertEquals('hf:moonshotai/Kimi-K2.5', $provider->getModelName());
    }

    public function test_uses_correct_temperature_and_max_tokens(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify temperature and max_tokens from config
            $this->assertEquals(0.3, $body['temperature']);
            $this->assertEquals(1024, $body['max_tokens']);

            return Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'skip',
                                'confidence' => 0.3,
                                'category' => 'other',
                                'reasoning' => 'Not relevant',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 50,
                    'total_tokens' => 550,
                ],
            ]);
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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

    public function test_request_includes_system_and_user_messages(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // Verify messages structure
            $this->assertArrayHasKey('messages', $body);
            $this->assertCount(2, $body['messages']);

            // Verify system message
            $this->assertEquals('system', $body['messages'][0]['role']);
            $this->assertStringContainsString('classification assistant', $body['messages'][0]['content']);

            // Verify user message contains the prompt
            $this->assertEquals('user', $body['messages'][1]['role']);
            $this->assertStringContainsString('Test post', $body['messages'][1]['content']);

            return Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'hf:moonshotai/Kimi-K2.5',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'verdict' => 'skip',
                                'confidence' => 0.3,
                                'category' => 'other',
                                'reasoning' => 'Not relevant',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 500,
                    'completion_tokens' => 50,
                    'total_tokens' => 550,
                ],
            ]);
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
            'id' => 'chatcmpl-test-123',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'hf:moonshotai/Kimi-K2.5',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'verdict' => 'keep',
                            'confidence' => 0.9,
                            'category' => 'genuine-problem',
                            'reasoning' => 'Clear problem statement',
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 500,
                'completion_tokens' => 100,
                'total_tokens' => 600,
            ],
        ];

        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response($rawApiResponse),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
        $this->assertEquals('chatcmpl-test-123', $response->rawResponse['id']);
        $this->assertArrayHasKey('usage', $response->rawResponse);
    }

    public function test_handles_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
        $this->assertEquals('network-error', $response->category);
        $this->assertEquals('Failed to connect to API', $response->reasoning);
    }

    public function test_handles_invalid_json_response(): void
    {
        Http::fake([
            'https://api.synthetic.new/openai/v1/chat/completions' => Http::response(
                'This is not valid JSON',
                200
            ),
        ]);

        $provider = new SyntheticKimiProvider($this->baseConfig);
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
}
