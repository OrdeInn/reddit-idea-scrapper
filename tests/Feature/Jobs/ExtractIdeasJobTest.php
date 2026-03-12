<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ExtractIdeasChunkJob;
use App\Jobs\ExtractIdeasJob;
use App\Models\Classification;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExtractIdeasJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_batches_for_keep_and_borderline_posts(): void
    {
        Bus::fake();
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_EXTRACTING,
        ]);

        $keepPost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);
        $borderlinePost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);
        $discardedPost = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Classification::factory()->keep()->create(['post_id' => $keepPost->id]);
        Classification::factory()->borderline()->create(['post_id' => $borderlinePost->id]);
        Classification::factory()->discard()->create(['post_id' => $discardedPost->id]);

        config(['llm.extraction.batch_chunk_size' => 10]);

        (new ExtractIdeasJob($scan))->handle();

        Bus::assertBatched(function ($batch) use ($keepPost, $borderlinePost) {
            if (count($batch->jobs) !== 1) {
                return false;
            }

            $job = $batch->jobs[0];

            return $job instanceof ExtractIdeasChunkJob
                && $job->postIds === [$keepPost->id, $borderlinePost->id];
        });
    }
}
