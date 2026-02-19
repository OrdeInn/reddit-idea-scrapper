<?php

namespace App\Jobs;

use App\Models\Scan;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
     * Stale batch threshold in seconds (2 hours).
     * Unfinished batches older than this are considered orphaned and skipped.
     */
    private const STALE_BATCH_THRESHOLD_SECONDS = 7200;

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

        if ($scan->status !== Scan::STATUS_EXTRACTING) {
            Log::info('Scan is not in extracting stage, skipping', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Atomic duplicate batch guard via scans row lock.
        // Locks the scan row (always exists) to serialize the check-and-dispatch operation.
        // Note: Requires MySQL or PostgreSQL for correct row-level locking behavior.
        $shouldDispatch = DB::transaction(function () use ($scan) {
            // Acquire exclusive lock on the scan row
            $lockedScan = Scan::lockForUpdate()->find($scan->id);

            if (! $lockedScan || $lockedScan->status !== Scan::STATUS_EXTRACTING) {
                return false;
            }

            $batchName = "extract-scan-{$scan->id}";

            $existingBatch = DB::table('job_batches')
                ->where('name', $batchName)
                ->whereNull('finished_at')
                ->orderByDesc('created_at')
                ->first();

            if ($existingBatch) {
                $staleThreshold = now()->subSeconds(self::STALE_BATCH_THRESHOLD_SECONDS)->timestamp;

                if ($existingBatch->created_at > $staleThreshold) {
                    // Active batch exists and is not stale — skip dispatch
                    Log::info('Active extraction batch already exists, skipping duplicate dispatch', [
                        'scan_id' => $scan->id,
                        'existing_batch_id' => $existingBatch->id,
                    ]);
                    return false;
                }

                // Stale batch detected — log and proceed with new dispatch
                Log::warning('Stale extraction batch detected, proceeding with new dispatch', [
                    'scan_id' => $scan->id,
                    'stale_batch_id' => $existingBatch->id,
                    'batch_created_at' => $existingBatch->created_at,
                ]);
            }

            return true;
        });

        if (! $shouldDispatch) {
            return;
        }

        // Collect post IDs needing extraction (keep/borderline classification)
        $postIds = $scan->posts()->needsExtraction()->pluck('id')->toArray();
        $postsCount = count($postIds);

        Log::info('Starting extraction orchestration', [
            'scan_id' => $scan->id,
            'posts_to_extract' => $postsCount,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
            'posts_extracted' => $scan->posts_extracted,
        ]);

        // Handle case where there are no posts to extract
        if ($postsCount === 0) {
            Log::info('No posts need extraction, dispatching FinalizeExtractionJob directly', [
                'scan_id' => $scan->id,
            ]);

            FinalizeExtractionJob::dispatch($scan->id);
            return;
        }

        $chunkSize = config('llm.extraction.batch_chunk_size', 5);
        $chunks = array_chunk($postIds, $chunkSize);
        $chunkCount = count($chunks);

        // Build batch jobs array — one ExtractIdeasChunkJob per chunk
        $batchJobs = array_map(
            fn (array $chunk) => new ExtractIdeasChunkJob($scan->id, $chunk),
            $chunks
        );

        $scanId = $scan->id;

        $batch = Bus::batch($batchJobs)
            ->name("extract-scan-{$scanId}")
            ->onConnection('redis-extract')
            ->onQueue('extract-chunk')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanId) {
                FinalizeExtractionJob::dispatch($scanId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($scanId) {
                Log::error('Extraction batch chunk job failed', [
                    'scan_id' => $scanId,
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        Log::info('Dispatched extraction batch', [
            'scan_id' => $scan->id,
            'total_posts' => $postsCount,
            'chunk_count' => $chunkCount,
            'chunk_size' => $chunkSize,
            'batch_id' => $batch->id,
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
            $scan->markAsFailed('Failed to orchestrate extraction: ' . $exception->getMessage());
        }
    }
}
