<?php

namespace App\Services\Reddit;

use App\Services\Reddit\DTOs\RedditComment;
use App\Services\Reddit\DTOs\RedditPost;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RedditService
{
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $userAgent;
    private int $delayMs;
    private int $maxRetries;
    private int $retryDelayMs;
    private ?float $lastRequestTime = null;

    public function __construct()
    {
        $this->clientId = config('reddit.client_id');
        $this->clientSecret = config('reddit.client_secret');
        $this->username = config('reddit.username');
        $this->password = config('reddit.password');
        $this->userAgent = config('reddit.user_agent');
        $this->delayMs = config('reddit.rate_limit.delay_between_requests_ms', 1000);
        $this->maxRetries = config('reddit.rate_limit.max_retries', 3);
        $this->retryDelayMs = config('reddit.rate_limit.retry_delay_ms', 5000);
    }

    /**
     * Get OAuth access token, fetching new one if needed.
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'reddit_access_token';

        return Cache::remember($cacheKey, 3500, function () {
            return $this->fetchNewAccessToken();
        });
    }

    /**
     * Fetch a new access token from Reddit.
     */
    private function fetchNewAccessToken(): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->withHeaders(['User-Agent' => $this->userAgent])
            ->asForm()
            ->post(config('reddit.endpoints.oauth_token'), [
                'grant_type' => 'password',
                'username' => $this->username,
                'password' => $this->password,
                'scope' => 'identity read',
            ]);

        if (!$response->successful()) {
            Log::error('Reddit OAuth failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to authenticate with Reddit: ' . $response->body());
        }

        $data = $response->json();

        if (!isset($data['access_token'])) {
            throw new \Exception('No access token in Reddit response');
        }

        return $data['access_token'];
    }

    /**
     * Clear cached access token (for refresh).
     */
    public function clearAccessToken(): void
    {
        Cache::forget('reddit_access_token');
    }

    /**
     * Create an authenticated HTTP client.
     */
    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'User-Agent' => $this->userAgent,
        ])->baseUrl(config('reddit.endpoints.api_base'));
    }

    /**
     * Make a rate-limited request with retry logic.
     */
    private function request(string $method, string $url, array $params = []): array
    {
        $this->rateLimit();

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = match(strtolower($method)) {
                    'get' => $this->client()->get($url, $params),
                    'post' => $this->client()->post($url, $params),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
                };

                if ($response->status() === 429) {
                    $attempt++;
                    $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                    Log::warning("Reddit rate limited, waiting {$delay}ms (attempt {$attempt})");
                    usleep($delay * 1000);
                    continue;
                }

                if ($response->status() === 401) {
                    // Token expired, refresh and retry
                    $this->clearAccessToken();
                    $attempt++;
                    continue;
                }

                if ($response->status() === 403) {
                    throw new \Exception("Access denied to subreddit - it may be private, banned, or quarantined");
                }

                if (!$response->successful()) {
                    throw new \Exception("Reddit API error: {$response->status()} - {$response->body()}");
                }

                return $response->json();
            } catch (RequestException | ConnectionException $e) {
                $lastException = $e;
                $attempt++;
                $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                Log::warning("Reddit request failed, retrying after {$delay}ms", [
                    'attempt' => $attempt,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                usleep($delay * 1000);
            }
        }

        throw $lastException ?? new \Exception('Reddit API request failed after max retries');
    }

    /**
     * Enforce rate limiting between requests.
     */
    private function rateLimit(): void
    {
        if ($this->lastRequestTime !== null) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            if ($elapsed < $this->delayMs) {
                usleep(($this->delayMs - $elapsed) * 1000);
            }
        }
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Fetch posts from a subreddit.
     *
     * @param string $subreddit Subreddit name without r/ prefix
     * @param Carbon|null $after Only fetch posts after this date
     * @param Carbon|null $before Only fetch posts before this date
     * @param string|null $afterCursor Pagination cursor from previous request
     * @param int $limit Number of posts per request (max 100)
     * @return array{posts: Collection<RedditPost>, after: string|null}
     */
    public function getSubredditPosts(
        string $subreddit,
        ?Carbon $after = null,
        ?Carbon $before = null,
        ?string $afterCursor = null,
        int $limit = 100
    ): array {
        $query = [
            'limit' => min($limit, 100),
            'raw_json' => 1,
        ];

        if ($afterCursor) {
            $query['after'] = $afterCursor;
        }

        // Use search endpoint for date filtering
        if ($after || $before) {
            return $this->searchSubredditPosts($subreddit, $after, $before, $afterCursor, $limit);
        }

        $response = $this->request('get', "/r/{$subreddit}/new.json", $query);

        if (!isset($response['data']['children']) || !is_array($response['data']['children'])) {
            Log::warning('Unexpected Reddit API response structure', ['response' => $response]);
            return ['posts' => collect(), 'after' => null];
        }

        $posts = collect($response['data']['children'] ?? [])
            ->map(fn($item) => RedditPost::fromApiResponse($item['data']))
            ->filter(fn($post) => $this->meetsEngagementThreshold($post));

        return [
            'posts' => $posts,
            'after' => $response['data']['after'] ?? null,
        ];
    }

    /**
     * Search subreddit posts with date filtering.
     */
    private function searchSubredditPosts(
        string $subreddit,
        ?Carbon $after,
        ?Carbon $before,
        ?string $afterCursor,
        int $limit
    ): array {
        $query = [
            'limit' => min($limit, 100),
            'sort' => 'new',
            'restrict_sr' => 'true',
            'raw_json' => 1,
        ];

        if ($afterCursor) {
            $query['after'] = $afterCursor;
        }

        // Build timestamp query
        if ($after && $before) {
            // Both bounds: single range query
            $query['q'] = "timestamp:{$after->timestamp}..{$before->timestamp}";
        } elseif ($after) {
            $query['q'] = "timestamp:{$after->timestamp}..";
        } elseif ($before) {
            $query['q'] = "timestamp:..{$before->timestamp}";
        }

        $response = $this->request('get', "/r/{$subreddit}/search.json", $query);

        if (!isset($response['data']['children']) || !is_array($response['data']['children'])) {
            Log::warning('Unexpected Reddit API response structure', ['response' => $response]);
            return ['posts' => collect(), 'after' => null];
        }

        $posts = collect($response['data']['children'] ?? [])
            ->map(fn($item) => RedditPost::fromApiResponse($item['data']))
            ->filter(fn($post) => $this->meetsEngagementThreshold($post));

        return [
            'posts' => $posts,
            'after' => $response['data']['after'] ?? null,
        ];
    }

    /**
     * Check if a post meets minimum engagement thresholds.
     */
    private function meetsEngagementThreshold(RedditPost $post): bool
    {
        $minUpvotes = config('reddit.fetch.min_upvotes', 5);
        $minComments = config('reddit.fetch.min_comments', 3);

        return $post->upvotes >= $minUpvotes && $post->numComments >= $minComments;
    }

    /**
     * Fetch comments for a post.
     *
     * @param string $subreddit Subreddit name
     * @param string $postId Reddit post ID (without t3_ prefix)
     * @param int $depth Maximum comment depth to fetch
     * @param int $limit Maximum comments to fetch
     * @return Collection<RedditComment>
     */
    public function getPostComments(
        string $subreddit,
        string $postId,
        int $depth = 1,
        int $limit = 100
    ): Collection {
        $query = [
            'depth' => $depth + 1, // API depth is 0-indexed
            'limit' => $limit,
            'sort' => 'top',
            'raw_json' => 1,
        ];

        $response = $this->request('get', "/r/{$subreddit}/comments/{$postId}.json", $query);

        // Response is an array: [post, comments]
        if (!isset($response[1]['data']['children']) || !is_array($response[1]['data']['children'])) {
            Log::warning('Unexpected Reddit comments API response structure', ['response' => $response]);
            return collect();
        }

        $commentsData = $response[1]['data']['children'] ?? [];

        return $this->flattenComments($commentsData, $depth);
    }

    /**
     * Flatten nested comment tree to a collection.
     */
    private function flattenComments(array $comments, int $maxDepth, int $currentDepth = 0): Collection
    {
        $result = collect();

        foreach ($comments as $item) {
            if (($item['kind'] ?? '') !== 't1') {
                continue; // Skip non-comment items (like "more" links)
            }

            $comment = RedditComment::fromApiResponse($item['data'], $currentDepth);
            $result->push($comment);

            // Recursively process replies if within depth limit
            if ($currentDepth < $maxDepth && isset($item['data']['replies']['data']['children'])) {
                $replies = $this->flattenComments(
                    $item['data']['replies']['data']['children'],
                    $maxDepth,
                    $currentDepth + 1
                );
                $result = $result->merge($replies);
            }
        }

        return $result;
    }

    /**
     * Verify credentials are valid by making a test request.
     */
    public function verifyCredentials(): bool
    {
        try {
            $this->getAccessToken();
            $response = $this->request('get', '/api/v1/me');
            return isset($response['name']);
        } catch (\Exception $e) {
            Log::error('Reddit credential verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get information about a subreddit.
     */
    public function getSubredditInfo(string $subreddit): array
    {
        $response = $this->request('get', "/r/{$subreddit}/about.json");
        return $response['data'] ?? [];
    }
}
