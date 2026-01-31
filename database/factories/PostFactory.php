<?php

namespace Database\Factories;

use App\Models\Scan;
use App\Models\Subreddit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subreddit = Subreddit::factory();

        return [
            'subreddit_id' => $subreddit,
            'scan_id' => Scan::factory()->for($subreddit),
            'reddit_id' => $this->faker->unique()->regexify('[a-z0-9]{6}'),
            'reddit_fullname' => 't3_'.$this->faker->unique()->regexify('[a-z0-9]{6}'),
            'title' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'author' => $this->faker->userName(),
            'permalink' => '/r/test/comments/'.$this->faker->regexify('[a-z0-9]{6}'),
            'url' => $this->faker->url(),
            'upvotes' => $this->faker->numberBetween(0, 1000),
            'downvotes' => $this->faker->numberBetween(0, 100),
            'num_comments' => $this->faker->numberBetween(0, 500),
            'upvote_ratio' => $this->faker->randomFloat(2, 0.5, 1.0),
            'is_self' => true,
            'is_nsfw' => false,
            'is_spoiler' => false,
            'reddit_created_at' => $this->faker->dateTime(),
            'fetched_at' => $this->faker->dateTime(),
        ];
    }
}
