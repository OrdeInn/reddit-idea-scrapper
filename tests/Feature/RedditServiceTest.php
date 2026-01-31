<?php

namespace Tests\Feature;

use App\Services\Reddit\RedditService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedditServiceTest extends TestCase
{
    public function test_can_authenticate_with_reddit(): void
    {
        // Skip if no credentials configured
        if (empty(config('reddit.client_id'))) {
            $this->markTestSkipped('Reddit credentials not configured');
        }

        $service = app(RedditService::class);
        $this->assertTrue($service->verifyCredentials());
    }

    public function test_can_fetch_posts_from_subreddit(): void
    {
        if (empty(config('reddit.client_id'))) {
            $this->markTestSkipped('Reddit credentials not configured');
        }

        $service = app(RedditService::class);
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
                ->push(['data' => ['children' => []]], 200), // Success after retry
        ]);

        $service = app(RedditService::class);
        // This should not throw an exception
        $result = $service->getSubredditPosts('test');

        $this->assertIsArray($result);
    }
}
