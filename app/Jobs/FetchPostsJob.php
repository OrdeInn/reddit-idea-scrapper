<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\Scan;
use App\Services\Reddit\DTOs\RedditPost;
use App\Services\Reddit\RedditService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Scan $scan,
        public ?string $cursor = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RedditService $reddit): void
    {
        $scan = $this->scan->fresh();

        // Guard: Skip if scan no longer exists or is in a terminal state
        if (! $scan) {
            Log::warning('Scan no longer exists, skipping fetch', ['scan_id' => $this->scan->id]);

            return;
        }

        if ($scan->isFailed() || $scan->isCompleted()) {
            Log::info('Scan already finished, skipping fetch', ['scan_id' => $scan->id, 'status' => $scan->status]);

            return;
        }

        // Only proceed if scan is pending or already fetching
        if (! in_array($scan->status, [Scan::STATUS_PENDING, Scan::STATUS_FETCHING], true)) {
            Log::info('Scan is past fetching stage, skipping', ['scan_id' => $scan->id, 'status' => $scan->status]);

            return;
        }

        try {
            $scan->updateStatus(Scan::STATUS_FETCHING);

            $subreddit = $scan->subreddit;
            $cursor = $this->cursor ?? $scan->checkpoint;

            $dateFrom = $scan->date_from;
            $dateTo = $scan->date_to;

            // TODO: Remove legacy fallback after all existing scans have completed
            if (! $dateFrom || ! $dateTo) {
                Log::warning('Scan missing date range — applying legacy fallback', ['scan_id' => $scan->id]);
                $dateFrom = $dateFrom ?? ($scan->scan_type === Scan::TYPE_RESCAN
                    ? now('UTC')->subWeeks(config('reddit.fetch.rescan_timeframe_weeks', 2))
                    : now('UTC')->subWeeks(config('reddit.fetch.default_timeframe_weeks', 1)));
                $dateTo = $dateTo ?? now('UTC');
                $scan->update(['date_from' => $dateFrom->utc(), 'date_to' => $dateTo->utc()]);
            }

            Log::info('Fetching posts', [
                'scan_id' => $scan->id,
                'subreddit' => $subreddit->name,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'cursor' => $cursor,
            ]);

            // Fetch posts from Reddit
            $result = $reddit->getSubredditPosts(
                subreddit: $subreddit->name,
                after: $dateFrom,
                before: $dateTo,
                afterCursor: $cursor,
            );

            $posts = $result['posts'];
            $nextCursor = $result['after'];

            // Store posts
            $storedCount = $this->storePosts($posts, $scan);

            // Update scan progress
            $scan->increment('posts_fetched', $storedCount);
            $scan->update(['checkpoint' => $nextCursor]);

            Log::info('Fetched posts batch', [
                'scan_id' => $scan->id,
                'batch_size' => $posts->count(),
                'stored' => $storedCount,
                'total_fetched' => $scan->fresh()->posts_fetched,
                'has_more' => $nextCursor !== null,
            ]);

            // Continue fetching if more pages exist
            if ($nextCursor) {
                self::dispatch($scan, $nextCursor)
                    ->onQueue('fetch');
            } else {
                // Fetching complete, start fetching comments
                $this->dispatchCommentJobs($scan);
            }

        } catch (\Exception $e) {
            Log::error('Fetch posts failed', [
                'scan_id' => $scan->id,
                'error' => $e->getMessage(),
            ]);

            // Don't mark as failed yet - allow retries
            throw $e;
        }
    }

    /**
     * Store fetched posts in the database.
     *
     * @param  Collection<RedditPost>  $posts
     */
    private function storePosts(Collection $posts, Scan $scan): int
    {
        $storedCount = 0;

        DB::transaction(function () use ($posts, $scan, &$storedCount) {
            foreach ($posts as $redditPost) {
                // Check if post already exists (globally by reddit_id)
                $existing = Post::where('reddit_id', $redditPost->redditId)->first();

                if ($existing) {
                    // Update engagement metrics and associate with current scan
                    $existing->update([
                        'upvotes' => $redditPost->upvotes,
                        'downvotes' => $redditPost->downvotes,
                        'num_comments' => $redditPost->numComments,
                        'upvote_ratio' => $redditPost->upvoteRatio,
                        'fetched_at' => now(),
                        'scan_id' => $scan->id, // Associate with current scan for rescan support
                    ]);
                } else {
                    // Create new post
                    Post::create($redditPost->toArray(
                        subredditId: $scan->subreddit_id,
                        scanId: $scan->id,
                    ));
                }

                $storedCount++;
            }
        });

        return $storedCount;
    }

    /**
     * Dispatch jobs to fetch comments for all posts.
     */
    private function dispatchCommentJobs(Scan $scan): void
    {
        // Use chunking to avoid loading all posts into memory
        $postCount = $scan->posts()->count();

        Log::info('Dispatching comment fetch jobs', [
            'scan_id' => $scan->id,
            'post_count' => $postCount,
        ]);

        // Handle 0-post case: transition directly to classifying
        if ($postCount === 0) {
            Log::info('No posts to fetch comments for, transitioning to classifying', [
                'scan_id' => $scan->id,
            ]);
            $scan->updateStatus(Scan::STATUS_CLASSIFYING);

            return;
        }

        // Reset counters and update total before dispatching
        $scan->update([
            'comment_jobs_total' => $postCount,
            'comment_jobs_done' => 0,
        ]);

        // Dispatch comment jobs in chunks to avoid memory issues
        $scan->posts()->chunkById(100, function ($posts) use ($scan) {
            foreach ($posts as $post) {
                FetchCommentsJob::dispatch($scan, $post)
                    ->onQueue('fetch');
            }
        });

        // Dispatch a job to check when all comments are fetched
        CheckFetchCompleteJob::dispatch($scan)
            ->delay(now()->addSeconds(10))
            ->onQueue('fetch');
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FetchPostsJob failed permanently', [
            'scan_id' => $this->scan->id,
            'error' => $exception->getMessage(),
        ]);

        $this->scan->markAsFailed('Failed to fetch posts: '.$exception->getMessage());
    }
}
