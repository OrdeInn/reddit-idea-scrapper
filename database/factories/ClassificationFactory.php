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
            'kimi_verdict' => null,
            'kimi_confidence' => null,
            'kimi_category' => null,
            'kimi_reasoning' => null,
            'gpt_verdict' => null,
            'gpt_confidence' => null,
            'gpt_category' => null,
            'gpt_reasoning' => null,
            'combined_score' => null,
            'final_decision' => 'pending',
            'kimi_completed' => false,
            'gpt_completed' => false,
            'classified_at' => null,
        ];
    }

    /**
     * State for incomplete classification (Kimi not done).
     */
    public function kimiIncomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'kimi_verdict' => null,
            'kimi_confidence' => null,
            'kimi_category' => null,
            'kimi_reasoning' => null,
            'kimi_completed' => false,
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
            'kimi_verdict' => 'keep',
            'gpt_verdict' => 'keep',
            'kimi_confidence' => 0.9,
            'gpt_confidence' => 0.9,
            'kimi_category' => $this->faker->randomElement([
                Classification::CATEGORY_GENUINE_PROBLEM,
                Classification::CATEGORY_TOOL_REQUEST,
            ]),
            'gpt_category' => $this->faker->randomElement([
                Classification::CATEGORY_GENUINE_PROBLEM,
                Classification::CATEGORY_TOOL_REQUEST,
            ]),
            'kimi_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_KEEP,
            'combined_score' => 0.9,
            'kimi_completed' => true,
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
            'kimi_verdict' => 'skip',
            'gpt_verdict' => 'skip',
            'kimi_confidence' => 0.9,
            'gpt_confidence' => 0.9,
            'kimi_category' => Classification::CATEGORY_SPAM,
            'gpt_category' => Classification::CATEGORY_SPAM,
            'kimi_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_DISCARD,
            'combined_score' => 0.0,
            'kimi_completed' => true,
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
            'kimi_verdict' => 'keep',
            'gpt_verdict' => 'skip',
            'kimi_confidence' => 0.5,
            'gpt_confidence' => 0.5,
            'kimi_category' => Classification::CATEGORY_ADVICE_THREAD,
            'gpt_category' => Classification::CATEGORY_RANT,
            'kimi_reasoning' => $this->faker->sentence(),
            'gpt_reasoning' => $this->faker->sentence(),
            'final_decision' => Classification::DECISION_BORDERLINE,
            'combined_score' => 0.25,
            'kimi_completed' => true,
            'gpt_completed' => true,
            'classified_at' => now(),
        ]);
    }
}
