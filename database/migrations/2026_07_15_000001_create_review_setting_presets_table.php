<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_setting_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('config');
            $table->boolean('is_default')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'review_presets_user_name_unique');
            $table->unique(['user_id', 'is_default'], 'review_presets_one_default_unique');
            $table->unique(['id', 'user_id'], 'review_presets_id_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_setting_presets');
    }
};
