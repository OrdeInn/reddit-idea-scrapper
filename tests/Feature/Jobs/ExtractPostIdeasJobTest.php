<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ExtractPostIdeasJob;
use App\Models\Classification;
use App\Models\Comment;
use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\LLM\ClaudeSonnetProvider;
use App\Services\LLM\DTOs\ExtractionResponse;
use App\Services\LLM\DTOs\IdeaDTO;
use App\Services\LLM\LLMProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ExtractPostIdeasJobTest extends TestCase
{
    use RefreshDatabase;

    private Scan $scan;
    private Post $post;
    private Subreddit $subreddit;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->subreddit = Subreddit::factory()->create();
        $this->scan = Scan::factory()->create([
            'subreddit_id' => $this->subreddit->id,
            'status' => Scan::STATUS_EXTRACTING,
            'posts_fetched' => 1,
            'posts_classified' => 1,
            'posts_extracted' => 0,
            'ideas_found' => 0,
        ]);

        $this->post = Post::factory()->create([
            'subreddit_id' => $this->subreddit->id,
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_extracts_and_stores_ideas(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        $mockIdea = $this->createMockIdeaDTO('TestApp');

        $this->mockProvider($mockIdea);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $this->assertDatabaseHas('ideas', [
            'post_id' => $this->post->id,
            'scan_id' => $this->scan->id,
            'idea_title' => 'TestApp',
            'problem_statement' => 'Users need X',
            'score_overall' => 4,
            'classification_status' => 'keep',
        ]);

        // Post should be marked as extracted
        $this->post->refresh();
        $this->assertNotNull($this->post->extracted_at);

        // Scan should have ideas_found updated
        $this->scan->refresh();
        $this->assertEquals(1, $this->scan->ideas_found);
    }

    public function test_handles_no_ideas_gracefully(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'borderline',
        ]);

        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldReceive('extract')
            ->once()
            ->andReturn(new ExtractionResponse(
                ideas: collect(),
                rawResponse: [],
            ));

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $this->assertEquals(0, Idea::where('post_id', $this->post->id)->count());

        // Post should still be marked as extracted even with no ideas
        $this->post->refresh();
        $this->assertNotNull($this->post->extracted_at);

        $this->scan->refresh();
        $this->assertEquals(0, $this->scan->ideas_found);
    }

    public function test_limits_ideas_per_post(): void
    {
        config(['llm.extraction.max_ideas_per_post' => 2]);

        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        $ideas = collect([
            $this->createMockIdeaDTO('Idea1'),
            $this->createMockIdeaDTO('Idea2'),
            $this->createMockIdeaDTO('Idea3'),
            $this->createMockIdeaDTO('Idea4'),
        ]);

        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldReceive('extract')
            ->once()
            ->andReturn(new ExtractionResponse(
                ideas: $ideas,
                rawResponse: [],
            ));

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // Should only store 2 ideas due to limit
        $this->assertEquals(2, Idea::where('post_id', $this->post->id)->count());

        $this->scan->refresh();
        $this->assertEquals(2, $this->scan->ideas_found);
    }

    public function test_skips_already_extracted_post(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        // Mark post as already extracted
        $this->post->markAsExtracted();

        // Provider should not be called
        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldNotReceive('extract');

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No new ideas should be created
        $this->assertEquals(0, Idea::where('post_id', $this->post->id)->count());

        // Scan ideas_found should not be updated
        $this->scan->refresh();
        $this->assertEquals(0, $this->scan->ideas_found);
    }

    public function test_skips_if_scan_failed(): void
    {
        $this->scan->update(['status' => Scan::STATUS_FAILED]);

        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        // Provider should not be called
        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldNotReceive('extract');

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No ideas should be created
        $this->assertEquals(0, Idea::where('post_id', $this->post->id)->count());
    }

    public function test_skips_if_scan_completed(): void
    {
        $this->scan->update(['status' => Scan::STATUS_COMPLETED]);

        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        // Provider should not be called
        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldNotReceive('extract');

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No ideas should be created
        $this->assertEquals(0, Idea::where('post_id', $this->post->id)->count());
    }

    public function test_skips_if_not_in_extracting_status(): void
    {
        $this->scan->update(['status' => Scan::STATUS_CLASSIFYING]);

        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        // Provider should not be called
        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldNotReceive('extract');

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // No ideas should be created
        $this->assertEquals(0, Idea::where('post_id', $this->post->id)->count());
    }

    public function test_extracts_multiple_ideas(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'borderline',
        ]);

        $ideas = collect([
            $this->createMockIdeaDTO('Idea1'),
            $this->createMockIdeaDTO('Idea2'),
            $this->createMockIdeaDTO('Idea3'),
        ]);

        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldReceive('extract')
            ->once()
            ->andReturn(new ExtractionResponse(
                ideas: $ideas,
                rawResponse: [],
            ));

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        // Should store all 3 ideas
        $this->assertEquals(3, Idea::where('post_id', $this->post->id)->count());

        $this->scan->refresh();
        $this->assertEquals(3, $this->scan->ideas_found);
    }

    public function test_preserves_classification_status_on_idea(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'borderline',
        ]);

        $mockIdea = $this->createMockIdeaDTO('TestApp');

        $this->mockProvider($mockIdea);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $job->handle(app(LLMProviderFactory::class));

        $this->assertDatabaseHas('ideas', [
            'post_id' => $this->post->id,
            'classification_status' => 'borderline',
        ]);
    }

    public function test_throws_exception_on_network_error(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldReceive('extract')
            ->once()
            ->andReturn(new ExtractionResponse(
                ideas: collect(),
                rawResponse: ['error' => 'network-error', 'message' => 'Failed to connect to API'],
            ));

        $this->mockFactory($mockProvider);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transient extraction failure (network), retrying');

        $job->handle(app(LLMProviderFactory::class));
    }

    public function test_failed_method_marks_post_extracted(): void
    {
        Classification::factory()->create([
            'post_id' => $this->post->id,
            'final_decision' => 'keep',
        ]);

        $job = new ExtractPostIdeasJob($this->scan, $this->post);
        $exception = new \RuntimeException('Test exception');
        $job->failed($exception);

        // Post should be marked as extracted even on failure
        $this->post->refresh();
        $this->assertNotNull($this->post->extracted_at);
    }

    /**
     * Create a mock IdeaDTO with the given title.
     */
    private function createMockIdeaDTO(string $title): IdeaDTO
    {
        return new IdeaDTO(
            ideaTitle: $title,
            problemStatement: 'Users need X',
            proposedSolution: 'Build Y',
            targetAudience: 'Developers',
            whySmallTeamViable: 'Simple scope',
            demandEvidence: '10 upvotes asking for this',
            monetizationModel: '$20/month subscription',
            brandingSuggestions: ['name_ideas' => [$title], 'positioning' => 'Best Y', 'tagline' => 'Do Y better'],
            marketingChannels: ['Twitter', 'Reddit'],
            existingCompetitors: ['Competitor1'],
            scores: [
                'monetization' => 4,
                'monetization_reasoning' => 'Clear model',
                'market_saturation' => 3,
                'saturation_reasoning' => 'Some competition',
                'complexity' => 4,
                'complexity_reasoning' => 'Easy to build',
                'demand_evidence' => 5,
                'demand_reasoning' => 'Strong signal',
                'overall' => 4,
                'overall_reasoning' => 'Good opportunity',
            ],
            sourceQuote: 'I really need this tool',
        );
    }

    /**
     * Mock the LLMProviderFactory to return our mock provider.
     */
    private function mockProvider(IdeaDTO $ideaDTO): void
    {
        $mockProvider = Mockery::mock(ClaudeSonnetProvider::class);
        $mockProvider->shouldReceive('extract')
            ->once()
            ->andReturn(new ExtractionResponse(
                ideas: collect([$ideaDTO]),
                rawResponse: [],
            ));

        $this->mockFactory($mockProvider);
    }

    /**
     * Mock the LLMProviderFactory to return our mock provider.
     */
    private function mockFactory($mockProvider): void
    {
        $this->mock(LLMProviderFactory::class, function ($mock) use ($mockProvider) {
            $mock->shouldReceive('extractionProvider')->andReturn($mockProvider);
        });
    }
}
