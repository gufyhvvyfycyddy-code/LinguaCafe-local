<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_senses', function (Blueprint $table) {
            $table->timestamp('learning_started_at')->nullable()->after('status');
            $table->string('learning_started_origin', 32)->nullable()->after('learning_started_at');
            $table->unsignedBigInteger('learning_started_source_occurrence_id')->nullable()->after('learning_started_origin');

            $table->index(
                ['user_id', 'language_id', 'learning_started_at'],
                'word_senses_learning_started_idx',
            );
            $table->foreign('learning_started_source_occurrence_id', 'word_senses_learning_source_fk')
                ->references('id')
                ->on('word_sense_occurrences')
                ->restrictOnDelete();
        });

        DB::table('word_senses')
            ->where(function ($query) {
                $query->where('status', 'rejected')
                    ->orWhereExists(function ($cards) {
                        $cards->selectRaw('1')
                            ->from('review_cards')
                            ->whereColumn('review_cards.target_id', 'word_senses.id')
                            ->where('review_cards.target_type', 'sense');
                    });
            })
            ->update(['learning_started_origin' => 'legacy_unknown']);
    }

    public function down(): void
    {
        Schema::table('word_senses', function (Blueprint $table) {
            $table->dropForeign('word_senses_learning_source_fk');
            $table->dropIndex('word_senses_learning_started_idx');
            $table->dropColumn([
                'learning_started_at',
                'learning_started_origin',
                'learning_started_source_occurrence_id',
            ]);
        });
    }
};
