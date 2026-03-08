<?php

namespace Tests\Feature\LLM;

use App\Exceptions\PermanentClassificationException;
use App\Exceptions\TransientClassificationException;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use App\Services\LLM\OpenAIProvider;
use App\Services\LLM\Prompts\ExtractionSystemPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    private array $baseConfig;
    private array $extractionConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'api_key' => 'test-api-key',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 1024,
            'temperature' => 0.3,
            'config_key' => 'openai-gpt5-mini',
            'display_name' => 'GPT-5 Mini',
            'vendor' => 'openai',
            'color' => 'emerald',
            'capabilities' => ['classification'],
        ];

        $this->extractionConfig = [
            'api_key' => 'test-api-key',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'config_key' => 'openai-gpt5-2',
            'display_name' => 'GPT-5.2',
            'vendor' => 'openai',
            'color' => 'green',
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
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-4o-mini',
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

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isKeep());
        $this->assertEquals('keep', $response->verdict);
        $this->assertEquals(0.85, $response->confidence);
        $this->assertEquals('genuine-problem', $response->category);
        $this->assertNotEmpty($response->reasoning);
    }

    public function test_handles_malformed_json_gracefully(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'This is not valid JSON at all',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('Failed to parse OpenAI API response');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_content_filter(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => ''],
                        'finish_reason' => 'content_filter',
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isSkip());
        $this->assertEquals('content-filtered', $response->category);
        $this->assertStringContainsString('filtered', $response->reasoning);
    }

    public function test_uses_json_mode(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertArrayHasKey('response_format', $body);
            $this->assertEquals(['type' => 'json_object'], $body['response_format']);

            return Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'Not relevant']),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($this->baseConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_extract_throws_when_not_supported(): void
    {
        $provider = new OpenAIProvider($this->baseConfig); // classification-only config

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support extraction');

        $provider->extract($this->makeExtractionRequest());
    }

    public function test_supports_classification(): void
    {
        $provider = new OpenAIProvider($this->baseConfig);
        $this->assertTrue($provider->supportsClassification());
    }

    public function test_does_not_support_extraction(): void
    {
        $provider = new OpenAIProvider($this->baseConfig);
        $this->assertFalse($provider->supportsExtraction());
    }

    public function test_get_provider_name_from_config(): void
    {
        $provider = new OpenAIProvider($this->baseConfig);
        $this->assertEquals('openai-gpt5-mini', $provider->getProviderName());
    }

    public function test_throws_exception_on_api_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Invalid API key', 'type' => 'authentication_error'],
            ], 401),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('OpenAI API returned status 401');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_uses_correct_authorization_header(): void
    {
        Http::fake(function ($request) {
            $this->assertEquals('Bearer test-api-key', $request->header('Authorization')[0]);

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($this->baseConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_json_in_markdown_code_blocks(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "```json\n" . json_encode([
                                'verdict' => 'keep',
                                'confidence' => 0.75,
                                'category' => 'tool-request',
                                'reasoning' => 'User asking for a specific tool',
                            ]) . "\n```",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertTrue($response->isKeep());
        $this->assertEquals(0.75, $response->confidence);
    }

    public function test_handles_empty_content_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => ''],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('OpenAI API returned empty content');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_get_model_name_returns_configured_model(): void
    {
        $provider = new OpenAIProvider($this->baseConfig);
        $this->assertEquals('gpt-4o-mini', $provider->getModelName());
    }

    public function test_handles_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $provider = new OpenAIProvider($this->baseConfig);

        $this->expectException(TransientClassificationException::class);
        $this->expectExceptionMessage('Failed to connect to OpenAI API');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_handles_invalid_json_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response('This is not valid JSON', 200),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);

        $this->expectException(PermanentClassificationException::class);
        $this->expectExceptionMessage('OpenAI API returned invalid JSON response');

        $provider->classify($this->makeClassificationRequest());
    }

    public function test_gpt5_uses_max_completion_tokens_and_omits_temperature(): void
    {
        $config = array_merge($this->baseConfig, [
            'model' => 'gpt-5-mini-2025-08-07',
        ]);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertArrayHasKey('max_completion_tokens', $body);
            $this->assertArrayNotHasKey('max_tokens', $body);
            $this->assertArrayNotHasKey('temperature', $body);

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($config);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_raw_response_is_included_in_classification_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test-123',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(['verdict' => 'keep', 'confidence' => 0.9, 'category' => 'genuine-problem', 'reasoning' => 'x']),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 100, 'total_tokens' => 600],
            ]),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());

        $this->assertEquals('chatcmpl-test-123', $response->rawResponse['id']);
        $this->assertArrayHasKey('usage', $response->rawResponse);
    }

    public function test_verdict_is_normalized_to_keep_or_skip(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'YES', 'confidence' => 0.8, 'category' => 'tool-request', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());
        $this->assertEquals('skip', $response->verdict);
    }

    public function test_confidence_is_clamped_to_valid_range(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'keep', 'confidence' => 1.5, 'category' => 'genuine-problem', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());
        $this->assertEquals(1.0, $response->confidence);
    }

    public function test_confidence_is_clamped_to_minimum_zero(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'keep', 'confidence' => -0.5, 'category' => 'genuine-problem', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $provider = new OpenAIProvider($this->baseConfig);
        $response = $provider->classify($this->makeClassificationRequest());
        $this->assertEquals(0.0, $response->confidence);
    }

    public function test_request_includes_system_and_user_messages(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertCount(2, $body['messages']);
            $this->assertEquals('system', $body['messages'][0]['role']);
            $this->assertStringContainsString('classification assistant', $body['messages'][0]['content']);
            $this->assertEquals('user', $body['messages'][1]['role']);

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($this->baseConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_request_includes_configured_model(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertEquals('gpt-4o-mini', $body['model']);

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($this->baseConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    public function test_uses_correct_temperature_and_max_tokens(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertEquals(0.3, $body['temperature']);
            $this->assertEquals(1024, $body['max_tokens']);

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => json_encode(['verdict' => 'skip', 'confidence' => 0.3, 'category' => 'other', 'reasoning' => 'x'])],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]);
        });

        $provider = new OpenAIProvider($this->baseConfig);
        $provider->classify($this->makeClassificationRequest());
    }

    // =========================================================================
    // Extraction Tests
    // =========================================================================

    private function makeExtractionOpenAIResponse(array $ideas): array
    {
        return [
            'id' => 'chatcmpl-extract-test',
            'object' => 'chat.completion',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($ideas),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    public function test_extract_returns_valid_ideas(): void
    {
        $ideas = [
            ['idea_title' => 'Test Idea', 'problem_statement' => 'A problem', 'overall' => 3],
        ];

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->makeExtractionOpenAIResponse($ideas)),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertInstanceOf(ExtractionResponse::class, $response);
        $this->assertTrue($response->hasIdeas());
    }

    public function test_extract_returns_empty_for_no_ideas(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($this->makeExtractionOpenAIResponse([])),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_handles_ideas_wrapper(): void
    {
        $wrappedResponse = [
            'id' => 'chatcmpl-extract-test',
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'ideas' => [
                                ['idea_title' => 'Wrapped Idea', 'problem_statement' => 'Problem', 'overall' => 3],
                            ],
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($wrappedResponse),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_extract_handles_single_idea_object(): void
    {
        $singleIdea = [
            'id' => 'chatcmpl-extract-test',
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'idea_title' => 'Single Idea',
                            'problem_statement' => 'A problem',
                            'overall' => 4,
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($singleIdea),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertTrue($response->hasIdeas());
        $this->assertEquals(1, $response->count());
    }

    public function test_extract_handles_connection_exception_gracefully(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertInstanceOf(ExtractionResponse::class, $response);
        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_throws_on_api_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Server error'],
            ], 500),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API error (500)');

        $provider->extract($this->makeExtractionRequest());
    }

    public function test_extract_handles_content_filter(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => ''],
                        'finish_reason' => 'content_filter',
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAIProvider($this->extractionConfig);
        $response = $provider->extract($this->makeExtractionRequest());

        $this->assertFalse($response->hasIdeas());
    }

    public function test_extract_does_not_use_json_object_mode(): void
    {
        // Extraction prompts require a JSON array at top-level; json_object mode would conflict.
        // Instead we rely on the explicit system prompt to guide format.
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertArrayNotHasKey('response_format', $body);

            return Http::response($this->makeExtractionOpenAIResponse([]));
        });

        $provider = new OpenAIProvider($this->extractionConfig);
        $provider->extract($this->makeExtractionRequest());
    }

    public function test_extract_uses_extraction_system_prompt(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $systemMessage = $body['messages'][0] ?? null;
            $this->assertEquals('system', $systemMessage['role']);
            $this->assertStringContainsString('SCORING DEFINITIONS', $systemMessage['content']);

            return Http::response($this->makeExtractionOpenAIResponse([]));
        });

        $provider = new OpenAIProvider($this->extractionConfig);
        $provider->extract($this->makeExtractionRequest());
    }

    public function test_gpt52_extract_uses_max_completion_tokens(): void
    {
        $gpt52Config = array_merge($this->extractionConfig, [
            'model' => 'gpt-5.2-2026-01-24',
        ]);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertArrayHasKey('max_completion_tokens', $body);
            $this->assertArrayNotHasKey('max_tokens', $body);
            $this->assertArrayNotHasKey('temperature', $body);

            return Http::response($this->makeExtractionOpenAIResponse([]));
        });

        $provider = new OpenAIProvider($gpt52Config);
        $provider->extract($this->makeExtractionRequest());
    }

    public function test_gpt52_supports_extraction_only(): void
    {
        $config = [
            'api_key' => 'test-key',
            'model' => 'gpt-5.2-2026-01-24',
            'max_tokens' => 4096,
            'temperature' => 0.5,
            'config_key' => 'openai-gpt5-2',
            'display_name' => 'GPT-5.2',
            'vendor' => 'openai',
            'color' => 'green',
            'capabilities' => ['extraction'],
        ];

        $provider = new OpenAIProvider($config);

        $this->assertFalse($provider->supportsClassification());
        $this->assertTrue($provider->supportsExtraction());
        $this->assertEquals('openai-gpt5-2', $provider->getProviderName());
        $this->assertEquals('gpt-5.2-2026-01-24', $provider->getModelName());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeClassificationRequest(): ClassificationRequest
    {
        return new ClassificationRequest(
            postTitle: 'I need a tool to manage my freelance invoices',
            postBody: 'Currently using spreadsheets and it\'s a mess',
            comments: [],
            upvotes: 42,
            numComments: 5,
            subreddit: 'freelance',
        );
    }

    private function makeExtractionRequest(): ExtractionRequest
    {
        return new ExtractionRequest(
            subreddit: 'test',
            postTitle: 'Test post',
            postBody: 'Test body',
            comments: [],
            upvotes: 10,
            numComments: 2,
            classificationStatus: 'keep',
        );
    }
}
