<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\Reddit\RedditService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanFetchCommand extends Command
{
    protected $signature = 'scan:fetch {--subreddit= : Subreddit name (required unless --scan provided)} {--scan= : Existing scan ID} {--limit= : Max posts to fetch} {--skip-comments : Skip fetching comments} {--dry-run : Fetch but do not save}';

    protected $description = 'Fetch Reddit posts for a subreddit synchronously for debugging';

    public function handle(RedditService $reddit): int
    {
        try {
            $startTime = microtime(true);

            // Validate options
            $subredditName = $this->option('subreddit');
            $scanId = $this->option('scan');
            $limit = $this->option('limit') ? (int) $this->option('limit') : null;
            $skipComments = $this->option('skip-comments');
            $dryRun = $this->option('dry-run');

            // Validate limit is positive
            if ($limit !== null && $limit <= 0) {
                $this->error('--limit must be a positive integer');
                return self::FAILURE;
            }

            // Option validation
            if (!$subredditName && !$scanId) {
                $this->error('Either --subreddit or --scan is required');
                return self::FAILURE;
            }

            // Get or create subreddit
            if ($scanId) {
                $scan = Scan::find($scanId);
                if (!$scan) {
                    $this->error("Scan ID {$scanId} not found");
                    return self::FAILURE;
                }

                $subreddit = $scan->subreddit;
                if (!$subreddit) {
                    $this->error("Scan {$scanId} has no subreddit associated");
                    return self::FAILURE;
                }

                // If both provided, validate consistency
                if ($subredditName) {
                    $normalizedProvided = $this->normalizeSubredditName($subredditName);
                    $normalizedScan = $this->normalizeSubredditName($subreddit->name);

                    if ($normalizedProvided !== $normalizedScan) {
                        $this->error("Scan ID {$scanId} belongs to subreddit '{$subreddit->name}', not '{$subredditName}'");
                        return self::FAILURE;
                    }
                }
            } else {
                $subreddit = $this->getOrCreateSubreddit($subredditName, $dryRun, $reddit);
                if (!$subreddit) {
                    return self::FAILURE;
                }

                $scan = $this->getOrCreateScan($subreddit, $dryRun);
            }

            // Fetch posts
            $this->info("Fetching posts from r/{$subreddit->name}...");
            $posts = $this->fetchPosts($reddit, $scan, $limit);

            if ($posts->isEmpty()) {
                $this->info('No posts found');
                return self::SUCCESS;
            }

            $this->info("Fetched {$posts->count()} posts");

            // Store posts (unless dry-run)
            $postCount = 0;
            if (!$dryRun) {
                $postCount = $this->storePosts($posts, $scan);
                $this->info("Stored {$postCount} new posts");
            } else {
                $this->info('[DRY-RUN] Would store ' . $posts->count() . ' posts');
            }

            // Fetch and store comments
            $commentCounts = ['fetched' => 0, 'stored' => 0];
            if (!$skipComments) {
                $this->info('Fetching comments...');
                $commentCounts = $this->fetchAndStoreComments($reddit, $scan, $posts, $dryRun);
            }

            $elapsedTime = microtime(true) - $startTime;
            $this->displayResults($posts, $postCount, $commentCounts, $dryRun, $elapsedTime);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('ScanFetchCommand failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Normalize subreddit name by lowercasing, trimming, and stripping r/ prefix.
     */
    private function normalizeSubredditName(string $name): string
    {
        $name = strtolower(trim($name));
        if (str_starts_with($name, 'r/')) {
            $name = substr($name, 2);
        }
        return trim($name);
    }

    /**
     * Find existing or create new subreddit record (or return unsaved instance in dry-run mode).
     */
    private function getOrCreateSubreddit(string $name, bool $dryRun, RedditService $reddit): ?Subreddit
    {
        $normalizedName = $this->normalizeSubredditName($name);

        try {
            // Verify subreddit exists on Reddit
            $reddit->getSubredditInfo($normalizedName);

            if ($dryRun) {
                // Return unsaved instance in dry-run mode
                $subreddit = new Subreddit(['name' => $normalizedName]);
                return $subreddit;
            }

            return Subreddit::firstOrCreate(
                ['name' => $normalizedName]
            );
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied')) {
                $this->error("Subreddit r/{$normalizedName} is private, banned, or quarantined");
            } else {
                $this->error("Subreddit r/{$normalizedName} not found or API error: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Find by ID or create new scan for subreddit (or return unsaved instance in dry-run mode).
     */
    private function getOrCreateScan(Subreddit $subreddit, bool $dryRun): Scan
    {
        $scan = new Scan([
            'subreddit_id' => $subreddit->id,
            'scan_type' => Scan::TYPE_INITIAL,
            'status' => Scan::STATUS_PENDING,
        ]);

        if ($dryRun) {
            // Return unsaved instance in dry-run mode with relation set
            $scan->setRelation('subreddit', $subreddit);
            return $scan;
        }

        // Create the scan in database
        return Scan::create([
            'subreddit_id' => $subreddit->id,
            'scan_type' => Scan::TYPE_INITIAL,
            'status' => Scan::STATUS_PENDING,
        ]);
    }

    /**
     * Fetch posts with pagination until limit or exhausted.
     */
    private function fetchPosts(RedditService $reddit, Scan $scan, ?int $limit): Collection
    {
        $posts = collect();
        $cursor = null;
        $postsFetched = 0;

        // Create progress bar (with max if limit provided, else indeterminate)
        $progress = $this->output->createProgressBar($limit ?? 0);
        $progress->setFormat(' %current%/%max% posts [%bar%] %percent%%') ;
        if (!$limit) {
            // For indeterminate mode, use a simpler format
            $progress->setFormat(' %current% posts fetched [%bar%]');
        }
        $progress->start();

        try {
            while (true) {
                // Calculate batch size
                $batchSize = 100;
                if ($limit) {
                    $remaining = $limit - $postsFetched;
                    if ($remaining <= 0) {
                        break;
                    }
                    $batchSize = min($batchSize, $remaining);
                }

                // Fetch batch
                $result = $reddit->getSubredditPosts(
                    subreddit: $scan->subreddit->name,
                    afterCursor: $cursor,
                    limit: $batchSize,
                );

                $batch = $result['posts'];
                if ($batch->isEmpty()) {
                    break;
                }

                $posts = $posts->merge($batch);
                $postsFetched += $batch->count();
                $progress->setProgress($postsFetched);

                // Check if we've reached limit
                if ($limit && $postsFetched >= $limit) {
                    break;
                }

                // Check if there are more pages
                $cursor = $result['after'];
                if (!$cursor) {
                    break;
                }
            }
        } finally {
            $progress->finish();
            $this->newLine();
        }

        return $posts;
    }

    /**
     * Store posts in database, return count stored.
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
                        'scan_id' => $scan->id,
                    ]);
                    continue;
                }

                // Create new post
                Post::create($redditPost->toArray(
                    subredditId: $scan->subreddit_id,
                    scanId: $scan->id,
                ));

                $storedCount++;
            }
        });

        return $storedCount;
    }

    /**
     * Fetch and store comments for each post.
     * Returns array with both fetched and stored counts.
     */
    private function fetchAndStoreComments(RedditService $reddit, Scan $scan, Collection $posts, bool $dryRun): array
    {
        $commentsFetched = 0;
        $commentsStored = 0;

        foreach ($posts as $redditPost) {
            try {
                // Fetch comments using the Reddit post ID
                $comments = $reddit->getPostComments(
                    subreddit: $scan->subreddit->name,
                    postId: $redditPost->redditId,
                    depth: config('reddit.fetch.comment_depth', 1),
                    limit: config('reddit.fetch.max_comments_per_post', 100),
                );

                if ($comments->isEmpty()) {
                    continue;
                }

                $commentsFetched += $comments->count();

                if (!$dryRun) {
                    // Find the post in database and store comments
                    $post = Post::where('reddit_id', $redditPost->redditId)->first();
                    if ($post) {
                        $commentsStored += $this->storeComments($comments, $post);
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Failed to fetch comments for post {$redditPost->redditId}: " . $e->getMessage());
                Log::warning('Failed to fetch comments', [
                    'reddit_id' => $redditPost->redditId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['fetched' => $commentsFetched, 'stored' => $commentsStored];
    }

    /**
     * Store comments in database, return count stored.
     */
    private function storeComments(Collection $comments, Post $post): int
    {
        $storedCount = 0;

        DB::transaction(function () use ($comments, $post, &$storedCount) {
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

                $storedCount++;
            }
        });

        return $storedCount;
    }

    /**
     * Output summary to console.
     */
    private function displayResults(Collection $posts, int $postCount, array $commentCounts, bool $dryRun, float $elapsedTime): void
    {
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->newLine();
        $this->info($prefix . 'Posts Fetched');

        // Display per-post table
        $postRows = $posts->map(function ($post) {
            return [
                'ID' => $post->redditId,
                'Title' => substr($post->title, 0, 60) . (strlen($post->title) > 60 ? '...' : ''),
                'Upvotes' => $post->upvotes,
                'Comments' => $post->numComments,
            ];
        })->toArray();

        if (!empty($postRows)) {
            $this->table(array_keys(reset($postRows)), $postRows);
        }

        // Display summary
        $this->newLine();
        $this->info($prefix . 'Summary');

        $summaryRows = [
            ['Posts fetched', $posts->count()],
            ['Posts stored', $dryRun ? 'N/A (dry-run)' : $postCount],
            ['Comments fetched', $commentCounts['fetched']],
        ];

        if (!$dryRun && $commentCounts['stored'] > 0) {
            $summaryRows[] = ['Comments stored', $commentCounts['stored']];
        }

        $summaryRows[] = ['Time elapsed', sprintf('%.2f seconds', $elapsedTime)];

        $this->table(
            ['Metric', 'Value'],
            $summaryRows
        );
    }
}
