<?php

namespace Tests\Feature;

use App\Models\Idea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListIdeasRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ideas_index_accepts_true_false_query_params_for_boolean_filters(): void
    {
        $idea = Idea::factory()->create();
        $subreddit = $idea->post->subreddit;

        $response = $this->getJson("/subreddits/{$subreddit->id}/ideas?min_score=1&min_complexity=1&starred_only=false&include_borderline=true&sort_by=score_overall&sort_dir=desc&page=1&per_page=20");

        $response
            ->assertOk()
            ->assertJsonStructure([
                'ideas',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }
}

