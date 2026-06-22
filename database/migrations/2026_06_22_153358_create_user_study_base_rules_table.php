<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_study_base_rules', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('language', 20);
            $table->string('surface', 255);
            $table->string('study_base', 255);
            $table->timestamps();

            $table->unique(['user_id', 'language', 'surface']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_study_base_rules');
    }
};
