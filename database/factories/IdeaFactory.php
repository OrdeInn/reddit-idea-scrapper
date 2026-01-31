<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Idea>
 */
class IdeaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subreddit = Subreddit::factory();
        $scan = Scan::factory()->for($subreddit);

        return [
            'post_id' => Post::factory()->for($scan)->for($subreddit),
            'scan_id' => $scan,
            'idea_title' => $this->faker->sentence(),
            'problem_statement' => $this->faker->paragraph(),
            'proposed_solution' => $this->faker->paragraph(),
            'target_audience' => $this->faker->sentence(),
            'why_small_team_viable' => $this->faker->sentence(),
            'demand_evidence' => $this->faker->sentence(),
            'monetization_model' => $this->faker->sentence(),
            'branding_suggestions' => [
                'name_ideas' => [$this->faker->company(), $this->faker->company()],
                'tagline' => $this->faker->catchPhrase(),
                'positioning' => $this->faker->sentence(),
            ],
            'marketing_channels' => [$this->faker->word(), $this->faker->word()],
            'existing_competitors' => [$this->faker->company(), $this->faker->company()],
            'scores' => [
                'monetization_reasoning' => $this->faker->sentence(),
                'saturation_reasoning' => $this->faker->sentence(),
                'complexity_reasoning' => $this->faker->sentence(),
                'demand_reasoning' => $this->faker->sentence(),
                'overall_reasoning' => $this->faker->sentence(),
            ],
            'score_monetization' => $this->faker->numberBetween(1, 5),
            'score_saturation' => $this->faker->numberBetween(1, 5),
            'score_complexity' => $this->faker->numberBetween(1, 5),
            'score_demand' => $this->faker->numberBetween(1, 5),
            'score_overall' => $this->faker->numberBetween(1, 5),
            'source_quote' => $this->faker->paragraph(),
            'classification_status' => 'keep',
            'is_starred' => false,
            'starred_at' => null,
        ];
    }
}
