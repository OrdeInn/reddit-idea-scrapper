<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Comment>
 */
class CommentFactory extends Factory
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
            'reddit_id' => $this->faker->unique()->regexify('[a-z0-9]{7}'),
            'reddit_fullname' => 't1_'.$this->faker->unique()->regexify('[a-z0-9]{7}'),
            'parent_reddit_id' => null,
            'body' => $this->faker->paragraph(),
            'author' => $this->faker->userName(),
            'upvotes' => $this->faker->numberBetween(0, 500),
            'downvotes' => $this->faker->numberBetween(0, 50),
            'depth' => 0,
            'reddit_created_at' => $this->faker->dateTime(),
            'fetched_at' => now(),
        ];
    }

    /**
     * State for a reply comment (not top-level).
     */
    public function reply(string $parentRedditId): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_reddit_id' => $parentRedditId,
            'depth' => 1,
        ]);
    }

    /**
     * State for a top-level comment.
     */
    public function topLevel(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_reddit_id' => null,
            'depth' => 0,
        ]);
    }
}
