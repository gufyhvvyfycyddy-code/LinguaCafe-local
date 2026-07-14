<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->unsignedInteger('review_duration_ms')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->dropColumn('review_duration_ms');
        });
    }
};
