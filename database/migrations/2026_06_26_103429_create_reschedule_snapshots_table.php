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
        Schema::create('reschedule_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->notNullable();
            $table->string('language_id', 10)->notNullable();
            $table->string('batch_id', 64)->notNullable()->unique();
            $table->string('preview_hash', 64)->nullable();
            $table->integer('total_cards')->notNullable()->default(0);
            $table->integer('applied_count')->notNullable()->default(0);
            $table->integer('skipped_count')->notNullable()->default(0);
            $table->integer('newly_due_today')->notNullable()->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('language_id');
            $table->index(['user_id', 'language_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reschedule_snapshots');
    }
};
