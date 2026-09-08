<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_study_card_pending_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language')->index();
            // #54: language_id and status take part in the composite unique
            // index below. At the default VARCHAR(255) under utf8mb4 the index
            // key exceeds InnoDB's 3072-byte limit and a clean build fails. They
            // hold short values (a language identifier and a pending-status
            // word), so the canonical long-running schema bounds them to 64/32;
            // reproducing those bounds keeps the full-value unique constraint
            // intact while letting a fresh database build from zero.
            $table->string('language_id', 64)->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->unsignedInteger('text_block_index')->index();
            $table->unsignedInteger('sentence_index')->nullable()->index();
            $table->string('sentence_id')->nullable();
            $table->string('word');
            $table->string('normalized_word')->index();
            $table->string('surface')->nullable();
            $table->string('lemma')->nullable()->index();
            $table->text('sentence_text')->nullable();
            $table->json('source_payload')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'chapter_id', 'text_block_index', 'normalized_word', 'status'],
                'ai_pending_user_lang_chapter_block_word_status_unique'
            );
            $table->index(['user_id', 'language_id', 'status'], 'ai_pending_user_language_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_study_card_pending_items');
    }
};
