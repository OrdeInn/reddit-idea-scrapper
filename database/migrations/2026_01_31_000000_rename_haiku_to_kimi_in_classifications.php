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
        Schema::table('classifications', function (Blueprint $table) {
            $table->renameColumn('haiku_verdict', 'kimi_verdict');
            $table->renameColumn('haiku_confidence', 'kimi_confidence');
            $table->renameColumn('haiku_category', 'kimi_category');
            $table->renameColumn('haiku_reasoning', 'kimi_reasoning');
            $table->renameColumn('haiku_completed', 'kimi_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->renameColumn('kimi_verdict', 'haiku_verdict');
            $table->renameColumn('kimi_confidence', 'haiku_confidence');
            $table->renameColumn('kimi_category', 'haiku_category');
            $table->renameColumn('kimi_reasoning', 'haiku_reasoning');
            $table->renameColumn('kimi_completed', 'haiku_completed');
        });
    }
};
