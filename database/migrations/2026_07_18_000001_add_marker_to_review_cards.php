<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('marker')
                ->default(0)
                ->after('lifecycle_changed_at');
            $table->index(
                ['user_id', 'language_id', 'target_type', 'marker'],
                'review_cards_user_language_id_target_marker_index'
            );
        });

        DB::statement(
            'ALTER TABLE review_cards '
            . 'ADD CONSTRAINT review_cards_marker_range_check '
            . 'CHECK (marker BETWEEN 0 AND 7)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE review_cards '
            . 'DROP CONSTRAINT review_cards_marker_range_check'
        );

        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropIndex('review_cards_user_language_id_target_marker_index');
            $table->dropColumn('marker');
        });
    }
};
