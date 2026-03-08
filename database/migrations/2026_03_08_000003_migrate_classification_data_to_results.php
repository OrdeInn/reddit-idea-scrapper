<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Set expected_provider_count=2 for all existing classifications
        DB::table('classifications')->chunkById(500, function ($classifications) {
            $ids = $classifications->pluck('id')->toArray();
            DB::table('classifications')->whereIn('id', $ids)->update([
                'expected_provider_count' => 2,
            ]);
        });

        // Migrate haiku_x and gpt_x columns to classification_results table
        DB::table('classifications')->chunkById(500, function ($classifications) {
            $rows = [];

            foreach ($classifications as $classification) {
                // Migrate haiku provider data
                if ($classification->haiku_verdict !== null || $classification->haiku_completed) {
                    $rows[] = [
                        'classification_id' => $classification->id,
                        'provider_name'     => 'anthropic-haiku',
                        'verdict'           => $classification->haiku_verdict,
                        'confidence'        => $classification->haiku_confidence,
                        'category'          => $classification->haiku_category,
                        'reasoning'         => $classification->haiku_reasoning,
                        'completed'         => $classification->haiku_completed,
                        'completed_at'      => $classification->haiku_completed ? $classification->classified_at : null,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                }

                // Migrate gpt provider data
                if ($classification->gpt_verdict !== null || $classification->gpt_completed) {
                    $rows[] = [
                        'classification_id' => $classification->id,
                        'provider_name'     => 'openai-gpt5-mini',
                        'verdict'           => $classification->gpt_verdict,
                        'confidence'        => $classification->gpt_confidence,
                        'category'          => $classification->gpt_category,
                        'reasoning'         => $classification->gpt_reasoning,
                        'completed'         => $classification->gpt_completed,
                        'completed_at'      => $classification->gpt_completed ? $classification->classified_at : null,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];
                }
            }

            if (! empty($rows)) {
                DB::table('classification_results')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('classification_results')->truncate();
    }
};
