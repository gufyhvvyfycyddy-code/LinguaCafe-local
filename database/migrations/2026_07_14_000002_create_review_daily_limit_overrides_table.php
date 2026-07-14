<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_daily_limit_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_id', 64);
            $table->date('study_date');
            $table->smallInteger('new_limit_delta')->default(0);
            $table->smallInteger('review_limit_delta')->default(0);
            $table->boolean('pause_new_cards')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'language_id', 'study_date'], 'review_daily_limits_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_daily_limit_overrides');
    }
};
