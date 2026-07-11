<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: adds undo ledger fields to review_logs.
 *
 * Six nullable columns:
 *   review_session_id     — UUID identifying the browser tab session
 *   before_card_snapshot  — JSON: complete FSRS state before this rating
 *   after_card_snapshot   — JSON: complete FSRS state after this rating
 *   undone_at             — timestamp when this action was undone (null = active)
 *   undo_request_id       — UUID idempotency key for the undo request
 *   undo_source           — which UI entry triggered the undo
 *
 * Old logs (pre-migration) have all 6 fields as null. They continue to
 * participate in product analytics normally but cannot be undone (no
 * snapshot).
 *
 * This is the ONLY migration allowed for the undo feature. Do not add
 * a second migration. Do not modify the review_cards schema.
 */
class AddReviewActionUndoFieldsToReviewLogsTable extends Migration
{
    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->string('review_session_id', 36)->nullable()->after('source');
            $table->json('before_card_snapshot')->nullable()->after('review_session_id');
            $table->json('after_card_snapshot')->nullable()->after('before_card_snapshot');
            $table->timestamp('undone_at')->nullable()->after('after_card_snapshot');
            $table->string('undo_request_id', 36)->nullable()->after('undone_at');
            $table->string('undo_source', 32)->nullable()->after('undo_request_id');

            $table->index('review_session_id', 'review_logs_review_session_id_index');
            $table->index('undone_at', 'review_logs_undone_at_index');
            $table->unique('undo_request_id', 'review_logs_undo_request_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table) {
            $table->dropIndex('review_logs_review_session_id_index');
            $table->dropIndex('review_logs_undone_at_index');
            $table->dropUnique('review_logs_undo_request_id_unique');

            $table->dropColumn([
                'review_session_id',
                'before_card_snapshot',
                'after_card_snapshot',
                'undone_at',
                'undo_request_id',
                'undo_source',
            ]);
        });
    }
}
