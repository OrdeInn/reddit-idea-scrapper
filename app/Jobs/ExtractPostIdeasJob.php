<?php

namespace App\Jobs;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\LLMProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExtractPostIdeasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Scan $scan,
        public Post $post,
    ) {}

    /**
     * Get the middleware the job should pass through.
     *
     * Prevents concurrent execution for the same post.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('extract-post-'.$this->post->id))
                ->expireAfter(600)
                ->releaseAfter(30),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $scan = $this->scan->fresh();
        $post = $this->post->fresh();

        // Guard: Skip if scan no longer exists or is in a terminal state
        if (! $scan) {
            Log::warning('Scan no longer exists, skipping extraction', ['scan_id' => $this->scan->id]);
            return;
        }

        if (! $post) {
            Log::warning('Post no longer exists, skipping extraction', ['post_id' => $this->post->id]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping extraction', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Only proceed if scan is in extracting stage
        if ($scan->status !== Scan::STATUS_EXTRACTING) {
            Log::info('Scan is not in extracting stage, skipping', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Idempotency: Skip if post already extracted
        if ($post->isExtracted()) {
            Log::debug('Post already extracted, skipping', [
                'post_id' => $post->id,
                'scan_id' => $scan->id,
            ]);
            return;
        }

        Log::info('Starting extraction for post', [
            'post_id' => $post->id,
            'scan_id' => $scan->id,
            'reddit_id' => $post->reddit_id,
        ]);

        try {
            // Load relationships needed for extraction (limit comments for performance)
            $post->load([
                'comments' => fn ($query) => $query->orderByDesc('upvotes')->limit(100),
                'subreddit',
                'classification',
            ]);

            // Create extraction request from post
            $request = ExtractionRequest::fromPost($post);

            // Get extraction provider and run extraction
            $provider = LLMProviderFactory::getExtractionProvider();
            $response = $provider->extract($request);

            // Check for network errors that should trigger retry
            if (($response->rawResponse['error'] ?? null) === 'network-error') {
                throw new RuntimeException('Transient extraction failure (network), retrying');
            }

            // Store extracted ideas, mark post as extracted, and update scan in a single transaction
            $ideasCreated = $this->storeIdeasAndMarkExtracted($response, $post, $scan);

            Log::info('Extraction complete for post', [
                'post_id' => $post->id,
                'scan_id' => $scan->id,
                'ideas_found' => $ideasCreated,
            ]);
        } catch (Throwable $e) {
            Log::error('Extraction failed for post', [
                'post_id' => $post->id,
                'scan_id' => $scan->id,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            throw $e;
        }
    }

    /**
     * Store extracted ideas, mark post as extracted, and update scan in a single transaction.
     *
     * @param \App\Services\LLM\DTOs\ExtractionResponse $response
     * @param Post $post
     * @param Scan $scan
     * @return int Number of ideas created
     */
    private function storeIdeasAndMarkExtracted(
        \App\Services\LLM\DTOs\ExtractionResponse $response,
        Post $post,
        Scan $scan
    ): int {
        $count = 0;
        $classificationStatus = $post->classification?->final_decision ?? 'keep';
        $maxIdeas = config('llm.extraction.max_ideas_per_post', 5);

        DB::transaction(function () use ($response, $post, $scan, $classificationStatus, $maxIdeas, &$count) {
            // Store ideas if any exist
            if ($response->hasIdeas()) {
                foreach ($response->ideas as $ideaDTO) {
                    $ideaData = $ideaDTO->toArray();

                    Idea::create(array_merge($ideaData, [
                        'post_id' => $post->id,
                        'scan_id' => $scan->id,
                        'classification_status' => $classificationStatus,
                    ]));

                    $count++;

                    // Limit ideas per post
                    if ($count >= $maxIdeas) {
                        Log::debug('Reached max ideas per post limit', [
                            'post_id' => $post->id,
                            'limit' => $maxIdeas,
                        ]);
                        break;
                    }
                }
            }

            // Mark post as extracted (even if no ideas found)
            // This ensures completion tracking works correctly
            $post->markAsExtracted();

            // Update scan ideas count inside transaction to prevent crash window
            $scan->increment('ideas_found', $count);
        });

        if ($count === 0) {
            Log::debug('No ideas found in post', ['post_id' => $post->id]);
        }

        return $count;
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExtractPostIdeasJob failed permanently', [
            'scan_id' => $this->scan->id,
            'post_id' => $this->post->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark post as extracted even if failed (to prevent scan getting stuck)
        // This ensures the completion check can proceed
        $post = $this->post->fresh();
        if ($post && ! $post->isExtracted()) {
            $post->markAsExtracted();
        }
    }
}
