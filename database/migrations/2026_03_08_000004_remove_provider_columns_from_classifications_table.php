<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety check: only drop if classification_results table has been populated
        // (i.e., the data migration ran successfully)
        $hasResults = DB::table('classification_results')->exists();

        // Also allow dropping if there are no classifications at all (fresh install)
        $hasClassifications = DB::table('classifications')->exists();

        if ($hasClassifications && ! $hasResults) {
            throw new \RuntimeException(
                'Cannot drop provider columns: classification_results table is empty but classifications exist. '
                . 'Run migration 000003 first to migrate data.'
            );
        }

        Schema::table('classifications', function (Blueprint $table) {
            $table->dropColumn([
                'haiku_verdict',
                'haiku_confidence',
                'haiku_category',
                'haiku_reasoning',
                'haiku_completed',
                'gpt_verdict',
                'gpt_confidence',
                'gpt_category',
                'gpt_reasoning',
                'gpt_completed',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->string('haiku_verdict', 10)->nullable();
            $table->decimal('haiku_confidence', 4, 3)->nullable();
            $table->string('haiku_category', 50)->nullable();
            $table->text('haiku_reasoning')->nullable();
            $table->boolean('haiku_completed')->default(false);
            $table->string('gpt_verdict', 10)->nullable();
            $table->decimal('gpt_confidence', 4, 3)->nullable();
            $table->string('gpt_category', 50)->nullable();
            $table->text('gpt_reasoning')->nullable();
            $table->boolean('gpt_completed')->default(false);
        });
    }
};
