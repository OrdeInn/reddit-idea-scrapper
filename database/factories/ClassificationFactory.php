<?php

namespace Database\Factories;

use App\Models\Classification;
use App\Models\ClassificationResult;
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
            'combined_score' => null,
            'final_decision' => 'pending',
            'expected_provider_count' => 2,
            'classified_at' => null,
        ];
    }

    /**
     * State for a "keep" decision.
     */
    public function keep(): static
    {
        return $this->state(fn (array $attributes) => [
            'final_decision' => Classification::DECISION_KEEP,
            'combined_score' => 0.9,
            'expected_provider_count' => 2,
            'classified_at' => now(),
        ])->afterCreating(function (Classification $classification) {
            ClassificationResult::factory()
                ->keep()
                ->forProvider('test-provider-1')
                ->create(['classification_id' => $classification->id]);

            ClassificationResult::factory()
                ->keep()
                ->forProvider('test-provider-2')
                ->create(['classification_id' => $classification->id]);
        });
    }

    /**
     * State for a "discard" decision.
     */
    public function discard(): static
    {
        return $this->state(fn (array $attributes) => [
            'final_decision' => Classification::DECISION_DISCARD,
            'combined_score' => 0.0,
            'expected_provider_count' => 2,
            'classified_at' => now(),
        ])->afterCreating(function (Classification $classification) {
            ClassificationResult::factory()
                ->skip()
                ->forProvider('test-provider-1')
                ->create(['classification_id' => $classification->id]);

            ClassificationResult::factory()
                ->skip()
                ->forProvider('test-provider-2')
                ->create(['classification_id' => $classification->id]);
        });
    }

    /**
     * State for a "borderline" decision (one keep, one skip).
     */
    public function borderline(): static
    {
        return $this->state(fn (array $attributes) => [
            'final_decision' => Classification::DECISION_BORDERLINE,
            'combined_score' => 0.25,
            'expected_provider_count' => 2,
            'classified_at' => now(),
        ])->afterCreating(function (Classification $classification) {
            ClassificationResult::factory()
                ->keep()
                ->forProvider('test-provider-1')
                ->state(['confidence' => 0.5, 'category' => Classification::CATEGORY_ADVICE_THREAD])
                ->create(['classification_id' => $classification->id]);

            ClassificationResult::factory()
                ->skip()
                ->forProvider('test-provider-2')
                ->state(['confidence' => 0.5, 'category' => Classification::CATEGORY_RANT])
                ->create(['classification_id' => $classification->id]);
        });
    }

    /**
     * State with a specific number of keep and skip results.
     */
    public function withResults(int $keepCount, int $skipCount): static
    {
        $total = $keepCount + $skipCount;
        return $this->state(fn (array $attributes) => [
            'expected_provider_count' => $total,
        ])->afterCreating(function (Classification $classification) use ($keepCount, $skipCount) {
            for ($i = 0; $i < $keepCount; $i++) {
                ClassificationResult::factory()
                    ->keep()
                    ->forProvider("test-provider-keep-{$i}")
                    ->create(['classification_id' => $classification->id]);
            }

            for ($i = 0; $i < $skipCount; $i++) {
                ClassificationResult::factory()
                    ->skip()
                    ->forProvider("test-provider-skip-{$i}")
                    ->create(['classification_id' => $classification->id]);
            }
        });
    }

    /**
     * State with specific provider names (creates incomplete results for each).
     */
    public function withProviders(array $providerNames): static
    {
        return $this->state(fn (array $attributes) => [
            'expected_provider_count' => count($providerNames),
        ])->afterCreating(function (Classification $classification) use ($providerNames) {
            foreach ($providerNames as $name) {
                ClassificationResult::factory()
                    ->forProvider($name)
                    ->create(['classification_id' => $classification->id]);
            }
        });
    }
}
