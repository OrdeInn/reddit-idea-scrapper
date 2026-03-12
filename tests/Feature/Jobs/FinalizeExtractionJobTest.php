<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FinalizeExtractionJob;
use App\Models\Classification;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalizeExtractionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_posts_extracted_using_keep_and_borderline_posts(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_EXTRACTING,
            'posts_extracted' => 99,
        ]);

        $keepPost = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
            'extracted_at' => now(),
        ]);

        $borderlinePost = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
            'extracted_at' => now(),
        ]);

        Classification::factory()->keep()->create(['post_id' => $keepPost->id]);
        Classification::factory()->borderline()->create(['post_id' => $borderlinePost->id]);

        (new FinalizeExtractionJob($scan->id))->handle();

        $scan->refresh();

        $this->assertSame(2, $scan->posts_extracted);
        $this->assertSame(Scan::STATUS_COMPLETED, $scan->status);
    }
}
