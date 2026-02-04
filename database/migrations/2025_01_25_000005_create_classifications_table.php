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
        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Claude Haiku results
            $table->string('haiku_verdict', 10)->nullable(); // 'keep' or 'skip'
            $table->decimal('haiku_confidence', 4, 3)->nullable(); // 0.000 to 1.000
            $table->string('haiku_category', 50)->nullable();
            $table->text('haiku_reasoning')->nullable();

            // OpenAI classifier results
            $table->string('gpt_verdict', 10)->nullable(); // 'keep' or 'skip'
            $table->decimal('gpt_confidence', 4, 3)->nullable(); // 0.000 to 1.000
            $table->string('gpt_category', 50)->nullable();
            $table->text('gpt_reasoning')->nullable();

            // Consensus results
            $table->decimal('combined_score', 4, 3)->nullable(); // Calculated consensus score
            $table->string('final_decision', 20)->default('pending'); // 'keep', 'discard', or 'borderline'

            // Processing metadata
            $table->boolean('haiku_completed')->default(false);
            $table->boolean('gpt_completed')->default(false);
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            // Unique constraint - one classification per post
            $table->unique('post_id');

            // Indexes
            $table->index('final_decision');
            $table->index('combined_score');
            $table->index(['final_decision', 'combined_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
