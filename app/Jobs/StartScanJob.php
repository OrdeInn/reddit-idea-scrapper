<?php

namespace App\Jobs;

use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Scan $scan,
    ) {}

    public function handle(): void
    {
        $scan = $this->scan->fresh();

        // Guard: Skip if scan no longer exists
        if (!$scan) {
            Log::warning('Scan no longer exists, skipping', ['scan_id' => $this->scan->id]);
            return;
        }

        // Guard: Skip if subreddit no longer exists
        if (!$scan->subreddit) {
            Log::warning('Subreddit no longer exists for scan', ['scan_id' => $scan->id]);
            $scan->markAsFailed('Subreddit no longer exists');
            return;
        }

        // Skip if already started or completed
        if ($scan->status !== Scan::STATUS_PENDING) {
            Log::info('Scan already started, skipping', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        Log::info('Starting scan pipeline', [
            'scan_id' => $scan->id,
            'subreddit' => $scan->subreddit->name,
            'type' => $scan->scan_type,
        ]);

        // Mark as started
        $scan->markAsStarted();

        // Start the fetch phase
        FetchPostsJob::dispatch($scan)->onQueue('fetch');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StartScanJob failed', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Re-fetch scan by ID to handle case where it was deleted
        $scan = Scan::find($this->scan->id);

        if (!$scan) {
            Log::warning('Cannot mark scan as failed - scan no longer exists', [
                'scan_id' => $this->scan->id,
            ]);
            return;
        }

        // Store generic error message to avoid leaking sensitive details
        $scan->markAsFailed('Failed to start scan pipeline');
    }
}
