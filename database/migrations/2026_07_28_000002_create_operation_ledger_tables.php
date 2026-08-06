<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_id', 50);
            $table->foreignId('mobile_device_id')
                ->nullable()
                ->constrained('mobile_devices')
                ->nullOnDelete();
            $table->string('operation_type', 80);
            $table->string('scope_type', 20);
            $table->string('scope_id', 100);
            $table->string('status', 20);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('review_card_id')
                ->nullable()
                ->constrained('review_cards')
                ->nullOnDelete();
            $table->foreignId('review_log_id')
                ->nullable()
                ->unique()
                ->constrained('review_logs')
                ->nullOnDelete();
            $table->uuid('review_session_id')->nullable();
            $table->unsignedBigInteger('last_transition_sequence')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'language_id', 'scope_type', 'scope_id', 'status'],
                'operations_stack_status_index'
            );
            $table->index(
                ['user_id', 'language_id', 'last_transition_sequence'],
                'operations_recent_index'
            );
            $table->index(
                ['user_id', 'language_id', 'review_session_id'],
                'operations_session_index'
            );
        });

        Schema::create('operation_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_record_id')
                ->constrained('operations')
                ->cascadeOnDelete();
            $table->string('transition', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedInteger('version');
            $table->foreignId('actor_mobile_device_id')
                ->nullable()
                ->constrained('mobile_devices')
                ->nullOnDelete();
            $table->uuid('client_action_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamps();

            $table->unique(
                ['operation_record_id', 'version'],
                'operation_changes_operation_version_unique'
            );
            $table->index(
                ['operation_record_id', 'id'],
                'operation_changes_timeline_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_changes');
        Schema::dropIfExists('operations');
    }
};
