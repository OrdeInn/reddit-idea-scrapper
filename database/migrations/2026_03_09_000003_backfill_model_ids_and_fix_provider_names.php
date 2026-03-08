<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Known mapping from classification_results.provider_name → model_id.
     * Based on Config Key Immutability Rule: each config key is permanently bound to a specific model.
     */
    private array $classificationModelMap = [
        'anthropic-haiku'   => 'claude-haiku-4-5-20251001',
        'openai-gpt5-mini'  => 'gpt-5-mini-2025-08-07',
        'anthropic-sonnet'  => 'claude-sonnet-4-5-20250929',
    ];

    /**
     * Known mapping from ideas.extraction_provider → [new_provider, extraction_model_id].
     * Also fixes the ambiguous "openai" value that was stored before this refactor.
     */
    private array $extractionProviderMap = [
        'anthropic-sonnet' => ['provider' => 'anthropic-sonnet', 'model_id' => 'claude-sonnet-4-5-20250929'],
        'openai'           => ['provider' => 'openai-gpt5-mini', 'model_id' => 'gpt-5-mini-2025-08-07'],
        'openai-gpt5-mini' => ['provider' => 'openai-gpt5-mini', 'model_id' => 'gpt-5-mini-2025-08-07'],
        'openai-gpt5-2'    => ['provider' => 'openai-gpt5-2',   'model_id' => 'gpt-5.2-2026-01-24'],
        'anthropic-opus'   => ['provider' => 'anthropic-opus',  'model_id' => 'claude-opus-4-6'],
    ];

    public function up(): void
    {
        // 1. Backfill model_id on classification_results
        foreach ($this->classificationModelMap as $providerName => $modelId) {
            DB::table('classification_results')
                ->where('provider_name', $providerName)
                ->whereNull('model_id')
                ->update(['model_id' => $modelId]);
        }

        // 2. Fix ambiguous extraction_provider = 'openai' → 'openai-gpt5-mini'
        DB::table('ideas')
            ->where('extraction_provider', 'openai')
            ->update(['extraction_provider' => 'openai-gpt5-mini']);

        // 3. Backfill extraction_model_id on ideas
        foreach ($this->extractionProviderMap as $oldProvider => $mapping) {
            DB::table('ideas')
                ->where('extraction_provider', $mapping['provider'])
                ->whereNull('extraction_model_id')
                ->update(['extraction_model_id' => $mapping['model_id']]);
        }
    }

    public function down(): void
    {
        // This data migration's down() is intentionally a no-op.
        //
        // Rationale: once the new application code is live, new rows will also use
        // 'openai-gpt5-mini' as extraction_provider and populate model_id columns —
        // making it impossible to distinguish backfilled rows from legitimately-created rows
        // without a persistent marker. Reverting blindly would corrupt new data.
        //
        // The model_id / extraction_model_id columns themselves are dropped by the schema
        // migration (tickets 01-02)'s down() methods, so no manual nulling is needed here.
        Log::warning(
            'Backfill migration rollback is a no-op — cannot distinguish backfilled rows from new rows. ' .
            'Schema columns will be dropped by their respective schema migration down() methods.'
        );
    }
};
