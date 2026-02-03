<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FetchPostsJob;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\Reddit\DTOs\RedditPost;
use App\Services\Reddit\RedditService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchPostsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetches_and_stores_posts(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'startups']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
            'posts_fetched' => 0,
        ]);

        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->once()
            ->andReturn([
                'posts' => collect([
                    new RedditPost(
                        redditId: 'abc123',
                        redditFullname: 't3_abc123',
                        subreddit: 'startups',
                        title: 'Test Post',
                        body: 'Test body',
                        author: 'testuser',
                        permalink: '/r/startups/comments/abc123',
                        url: null,
                        upvotes: 50,
                        downvotes: 5,
                        numComments: 10,
                        upvoteRatio: 0.9,
                        flair: null,
                        isSelf: true,
                        isNsfw: false,
                        isSpoiler: false,
                        redditCreatedAt: Carbon::now(),
                    ),
                ]),
                'after' => null,
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        $this->assertDatabaseHas('posts', [
            'reddit_id' => 'abc123',
            'subreddit_id' => $subreddit->id,
        ]);

        $scan->refresh();
        $this->assertEquals(1, $scan->posts_fetched);
    }

    public function test_skips_duplicate_posts(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
        ]);

        // Create existing post
        Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
            'reddit_id' => 'existing123',
            'upvotes' => 10,
        ]);

        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->andReturn([
                'posts' => collect([
                    new RedditPost(
                        redditId: 'existing123',
                        redditFullname: 't3_existing123',
                        subreddit: $subreddit->name,
                        title: 'Existing Post',
                        body: 'Body',
                        author: 'user',
                        permalink: '/r/test/existing123',
                        url: null,
                        upvotes: 100, // Updated upvotes
                        downvotes: 0,
                        numComments: 5,
                        upvoteRatio: 1.0,
                        flair: null,
                        isSelf: true,
                        isNsfw: false,
                        isSpoiler: false,
                        redditCreatedAt: Carbon::now(),
                    ),
                ]),
                'after' => null,
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        // Should still only have one post
        $this->assertEquals(1, Post::where('reddit_id', 'existing123')->count());

        // But upvotes should be updated
        $this->assertEquals(100, Post::where('reddit_id', 'existing123')->first()->upvotes);
    }

    public function test_transitions_to_classifying_when_no_posts(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
            'posts_fetched' => 0,
        ]);

        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->andReturn([
                'posts' => collect([]),
                'after' => null,
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        $scan->refresh();
        // When no posts are found, should transition directly to classifying
        $this->assertEquals(Scan::STATUS_CLASSIFYING, $scan->status);
    }

    public function test_skips_processing_if_scan_already_failed(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FAILED,
        ]);

        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldNotReceive('getSubredditPosts');

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        // Should not throw exception and should return early
        $this->assertTrue(true);
    }

    public function test_calculates_date_range_for_initial_scan(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'startups']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_type' => Scan::TYPE_INITIAL,
            'status' => Scan::STATUS_PENDING,
            'date_from' => null,
            'date_to' => null,
        ]);

        $capturedAfter = null;
        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->with(
                'startups',
                \Mockery::on(function ($date) use (&$capturedAfter) {
                    $capturedAfter = $date;
                    return $date instanceof Carbon;
                }),
                \Mockery::any(),
                null,
            )
            ->andReturn([
                'posts' => collect([]),
                'after' => null,
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        $scan->refresh();
        $this->assertNotNull($scan->date_from);
        $this->assertNotNull($scan->date_to);

        // Initial scan should use configured timeframe
        $this->assertNotNull($capturedAfter);

        $now = now();
        // Verify date is in the past (not future)
        $this->assertTrue($capturedAfter->lessThanOrEqualTo($now), 'Expected captured date to be in the past');

        $daysDiff = $capturedAfter->diffInDays($now);
        $expectedMonths = (int) config('reddit.fetch.default_timeframe_months', 3);
        $this->assertGreaterThan(0, $expectedMonths, 'Timeframe months should be positive');

        $expectedDaysMin = ($expectedMonths * 30) - 5; // Buffer for month variation
        $expectedDaysMax = ($expectedMonths * 31) + 5;
        $this->assertGreaterThanOrEqual($expectedDaysMin, $daysDiff);
        $this->assertLessThanOrEqual($expectedDaysMax, $daysDiff);
    }

    public function test_calculates_date_range_for_rescan(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create(['name' => 'startups']);
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_type' => Scan::TYPE_RESCAN,
            'status' => Scan::STATUS_PENDING,
            'date_from' => null,
            'date_to' => null,
        ]);

        $capturedAfter = null;
        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->with(
                'startups',
                \Mockery::on(function ($date) use (&$capturedAfter) {
                    $capturedAfter = $date;
                    return $date instanceof Carbon;
                }),
                \Mockery::any(),
                null,
            )
            ->andReturn([
                'posts' => collect([]),
                'after' => null,
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        // Rescan should use configured timeframe
        $this->assertNotNull($capturedAfter);

        $now = now();
        // Verify date is in the past (not future)
        $this->assertTrue($capturedAfter->lessThanOrEqualTo($now), 'Expected captured date to be in the past');

        $daysDiff = $capturedAfter->diffInDays($now);
        $expectedWeeks = (int) config('reddit.fetch.rescan_timeframe_weeks', 2);
        $this->assertGreaterThan(0, $expectedWeeks, 'Timeframe weeks should be positive');

        $expectedDaysMin = ($expectedWeeks * 7) - 1; // Small buffer for timing
        $expectedDaysMax = ($expectedWeeks * 7) + 1;
        $this->assertGreaterThanOrEqual($expectedDaysMin, $daysDiff);
        $this->assertLessThanOrEqual($expectedDaysMax, $daysDiff);
    }

    public function test_uses_checkpoint_for_resumable_scan(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
            'checkpoint' => 't3_previouscursor',
        ]);

        $checkpointUsed = false;
        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->with(
                \Mockery::any(),
                \Mockery::any(),
                \Mockery::any(),
                't3_previouscursor',
            )
            ->andReturnUsing(function () use (&$checkpointUsed) {
                $checkpointUsed = true;
                return [
                    'posts' => collect([]),
                    'after' => null,
                ];
            });

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        $this->assertTrue($checkpointUsed, 'Expected getSubredditPosts to be called with the checkpoint cursor');
    }

    public function test_updates_checkpoint_after_fetch(): void
    {
        Queue::fake();

        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_PENDING,
            'checkpoint' => null,
        ]);

        $mockReddit = $this->mock(RedditService::class);
        $mockReddit->shouldReceive('getSubredditPosts')
            ->andReturn([
                'posts' => collect([
                    new RedditPost(
                        redditId: 'post1',
                        redditFullname: 't3_post1',
                        subreddit: $subreddit->name,
                        title: 'Post 1',
                        body: 'Body',
                        author: 'user',
                        permalink: '/r/test/post1',
                        url: null,
                        upvotes: 50,
                        downvotes: 5,
                        numComments: 10,
                        upvoteRatio: 0.9,
                        flair: null,
                        isSelf: true,
                        isNsfw: false,
                        isSpoiler: false,
                        redditCreatedAt: Carbon::now(),
                    ),
                ]),
                'after' => 't3_nextcursor',
            ]);

        $job = new FetchPostsJob($scan);
        $job->handle($mockReddit);

        $scan->refresh();
        $this->assertEquals('t3_nextcursor', $scan->checkpoint);
    }
}
