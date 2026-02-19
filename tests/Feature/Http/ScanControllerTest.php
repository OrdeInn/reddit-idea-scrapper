<?php

namespace Tests\Feature\Http;

use App\Jobs\StartScanJob;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_start_scan_for_subreddit(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();

        $response = $this->postJson(route('scan.start', $subreddit));

        $response->assertOk();
        $response->assertJsonStructure(['scan', 'message']);
        $response->assertJsonPath('message', 'Scan started');

        $this->assertDatabaseHas('scans', [
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
        ]);

        Queue::assertPushed(StartScanJob::class);
    }

    public function test_returns_existing_scan_when_already_in_progress(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $existingScan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);

        $response = $this->postJson(route('scan.start', $subreddit));

        $response->assertOk();
        $response->assertJsonPath('message', 'Scan already in progress');
        $response->assertJsonPath('scan.id', $existingScan->id);

        // No new job should be dispatched
        Queue::assertNotPushed(StartScanJob::class);
    }

    public function test_can_get_scan_status(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_FETCHING,
            'posts_fetched' => 25,
        ]);

        $response = $this->getJson(route('scan.status', $scan));

        $response->assertOk();
        $response->assertJsonStructure(['scan']);
        $response->assertJsonPath('scan.status', Scan::STATUS_FETCHING);
        $response->assertJsonPath('scan.posts_fetched', 25);
    }

    public function test_can_cancel_in_progress_scan(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_FETCHING,
        ]);

        $response = $this->postJson(route('scan.cancel', $scan));

        $response->assertOk();
        $response->assertJsonPath('message', 'Scan cancelled');

        $this->assertEquals(Scan::STATUS_FAILED, $scan->fresh()->status);
    }

    public function test_returns_422_when_canceling_completed_scan(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $response = $this->postJson(route('scan.cancel', $scan));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_can_retry_failed_scan(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $failedScan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
            'error_message' => 'API timeout',
        ]);

        $response = $this->postJson(route('scan.retry', $failedScan));

        $response->assertOk();
        $response->assertJsonPath('message', 'Scan restarted');

        $newScanId = $response->json('scan.id');
        $this->assertNotEquals($failedScan->id, $newScanId);

        Queue::assertPushed(StartScanJob::class);
    }

    public function test_returns_422_when_retrying_non_failed_scan(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $response = $this->postJson(route('scan.retry', $scan));

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_start_scan_accepts_date_range_parameters(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();

        // Date format required: Y-m-d\TH:i:s.v\Z (ISO8601 UTC with milliseconds)
        $response = $this->postJson(route('scan.start', $subreddit), [
            'date_from' => '2024-01-01T00:00:00.000Z',
            'date_to' => '2024-01-31T23:59:59.000Z',
        ]);

        $response->assertOk();

        $scan = Scan::where('subreddit_id', $subreddit->id)->latest()->first();
        $this->assertNotNull($scan->date_from);
        $this->assertNotNull($scan->date_to);
    }
}
