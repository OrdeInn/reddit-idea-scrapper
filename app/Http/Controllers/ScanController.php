<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\ScanService;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    public function __construct(
        private ScanService $scanService,
    ) {}

    /**
     * Start a new scan for a subreddit.
     */
    public function start(Subreddit $subreddit): JsonResponse
    {
        $scan = $this->scanService->startScan($subreddit);

        return response()->json([
            'scan' => $this->scanService->getScanStatus($scan),
            'message' => $scan->wasRecentlyCreated
                ? 'Scan started'
                : 'Scan already in progress',
        ]);
    }

    /**
     * Get the current status of a scan.
     */
    public function status(Scan $scan): JsonResponse
    {
        return response()->json([
            'scan' => $this->scanService->getScanStatus($scan),
        ]);
    }

    /**
     * Cancel an in-progress scan.
     */
    public function cancel(Scan $scan): JsonResponse
    {
        try {
            $this->scanService->cancelScan($scan);

            return response()->json([
                'scan' => $this->scanService->getScanStatus($scan->fresh()),
                'message' => 'Scan cancelled',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Retry a failed scan.
     */
    public function retry(Scan $scan): JsonResponse
    {
        try {
            $newScan = $this->scanService->retryScan($scan);

            return response()->json([
                'scan' => $this->scanService->getScanStatus($newScan),
                'message' => 'Scan restarted',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
