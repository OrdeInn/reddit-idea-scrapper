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
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subreddit_id')->constrained()->cascadeOnDelete();

            // Scan type: 'initial' (3 months) or 'rescan' (2 weeks)
            $table->string('scan_type', 20)->default('initial');

            // Status: pending, fetching, classifying, extracting, completed, failed
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();

            // Date range being scanned
            $table->timestamp('date_from')->nullable();
            $table->timestamp('date_to')->nullable();

            // Progress tracking
            $table->unsignedInteger('posts_fetched')->default(0);
            $table->unsignedInteger('posts_classified')->default(0);
            $table->unsignedInteger('posts_extracted')->default(0);
            $table->unsignedInteger('ideas_found')->default(0);

            // Checkpoint for resumable scans (stores Reddit's 'after' cursor)
            $table->string('checkpoint')->nullable();

            // Comment job tracking for completion detection (works with any queue driver)
            $table->unsignedInteger('comment_jobs_total')->nullable();
            $table->unsignedInteger('comment_jobs_done')->default(0);

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['subreddit_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
