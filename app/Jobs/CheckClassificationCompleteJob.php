<?php

namespace App\Jobs;

use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckClassificationCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     * Set to 0 for unlimited attempts to prevent scans getting stuck.
     */
    public int $tries = 0;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 10;

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

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping completion check', [
                'scan_id' => $this->scan->id,
            ]);
            return;
        }

        // Only proceed if we're still in classifying stage
        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::debug('Scan is not in classifying status, skipping completion check', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Get counts
        $postsFetched = $scan->posts_fetched ?? 0;
        $postsClassified = $scan->posts_classified ?? 0;

        Log::debug('Checking classification completion', [
            'scan_id' => $scan->id,
            'posts_fetched' => $postsFetched,
            'posts_classified' => $postsClassified,
        ]);

        // If no posts were fetched, transition directly to extracting
        if ($postsFetched === 0) {
            Log::info('No posts to classify, transitioning to extracting', [
                'scan_id' => $scan->id,
            ]);
            $this->transitionToExtracting($scan);
            return;
        }

        // If not all posts are classified, re-check later
        if ($postsClassified < $postsFetched) {
            $pending = $postsFetched - $postsClassified;
            Log::debug('Classification jobs still pending', [
                'scan_id' => $scan->id,
                'done' => $postsClassified,
                'total' => $postsFetched,
                'pending' => $pending,
            ]);

            // Release job back to queue with delay instead of dispatching new job
            // This prevents unbounded queue growth
            $this->release($this->backoff);
            return;
        }

        // All posts classified, transition to extracting
        Log::info('All classification jobs complete, transitioning to extracting', [
            'scan_id' => $scan->id,
            'posts_classified' => $postsClassified,
        ]);

        $this->transitionToExtracting($scan);
    }

    /**
     * Transition scan to extracting status and dispatch extraction job.
     */
    private function transitionToExtracting(Scan $scan): void
    {
        $scan->updateStatus(Scan::STATUS_EXTRACTING);

        // TODO: Dispatch ExtractIdeasJob when implemented
        // ExtractIdeasJob::dispatch($scan)->onQueue('extract');

        Log::info('Extraction not yet implemented. Scan left in extracting state.', [
            'scan_id' => $scan->id,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckClassificationCompleteJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark scan as failed since completion checking failed
        $scan = $this->scan->fresh();
        if ($scan && $scan->status === Scan::STATUS_CLASSIFYING) {
            $scan->markAsFailed('Classification completion check failed after extended polling');
        }
    }
}
