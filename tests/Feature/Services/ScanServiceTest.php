<?php

namespace Tests\Feature\Services;

use App\Jobs\StartScanJob;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScanService::class);
    }

    public function test_starts_initial_scan_for_new_subreddit(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();

        $scan = $this->service->startScan($subreddit);

        $this->assertEquals(Scan::TYPE_INITIAL, $scan->scan_type);
        $this->assertEquals(Scan::STATUS_PENDING, $scan->status);

        Queue::assertPushed(StartScanJob::class);
    }

    public function test_starts_rescan_for_previously_scanned_subreddit(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create([
            'last_scanned_at' => now()->subDay(),
        ]);

        // Create a completed scan
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
            'completed_at' => now()->subDay(),
        ]);

        $scan = $this->service->startScan($subreddit);

        $this->assertEquals(Scan::TYPE_RESCAN, $scan->scan_type);
    }

    public function test_returns_existing_scan_if_in_progress(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $existingScan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FETCHING,
        ]);

        $scan = $this->service->startScan($subreddit);

        $this->assertEquals($existingScan->id, $scan->id);

        // Should not dispatch a new job
        Queue::assertNotPushed(StartScanJob::class);
    }

    public function test_get_scan_status_returns_correct_data(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_CLASSIFYING,
            'posts_fetched' => 100,
            'posts_classified' => 50,
        ]);

        $status = $this->service->getScanStatus($scan);

        $this->assertEquals('classifying', $status['status']);
        $this->assertEquals(100, $status['posts_fetched']);
        $this->assertEquals(50, $status['posts_classified']);
        $this->assertTrue($status['is_in_progress']);
    }

    public function test_can_cancel_in_progress_scan(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_FETCHING,
        ]);

        $this->service->cancelScan($scan);

        $scan->refresh();
        $this->assertEquals(Scan::STATUS_FAILED, $scan->status);
        $this->assertEquals('Scan cancelled by user', $scan->error_message);
    }

    public function test_cannot_cancel_completed_scan(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->cancelScan($scan);
    }

    public function test_can_retry_failed_scan(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $failedScan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
            'error_message' => 'Something went wrong',
        ]);

        $newScan = $this->service->retryScan($failedScan);

        $this->assertNotEquals($failedScan->id, $newScan->id);
        $this->assertEquals(Scan::STATUS_PENDING, $newScan->status);

        Queue::assertPushed(StartScanJob::class);
    }

    public function test_cannot_retry_non_failed_scan(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->retryScan($scan);
    }

    public function test_get_subreddit_status_includes_active_scan(): void
    {
        $subreddit = Subreddit::factory()->create();
        $activeScan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FETCHING,
            'posts_fetched' => 25,
        ]);

        $status = $this->service->getSubredditStatus($subreddit);

        $this->assertEquals($subreddit->id, $status['subreddit_id']);
        $this->assertEquals($subreddit->name, $status['subreddit_name']);
        $this->assertTrue($status['has_active_scan']);
        $this->assertNotNull($status['active_scan']);
        $this->assertEquals($activeScan->id, $status['active_scan']['id']);
    }

    public function test_get_active_scans_returns_only_active(): void
    {
        $subreddit1 = Subreddit::factory()->create();
        $subreddit2 = Subreddit::factory()->create();
        $subreddit3 = Subreddit::factory()->create();

        // Active scans
        Scan::factory()->create([
            'subreddit_id' => $subreddit1->id,
            'status' => Scan::STATUS_FETCHING,
        ]);
        Scan::factory()->create([
            'subreddit_id' => $subreddit2->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);

        // Completed scan
        Scan::factory()->create([
            'subreddit_id' => $subreddit3->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $activeScans = $this->service->getActiveScans();

        $this->assertCount(2, $activeScans);
    }

    public function test_get_recent_scans_returns_completed_only(): void
    {
        $subreddit1 = Subreddit::factory()->create();
        $subreddit2 = Subreddit::factory()->create();

        // Completed scan
        Scan::factory()->create([
            'subreddit_id' => $subreddit1->id,
            'status' => Scan::STATUS_COMPLETED,
            'completed_at' => now()->subHour(),
        ]);

        // Failed scan
        Scan::factory()->create([
            'subreddit_id' => $subreddit2->id,
            'status' => Scan::STATUS_FAILED,
            'completed_at' => now()->subHour(),
        ]);

        $recentScans = $this->service->getRecentScans();

        $this->assertCount(1, $recentScans);
        $this->assertEquals(Scan::STATUS_COMPLETED, $recentScans->first()->status);
    }
}
