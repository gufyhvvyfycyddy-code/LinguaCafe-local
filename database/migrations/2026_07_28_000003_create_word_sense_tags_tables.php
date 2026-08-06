<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_sense_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_id', 64);
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'normalized_name'],
                'word_sense_tags_user_language_name_unique'
            );
            $table->index(
                ['user_id', 'language_id', 'name'],
                'word_sense_tags_user_language_name_index'
            );
        });

        Schema::create('word_sense_tag_assignments', function (Blueprint $table) {
            $table->foreignId('word_sense_id')
                ->constrained('word_senses')
                ->cascadeOnDelete();
            $table->foreignId('word_sense_tag_id')
                ->constrained('word_sense_tags')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['word_sense_id', 'word_sense_tag_id'],
                'word_sense_tag_assignments_unique'
            );
            $table->index(
                ['word_sense_tag_id', 'word_sense_id'],
                'word_sense_tag_assignments_reverse_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_sense_tag_assignments');
        Schema::dropIfExists('word_sense_tags');
    }
};
