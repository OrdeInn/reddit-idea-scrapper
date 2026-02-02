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

class ExtractIdeasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 1;

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
            Log::warning('Scan no longer exists, skipping extraction orchestration', [
                'scan_id' => $this->scan->id,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping extraction orchestration', [
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

        // Get posts that passed classification and need extraction
        $postsQuery = $scan->posts()->needsExtraction();
        $postsCount = $postsQuery->count();

        Log::info('Starting extraction orchestration', [
            'scan_id' => $scan->id,
            'posts_to_extract' => $postsCount,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
            'posts_extracted' => $scan->posts_extracted,
        ]);

        // Handle case where there are no posts to extract
        if ($postsCount === 0) {
            Log::info('No posts need extraction, completing scan', [
                'scan_id' => $scan->id,
            ]);

            $this->completeScan($scan);
            return;
        }

        // Dispatch extraction jobs for each post in chunks
        $dispatchedCount = 0;
        $postsQuery->chunkById(100, function ($posts) use ($scan, &$dispatchedCount) {
            foreach ($posts as $post) {
                ExtractPostIdeasJob::dispatch($scan, $post)
                    ->onQueue('extract');
                $dispatchedCount++;
            }
        });

        Log::info('Dispatched extraction jobs', [
            'scan_id' => $scan->id,
            'dispatched_count' => $dispatchedCount,
        ]);

        // Dispatch completion check job with delay
        CheckExtractionCompleteJob::dispatch($scan)
            ->delay(now()->addSeconds(30))
            ->onQueue('extract');
    }

    /**
     * Complete the scan when no posts need extraction.
     */
    private function completeScan(Scan $scan): void
    {
        $scan->markAsCompleted();

        Log::info('Scan completed', [
            'scan_id' => $scan->id,
            'ideas_found' => $scan->ideas_found,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExtractIdeasJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        $scan = $this->scan->fresh();
        if ($scan && $scan->status === Scan::STATUS_EXTRACTING) {
            $scan->markAsFailed('Failed to orchestrate extraction: '.$exception->getMessage());
        }
    }
}
