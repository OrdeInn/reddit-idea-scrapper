<?php

namespace Tests\Unit\Models;

use App\Models\Classification;
use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassificationResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_classification(): void
    {
        $classification = Classification::factory()->create();
        $result = ClassificationResult::factory()->create([
            'classification_id' => $classification->id,
        ]);

        $this->assertInstanceOf(Classification::class, $result->classification);
        $this->assertEquals($classification->id, $result->classification->id);
    }

    public function test_is_keep_returns_true_for_keep_verdict(): void
    {
        $result = ClassificationResult::factory()->keep()->make();

        $this->assertTrue($result->isKeep());
    }

    public function test_is_keep_returns_false_for_skip_verdict(): void
    {
        $result = ClassificationResult::factory()->skip()->make();

        $this->assertFalse($result->isKeep());
    }

    public function test_completed_scope_filters_correctly(): void
    {
        $classification = Classification::factory()->create();

        ClassificationResult::factory()->keep()->forProvider('provider-a')->create(['classification_id' => $classification->id]);
        ClassificationResult::factory()->skip()->forProvider('provider-b')->create(['classification_id' => $classification->id]);
        ClassificationResult::factory()->incomplete()->forProvider('provider-c')->create(['classification_id' => $classification->id]);

        $completedResults = ClassificationResult::completed()->get();

        $this->assertCount(2, $completedResults);
        $completedResults->each(fn ($r) => $this->assertTrue($r->completed));
    }

    public function test_for_provider_scope_filters_correctly(): void
    {
        $classification1 = Classification::factory()->create();
        $classification2 = Classification::factory()->create();

        ClassificationResult::factory()->forProvider('anthropic-haiku')->create(['classification_id' => $classification1->id]);
        ClassificationResult::factory()->forProvider('openai-gpt5-mini')->create(['classification_id' => $classification1->id]);
        ClassificationResult::factory()->forProvider('anthropic-haiku')->create(['classification_id' => $classification2->id]);

        $haikuResults = ClassificationResult::forProvider('anthropic-haiku')->get();

        $this->assertCount(2, $haikuResults);
        $haikuResults->each(fn ($r) => $this->assertEquals('anthropic-haiku', $r->provider_name));
    }
}
