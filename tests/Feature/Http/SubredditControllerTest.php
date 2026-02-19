<?php

namespace Tests\Feature\Http;

use App\Models\Subreddit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubredditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_store_subreddit(): void
    {
        $response = $this->post(route('subreddit.store'), [
            'name' => 'startups',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('subreddits', ['name' => 'startups']);
    }

    public function test_normalizes_subreddit_name_strips_r_prefix_and_lowercases(): void
    {
        $this->post(route('subreddit.store'), ['name' => 'r/SaaS']);

        $this->assertDatabaseHas('subreddits', ['name' => 'saas']);
        $this->assertDatabaseMissing('subreddits', ['name' => 'r/SaaS']);
    }

    public function test_strips_whitespace_from_subreddit_name(): void
    {
        $this->post(route('subreddit.store'), ['name' => '  smallbusiness  ']);

        $this->assertDatabaseHas('subreddits', ['name' => 'smallbusiness']);
    }

    public function test_redirects_to_subreddit_page_after_store(): void
    {
        $this->withoutVite();

        $response = $this->post(route('subreddit.store'), ['name' => 'indiehackers']);

        $subreddit = Subreddit::where('name', 'indiehackers')->first();
        $this->assertNotNull($subreddit);

        $response->assertRedirect(route('subreddit.show', $subreddit));
    }

    public function test_redirects_to_existing_subreddit_if_already_exists(): void
    {
        $existing = Subreddit::factory()->create(['name' => 'startups']);

        $response = $this->post(route('subreddit.store'), ['name' => 'startups']);

        $response->assertRedirect(route('subreddit.show', $existing));
        $this->assertEquals(1, Subreddit::where('name', 'startups')->count());
    }

    public function test_can_delete_subreddit_and_redirects_to_dashboard(): void
    {
        $subreddit = Subreddit::factory()->create();

        $response = $this->delete(route('subreddit.destroy', $subreddit));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('subreddits', ['id' => $subreddit->id]);
    }

    public function test_can_view_subreddit_page(): void
    {
        $subreddit = Subreddit::factory()->create();

        $response = $this->get(route('subreddit.show', $subreddit));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Subreddit/Show')
        );
    }

    public function test_subreddit_page_contains_subreddit_data(): void
    {
        $subreddit = Subreddit::factory()->create(['name' => 'testsubreddit']);

        $response = $this->get(route('subreddit.show', $subreddit));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Subreddit/Show')
            ->where('subreddit.name', 'testsubreddit')
        );
    }

    public function test_store_validates_name_is_required(): void
    {
        $response = $this->post(route('subreddit.store'), []);

        $response->assertSessionHasErrors(['name']);
    }

    public function test_store_validates_name_max_length(): void
    {
        $response = $this->post(route('subreddit.store'), [
            'name' => str_repeat('a', 101),
        ]);

        $response->assertSessionHasErrors(['name']);
    }
}
