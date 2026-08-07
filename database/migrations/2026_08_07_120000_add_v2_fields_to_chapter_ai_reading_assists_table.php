<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_ai_reading_assists', function (Blueprint $table) {
            $table->string('source_revision', 80)->nullable()->after('schema_version');
            $table->string('payload_hash', 80)->nullable()->after('source_revision');
            $table->longText('validated_payload')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_ai_reading_assists', function (Blueprint $table) {
            $table->dropColumn([
                'source_revision',
                'payload_hash',
                'validated_payload',
            ]);
        });
    }
};
