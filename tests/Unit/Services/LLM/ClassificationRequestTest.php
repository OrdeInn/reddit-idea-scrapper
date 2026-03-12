<?php

namespace Tests\Unit\Services\LLM;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use App\Services\LLM\DTOs\ClassificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_post_filters_noise_and_promotional_comments(): void
    {
        $subreddit = Subreddit::factory()->create(['name' => 'shopify']);
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        Comment::factory()->topLevel()->create([
            'post_id' => $post->id,
            'author' => 'AutoModerator',
            'body' => 'Read the rules before posting.',
            'upvotes' => 20,
        ]);

        Comment::factory()->topLevel()->create([
            'post_id' => $post->id,
            'author' => 'builder123',
            'body' => 'I built an app for this exact problem. Check out https://example.com',
            'upvotes' => 30,
        ]);

        Comment::factory()->topLevel()->create([
            'post_id' => $post->id,
            'author' => 'operator1',
            'body' => 'We have the same issue and still track it manually in spreadsheets every week.',
            'upvotes' => 12,
        ]);

        $request = ClassificationRequest::fromPost($post);
        $formattedComments = $request->getFormattedComments();

        $this->assertStringContainsString('operator1', $formattedComments);
        $this->assertStringNotContainsString('AutoModerator', $formattedComments);
        $this->assertStringNotContainsString('Check out https://example.com', $formattedComments);
    }

    public function test_from_post_keeps_non_promotional_links_and_applies_same_rules_when_comments_are_preloaded(): void
    {
        $subreddit = Subreddit::factory()->create(['name' => 'shopify']);
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create([
            'subreddit_id' => $subreddit->id,
            'scan_id' => $scan->id,
        ]);

        $topLevel = Comment::factory()->topLevel()->create([
            'post_id' => $post->id,
            'author' => 'operator2',
            'body' => 'Cloudflare docs helped a bit: https://developers.cloudflare.com but we still have this problem.',
            'upvotes' => 9,
        ]);

        Comment::factory()->reply($topLevel->reddit_id)->create([
            'post_id' => $post->id,
            'author' => 'reply-user',
            'body' => 'Nested reply should not be included.',
            'upvotes' => 50,
        ]);

        $loadedPost = Post::with('comments', 'subreddit')->findOrFail($post->id);
        $request = ClassificationRequest::fromPost($loadedPost);
        $formattedComments = $request->getFormattedComments();

        $this->assertStringContainsString('Cloudflare docs helped a bit', $formattedComments);
        $this->assertStringNotContainsString('Nested reply should not be included', $formattedComments);
    }
}
