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

        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::info('Scan is not in classifying stage, skipping', [
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

            if (! $lockedScan || $lockedScan->status !== Scan::STATUS_CLASSIFYING) {
                return false;
            }

            $batchName = "classify-scan-{$scan->id}";

            $existingBatch = DB::table('job_batches')
                ->where('name', $batchName)
                ->whereNull('finished_at')
                ->orderByDesc('created_at')
                ->first();

            if ($existingBatch) {
                $staleThreshold = now()->subSeconds(self::STALE_BATCH_THRESHOLD_SECONDS)->timestamp;

                if ($existingBatch->created_at > $staleThreshold) {
                    // Active batch exists and is not stale — skip dispatch
                    Log::info('Active classification batch already exists, skipping duplicate dispatch', [
                        'scan_id' => $scan->id,
                        'existing_batch_id' => $existingBatch->id,
                    ]);
                    return false;
                }

                // Stale batch detected — log and proceed with new dispatch
                Log::warning('Stale classification batch detected, proceeding with new dispatch', [
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

        // Collect post IDs needing classification
        $postIds = $scan->posts()->needsClassification()->pluck('id')->toArray();
        $postsCount = count($postIds);

        Log::info('Starting classification orchestration', [
            'scan_id' => $scan->id,
            'posts_to_classify' => $postsCount,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
        ]);

        // Handle case where there are no posts to classify
        if ($postsCount === 0) {
            Log::info('No posts need classification, dispatching FinalizeClassificationJob directly', [
                'scan_id' => $scan->id,
            ]);

            FinalizeClassificationJob::dispatch($scan->id);
            return;
        }

        $chunkSize = config('llm.classification.batch_chunk_size', 10);
        $chunks = array_chunk($postIds, $chunkSize);
        $chunkCount = count($chunks);

        // Build batch jobs array — one ClassifyPostsChunkJob per chunk
        $batchJobs = array_map(
            fn (array $chunk) => new ClassifyPostsChunkJob($scan->id, $chunk),
            $chunks
        );

        $scanId = $scan->id;

        $batch = Bus::batch($batchJobs)
            ->name("classify-scan-{$scanId}")
            ->onConnection('redis-classify')
            ->onQueue('classify-chunk')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($scanId) {
                FinalizeClassificationJob::dispatch($scanId);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($scanId) {
                Log::error('Classification batch chunk job failed', [
                    'scan_id' => $scanId,
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        Log::info('Dispatched classification batch', [
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
        Log::error('ClassifyPostsJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        $this->scan->markAsFailed('Failed to orchestrate classification: ' . $exception->getMessage());
    }
}
