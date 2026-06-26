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
        Schema::create('reschedule_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reschedule_snapshot_id')->notNullable();
            $table->unsignedBigInteger('review_card_id')->notNullable();
            $table->dateTime('previous_due_at')->nullable();
            $table->double('previous_stability')->nullable();
            $table->double('previous_difficulty')->nullable();
            $table->dateTime('new_due_at')->nullable();
            $table->double('new_stability')->nullable();
            $table->double('new_difficulty')->nullable();
            $table->boolean('skipped')->default(false);
            $table->string('skip_reason', 255)->nullable();
            $table->boolean('undone')->default(false);
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();

            $table->foreign('reschedule_snapshot_id')->references('id')->on('reschedule_snapshots')->onDelete('cascade');
            $table->foreign('review_card_id')->references('id')->on('review_cards')->onDelete('cascade');
            $table->unique(['reschedule_snapshot_id', 'review_card_id'], 'rs_items_snapshot_card_unique');
            $table->index('review_card_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reschedule_snapshot_items');
    }
};
