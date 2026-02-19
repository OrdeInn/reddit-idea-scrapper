<?php

namespace App\Jobs;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Services\LLM\DTOs\ExtractionRequest;
use App\Services\LLM\DTOs\ExtractionResponse;
use App\Services\LLM\LLMProviderFactory;
use App\Services\LLM\LLMProviderInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExtractIdeasChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * Set to 1 because retries are handled per-post internally.
     * Re-running at queue level would re-process already-extracted posts.
     */
    public int $tries = 1;

    /**
     * Job timeout in seconds.
     * Accommodates internal per-post retries with backoff.
     * Worst case: 5 posts × (120s LLM timeout + 3 × 30s backoff) ≈ 1050s.
     *
     * DEPLOYMENT REQUIREMENT: The queue connection's retry_after must exceed this value.
     * Use the dedicated 'redis-extract' connection (retry_after=1600) for these jobs.
     * Horizon's extract-chunk-supervisor must set timeout > 1500.
     */
    public int $timeout = 1500;

    /**
     * Create a new job instance.
     *
     * @param int $scanId The scan ID (scalar, NOT Eloquent model — safer for batch serialization)
     * @param array<int> $postIds Array of post IDs to extract in this chunk
     */
    public function __construct(
        public int $scanId,
        public array $postIds,
    ) {
        $this->onQueue('extract-chunk');
    }

    /**
     * Execute the job.
     */
    public function handle(LLMProviderFactory $providerFactory): void
    {
        // Check if the batch has been cancelled before processing
        if ($this->batch()?->cancelled()) {
            Log::info('Batch cancelled, skipping extraction chunk', [
                'scan_id' => $this->scanId,
                'post_count' => count($this->postIds),
            ]);
            return;
        }

        // Empty chunk — nothing to do
        if (empty($this->postIds)) {
            return;
        }

        // Re-hydrate scan from scalar ID
        $scan = Scan::find($this->scanId);

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping extraction chunk', [
                'scan_id' => $this->scanId,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping extraction chunk', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        if ($scan->status !== Scan::STATUS_EXTRACTING) {
            Log::info('Scan is not in extracting stage, skipping chunk', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        $provider = $providerFactory->extractionProvider();
        $maxAttempts = (int) config('llm.retry.max_attempts', 3);

        // Load posts by IDs — do NOT eager-load comments across all posts here.
        // Comments must be loaded per-post to guarantee the "top 100 comments per post" constraint.
        // A global eager-load with ->limit(100) applies globally, not per-parent.
        $posts = Post::whereIn('id', $this->postIds)->get()->keyBy('id');

        Log::info('Processing extraction chunk', [
            'scan_id' => $scan->id,
            'chunk_size' => count($this->postIds),
            'posts_found' => $posts->count(),
        ]);

        foreach ($this->postIds as $postId) {
            $post = $posts->get($postId);

            // Post deleted between dispatch and execution — skip gracefully
            if (! $post) {
                Log::warning('Post not found, skipping extraction', [
                    'post_id' => $postId,
                    'scan_id' => $scan->id,
                ]);
                continue;
            }

            // Idempotency: skip if post already extracted
            if ($post->isExtracted()) {
                Log::debug('Post already extracted, skipping', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                ]);
                continue;
            }

            // Load relationships per-post to guarantee top 100 comments per post
            $post->load([
                'comments' => fn ($query) => $query->orderByDesc('upvotes')->limit(100),
                'subreddit',
                'classification',
            ]);

            try {
                $this->extractSinglePost($post, $scan, $provider, $maxAttempts);

                Log::info('Extraction complete for post', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                ]);
            } catch (Throwable $e) {
                // Per-post failure after all retries exhausted — mark as extracted to prevent scan getting stuck
                Log::error('Extraction failed for post after all retries, marking as extracted', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                    'error' => $e->getMessage(),
                ]);

                try {
                    if (! $post->isExtracted()) {
                        $post->markAsExtracted();
                    }
                } catch (Throwable $markException) {
                    Log::error('Failed to mark post as extracted after extraction failure', [
                        'post_id' => $post->id,
                        'scan_id' => $scan->id,
                        'error' => $markException->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Run extraction for one post with per-post retry loop and store results.
     *
     * @param Post $post Post with relationships already loaded
     * @param Scan $scan
     * @param LLMProviderInterface $provider
     * @param int $maxAttempts
     * @throws Throwable When all retry attempts are exhausted
     */
    private function extractSinglePost(Post $post, Scan $scan, LLMProviderInterface $provider, int $maxAttempts): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $request = ExtractionRequest::fromPost($post);
                $response = $provider->extract($request);

                // Check for network errors that should trigger per-post retry
                if (($response->rawResponse['error'] ?? null) === 'network-error') {
                    if ($attempt < $maxAttempts) {
                        $backoff = min(2 ** $attempt, 30);
                        Log::warning('Network error during extraction, retrying', [
                            'post_id' => $post->id,
                            'scan_id' => $scan->id,
                            'attempt' => $attempt,
                            'max_attempts' => $maxAttempts,
                            'backoff_seconds' => $backoff,
                        ]);
                        sleep($backoff);
                        continue;
                    }

                    throw new RuntimeException('Extraction failed after all retries (network-error)');
                }

                // Success — store results and return
                $ideasCreated = $this->storeIdeasAndMarkExtracted($response, $post, $scan);

                Log::info('Ideas extracted and stored for post', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                    'ideas_created' => $ideasCreated,
                ]);

                return;
            } catch (RuntimeException $e) {
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    $backoff = min(2 ** $attempt, 30);
                    Log::warning('Transient extraction failure, retrying', [
                        'post_id' => $post->id,
                        'scan_id' => $scan->id,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'backoff_seconds' => $backoff,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($backoff);
                    continue;
                }
            } catch (Throwable $e) {
                $lastException = $e;
                break;
            }
        }

        throw $lastException ?? new RuntimeException('Extraction failed after all attempts');
    }

    /**
     * Store extracted ideas, mark post as extracted, and update scan counters.
     * Stores extracted ideas, marks post as extracted, and updates scan counters atomically.
     *
     * Uses pessimistic locking (lockForUpdate) to provide concurrent safety equivalent to
     * WithoutOverlapping. This prevents duplicate ideas under at-least-once queue delivery.
     *
     * Note: Requires MySQL or PostgreSQL for correct row-level locking behavior.
     *
     * @param ExtractionResponse $response
     * @param Post $post
     * @param Scan $scan
     * @return int Number of ideas created
     */
    private function storeIdeasAndMarkExtracted(ExtractionResponse $response, Post $post, Scan $scan): int
    {
        $count = 0;
        $classificationStatus = $post->classification?->final_decision ?? 'keep';
        $maxIdeas = config('llm.extraction.max_ideas_per_post', 5);

        DB::transaction(function () use ($response, $post, $scan, $classificationStatus, $maxIdeas, &$count) {
            // Acquire exclusive row lock on the post to prevent concurrent extraction
            $lockedPost = Post::lockForUpdate()->find($post->id);

            if (! $lockedPost) {
                // Post deleted mid-transaction — nothing to do
                return;
            }

            // Re-check idempotency under lock: another concurrent worker may have already extracted
            if ($lockedPost->isExtracted()) {
                Log::debug('Post already extracted (concurrent worker), skipping under lock', [
                    'post_id' => $post->id,
                    'scan_id' => $scan->id,
                ]);
                return;
            }

            // Store ideas if any exist
            if ($response->hasIdeas()) {
                foreach ($response->ideas as $ideaDTO) {
                    $ideaData = $ideaDTO->toArray();

                    Idea::create(array_merge($ideaData, [
                        'post_id' => $lockedPost->id,
                        'scan_id' => $scan->id,
                        'classification_status' => $classificationStatus,
                    ]));

                    $count++;

                    if ($count >= $maxIdeas) {
                        Log::debug('Reached max ideas per post limit', [
                            'post_id' => $lockedPost->id,
                            'limit' => $maxIdeas,
                        ]);
                        break;
                    }
                }
            }

            // Mark post as extracted (even if no ideas found)
            $lockedPost->markAsExtracted();

            // Update scan ideas count inside transaction to prevent crash window
            $scan->increment('ideas_found', $count);
        });

        if ($count === 0) {
            Log::debug('No ideas found in post', ['post_id' => $post->id]);
        }

        return $count;
    }

    /**
     * Handle permanent job failure.
     * Marks all unextracted posts in the chunk as extracted to prevent scan from getting stuck.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExtractIdeasChunkJob failed permanently', [
            'scan_id' => $this->scanId,
            'post_count' => count($this->postIds),
            'error' => $exception->getMessage(),
        ]);

        foreach ($this->postIds as $postId) {
            try {
                $post = Post::find($postId);

                if ($post && ! $post->isExtracted()) {
                    $post->markAsExtracted();

                    Log::warning('Force-marked post as extracted in chunk failed handler', [
                        'post_id' => $postId,
                        'scan_id' => $this->scanId,
                    ]);
                }
            } catch (Throwable $e) {
                Log::error('Failed to force-mark post as extracted in chunk failed handler', [
                    'post_id' => $postId,
                    'scan_id' => $this->scanId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
