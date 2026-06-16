<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('review_card_id');
            $table->string('rating', 16);
            $table->timestamp('reviewed_at');
            $table->string('previous_state', 32)->nullable();
            $table->string('new_state', 32);
            $table->timestamp('previous_due_at')->nullable();
            $table->timestamp('new_due_at')->nullable();
            $table->double('previous_stability')->nullable();
            $table->double('new_stability')->nullable();
            $table->double('previous_difficulty')->nullable();
            $table->double('new_difficulty')->nullable();
            $table->string('source', 32)->default('review');
            $table->timestamps();

            $table->index(['user_id', 'reviewed_at']);
            $table->index(['review_card_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_logs');
    }
};
