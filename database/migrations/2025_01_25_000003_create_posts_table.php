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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subreddit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();

            // Reddit identifiers
            $table->string('reddit_id', 20)->unique(); // Reddit's base36 ID (e.g., "abc123")
            $table->string('reddit_fullname', 30)->index(); // Full thing ID (e.g., "t3_abc123")

            // Post content
            $table->string('title', 500);
            $table->text('body')->nullable(); // Self-text, null for link posts
            $table->string('author', 50)->nullable(); // null if [deleted]
            $table->string('permalink', 500);
            $table->string('url', 1000)->nullable(); // External URL for link posts

            // Engagement metrics
            $table->integer('upvotes')->default(0);
            $table->integer('downvotes')->default(0);
            $table->unsignedInteger('num_comments')->default(0);
            $table->decimal('upvote_ratio', 4, 3)->default(0); // 0.000 to 1.000

            // Post metadata
            $table->string('flair', 100)->nullable();
            $table->boolean('is_self')->default(true); // true for text posts
            $table->boolean('is_nsfw')->default(false);
            $table->boolean('is_spoiler')->default(false);

            // Timestamps
            $table->timestamp('reddit_created_at'); // When posted on Reddit
            $table->timestamp('fetched_at'); // When we fetched it
            $table->timestamps();

            // Indexes for common queries
            $table->index('subreddit_id');
            $table->index('scan_id');
            $table->index('upvotes');
            $table->index('num_comments');
            $table->index('reddit_created_at');
            $table->index(['subreddit_id', 'reddit_created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
