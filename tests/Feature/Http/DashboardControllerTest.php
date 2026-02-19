<?php

namespace Tests\Feature\Http;

use App\Models\Idea;
use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_loads_with_inertia_component(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
        );
    }

    public function test_dashboard_returns_subreddits_list(): void
    {
        Subreddit::factory()->count(3)->create();

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('subreddits', 3)
        );
    }

    public function test_dashboard_returns_stats_with_correct_keys(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('stats.total_subreddits')
            ->has('stats.total_ideas')
            ->has('stats.starred_count')
        );
    }

    public function test_dashboard_counts_starred_ideas_correctly(): void
    {
        $subreddit = Subreddit::factory()->create();
        $scan = Scan::factory()->create(['subreddit_id' => $subreddit->id]);
        $post = Post::factory()->create(['subreddit_id' => $subreddit->id, 'scan_id' => $scan->id]);

        Idea::factory()->count(2)->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'is_starred' => true]);
        Idea::factory()->count(3)->create(['post_id' => $post->id, 'scan_id' => $scan->id, 'is_starred' => false]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.starred_count', 2)
        );
    }

    public function test_dashboard_shows_active_scan_state_for_subreddits(): void
    {
        $subreddit = Subreddit::factory()->create();
        Scan::factory()->create([
            'subreddit_id' => $subreddit->id,
            'status' => Scan::STATUS_FETCHING,
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('subreddits', 1, fn (Assert $subreddit) => $subreddit
                ->where('has_active_scan', true)
                ->etc()
            )
        );
    }
}
