<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->json('details')->nullable()->after('reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
