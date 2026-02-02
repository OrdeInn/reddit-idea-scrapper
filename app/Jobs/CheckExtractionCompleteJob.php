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

class CheckExtractionCompleteJob implements ShouldQueue
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
    public int $backoff = 15;

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
            Log::warning('Scan no longer exists, skipping extraction completion check', [
                'scan_id' => $this->scan->id,
            ]);
            return;
        }

        // Only proceed if we're still in extracting stage
        if ($scan->status !== Scan::STATUS_EXTRACTING) {
            Log::debug('Scan is not in extracting status, skipping completion check', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Count posts that need extraction (keep or borderline classification)
        $postsToExtract = $scan->posts()
            ->whereHas('classification', fn ($q) => $q->whereIn('final_decision', ['keep', 'borderline']))
            ->count();

        // Count posts that have been extracted (using per-post extracted_at timestamp)
        $postsExtracted = $scan->posts()
            ->whereHas('classification', fn ($q) => $q->whereIn('final_decision', ['keep', 'borderline']))
            ->whereNotNull('extracted_at')
            ->count();

        Log::debug('Checking extraction completion', [
            'scan_id' => $scan->id,
            'posts_to_extract' => $postsToExtract,
            'posts_extracted' => $postsExtracted,
        ]);

        // If no posts need extraction, complete immediately
        if ($postsToExtract === 0) {
            Log::info('No posts to extract, completing scan', [
                'scan_id' => $scan->id,
            ]);
            $this->completeScan($scan);
            return;
        }

        // If not all posts are extracted, re-check later
        if ($postsExtracted < $postsToExtract) {
            $pending = $postsToExtract - $postsExtracted;
            Log::debug('Extraction jobs still pending', [
                'scan_id' => $scan->id,
                'done' => $postsExtracted,
                'total' => $postsToExtract,
                'pending' => $pending,
            ]);

            // Release job back to queue with delay instead of dispatching new job
            // This prevents unbounded queue growth
            $this->release($this->backoff);
            return;
        }

        // All posts extracted, complete the scan
        Log::info('All extraction jobs complete, finalizing scan', [
            'scan_id' => $scan->id,
            'posts_extracted' => $postsExtracted,
            'ideas_found' => $scan->ideas_found,
        ]);

        $this->completeScan($scan);
    }

    /**
     * Complete the scan and log final statistics.
     */
    private function completeScan(Scan $scan): void
    {
        // Update the scan's posts_extracted counter to match actual extracted count
        $actualExtracted = $scan->posts()
            ->whereHas('classification', fn ($q) => $q->whereIn('final_decision', ['keep', 'borderline']))
            ->whereNotNull('extracted_at')
            ->count();

        $scan->update([
            'posts_extracted' => $actualExtracted,
        ]);

        $scan->markAsCompleted();

        Log::info('Scan completed successfully', [
            'scan_id' => $scan->id,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
            'posts_extracted' => $actualExtracted,
            'ideas_found' => $scan->ideas_found,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('CheckExtractionCompleteJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark scan as failed since completion checking failed
        $scan = $this->scan->fresh();
        if ($scan && $scan->status === Scan::STATUS_EXTRACTING) {
            $scan->markAsFailed('Extraction completion check failed after extended polling');
        }
    }
}
