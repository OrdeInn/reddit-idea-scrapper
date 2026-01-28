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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            // Reddit identifiers
            $table->string('reddit_id', 20); // Reddit's base36 ID
            $table->string('reddit_fullname', 30)->index(); // Full thing ID (e.g., "t1_abc123")

            // Parent reference for nested comments (null = top-level)
            $table->string('parent_reddit_id', 20)->nullable();

            // Comment content
            $table->text('body');
            $table->string('author', 50)->nullable(); // null if [deleted]

            // Engagement
            $table->integer('upvotes')->default(0);
            $table->integer('downvotes')->default(0);

            // Nesting depth (0 = top-level, 1 = reply to top-level, etc.)
            $table->unsignedTinyInteger('depth')->default(0);

            // Timestamps
            $table->timestamp('reddit_created_at');
            $table->timestamp('fetched_at');
            $table->timestamps();

            // Composite unique constraint (same comment shouldn't appear twice per post)
            $table->unique(['post_id', 'reddit_id']);

            // Indexes
            $table->index('post_id');
            $table->index('parent_reddit_id');
            $table->index('upvotes');
            $table->index('depth');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
