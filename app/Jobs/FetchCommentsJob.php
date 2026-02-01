<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Scan;
use App\Services\Reddit\DTOs\RedditComment;
use App\Services\Reddit\RedditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchCommentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public Scan $scan,
        public Post $post,
    ) {}

    public function handle(RedditService $reddit): void
    {
        $scan = $this->scan->fresh();
        $post = $this->post->fresh();

        // Guard: Ensure scan and post still exist
        if (! $scan || ! $post) {
            Log::warning('Scan or post no longer exists, skipping comment fetch', [
                'scan_id' => $this->scan->id,
                'post_id' => $this->post->id,
            ]);
            // Count as done since we won't retry this case
            $this->incrementDoneCounter($scan);
            return;
        }

        // Guard: Only proceed if scan is in fetching status
        if ($scan->status !== Scan::STATUS_FETCHING) {
            Log::debug('Scan is not in fetching status, skipping comment fetch', [
                'scan_id' => $scan->id,
                'status' => $scan->status,
            ]);
            // Count as done since we won't retry this case
            $this->incrementDoneCounter($scan);
            return;
        }

        try {
            Log::debug('Fetching comments', [
                'scan_id' => $scan->id,
                'post_id' => $post->id,
                'reddit_id' => $post->reddit_id,
            ]);

            $comments = $reddit->getPostComments(
                subreddit: $scan->subreddit->name,
                postId: $post->reddit_id,
                depth: config('reddit.fetch.comment_depth', 1),
                limit: config('reddit.fetch.max_comments_per_post', 100),
            );

            $this->storeComments($comments, $post);

            Log::debug('Fetched comments for post', [
                'post_id' => $post->id,
                'comment_count' => $comments->count(),
            ]);

            // Increment counter on successful completion
            $this->incrementDoneCounter($scan);

        } catch (\Exception $e) {
            Log::error('Fetch comments failed', [
                'scan_id' => $scan->id,
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Store fetched comments in the database.
     *
     * @param Collection<RedditComment> $comments
     */
    private function storeComments(Collection $comments, Post $post): void
    {
        DB::transaction(function () use ($comments, $post) {
            foreach ($comments as $redditComment) {
                // Skip if comment already exists
                $exists = Comment::where('post_id', $post->id)
                    ->where('reddit_id', $redditComment->redditId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                Comment::create($redditComment->toArray(
                    postId: $post->id,
                ));
            }
        });
    }

    /**
     * Increment the comment_jobs_done counter on the scan.
     */
    private function incrementDoneCounter(?Scan $scan): void
    {
        if (! $scan) {
            return;
        }

        try {
            $scan->increment('comment_jobs_done');
        } catch (\Exception $e) {
            Log::warning('Failed to increment comment_jobs_done counter', [
                'scan_id' => $scan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FetchCommentsJob failed', [
            'scan_id' => $this->scan->id,
            'post_id' => $this->post->id,
            'error' => $exception->getMessage(),
        ]);

        // Increment done counter even on failure so completion check can proceed
        $scan = $this->scan->fresh();
        $this->incrementDoneCounter($scan);

        // Don't fail the whole scan for one post's comments
    }
}
