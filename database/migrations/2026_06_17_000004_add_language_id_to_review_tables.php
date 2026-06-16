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
            if (!Schema::hasColumn('review_cards', 'language_id')) {
                $table->string('language_id')->after('user_id')->default('');
            }
        });

        DB::table('review_cards')->update([
            'language_id' => DB::raw('language'),
        ]);

        Schema::table('review_cards', function (Blueprint $table) {
            $table->unique(['user_id', 'language_id', 'target_type', 'target_id'], 'review_cards_user_language_id_target_unique');
            $table->index(['user_id', 'language_id', 'target_type', 'fsrs_enabled', 'fsrs_due_at'], 'review_cards_language_id_due_lookup_index');
        });

        Schema::table('review_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('review_logs', 'language_id')) {
                $table->string('language_id')->after('user_id')->default('');
            }
        });

        DB::table('review_logs')->update([
            'language_id' => DB::raw('language'),
        ]);

        Schema::table('review_logs', function (Blueprint $table) {
            $table->index(['user_id', 'language_id', 'reviewed_at'], 'review_logs_user_language_id_reviewed_index');
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->dropIndex('review_logs_user_language_id_reviewed_index');
            $table->dropColumn('language_id');
        });

        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropIndex('review_cards_language_id_due_lookup_index');
            $table->dropUnique('review_cards_user_language_id_target_unique');
            $table->dropColumn('language_id');
        });
    }
};
