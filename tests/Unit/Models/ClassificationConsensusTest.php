<?php

namespace Tests\Unit\Models;

use App\Models\Classification;
use App\Models\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ClassificationConsensusTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // calculateConsensusScore tests
    // -------------------------------------------------------------------------

    public function test_consensus_score_with_two_providers_both_keep(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.9],
            ['verdict' => 'keep', 'confidence' => 0.8],
        ]);

        $score = Classification::calculateConsensusScore($results);

        $this->assertEqualsWithDelta((0.9 + 0.8) / 2, $score, 0.001);
    }

    public function test_consensus_score_with_two_providers_both_skip(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'skip', 'confidence' => 0.9],
            ['verdict' => 'skip', 'confidence' => 0.8],
        ]);

        $score = Classification::calculateConsensusScore($results);

        $this->assertEqualsWithDelta(0.0, $score, 0.001);
    }

    public function test_consensus_score_with_two_providers_disagree(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.8],
            ['verdict' => 'skip', 'confidence' => 0.9],
        ]);

        $score = Classification::calculateConsensusScore($results);

        // Only keep contributes: 0.8 / 2 = 0.4
        $this->assertEqualsWithDelta(0.4, $score, 0.001);
    }

    public function test_consensus_score_with_three_providers_all_keep(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.9],
            ['verdict' => 'keep', 'confidence' => 0.8],
            ['verdict' => 'keep', 'confidence' => 0.7],
        ]);

        $score = Classification::calculateConsensusScore($results);

        $this->assertEqualsWithDelta((0.9 + 0.8 + 0.7) / 3, $score, 0.001);
    }

    public function test_consensus_score_with_three_providers_two_keep_one_skip(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.9],
            ['verdict' => 'keep', 'confidence' => 0.8],
            ['verdict' => 'skip', 'confidence' => 0.7],
        ]);

        $score = Classification::calculateConsensusScore($results);

        // (0.9 + 0.8 + 0) / 3
        $this->assertEqualsWithDelta((0.9 + 0.8) / 3, $score, 0.001);
    }

    public function test_consensus_score_with_single_provider_keep(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.85],
        ]);

        $score = Classification::calculateConsensusScore($results);

        $this->assertEqualsWithDelta(0.85, $score, 0.001);
    }

    public function test_consensus_score_with_zero_results(): void
    {
        $score = Classification::calculateConsensusScore(collect());

        $this->assertEquals(0.0, $score);
    }

    // -------------------------------------------------------------------------
    // checkShortcutRule tests
    // -------------------------------------------------------------------------

    public function test_shortcut_rule_all_agree_keep_high_confidence(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.95],
            ['verdict' => 'keep', 'confidence' => 0.9],
        ]);

        $decision = Classification::checkShortcutRule($results, 0.8);

        $this->assertEquals(Classification::DECISION_KEEP, $decision);
    }

    public function test_shortcut_rule_all_agree_skip_high_confidence(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'skip', 'confidence' => 0.95],
            ['verdict' => 'skip', 'confidence' => 0.9],
        ]);

        $decision = Classification::checkShortcutRule($results, 0.8);

        $this->assertEquals(Classification::DECISION_DISCARD, $decision);
    }

    public function test_shortcut_rule_one_disagrees(): void
    {
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.95],
            ['verdict' => 'skip', 'confidence' => 0.9],
        ]);

        $decision = Classification::checkShortcutRule($results, 0.8);

        $this->assertNull($decision);
    }

    public function test_shortcut_rule_low_confidence(): void
    {
        // All agree but below threshold
        $results = $this->makeResults([
            ['verdict' => 'keep', 'confidence' => 0.7],
            ['verdict' => 'keep', 'confidence' => 0.75],
        ]);

        $decision = Classification::checkShortcutRule($results, 0.8);

        $this->assertNull($decision);
    }

    // -------------------------------------------------------------------------
    // isComplete tests
    // -------------------------------------------------------------------------

    public function test_is_complete_with_all_providers_done(): void
    {
        $classification = Classification::factory()->create(['expected_provider_count' => 2]);

        ClassificationResult::factory()->keep()->forProvider('provider-1')->create([
            'classification_id' => $classification->id,
        ]);
        ClassificationResult::factory()->keep()->forProvider('provider-2')->create([
            'classification_id' => $classification->id,
        ]);

        $this->assertTrue($classification->isComplete());
    }

    public function test_is_complete_with_partial_providers(): void
    {
        $classification = Classification::factory()->create(['expected_provider_count' => 2]);

        ClassificationResult::factory()->keep()->forProvider('provider-1')->create([
            'classification_id' => $classification->id,
        ]);
        ClassificationResult::factory()->incomplete()->forProvider('provider-2')->create([
            'classification_id' => $classification->id,
        ]);

        $this->assertFalse($classification->isComplete());
    }

    // -------------------------------------------------------------------------
    // determineFinalDecision tests
    // -------------------------------------------------------------------------

    public function test_determine_final_decision_thresholds(): void
    {
        $this->assertEquals(Classification::DECISION_KEEP, Classification::determineFinalDecision(0.9));
        $this->assertEquals(Classification::DECISION_KEEP, Classification::determineFinalDecision(0.6));
        $this->assertEquals(Classification::DECISION_BORDERLINE, Classification::determineFinalDecision(0.5));
        $this->assertEquals(Classification::DECISION_BORDERLINE, Classification::determineFinalDecision(0.4));
        $this->assertEquals(Classification::DECISION_DISCARD, Classification::determineFinalDecision(0.39));
        $this->assertEquals(Classification::DECISION_DISCARD, Classification::determineFinalDecision(0.0));
    }

    public function test_process_results_marks_disagreement_as_borderline(): void
    {
        config([
            'llm.classification.consensus_threshold_keep' => 0.6,
            'llm.classification.consensus_threshold_discard' => 0.4,
            'llm.classification.shortcut_confidence' => 0.8,
        ]);

        $classification = Classification::factory()->create([
            'expected_provider_count' => 2,
        ]);

        ClassificationResult::factory()->keep()->forProvider('provider-1')->create([
            'classification_id' => $classification->id,
            'confidence' => 0.95,
        ]);
        ClassificationResult::factory()->skip()->forProvider('provider-2')->create([
            'classification_id' => $classification->id,
            'confidence' => 0.2,
        ]);

        $classification->processResults();
        $classification->refresh();

        $this->assertEquals(Classification::DECISION_BORDERLINE, $classification->final_decision);
        $this->assertEqualsWithDelta(0.475, $classification->combined_score, 0.001);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<array{verdict: string, confidence: float}> $data
     */
    private function makeResults(array $data): Collection
    {
        return collect($data)->map(function (array $item) {
            $result = new ClassificationResult();
            $result->verdict = $item['verdict'];
            $result->confidence = $item['confidence'];
            $result->completed = true;
            return $result;
        });
    }
}
