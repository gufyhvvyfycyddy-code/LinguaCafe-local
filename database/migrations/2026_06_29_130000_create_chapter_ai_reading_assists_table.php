<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_ai_reading_assists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('language', 255);
            $table->unsignedBigInteger('chapter_id');
            $table->string('schema_version', 100);
            $table->longText('sentence_translations')->nullable();
            $table->longText('vocabulary_items')->nullable();
            $table->longText('phrase_items')->nullable();
            $table->longText('warnings')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'language', 'chapter_id'], 'ai_assist_user_lang_chapter_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_ai_reading_assists');
    }
};
