<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_occurrence_sense_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->string('source_revision', 80)->index();
            $table->string('occurrence_id', 80);
            $table->string('target_origin', 32);
            $table->unsignedInteger('start_word_index');
            $table->unsignedInteger('end_word_index');
            $table->integer('sentence_index');
            $table->text('surface');
            $table->string('lemma')->nullable()->index();
            $table->string('pos')->nullable();
            $table->string('resolution', 32);
            $table->unsignedBigInteger('word_sense_id')->nullable()->index();
            $table->string('resolution_source', 16);
            $table->string('ai_confidence', 16)->nullable();
            $table->string('ai_package_id', 80)->nullable();
            $table->string('ai_payload_hash', 80)->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'chapter_id', 'source_revision', 'occurrence_id'],
                'reading_occurrence_evidence_identity_unique'
            );
            $table->index(
                ['user_id', 'language_id', 'chapter_id', 'source_revision'],
                'reading_occurrence_evidence_scope_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_occurrence_sense_evidence');
    }
};
