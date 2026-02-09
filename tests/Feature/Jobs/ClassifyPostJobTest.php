<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ClassifyPostJob;
use App\Models\Classification;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\LLM\DTOs\ClassificationRequest;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use App\Services\LLM\OpenAIGPT4MiniProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClassifyPostJobTest extends TestCase
{
    use RefreshDatabase;

    private Scan $scan;
    private Post $post;
    private $mockKimiProvider;
    private $mockGptProvider;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Set sync concurrency driver for all tests
        config(['concurrency.default' => 'sync']);

        $subreddit = Subreddit::factory()->create();
        $this->scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
            'posts_fetched' => 1,
            'posts_classified' => 0,
        ]);

        $this->post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $this->scan->id,
            'title' => 'Test Post Title',
            'body' => 'Test post body content',
            'upvotes' => 100,
            'num_comments' => 5,
        ]);

        // Create some comments for the post
        Comment::factory()->count(3)->create([
            'post_id' => $this->post->id,
            'upvotes' => 10,
            'body' => 'Test comment',
            'author' => 'testuser',
        ]);

        // Create mock providers
        $this->mockKimiProvider = Mockery::mock(LLMProviderInterface::class);
        $this->mockKimiProvider->shouldReceive('getProviderName')->andReturn('synthetic');
        $this->mockKimiProvider->shouldReceive('getModelName')->andReturn('hf:moonshotai/Kimi-K2.5');

        $this->mockGptProvider = Mockery::mock(OpenAIGPT4MiniProvider::class);
        $this->mockGptProvider->shouldReceive('getProviderName')->andReturn('openai');
        $this->mockGptProvider->shouldReceive('getModelName')->andReturn('gpt-5-mini-2025-08-07');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_classifies_post_with_consensus(): void
    {
        // Mock providers to return different verdicts that result in borderline
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.7,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Kimi thinks this is a genuine problem',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.8,
                category: Classification::CATEGORY_TOOL_REQUEST,
                reasoning: 'GPT thinks this is a tool request',
                rawResponse: [],
            ));

        // Mock the factory to return our mock providers
        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // Assert classification was created
        $this->assertDatabaseHas('classifications', [
            'post_id' => $this->post->id,
            'kimi_verdict' => 'keep',
            'kimi_confidence' => 0.7,
            'kimi_category' => Classification::CATEGORY_GENUINE_PROBLEM,
            'gpt_verdict' => 'keep',
            'gpt_confidence' => 0.8,
            'gpt_category' => Classification::CATEGORY_TOOL_REQUEST,
        ]);

        // Reload and check consensus calculation
        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Consensus score: (0.7 * 1 + 0.8 * 1) / 2 = 0.75
        $this->assertEquals(0.75, $classification->combined_score);
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);

        // Check scan progress was updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->posts_classified);
    }

    public function test_applies_shortcut_rule_for_unanimous_keep(): void
    {
        // Both providers agree with high confidence - should shortcut to keep
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.9,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Strong keep from Kimi',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.85,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Strong keep from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Should be keep with max score due to shortcut rule
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);
        $this->assertEquals(1.0, $classification->combined_score);
    }

    public function test_applies_shortcut_rule_for_unanimous_skip(): void
    {
        // Both providers agree to skip with high confidence - should shortcut to discard
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.9,
                category: Classification::CATEGORY_SPAM,
                reasoning: 'Strong skip from Kimi',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.85,
                category: Classification::CATEGORY_SPAM,
                reasoning: 'Strong skip from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Should be discard with min score due to shortcut rule
        $this->assertEquals(Classification::DECISION_DISCARD, $classification->final_decision);
        $this->assertEquals(0.0, $classification->combined_score);
    }

    public function test_retries_on_single_model_transient_failure(): void
    {
        // Kimi throws transient error
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\TransientClassificationException(
                'Temporary API error',
                'synthetic'
            ));

        // GPT succeeds
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.8,
                category: Classification::CATEGORY_TOOL_REQUEST,
                reasoning: 'GPT classification successful',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        // Mock the job to simulate first attempt (not final)
        $job = Mockery::mock(ClassifyPostJob::class, [$this->scan, $this->post])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')->andReturn(1); // First attempt
        $job->tries = 3; // Max attempts

        // Expect RuntimeException to trigger retry
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Classification partially failed with transient error');

        $job->handle(app(LLMProviderFactory::class));

        // Verify partial classification was deleted (for retry)
        $this->assertDatabaseMissing('classifications', [
            'post_id' => $this->post->id,
        ]);
    }

    public function test_uses_fallback_on_final_attempt_with_transient_error(): void
    {
        // Kimi throws transient error
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\TransientClassificationException(
                'Temporary API error',
                'synthetic'
            ));

        // GPT succeeds
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.8,
                category: Classification::CATEGORY_TOOL_REQUEST,
                reasoning: 'GPT classification successful',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        // Mock the job to simulate final attempt
        $job = Mockery::mock(ClassifyPostJob::class, [$this->scan, $this->post])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')->andReturn(3); // Final attempt
        $job->tries = 3;

        // Should NOT throw - should use fallback
        $job->handle(app(LLMProviderFactory::class));

        // Verify classification was created with fallback logic
        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Kimi should have error result
        $this->assertEquals('skip', $classification->kimi_verdict);
        $this->assertFalse($classification->kimi_completed);

        // GPT should have successful result
        $this->assertEquals('keep', $classification->gpt_verdict);
        $this->assertTrue($classification->gpt_completed);

        // Should use fallback (KEEP with 0.8 confidence >= 0.7 threshold)
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);
        $this->assertEquals(0.8, $classification->combined_score);
    }

    public function test_handles_both_models_failing(): void
    {
        // Both providers throw permanent errors (no retry)
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'synthetic'
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'openai'
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Both should have error results
        $this->assertFalse($classification->kimi_completed);
        $this->assertFalse($classification->gpt_completed);

        // Should result in discard when both fail
        $this->assertEquals(Classification::DECISION_DISCARD, $classification->final_decision);
        $this->assertEquals(0.0, $classification->combined_score);

        // Scan progress should still be updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->posts_classified);
    }

    public function test_borderline_classification(): void
    {
        // Results that produce a borderline score (between 0.4 and 0.6)
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.5,
                category: Classification::CATEGORY_ADVICE_THREAD,
                reasoning: 'Weak keep from Kimi',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.5,
                category: Classification::CATEGORY_RANT,
                reasoning: 'Weak skip from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Consensus score: (0.5 * 1 + 0.5 * 0) / 2 = 0.25 -> discard
        $this->assertEquals(0.25, $classification->combined_score);
        $this->assertEquals(Classification::DECISION_DISCARD, $classification->final_decision);
    }

    public function test_borderline_classification_mid_range(): void
    {
        // Results that produce a true borderline score (0.4 <= score < 0.6)
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.9,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Strong keep from Kimi',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.7,
                category: Classification::CATEGORY_RANT,
                reasoning: 'Moderate skip from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Consensus score: (0.9 * 1 + 0.7 * 0) / 2 = 0.45 -> borderline
        $this->assertEquals(0.45, $classification->combined_score);
        $this->assertEquals(Classification::DECISION_BORDERLINE, $classification->final_decision);
    }

    public function test_updates_scan_progress(): void
    {
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.8,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Test',
                rawResponse: [],
            ));

        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.8,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Test',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        // Initial state
        $this->assertEquals(0, $this->scan->posts_classified);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // Progress should be updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->posts_classified);
    }

    public function test_skips_already_classified_post(): void
    {
        // Create existing completed classification
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => Classification::DECISION_KEEP,
            'classified_at' => now(),
        ]);

        // Providers should not be called
        $this->mockKimiProvider->shouldNotReceive('classify');
        $this->mockGptProvider->shouldNotReceive('classify');

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // Should only have one classification record
        $this->assertEquals(1, Classification::where('post_id', $this->post->id)->count());

        // Progress should NOT be updated since we skipped
        $this->scan->refresh();
        $this->assertEquals(0, $this->scan->posts_classified);
    }

    public function test_skips_if_scan_failed(): void
    {
        $this->scan->update(['status' => Scan::STATUS_FAILED]);

        // Providers should not be called
        $this->mockKimiProvider->shouldNotReceive('classify');
        $this->mockGptProvider->shouldNotReceive('classify');

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No classification should be created
        $this->assertDatabaseMissing('classifications', [
            'post_id' => $this->post->id,
        ]);
    }

    public function test_skips_if_scan_completed(): void
    {
        $this->scan->update(['status' => Scan::STATUS_COMPLETED]);

        // Providers should not be called
        $this->mockKimiProvider->shouldNotReceive('classify');
        $this->mockGptProvider->shouldNotReceive('classify');

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No classification should be created
        $this->assertDatabaseMissing('classifications', [
            'post_id' => $this->post->id,
        ]);
    }

    public function test_failed_method_creates_discard_record(): void
    {
        $job = new ClassifyPostJob($this->scan, $this->post);
        $exception = new \RuntimeException('Test exception');
        $job->failed($exception);

        // Should create a discard classification
        $this->assertDatabaseHas('classifications', [
            'post_id' => $this->post->id,
            'final_decision' => Classification::DECISION_DISCARD,
            'kimi_verdict' => 'skip',
            'gpt_verdict' => 'skip',
        ]);

        // Scan progress should be updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->posts_classified);
    }

    public function test_permanent_error_uses_fallback_immediately(): void
    {
        // Kimi throws permanent error (no retry even on first attempt)
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'synthetic'
            ));

        // GPT succeeds
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.9,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'GPT classification successful',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        // First attempt - but permanent error should use fallback immediately
        $job = Mockery::mock(ClassifyPostJob::class, [$this->scan, $this->post])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')->andReturn(1);
        $job->tries = 3;

        // Should NOT throw - should use fallback despite being first attempt
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Should use fallback logic (KEEP with 0.9 confidence >= 0.7 threshold)
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);
        $this->assertEquals(0.9, $classification->combined_score);

        // Scan progress updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->posts_classified);
    }

    public function test_single_model_keep_below_confidence_threshold(): void
    {
        // Kimi throws permanent error (no retry)
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'synthetic'
            ));

        // GPT succeeds with confidence < 0.7
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.6,
                category: Classification::CATEGORY_TOOL_REQUEST,
                reasoning: 'Weak keep from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Single model with confidence < 0.7 should be BORDERLINE
        $this->assertEquals(Classification::DECISION_BORDERLINE, $classification->final_decision);
        $this->assertEquals(0.6, $classification->combined_score);
    }

    public function test_single_model_keep_at_confidence_threshold(): void
    {
        // Kimi throws permanent error
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'synthetic'
            ));

        // GPT succeeds with confidence exactly 0.7
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'keep',
                confidence: 0.7,
                category: Classification::CATEGORY_GENUINE_PROBLEM,
                reasoning: 'Moderate keep from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Single model with confidence >= 0.7 should be KEEP
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);
        $this->assertEquals(0.7, $classification->combined_score);
    }

    public function test_single_model_skip_verdict(): void
    {
        // Kimi throws permanent error
        $this->mockKimiProvider->shouldReceive('classify')
            ->once()
            ->andThrow(new \App\Exceptions\PermanentClassificationException(
                'Invalid API key',
                'synthetic'
            ));

        // GPT succeeds with skip verdict
        $this->mockGptProvider->shouldReceive('classify')
            ->once()
            ->andReturn(new ClassificationResponse(
                verdict: 'skip',
                confidence: 0.9,
                category: Classification::CATEGORY_SPAM,
                reasoning: 'Strong skip from GPT',
                rawResponse: [],
            ));

        $this->mockFactory([$this->mockKimiProvider, $this->mockGptProvider]);

        $job = new ClassifyPostJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $classification = Classification::where('post_id', $this->post->id)->first();
        $this->assertNotNull($classification);

        // Single model with skip verdict should be DISCARD regardless of confidence
        $this->assertEquals(Classification::DECISION_DISCARD, $classification->final_decision);
        $this->assertEquals(0.0, $classification->combined_score);
    }

    /**
     * Mock the LLMProviderFactory to return our mock providers.
     *
     * @param array $providers
     */
    private function mockFactory(array $providers): void
    {
        $this->mock(LLMProviderFactory::class, function ($mock) use ($providers) {
            $mock->shouldReceive('classificationProviders')->andReturn($providers);
        });
    }
}
