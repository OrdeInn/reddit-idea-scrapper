<?php

namespace Database\Factories;

use App\Models\Classification;
use App\Models\ClassificationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClassificationResult>
 */
class ClassificationResultFactory extends Factory
{
    protected $model = ClassificationResult::class;

    public function definition(): array
    {
        return [
            'classification_id' => Classification::factory(),
            'provider_name' => 'test-provider',
            'verdict' => null,
            'confidence' => null,
            'category' => null,
            'reasoning' => null,
            'completed' => false,
            'completed_at' => null,
        ];
    }

    public function keep(): static
    {
        return $this->state(fn (array $attributes) => [
            'verdict' => 'keep',
            'confidence' => 0.9,
            'category' => $this->faker->randomElement([
                Classification::CATEGORY_GENUINE_PROBLEM,
                Classification::CATEGORY_TOOL_REQUEST,
            ]),
            'reasoning' => $this->faker->sentence(),
            'completed' => true,
            'completed_at' => now(),
        ]);
    }

    public function skip(): static
    {
        return $this->state(fn (array $attributes) => [
            'verdict' => 'skip',
            'confidence' => 0.9,
            'category' => $this->faker->randomElement([
                Classification::CATEGORY_SPAM,
                Classification::CATEGORY_RANT,
            ]),
            'reasoning' => $this->faker->sentence(),
            'completed' => true,
            'completed_at' => now(),
        ]);
    }

    public function forProvider(string $name): static
    {
        return $this->state(fn (array $attributes) => ['provider_name' => $name]);
    }

    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed' => false,
            'verdict' => null,
            'confidence' => null,
            'completed_at' => null,
        ]);
    }
}
