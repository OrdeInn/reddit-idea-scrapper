<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ClassifyPostsChunkJob;
use App\Models\Classification;
use App\Models\ClassificationResult;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\LLM\DTOs\ClassificationResponse;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

// Use sync concurrency driver so Mockery works in tests

class ClassifyPostsChunkJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use sync concurrency driver so Mockery mocks work correctly in tests
        // (the default process/fork driver runs in separate processes and can't see mocks)
        config(['concurrency.default' => 'sync']);
    }

    public function test_store_classification_creates_classification_and_results(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'test']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        // Set up config for two providers
        config([
            'llm.classification.providers' => ['test-provider-1', 'test-provider-2'],
            'llm.classification.consensus_threshold_keep' => 0.6,
            'llm.classification.consensus_threshold_discard' => 0.4,
            'llm.classification.shortcut_confidence' => 0.8,
        ]);

        $keepResponse = new ClassificationResponse(
            verdict: 'keep',
            confidence: 0.9,
            category: Classification::CATEGORY_GENUINE_PROBLEM,
            reasoning: 'Great idea',
            details: ['points' => ['total' => 8]],
            rawResponse: [],
        );

        $provider1 = Mockery::mock(LLMProviderInterface::class);
        $provider1->shouldReceive('classify')->andReturn($keepResponse);
        $provider1->shouldReceive('supportsClassification')->andReturn(true);
        $provider1->shouldReceive('getModelName')->andReturn('test-model-1');

        $provider2 = Mockery::mock(LLMProviderInterface::class);
        $provider2->shouldReceive('classify')->andReturn($keepResponse);
        $provider2->shouldReceive('supportsClassification')->andReturn(true);
        $provider2->shouldReceive('getModelName')->andReturn('test-model-2');

        $factory = Mockery::mock(LLMProviderFactory::class);
        $factory->shouldReceive('classificationProviders')->andReturn([
            'test-provider-1' => $provider1,
            'test-provider-2' => $provider2,
        ]);

        $job = new ClassifyPostsChunkJob($scan->id, [$post->id]);
        $job->handle($factory);

        // Classification should have been created
        $this->assertDatabaseHas('classifications', [
            'post_id' => $post->id,
            'expected_provider_count' => 2,
        ]);

        // Two ClassificationResult rows should exist
        $classification = Classification::where('post_id', $post->id)->first();
        $this->assertNotNull($classification);
        $this->assertCount(2, $classification->results);

        $this->assertDatabaseHas('classification_results', [
            'classification_id' => $classification->id,
            'provider_name' => 'test-provider-1',
            'verdict' => 'keep',
            'completed' => true,
        ]);

        $this->assertDatabaseHas('classification_results', [
            'classification_id' => $classification->id,
            'provider_name' => 'test-provider-2',
            'verdict' => 'keep',
            'completed' => true,
        ]);

        $storedResult = ClassificationResult::where('classification_id', $classification->id)
            ->where('provider_name', 'test-provider-1')
            ->first();
        $this->assertSame(8, $storedResult?->details['points']['total']);
    }

    public function test_all_providers_succeed_triggers_process_results(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'test']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        config([
            'llm.classification.providers' => ['test-provider-1', 'test-provider-2'],
            'llm.classification.consensus_threshold_keep' => 0.6,
            'llm.classification.consensus_threshold_discard' => 0.4,
            'llm.classification.shortcut_confidence' => 0.8,
        ]);

        $keepResponse = new ClassificationResponse(
            verdict: 'keep',
            confidence: 0.9,
            category: Classification::CATEGORY_GENUINE_PROBLEM,
            reasoning: 'Great idea',
            details: ['points' => ['total' => 8]],
            rawResponse: [],
        );

        $provider1 = Mockery::mock(LLMProviderInterface::class);
        $provider1->shouldReceive('classify')->andReturn($keepResponse);
        $provider1->shouldReceive('supportsClassification')->andReturn(true);
        $provider1->shouldReceive('getModelName')->andReturn('test-model-1');

        $provider2 = Mockery::mock(LLMProviderInterface::class);
        $provider2->shouldReceive('classify')->andReturn($keepResponse);
        $provider2->shouldReceive('supportsClassification')->andReturn(true);
        $provider2->shouldReceive('getModelName')->andReturn('test-model-2');

        $factory = Mockery::mock(LLMProviderFactory::class);
        $factory->shouldReceive('classificationProviders')->andReturn([
            'test-provider-1' => $provider1,
            'test-provider-2' => $provider2,
        ]);

        $job = new ClassifyPostsChunkJob($scan->id, [$post->id]);
        $job->handle($factory);

        $classification = Classification::where('post_id', $post->id)->first();

        // Both providers agree with high confidence → shortcut should apply
        $this->assertNotNull($classification->classified_at);
        $this->assertEquals(Classification::DECISION_KEEP, $classification->final_decision);
    }

    public function test_idempotency_skips_already_classified(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'test']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        // Pre-create a completed classification
        Classification::factory()->keep()->create(['post_id' => $post->id]);

        config([
            'llm.classification.providers' => ['test-provider-1', 'test-provider-2'],
        ]);

        $factory = Mockery::mock(LLMProviderFactory::class);
        // classificationProviders should NOT be called if the post is already classified
        $factory->shouldReceive('classificationProviders')->andReturn([]);

        $job = new ClassifyPostsChunkJob($scan->id, [$post->id]);
        $job->handle($factory);

        // Only one classification should exist (the pre-existing one)
        $this->assertEquals(1, Classification::where('post_id', $post->id)->count());
    }
}
