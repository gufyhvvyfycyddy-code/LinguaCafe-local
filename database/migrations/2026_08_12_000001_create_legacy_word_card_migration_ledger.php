<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_word_card_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_uuid')->unique();
            $table->string('schema_version', 64);
            $table->string('classifier_schema_version', 64);
            $table->char('report_fingerprint', 64);
            $table->char('plan_fingerprint', 64)->unique();
            $table->uuid('backup_id');
            $table->char('backup_manifest_sha256', 64);
            $table->char('backup_payload_sha256', 64);
            $table->json('filters');
            $table->json('counts');
            $table->string('state', 20);
            $table->timestamp('applied_at');
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legacy_word_card_migration_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->index();
            $table->unsignedBigInteger('legacy_review_card_id')->index();
            $table->unsignedBigInteger('encountered_word_id')->index();
            $table->unsignedBigInteger('word_sense_id')->index();
            $table->unsignedBigInteger('sense_review_card_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id');
            $table->boolean('created_sense_card');
            $table->string('classification', 32);
            $table->string('primary_reason_code', 96);
            $table->json('reason_codes');
            $table->json('before_classification_evidence');
            $table->char('before_classification_fingerprint', 64);
            $table->json('after_classification_evidence')->nullable();
            $table->char('after_classification_fingerprint', 64)->nullable();
            $table->json('before_legacy_snapshot');
            $table->char('before_legacy_fingerprint', 64);
            $table->json('after_legacy_snapshot');
            $table->char('after_legacy_fingerprint', 64);
            $table->json('before_sense_snapshot')->nullable();
            $table->char('before_sense_fingerprint', 64)->nullable();
            $table->json('after_sense_snapshot');
            $table->char('after_sense_fingerprint', 64);
            $table->timestamps();

            $table->unique('legacy_review_card_id', 'lwcmi_legacy_unique');
            $table->foreign('run_id', 'lwcmi_run_fk')
                ->references('id')
                ->on('legacy_word_card_migration_runs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_word_card_migration_items');
        Schema::dropIfExists('legacy_word_card_migration_runs');
    }
};
