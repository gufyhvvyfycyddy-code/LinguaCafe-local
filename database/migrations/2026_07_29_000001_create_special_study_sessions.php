<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_study_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_id', 50);
            $table->string('name', 100)->nullable();
            $table->string('normalized_name', 100)->nullable();
            $table->string('execution_mode', 20);
            $table->string('scenario', 30);
            $table->json('definition');
            $table->json('ordered_card_ids');
            $table->json('remaining_card_ids');
            $table->json('completed_card_ids');
            $table->json('skipped_card_ids');
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 20);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'normalized_name'],
                'special_study_user_language_name_unique'
            );
            $table->index(
                ['user_id', 'language_id', 'status', 'updated_at'],
                'special_study_user_language_status_index'
            );
        });

        Schema::create('special_study_session_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('special_study_session_id');
            $table->uuid('client_action_id');
            $table->char('request_hash', 64);
            $table->string('status', 20);
            $table->uuid('operation_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->foreign('special_study_session_id', 'special_study_action_session_fk')
                ->references('id')
                ->on('special_study_sessions')
                ->cascadeOnDelete();
            $table->unique(
                ['special_study_session_id', 'client_action_id'],
                'special_study_session_client_action_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_study_session_actions');
        Schema::dropIfExists('special_study_sessions');
    }
};
