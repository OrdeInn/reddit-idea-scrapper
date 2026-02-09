<?php

namespace Database\Factories;

use App\Models\Classification;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Classification>
 */
class ClassificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'haiku_verdict' => null,
            'haiku_confidence' => null,
            'haiku_category' => null,
            'haiku_reasoning' => null,
            'gpt_verdict' => null,
            'gpt_confidence' => null,
            'gpt_category' => null,
            'gpt_reasoning' => null,
            'combined_score' => null,
            'final_decision' => 'pending',
            'haiku_completed' => false,
            'gpt_completed' => false,
            'classified_at' => null,
        ];
    }

    /**
     * State for incomplete classification (Haiku not done).
     */
    public function haikuIncomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'haiku_verdict' => null,
            'haiku_confidence' => null,
            'haiku_category' => null,
            'haiku_reasoning' => null,
            'haiku_completed' => false,
        ]);
    }

    /**
     * State for incomplete classification (GPT not done).
     */
    public function gptIncomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'gpt_verdict' => null,
            'gpt_confidence' => null,
            'gpt_category' => null,
            'gpt_reasoning' => null,
            'gpt_completed' => false,
        ]);
    }

    /**
     * State for a "keep" decision.
     */
    public function keep(): static
    {
        return $this->state(fn (array $attributes) => [
            'haiku_verdict' => 'keep',
            'gpt_verdict' => 'keep',
            'haiku_confidence' => 0.9,
            'gpt_confidence' => 0.9,
            'haiku_category' => $this->faker->randomElement([
                Classification::CATEGORY_GENUINE_PROBLEM,
                Classification::CATEGORY_TOOL_REQUEST,
            ]),
            'gpt_category' => $this->faker->randomElement([
                Classification::CATEGORY_GENUINE_PROBLEM,
                Classification::CATEGORY_TOOL_REQUEST,
            ]),
            'haiku_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_KEEP,
            'combined_score' => 0.9,
            'haiku_completed' => true,
            'gpt_completed' => true,
            'classified_at' => now(),
        ]);
    }

    /**
     * State for a "discard" decision.
     */
    public function discard(): static
    {
        return $this->state(fn (array $attributes) => [
            'haiku_verdict' => 'skip',
            'gpt_verdict' => 'skip',
            'haiku_confidence' => 0.9,
            'gpt_confidence' => 0.9,
            'haiku_category' => Classification::CATEGORY_SPAM,
            'gpt_category' => Classification::CATEGORY_SPAM,
            'haiku_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_DISCARD,
            'combined_score' => 0.0,
            'haiku_completed' => true,
            'gpt_completed' => true,
            'classified_at' => now(),
        ]);
    }

    /**
     * State for a "borderline" decision.
     */
    public function borderline(): static
    {
        return $this->state(fn (array $attributes) => [
            'haiku_verdict' => 'keep',
            'gpt_verdict' => 'skip',
            'haiku_confidence' => 0.5,
            'gpt_confidence' => 0.5,
            'haiku_category' => Classification::CATEGORY_ADVICE_THREAD,
            'gpt_category' => Classification::CATEGORY_RANT,
            'haiku_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_BORDERLINE,
            'combined_score' => 0.25,
            'haiku_completed' => true,
            'gpt_completed' => true,
            'classified_at' => now(),
        ]);
    }
}
