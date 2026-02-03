<?php

namespace Tests\Feature\Http;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_ideas_for_subreddit(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);
        Idea::factory()->count(3)->create(['post_id' => $post->id, 'scan_id' => $scan->id]);

        $response = $this->getJson(route('ideas.index', $subreddit));

        $response->assertOk();
        $response->assertJsonCount(3, 'ideas');
    }

    public function test_can_filter_ideas_by_min_score(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 2]);
        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 4]);
        Idea::factory()->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'score_overall' => 5]);

        $response = $this->getJson(route('ideas.index', $subreddit) . '?min_score=4');

        $response->assertOk();
        $response->assertJsonCount(2, 'ideas');
    }

    public function test_can_toggle_idea_star(): void
    {
        $idea = Idea::factory()->create(['is_starred' => false]);

        $response = $this->postJson(route('ideas.star', $idea));

        $response->assertOk();
        $response->assertJson(['is_starred' => true]);

        $this->assertTrue($idea->fresh()->is_starred);
    }

    public function test_starred_page_loads(): void
    {
        $this->withoutVite();

        // Make request as Inertia request
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
        ])->get(route('ideas.starred'));

        $response->assertOk();
        // Verify Inertia response by checking for Inertia headers and JSON structure
        $response->assertHeader('X-Inertia');
        $response->assertJsonStructure(['component', 'props', 'url', 'version']);
        $this->assertEquals('Starred', $response->json('component'));
    }
}
