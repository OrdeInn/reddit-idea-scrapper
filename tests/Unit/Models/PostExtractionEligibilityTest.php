<?php

namespace Tests\Unit\Models;

use App\Models\Classification;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostExtractionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_extraction_includes_keep_and_borderline_posts(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);

        $keepPost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);
        $borderlinePost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);
        $discardedPost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Classification::factory()->keep()->create(['post_id' => $keepPost->id]);
        Classification::factory()->borderline()->create(['post_id' => $borderlinePost->id]);
        Classification::factory()->discard()->create(['post_id' => $discardedPost->id]);

        $ids = Post::query()->needsExtraction()->pluck('id')->all();

        $this->assertContains($keepPost->id, $ids);
        $this->assertContains($borderlinePost->id, $ids);
        $this->assertNotContains($discardedPost->id, $ids);
    }
}
