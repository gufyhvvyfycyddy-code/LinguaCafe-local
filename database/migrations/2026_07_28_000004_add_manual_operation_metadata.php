<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('source_channel', 20)->nullable()->after('mobile_device_id');
            $table->json('action_payload')->nullable()->after('operation_type');
            $table->char('request_fingerprint', 64)->nullable()->after('action_payload');
        });

        Schema::table('operation_changes', function (Blueprint $table) {
            $table->string('actor_source_channel', 20)
                ->nullable()
                ->after('actor_mobile_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('operation_changes', function (Blueprint $table) {
            $table->dropColumn('actor_source_channel');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn([
                'source_channel',
                'action_payload',
                'request_fingerprint',
            ]);
        });
    }
};
