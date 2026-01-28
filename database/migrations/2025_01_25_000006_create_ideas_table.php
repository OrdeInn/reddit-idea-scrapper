<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();

            // Core idea information
            $table->string('idea_title', 200);
            $table->text('problem_statement');
            $table->text('proposed_solution');
            $table->string('target_audience', 500);
            $table->text('why_small_team_viable');
            $table->text('demand_evidence');
            $table->text('monetization_model');

            // Branding suggestions (JSON)
            // { name_ideas: string[], positioning: string, tagline: string }
            $table->json('branding_suggestions');

            // Marketing channels (JSON array of strings)
            $table->json('marketing_channels');

            // Competitors (JSON array of strings or "None identified")
            $table->json('existing_competitors');

            // Scores (JSON)
            // {
            //   monetization: 1-5, monetization_reasoning: string,
            //   market_saturation: 1-5, saturation_reasoning: string,
            //   complexity: 1-5, complexity_reasoning: string,
            //   demand_evidence: 1-5, demand_reasoning: string,
            //   overall: 1-5, overall_reasoning: string
            // }
            $table->json('scores');

            // Individual score columns for filtering/sorting
            $table->unsignedTinyInteger('score_monetization')->default(0);
            $table->unsignedTinyInteger('score_saturation')->default(0);
            $table->unsignedTinyInteger('score_complexity')->default(0);
            $table->unsignedTinyInteger('score_demand')->default(0);
            $table->unsignedTinyInteger('score_overall')->default(0);

            // Source quote from Reddit
            $table->text('source_quote');

            // Classification status carried from classification
            $table->string('classification_status', 20)->default('keep'); // 'keep' or 'borderline'

            // Favorites
            $table->boolean('is_starred')->default(false);
            $table->timestamp('starred_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes for filtering and sorting
            $table->index('post_id');
            $table->index('scan_id');
            $table->index('score_overall');
            $table->index('score_complexity');
            $table->index('is_starred');
            $table->index(['is_starred', 'starred_at']);
            $table->index('classification_status');
            $table->index('created_at');

            // Compound indexes for common filter combinations
            $table->index(['scan_id', 'score_overall']);
            $table->index(['scan_id', 'is_starred']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
