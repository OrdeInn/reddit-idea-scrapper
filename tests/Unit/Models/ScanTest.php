<?php

namespace Tests\Unit\Models;

use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_in_progress_returns_true_for_pending(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_PENDING]);
        $this->assertTrue($scan->isInProgress());
    }

    public function test_is_in_progress_returns_true_for_fetching(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_FETCHING]);
        $this->assertTrue($scan->isInProgress());
    }

    public function test_is_in_progress_returns_true_for_classifying(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_CLASSIFYING]);
        $this->assertTrue($scan->isInProgress());
    }

    public function test_is_in_progress_returns_true_for_extracting(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_EXTRACTING]);
        $this->assertTrue($scan->isInProgress());
    }

    public function test_is_in_progress_returns_false_for_completed(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_COMPLETED]);
        $this->assertFalse($scan->isInProgress());
    }

    public function test_is_in_progress_returns_false_for_failed(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_FAILED]);
        $this->assertFalse($scan->isInProgress());
    }

    public function test_is_completed_returns_true_only_when_status_is_completed(): void
    {
        $completed = Scan::factory()->create(['status' => Scan::STATUS_COMPLETED]);
        $pending = Scan::factory()->create(['status' => Scan::STATUS_PENDING]);

        $this->assertTrue($completed->isCompleted());
        $this->assertFalse($pending->isCompleted());
    }

    public function test_is_failed_returns_true_only_when_status_is_failed(): void
    {
        $failed = Scan::factory()->create(['status' => Scan::STATUS_FAILED]);
        $completed = Scan::factory()->create(['status' => Scan::STATUS_COMPLETED]);

        $this->assertTrue($failed->isFailed());
        $this->assertFalse($completed->isFailed());
    }

    public function test_progress_percent_maps_correctly_for_each_status(): void
    {
        $cases = [
            Scan::STATUS_PENDING => 0,
            Scan::STATUS_FETCHING => 25,
            Scan::STATUS_CLASSIFYING => 50,
            Scan::STATUS_EXTRACTING => 75,
            Scan::STATUS_COMPLETED => 100,
            Scan::STATUS_FAILED => 0,
        ];

        foreach ($cases as $status => $expectedPercent) {
            $scan = Scan::factory()->create(['status' => $status]);
            $this->assertEquals(
                $expectedPercent,
                $scan->progress_percent,
                "Failed for status: {$status}"
            );
        }
    }

    public function test_status_message_for_fetching_includes_post_count(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_FETCHING,
            'posts_fetched' => 42,
        ]);

        $this->assertStringContainsString('42', $scan->status_message);
    }

    public function test_status_message_for_classifying_includes_progress(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_CLASSIFYING,
            'posts_fetched' => 100,
            'posts_classified' => 60,
        ]);

        $this->assertStringContainsString('60', $scan->status_message);
        $this->assertStringContainsString('100', $scan->status_message);
    }

    public function test_status_message_for_completed_includes_ideas_found(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_COMPLETED,
            'ideas_found' => 15,
        ]);

        $this->assertStringContainsString('15', $scan->status_message);
    }

    public function test_status_message_for_failed_includes_error(): void
    {
        $scan = Scan::factory()->create([
            'status' => Scan::STATUS_FAILED,
            'error_message' => 'API rate limit exceeded',
        ]);

        $this->assertStringContainsString('API rate limit exceeded', $scan->status_message);
    }

    public function test_mark_as_started_sets_fetching_status_and_started_at(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_PENDING, 'started_at' => null]);

        $scan->markAsStarted();
        $scan->refresh();

        $this->assertEquals(Scan::STATUS_FETCHING, $scan->status);
        $this->assertNotNull($scan->started_at);
    }

    public function test_mark_as_completed_sets_completed_status_and_updates_subreddit(): void
    {
        $subreddit = Subreddit::factory()->create(['last_scanned_at' => null]);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_EXTRACTING,
        ]);

        $scan->markAsCompleted();
        $scan->refresh();
        $subreddit->refresh();

        $this->assertEquals(Scan::STATUS_COMPLETED, $scan->status);
        $this->assertNotNull($scan->completed_at);
        $this->assertNotNull($subreddit->last_scanned_at);
    }

    public function test_mark_as_failed_stores_error_message(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_FETCHING]);

        $scan->markAsFailed('Network timeout');
        $scan->refresh();

        $this->assertEquals(Scan::STATUS_FAILED, $scan->status);
        $this->assertEquals('Network timeout', $scan->error_message);
        $this->assertNotNull($scan->completed_at);
    }

    public function test_update_status_with_progress_updates_both_status_and_counters(): void
    {
        $scan = Scan::factory()->create(['status' => Scan::STATUS_FETCHING, 'posts_fetched' => 0]);

        $scan->updateStatus(Scan::STATUS_CLASSIFYING, ['posts_classified' => 30]);
        $scan->refresh();

        $this->assertEquals(Scan::STATUS_CLASSIFYING, $scan->status);
        $this->assertEquals(30, $scan->posts_classified);
    }
}
