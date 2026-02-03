<?php

namespace Tests\Feature;

use App\Services\Reddit\RedditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'reddit.client_id' => 'test-client-id',
            'reddit.client_secret' => 'test-client-secret',
            'reddit.username' => 'test-user',
            'reddit.password' => 'test-pass',
            'reddit.user_agent' => 'TestAgent/1.0',
            'reddit.rate_limit.delay_between_requests_ms' => 0,
            'reddit.rate_limit.retry_delay_ms' => 0,
            'reddit.rate_limit.max_retries' => 3,
        ]);

        Cache::forget('reddit_access_token');
    }

    public function test_can_authenticate_with_reddit(): void
    {
        Http::fake([
            config('reddit.endpoints.oauth_token') => Http::response(['access_token' => 'test_token'], 200),
            rtrim(config('reddit.endpoints.api_base'), '/') . '/api/v1/me' => Http::response(['name' => 'tester'], 200),
        ]);

        $service = app(RedditService::class);
        $service->clearAccessToken();
        $this->assertTrue($service->verifyCredentials());
    }

    public function test_can_fetch_posts_from_subreddit(): void
    {
        Http::fake([
            config('reddit.endpoints.oauth_token') => Http::response(['access_token' => 'test_token'], 200),
            rtrim(config('reddit.endpoints.api_base'), '/') . '/r/test/new.json*' => Http::response([
                'data' => [
                    'after' => null,
                    'children' => [
                        [
                            'kind' => 't3',
                            'data' => [
                                'id' => 'abc123',
                                'name' => 't3_abc123',
                                'subreddit' => 'test',
                                'title' => 'Test Post',
                                'selftext' => 'Body',
                                'author' => 'tester',
                                'permalink' => '/r/test/comments/abc123/test_post',
                                'url' => null,
                                'ups' => 10,
                                'downs' => 0,
                                'num_comments' => 3,
                                'upvote_ratio' => 1.0,
                                'link_flair_text' => null,
                                'is_self' => true,
                                'over_18' => false,
                                'spoiler' => false,
                                'created_utc' => time(),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(RedditService::class);
        $service->clearAccessToken();
        $result = $service->getSubredditPosts('test', limit: 10);

        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('after', $result);
    }

    public function test_handles_rate_limiting(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['access_token' => 'test_token'], 200)
                ->push([], 429) // Rate limited
                ->push(['data' => ['after' => null, 'children' => []]], 200), // Success after retry
        ]);

        $service = app(RedditService::class);
        $service->clearAccessToken();
        // This should not throw an exception
        $result = $service->getSubredditPosts('test');

        $this->assertIsArray($result);
    }
}
