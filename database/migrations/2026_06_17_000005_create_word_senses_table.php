<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_senses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('word_id')->nullable();
            $table->unsignedBigInteger('encountered_word_id')->nullable();
            $table->string('lemma')->index();
            $table->string('surface_form')->nullable();
            $table->string('pos')->nullable()->index();
            $table->string('sense_key')->index();
            $table->text('sense_zh');
            $table->text('sense_en')->nullable();
            $table->json('aliases_zh')->nullable();
            $table->json('collocations')->nullable();
            $table->text('example_sentence_en')->nullable();
            $table->text('example_sentence_zh')->nullable();
            $table->string('source_text_id')->nullable();
            $table->unsignedBigInteger('source_chapter_id')->nullable();
            $table->string('sentence_id')->nullable();
            $table->string('sentence_hash')->nullable();
            $table->boolean('is_context_specific')->default(true);
            $table->string('status')->default('confirmed')->index();
            $table->timestamps();

            $table->index(['user_id', 'language_id', 'lemma'], 'word_senses_user_language_lemma_index');
            $table->index(['user_id', 'language_id', 'sense_key'], 'word_senses_user_language_sense_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_senses');
    }
};
