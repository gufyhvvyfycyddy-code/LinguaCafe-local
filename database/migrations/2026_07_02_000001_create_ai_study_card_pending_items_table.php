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
            $table->string('language_id')->index();
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
            $table->string('status')->default('pending')->index();
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
