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
            $table->string('language')->after('user_id')->default('');
        });

        DB::table('review_cards')
            ->join('encountered_words', function ($join) {
                $join->on('encountered_words.id', '=', 'review_cards.target_id')
                    ->where('review_cards.target_type', 'word');
            })
            ->update(['review_cards.language' => DB::raw('encountered_words.language')]);

        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropUnique('review_cards_user_id_target_type_target_id_unique');
            $table->unique(['user_id', 'language', 'target_type', 'target_id'], 'review_cards_user_language_target_unique');
            $table->index(['user_id', 'language', 'target_type', 'fsrs_enabled', 'fsrs_due_at'], 'review_cards_due_lookup_index');
        });

        Schema::table('review_logs', function (Blueprint $table) {
            $table->string('language')->after('user_id')->default('');
            $table->index(['user_id', 'language', 'reviewed_at'], 'review_logs_user_language_reviewed_index');
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->dropIndex('review_logs_user_language_reviewed_index');
            $table->dropColumn('language');
        });

        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropIndex('review_cards_due_lookup_index');
            $table->dropUnique('review_cards_user_language_target_unique');
            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->dropColumn('language');
        });
    }
};
