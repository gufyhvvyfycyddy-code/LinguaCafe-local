<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_setting_preset_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_id', 64);
            $table->unsignedBigInteger('preset_id');
            $table->timestamps();

            $table->unique(['user_id', 'language_id'], 'review_preset_binding_user_lang_unique');
            $table->foreign(['preset_id', 'user_id'], 'review_preset_binding_owner_fk')
                ->references(['id', 'user_id'])
                ->on('review_setting_presets')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_setting_preset_bindings');
    }
};
