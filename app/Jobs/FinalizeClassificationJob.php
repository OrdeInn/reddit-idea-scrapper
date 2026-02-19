<?php

namespace App\Jobs;

use App\Models\Classification;
use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeClassificationJob implements ShouldQueue
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
        $this->onQueue('classify');
    }

    /**
     * Execute the job.
     *
     * Runs after all classification batch workers complete (triggered by Bus::batch()->finally()).
     * Enforces completeness invariant, reconciles counters, and transitions to extraction.
     */
    public function handle(): void
    {
        $scan = Scan::find($this->scanId);

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping classification finalization', [
                'scan_id' => $this->scanId,
            ]);
            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping classification finalization', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        if ($scan->status === Scan::STATUS_EXTRACTING) {
            // Job may have crashed after updating status but before dispatching extraction.
            // Re-dispatch extraction — ExtractIdeasJob has its own idempotent batch guard.
            Log::info('Scan already in extracting stage, re-dispatching extraction to recover from potential crash', [
                'scan_id' => $scan->id,
            ]);
            ExtractIdeasJob::dispatch($scan)->onQueue('extract');
            return;
        }

        if ($scan->status !== Scan::STATUS_CLASSIFYING) {
            Log::info('Scan is not in classifying stage, skipping finalization (already transitioned)', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Enforce completeness invariant: every fetched post must have a terminal classification
        $unclassifiedPosts = $scan->posts()
            ->whereDoesntHave('classification', fn ($q) => $q->whereNotNull('classified_at'))
            ->get();

        $gapFilled = 0;

        foreach ($unclassifiedPosts as $post) {
            // Use updateOrCreate to handle both cases:
            // - Post has NO classification row → creates new discard record
            // - Post has INCOMPLETE classification row (classified_at=null) → updates to discard
            // This respects the unique('post_id') constraint on the classifications table
            Classification::updateOrCreate(
                ['post_id' => $post->id],
                [
                    'haiku_verdict' => 'skip',
                    'haiku_confidence' => 0.0,
                    'haiku_category' => 'finalization-gap-fill',
                    'haiku_reasoning' => 'Post was not classified before finalization',
                    'haiku_completed' => false,
                    'gpt_verdict' => 'skip',
                    'gpt_confidence' => 0.0,
                    'gpt_category' => 'finalization-gap-fill',
                    'gpt_reasoning' => 'Post was not classified before finalization',
                    'gpt_completed' => false,
                    'final_decision' => Classification::DECISION_DISCARD,
                    'combined_score' => 0.0,
                    'classified_at' => now(),
                ]
            );

            $gapFilled++;
        }

        if ($gapFilled > 0) {
            Log::warning('Gap-filled unclassified posts during finalization', [
                'scan_id' => $scan->id,
                'gap_filled_count' => $gapFilled,
            ]);
        }

        // Reconcile posts_classified counter with actual DB state to fix any counter drift
        $actualClassified = $scan->posts()
            ->whereHas('classification', fn ($q) => $q->whereNotNull('classified_at'))
            ->count();

        $scan->update(['posts_classified' => $actualClassified]);

        Log::info('Classification finalization complete', [
            'scan_id' => $scan->id,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified_actual' => $actualClassified,
            'gap_filled_count' => $gapFilled,
        ]);

        // Transition to extracting stage
        $scan->updateStatus(Scan::STATUS_EXTRACTING);

        // Dispatch extraction pipeline
        ExtractIdeasJob::dispatch($scan)->onQueue('extract');

        Log::info('Transitioned to extracting, dispatched ExtractIdeasJob', [
            'scan_id' => $scan->id,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('FinalizeClassificationJob failed permanently', [
            'scan_id' => $this->scanId,
            'error' => $exception->getMessage(),
        ]);

        $scan = Scan::find($this->scanId);
        if ($scan) {
            $scan->markAsFailed('Classification finalization failed: ' . $exception->getMessage());
        }
    }
}
