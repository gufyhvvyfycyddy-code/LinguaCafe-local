<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_cards', function (Blueprint $table): void {
            $table->unsignedTinyInteger('fsrs_step_index')
                ->nullable()
                ->after('fsrs_state');
        });
    }

    public function down(): void
    {
        Schema::table('review_cards', function (Blueprint $table): void {
            $table->dropColumn('fsrs_step_index');
        });
    }
};
