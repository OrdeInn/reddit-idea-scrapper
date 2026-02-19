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

class FinalizeExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @param int $scanId The scan ID (scalar, NOT Eloquent model — dispatched from serialized batch callback)
     */
    public function __construct(
        public int $scanId,
    ) {
        $this->onQueue('extract');
    }

    /**
     * Execute the job.
     *
     * Runs after all extraction batch workers complete (triggered by Bus::batch()->finally()).
     * Reconciles the posts_extracted counter and marks the scan as completed.
     */
    public function handle(): void
    {
        $scan = Scan::find($this->scanId);

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping extraction finalization', [
                'scan_id' => $this->scanId,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping extraction finalization (idempotent)', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        if ($scan->status !== Scan::STATUS_EXTRACTING) {
            Log::info('Scan is not in extracting stage, skipping finalization', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Reconcile posts_extracted counter with actual DB state
        // Reconcile actual extracted posts count from DB to fix any counter drift
        $actualExtracted = $scan->posts()
            ->whereHas('classification', fn ($q) => $q->whereIn('final_decision', ['keep', 'borderline']))
            ->whereNotNull('extracted_at')
            ->count();

        $previousExtracted = $scan->posts_extracted;
        $scan->update(['posts_extracted' => $actualExtracted]);

        if ($previousExtracted !== $actualExtracted) {
            Log::warning('posts_extracted counter drift corrected during finalization', [
                'scan_id' => $scan->id,
                'previous' => $previousExtracted,
                'actual' => $actualExtracted,
            ]);
        }

        // Log final statistics
        Log::info('Extraction finalization complete', [
            'scan_id' => $scan->id,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
            'posts_extracted' => $actualExtracted,
            'ideas_found' => $scan->ideas_found,
        ]);

        // Mark scan as completed — updates status, completed_at, and subreddit's last_scanned_at
        $scan->markAsCompleted();

        Log::info('Scan completed successfully', [
            'scan_id' => $scan->id,
            'ideas_found' => $scan->ideas_found,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('FinalizeExtractionJob failed permanently', [
            'scan_id' => $this->scanId,
            'error' => $exception->getMessage(),
        ]);

        $scan = Scan::find($this->scanId);
        if ($scan && $scan->status === Scan::STATUS_EXTRACTING) {
            $scan->markAsFailed('Extraction finalization failed: ' . $exception->getMessage());
        }
    }
}
