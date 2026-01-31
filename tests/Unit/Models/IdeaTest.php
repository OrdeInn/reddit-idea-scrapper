<?php

namespace Tests\Unit\Models;

use App\Models\Idea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaTest extends TestCase
{
    use RefreshDatabase;

    public function test_idea_can_be_starred(): void
    {
        $idea = Idea::factory()->create(['is_starred' => false]);

        $idea->star();

        $this->assertTrue($idea->is_starred);
        $this->assertNotNull($idea->starred_at);
    }

    public function test_idea_can_be_unstarred(): void
    {
        $idea = Idea::factory()->create(['is_starred' => true, 'starred_at' => now()]);

        $idea->unstar();

        $this->assertFalse($idea->is_starred);
        $this->assertNull($idea->starred_at);
    }

    public function test_starred_scope_returns_only_starred_ideas(): void
    {
        Idea::factory()->count(3)->create(['is_starred' => false]);
        Idea::factory()->count(2)->create(['is_starred' => true]);

        $starredIdeas = Idea::starred()->get();

        $this->assertCount(2, $starredIdeas);
    }

    public function test_min_score_scope_filters_correctly(): void
    {
        Idea::factory()->create(['score_overall' => 3]);
        Idea::factory()->create(['score_overall' => 4]);
        Idea::factory()->create(['score_overall' => 5]);

        $highScoreIdeas = Idea::minScore(4)->get();

        $this->assertCount(2, $highScoreIdeas);
    }
}
