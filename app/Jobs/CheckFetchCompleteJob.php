<?php

namespace App\Jobs;

use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckFetchCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $backoff = 10;

    public function __construct(
        public Scan $scan,
    ) {}

    public function handle(): void
    {
        $scan = $this->scan->fresh();

        if (! $scan) {
            Log::warning('Scan no longer exists, skipping completion check', ['scan_id' => $this->scan->id]);
            return;
        }

        // Only proceed if we're still in fetching stage
        if ($scan->status !== Scan::STATUS_FETCHING) {
            Log::debug('Scan is not in fetching status, skipping completion check', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            return;
        }

        // Check if all comment jobs are complete using counters
        $totalJobs = $scan->comment_jobs_total ?? 0;
        $doneJobs = $scan->comment_jobs_done ?? 0;

        // If no comment jobs were dispatched yet, wait and retry
        // Note: comment_jobs_total is null until FetchPostsJob sets it
        if ($totalJobs === 0 && $scan->comment_jobs_total === null) {
            Log::debug('No comment jobs dispatched yet, re-checking later', [
                'scan_id' => $scan->id,
            ]);

            self::dispatch($scan)
                ->delay(now()->addSeconds(10))
                ->onQueue('fetch');
            return;
        }

        // Edge case: totalJobs is 0 but comment_jobs_total was explicitly set to 0
        // This means no posts were found, so we should transition to classifying
        if ($totalJobs === 0 && $scan->comment_jobs_total !== null) {
            Log::info('No comment jobs needed (0 posts), transitioning to classifying', [
                'scan_id' => $scan->id,
            ]);
            $scan->updateStatus(Scan::STATUS_CLASSIFYING);

            // Dispatch classification jobs to trigger completion check
            ClassifyPostsJob::dispatch($scan)->onQueue('classify');

            return;
        }

        // If not all jobs are done, re-check later
        if ($doneJobs < $totalJobs) {
            Log::debug('Comment jobs still pending', [
                'scan_id' => $scan->id,
                'done' => $doneJobs,
                'total' => $totalJobs,
                'pending' => $totalJobs - $doneJobs,
            ]);

            self::dispatch($scan)
                ->delay(now()->addSeconds(10))
                ->onQueue('fetch');
            return;
        }

        Log::info('All comment jobs complete, transitioning to classification', [
            'scan_id' => $scan->id,
            'posts_fetched' => $scan->posts_fetched,
            'comment_jobs_total' => $totalJobs,
        ]);

        // All fetch jobs complete, transition to classification
        $scan->updateStatus(Scan::STATUS_CLASSIFYING);

        // Dispatch classification jobs
        ClassifyPostsJob::dispatch($scan)->onQueue('classify');

        Log::info('Dispatched ClassifyPostsJob to start classification', [
            'scan_id' => $scan->id,
        ]);
    }
}
