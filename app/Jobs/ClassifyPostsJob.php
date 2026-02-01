<?php

namespace App\Jobs;

use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyPostsJob implements ShouldQueue
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
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $scan = $this->scan->fresh();

        // Guard: Skip if scan no longer exists or is in a terminal state
        if (! $scan) {
            Log::warning('Scan no longer exists, skipping classification orchestration', [
                'scan_id' => $this->scan->id,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping classification orchestration', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Only proceed if scan is in classifying stage
        // Note: CheckFetchCompleteJob sets this status before dispatching us
        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::info('Scan is not in classifying stage, skipping', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Get posts that need classification
        $postsQuery = $scan->posts()->needsClassification();
        $postsCount = $postsQuery->count();

        Log::info('Starting classification orchestration', [
            'scan_id' => $scan->id,
            'posts_to_classify' => $postsCount,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
        ]);

        // Handle case where there are no posts to classify
        if ($postsCount === 0) {
            Log::info('No posts need classification, checking completion', [
                'scan_id' => $scan->id,
            ]);

            // Dispatch completion check job
            CheckClassificationCompleteJob::dispatch($scan)
                ->delay(now()->addSeconds(5))
                ->onQueue('classify');

            return;
        }

        // Dispatch classification jobs for each post in chunks
        $dispatchedCount = 0;
        $postsQuery->chunkById(100, function ($posts) use ($scan, &$dispatchedCount) {
            foreach ($posts as $post) {
                ClassifyPostJob::dispatch($scan, $post)
                    ->onQueue('classify');
                $dispatchedCount++;
            }
        });

        Log::info('Dispatched classification jobs', [
            'scan_id' => $scan->id,
            'dispatched_count' => $dispatchedCount,
        ]);

        // Dispatch completion check job with delay
        CheckClassificationCompleteJob::dispatch($scan)
            ->delay(now()->addSeconds(10))
            ->onQueue('classify');
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ClassifyPostsJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        $this->scan->markAsFailed('Failed to orchestrate classification: ' . $exception->getMessage());
    }
}
