<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only migration for ADR-0003.
 *
 * Creates a new table `reading_inline_sense_confirmations` to persist
 * user-initiated "是这个意思 / 不是这个意思" choices made on the reading
 * page inline sense preview panel.
 *
 * Safety contract (ADR-0003):
 *  - This migration ONLY creates a new table. It does NOT alter, drop,
 *    truncate, or delete any existing table or data.
 *  - The table is intentionally separate from `word_sense_occurrences`
 *    to avoid coupling the read-only preview flow with the sense-mapping
 *    AI import / bind / ignore / reject lifecycle.
 *  - This table MUST NOT be referenced by ReviewLog, FSRS, ReviewCard,
 *    or WordSense creation logic. Its only writer is
 *    `ReadingInlineSenseConfirmationService`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_inline_sense_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language')->index();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->integer('sentence_index')->nullable();
            $table->string('sentence_hash')->nullable();
            $table->text('sentence_text')->nullable();
            $table->string('surface');
            $table->string('lemma')->index();
            $table->unsignedBigInteger('word_sense_id')->index();
            // 'match' = 是这个意思; 'not_match' = 不是这个意思
            $table->string('choice');
            $table->string('source')->default('reading_inline_preview');
            $table->timestamps();

            // Same occurrence + same sense → at most one row. Updating the
            // choice re-uses this row instead of inserting a duplicate.
            $table->unique(
                ['user_id', 'language', 'chapter_id', 'sentence_index', 'surface', 'lemma', 'word_sense_id'],
                'risc_user_lang_chapter_sentence_surface_lemma_sense_unique'
            );

            $table->index(['user_id', 'language', 'lemma'], 'risc_user_lang_lemma_index');
            $table->index(['user_id', 'language', 'word_sense_id'], 'risc_user_lang_sense_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_inline_sense_confirmations');
    }
};
