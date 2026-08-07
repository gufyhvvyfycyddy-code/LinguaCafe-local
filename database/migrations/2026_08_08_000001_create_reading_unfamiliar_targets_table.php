<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_unfamiliar_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->string('source_revision', 80)->index();
            $table->string('occurrence_id', 80);
            $table->string('kind', 16);
            $table->unsignedInteger('start_word_index');
            $table->unsignedInteger('end_word_index');
            $table->integer('sentence_index');
            $table->text('surface');
            $table->string('lemma')->nullable()->index();
            $table->string('pos')->nullable();
            $table->text('source_sentence');
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'chapter_id', 'source_revision', 'occurrence_id'],
                'reading_unfamiliar_target_identity_unique'
            );
            $table->index(
                ['user_id', 'language_id', 'chapter_id', 'source_revision'],
                'reading_unfamiliar_target_scope_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_unfamiliar_targets');
    }
};
