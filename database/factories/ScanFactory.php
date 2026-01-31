<?php

namespace Database\Factories;

use App\Models\Subreddit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Scan>
 */
class ScanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subreddit_id' => Subreddit::factory(),
            'scan_type' => 'initial',
            'status' => 'completed',
            'posts_fetched' => $this->faker->numberBetween(10, 100),
            'posts_classified' => $this->faker->numberBetween(10, 100),
            'posts_extracted' => $this->faker->numberBetween(5, 50),
            'ideas_found' => $this->faker->numberBetween(1, 10),
            'started_at' => $this->faker->dateTime(),
            'completed_at' => $this->faker->dateTime(),
        ];
    }
}
