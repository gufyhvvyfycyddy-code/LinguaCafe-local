<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->char('sha256', 64)->index();
            $table->string('storage_name');
            $table->string('original_name');
            $table->string('mime_type', 64);
            $table->string('extension', 8);
            $table->unsignedBigInteger('size_bytes');
            $table->string('source_kind', 32)->default('user_upload');
            $table->string('copyright_status', 32)->default('unknown');
            $table->string('copyright_source', 512)->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('retained_until')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'language_id', 'sha256'], 'media_assets_scope_hash_index');
        });

        Schema::create('media_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('language_id')->index();
            $table->unsignedBigInteger('media_asset_id')->index();
            $table->unsignedBigInteger('word_sense_id')->index();
            $table->string('role', 32);
            $table->char('slot_key', 64);
            $table->text('source_text')->nullable();
            $table->timestamps();

            $table->unique(
                ['word_sense_id', 'role', 'slot_key'],
                'media_references_active_slot_unique',
            );
            $table->foreign('media_asset_id')->references('id')->on('media_assets');
            $table->foreign('word_sense_id')->references('id')->on('word_senses')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_references');
        Schema::dropIfExists('media_assets');
    }
};
