<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('expected_provider_count')->default(2)->after('final_decision');
        });
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->dropColumn('expected_provider_count');
        });
    }
};
