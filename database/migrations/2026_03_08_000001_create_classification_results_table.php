<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->string('provider_name', 100);
            $table->string('verdict', 10)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('category', 50)->nullable();
            $table->text('reasoning')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['classification_id', 'provider_name'], 'classification_results_unique');
            $table->index('provider_name');
            $table->index(['classification_id', 'completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_results');
    }
};
