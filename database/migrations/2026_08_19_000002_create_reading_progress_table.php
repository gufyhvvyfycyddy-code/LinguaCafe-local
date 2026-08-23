<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('language_id');
            $table->unsignedBigInteger('chapter_id');
            $table->string('source_revision', 80);
            $table->unsignedInteger('canonical_token_index');
            $table->unsignedInteger('furthest_canonical_token_index');
            $table->timestamp('position_occurred_at', 6);
            $table->foreignId('last_mobile_device_id')
                ->nullable()
                ->constrained('mobile_devices')
                ->nullOnDelete();
            $table->unsignedBigInteger('client_sequence')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'language_id', 'chapter_id', 'source_revision'],
                'reading_progress_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_progress');
    }
};
