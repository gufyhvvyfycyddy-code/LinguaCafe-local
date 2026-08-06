<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->string('platform', 20);
            $table->string('device_name', 100)->nullable();
            $table->string('app_version', 50);
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid'], 'mobile_devices_user_device_unique');
            $table->unique('personal_access_token_id', 'mobile_devices_token_unique');
            $table->index(['user_id', 'revoked_at'], 'mobile_devices_user_revoked_index');
        });

        Schema::create('mobile_client_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mobile_device_id')->constrained('mobile_devices')->cascadeOnDelete();
            $table->string('action_type', 80);
            $table->uuid('client_action_id');
            $table->char('request_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'mobile_device_id', 'action_type', 'client_action_id'],
                'mobile_actions_user_device_type_client_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_client_actions');
        Schema::dropIfExists('mobile_devices');
    }
};
