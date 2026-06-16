<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('fsrs_state', 32)->default('new');
            $table->timestamp('fsrs_due_at')->nullable()->index();
            $table->double('fsrs_stability')->nullable();
            $table->double('fsrs_difficulty')->nullable();
            $table->unsignedInteger('fsrs_reps')->default(0);
            $table->unsignedInteger('fsrs_lapses')->default(0);
            $table->timestamp('fsrs_last_reviewed_at')->nullable();
            $table->boolean('fsrs_enabled')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'target_type', 'target_id']);
            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->index(['user_id', 'target_type', 'fsrs_enabled', 'fsrs_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_cards');
    }
};
