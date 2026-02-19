<?php

namespace Tests\Unit\Models;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubredditTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_name_prepends_r_slash(): void
    {
        $subreddit = Subreddit::factory()->create(['name' => 'startups']);

        $this->assertEquals('r/startups', $subreddit->full_name);
    }

    public function test_is_scan_in_progress_returns_true_when_active_scan_exists(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FETCHING,
        ]);

        $this->assertTrue($subreddit->isScanInProgress());
    }

    public function test_is_scan_in_progress_returns_false_when_only_completed_scans(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $this->assertFalse($subreddit->isScanInProgress());
    }

    public function test_is_scan_in_progress_returns_false_when_only_failed_scans(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
        ]);

        $this->assertFalse($subreddit->isScanInProgress());
    }

    public function test_is_scan_in_progress_returns_false_when_no_scans(): void
    {
        $subreddit = Subreddit::factory()->create();

        $this->assertFalse($subreddit->isScanInProgress());
    }

    public function test_latest_completed_scan_returns_most_recently_completed(): void
    {
        $subreddit = Subreddit::factory()->create();

        $older = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
            'completed_at' => now()->subDays(5),
        ]);

        $newer = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
            'completed_at' => now()->subDay(),
        ]);

        $latest = $subreddit->latestCompletedScan();

        $this->assertNotNull($latest);
        $this->assertEquals($newer->id, $latest->id);
    }

    public function test_latest_completed_scan_returns_null_when_no_completed_scans(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
        ]);

        $this->assertNull($subreddit->latestCompletedScan());
    }

    public function test_active_scan_excludes_completed_and_failed(): void
    {
        $subreddit = Subreddit::factory()->create();

        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
        ]);
        $active = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_CLASSIFYING,
        ]);

        $result = $subreddit->activeScan();

        $this->assertNotNull($result);
        $this->assertEquals($active->id, $result->id);
    }

    public function test_active_scan_returns_null_when_no_active_scan(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_COMPLETED,
        ]);

        $this->assertNull($subreddit->activeScan());
    }

    public function test_idea_count_returns_total_ideas_through_posts(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Idea::factory()->count(3)->create(['post_id' => $post->id, 'scan_id' => $scan->id]);

        // Other subreddit should not count
        $other = Subreddit::factory()->create();
        $otherScan = Scan::factory()->create(['subreddit_id' => $other->id]);
        $otherPost = Post::factory()->create(['subreddit_id' => $other->id, 'scan_id' => $otherScan->id]);
        Idea::factory()->count(5)->create(['post_id' => $otherPost->id, 'scan_id' => $otherScan->id]);

        $this->assertEquals(3, $subreddit->idea_count);
    }

    public function test_idea_count_returns_zero_when_no_ideas(): void
    {
        $subreddit = Subreddit::factory()->create();

        $this->assertEquals(0, $subreddit->idea_count);
    }

    public function test_top_score_returns_max_score_overall(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 3]);
        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 7]);
        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 5]);

        $this->assertEquals(7, $subreddit->top_score);
    }

    public function test_top_score_returns_null_when_no_ideas(): void
    {
        $subreddit = Subreddit::factory()->create();

        $this->assertNull($subreddit->top_score);
    }
}
