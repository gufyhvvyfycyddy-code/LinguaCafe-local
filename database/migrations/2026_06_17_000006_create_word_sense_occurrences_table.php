<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_sense_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('word_sense_id')->nullable()->index();
            $table->unsignedBigInteger('review_card_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('text_id')->nullable();
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->string('sentence_id')->index();
            $table->string('sentence_hash')->nullable();
            $table->text('sentence_en');
            $table->text('sentence_zh')->nullable();
            $table->string('type')->default('word');
            $table->string('surface');
            $table->string('lemma')->index();
            $table->string('pos')->nullable();
            $table->string('decision')->index();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('evidence')->nullable();
            $table->boolean('auto_fsrs_allowed')->default(false);
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('sense_mapping_import');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('review_card_id');
            $table->index(['user_id', 'language_id', 'sentence_id'], 'word_sense_occurrences_user_language_sentence_index');
            $table->index(['user_id', 'language_id', 'word_sense_id'], 'word_sense_occurrences_user_language_sense_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_sense_occurrences');
    }
};
