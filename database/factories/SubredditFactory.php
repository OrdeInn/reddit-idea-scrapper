<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subreddit>
 */
class SubredditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(2).'_'.$this->faker->unique()->numberBetween(1, 100000),
            'last_scanned_at' => $this->faker->dateTime(),
        ];
    }
}
