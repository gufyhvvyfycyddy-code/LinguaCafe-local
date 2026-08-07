<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->string('source_revision', 80)->index();
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'language_id', 'chapter_id', 'status'], 'reading_session_scope_index');
        });

        Schema::create('reading_session_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reading_session_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->string('interaction_key', 160);
            $table->string('occurrence_id', 80)->nullable();
            $table->string('interaction_type', 32);
            $table->unsignedBigInteger('word_sense_id')->nullable();
            $table->unsignedBigInteger('review_card_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['reading_session_id', 'interaction_key'], 'reading_session_interaction_unique');
        });

        Schema::create('reading_session_card_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reading_session_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('review_card_id')->index();
            $table->unsignedBigInteger('word_sense_id')->index();
            $table->unsignedBigInteger('review_log_id')->nullable()->index();
            $table->string('rating', 16)->default('good');
            $table->timestamps();

            $table->unique(['reading_session_id', 'review_card_id'], 'reading_session_card_settlement_unique');
        });

        Schema::create('reading_session_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reading_session_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->string('source_revision', 80);
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_session_completions');
        Schema::dropIfExists('reading_session_card_settlements');
        Schema::dropIfExists('reading_session_interactions');
        Schema::dropIfExists('reading_sessions');
    }
};
