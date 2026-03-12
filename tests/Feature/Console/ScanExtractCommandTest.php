<?php

namespace Tests\Feature\Console;

use App\Models\Classification;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanExtractCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_discarded_post_for_manual_extraction(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        Classification::factory()->discard()->create(['post_id' => $post->id]);

        $this->artisan('scan:extract', ['--post' => $post->id])
            ->expectsOutput("Post {$post->id} classification is 'discard' (not eligible for extraction)")
            ->assertExitCode(1);
    }
}
