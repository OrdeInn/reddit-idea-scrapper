<?php

namespace App\Services;

use App\Jobs\StartScanJob;
use App\Models\Scan;
use App\Models\Subreddit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanService
{
    /**
     * Start a scan for a subreddit.
     *
     * @param Subreddit $subreddit
     * @param Carbon|null $dateFrom
     * @param Carbon|null $dateTo
     * @return Scan The created or existing scan
     */
    public function startScan(Subreddit $subreddit, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): Scan
    {
        // Use transaction with lock to prevent race conditions
        return DB::transaction(function () use ($subreddit, $dateFrom, $dateTo) {
            // Re-fetch subreddit with lock to prevent concurrent scans
            $lockedSubreddit = Subreddit::lockForUpdate()->find($subreddit->id);

            if (!$lockedSubreddit) {
                throw new \RuntimeException('Subreddit no longer exists');
            }

            // Check for existing active scan (within transaction for consistency)
            $activeScan = $lockedSubreddit->activeScan();

            if ($activeScan) {
                Log::info('Scan already in progress', [
                    'subreddit' => $lockedSubreddit->name,
                    'scan_id' => $activeScan->id,
                ]);
                return $activeScan;
            }

            // Determine scan type
            $scanType = $this->determineScanType($lockedSubreddit);

            // Compute fallback dates from config when not provided by user
            if (!$dateFrom) {
                $dateFrom = $scanType === Scan::TYPE_RESCAN
                    ? now('UTC')->subWeeks(config('reddit.fetch.rescan_timeframe_weeks', 2))
                    : now('UTC')->subWeeks(config('reddit.fetch.default_timeframe_weeks', 1));
                $dateTo = now('UTC');
            }

            // Create new scan with date range always set
            $scan = Scan::create([
                'subreddit_id' => $lockedSubreddit->id,
                'scan_type' => $scanType,
                'status' => Scan::STATUS_PENDING,
                'date_from' => $dateFrom->utc(),
                'date_to' => ($dateTo ?? now('UTC'))->utc(),
            ]);

            Log::info('Created new scan', [
                'scan_id' => $scan->id,
                'subreddit' => $lockedSubreddit->name,
                'type' => $scanType,
            ]);

            // Dispatch the pipeline after transaction commits
            StartScanJob::dispatch($scan)
                ->onQueue('fetch')
                ->afterCommit();

            return $scan;
        });
    }

    /**
     * Determine whether this should be an initial scan or rescan.
     */
    private function determineScanType(Subreddit $subreddit): string
    {
        $lastCompletedScan = $subreddit->latestCompletedScan();

        if (!$lastCompletedScan) {
            return Scan::TYPE_INITIAL;
        }

        return Scan::TYPE_RESCAN;
    }

    /**
     * Get the current status of a scan with progress details.
     */
    public function getScanStatus(Scan $scan): array
    {
        $scan = $scan->fresh();

        // Guard: Handle case where scan was deleted
        if (!$scan) {
            return [
                'id' => null,
                'status' => 'deleted',
                'status_message' => 'Scan no longer exists',
                'progress_percent' => 0,
                'scan_type' => null,
                'posts_fetched' => 0,
                'posts_classified' => 0,
                'posts_extracted' => 0,
                'ideas_found' => 0,
                'started_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'is_in_progress' => false,
                'is_completed' => false,
                'is_failed' => true,
            ];
        }

        return [
            'id' => $scan->id,
            'status' => $scan->status,
            'status_message' => $scan->status_message,
            'progress_percent' => $scan->progress_percent,
            'scan_type' => $scan->scan_type,
            'posts_fetched' => $scan->posts_fetched,
            'posts_classified' => $scan->posts_classified,
            'posts_extracted' => $scan->posts_extracted,
            'ideas_found' => $scan->ideas_found,
            'started_at' => $scan->started_at?->toIso8601String(),
            'completed_at' => $scan->completed_at?->toIso8601String(),
            'error_message' => $scan->error_message,
            'is_in_progress' => $scan->isInProgress(),
            'is_completed' => $scan->isCompleted(),
            'is_failed' => $scan->isFailed(),
        ];
    }

    /**
     * Get status for a subreddit (including active scan if any).
     */
    public function getSubredditStatus(Subreddit $subreddit): array
    {
        $activeScan = $subreddit->activeScan();
        $lastScan = $subreddit->latestCompletedScan();

        return [
            'subreddit_id' => $subreddit->id,
            'subreddit_name' => $subreddit->name,
            'has_active_scan' => $activeScan !== null,
            'active_scan' => $activeScan ? $this->getScanStatus($activeScan) : null,
            'last_scan' => $lastScan ? $this->getScanStatus($lastScan) : null,
            'last_scanned_at' => $subreddit->last_scanned_at?->toIso8601String(),
            'idea_count' => $subreddit->idea_count,
            'top_score' => $subreddit->top_score,
        ];
    }

    /**
     * Cancel an in-progress scan.
     */
    public function cancelScan(Scan $scan): void
    {
        if (!$scan->isInProgress()) {
            throw new \RuntimeException('Can only cancel in-progress scans');
        }

        $scan->markAsFailed('Scan cancelled by user');

        Log::info('Scan cancelled', ['scan_id' => $scan->id]);
    }

    /**
     * Retry a failed scan.
     */
    public function retryScan(Scan $scan): Scan
    {
        if (!$scan->isFailed()) {
            throw new \RuntimeException('Can only retry failed scans');
        }

        return $this->startScan($scan->subreddit);
    }

    /**
     * Get completed scan history for a subreddit.
     *
     * @param Subreddit $subreddit
     * @return array
     */
    public function getScanHistory(Subreddit $subreddit): array
    {
        return $subreddit->scans()
            ->where('status', Scan::STATUS_COMPLETED)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->map(fn (Scan $scan) => [
                'id' => $scan->id,
                'scan_type' => $scan->scan_type,
                'date_from' => $scan->date_from?->toIso8601String(),
                'date_to' => $scan->date_to?->toIso8601String(),
                'posts_fetched' => $scan->posts_fetched,
                'ideas_found' => $scan->ideas_found,
                'completed_at' => $scan->completed_at?->toIso8601String(),
                'completed_at_human' => $scan->completed_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Get all active scans.
     */
    public function getActiveScans(): \Illuminate\Database\Eloquent\Collection
    {
        return Scan::whereNotIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_FAILED])
            ->with('subreddit')
            ->latest()
            ->get();
    }

    /**
     * Get recent completed scans.
     */
    public function getRecentScans(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return Scan::where('status', Scan::STATUS_COMPLETED)
            ->with('subreddit')
            ->latest('completed_at')
            ->limit($limit)
            ->get();
    }
}
