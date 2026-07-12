<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive migration: review card lifecycle state machine (ADR-0010).
 *
 * Adds four columns to review_cards:
 *   lifecycle_state       — 'active' | 'buried' | 'suspended' | 'archived'
 *   buried_until          — nullable timestamp; buried cards auto-revert at this instant
 *   lifecycle_version     — unsigned int, optimistic lock counter
 *   lifecycle_changed_at  — nullable timestamp of last lifecycle transition
 *
 * Creates review_card_state_events:
 *   append-only audit table for every lifecycle transition. One row per
 *   successful (or idempotent) action. request_id is unique for idempotency.
 *
 * Backfill:
 *   fsrs_enabled=0 → lifecycle_state='archived'
 *   fsrs_enabled=1 → lifecycle_state='active'
 *
 * fsrs_enabled is retained as a compatibility mirror:
 *   active/buried → true
 *   suspended/archived → false
 * The command service maintains this invariant on every transition.
 *
 * This is the ONLY migration allowed for the lifecycle feature. Do not add
 * a second migration. Do not modify the review_logs schema. Do not fresh/wipe/
 * drop/truncate.
 */
class AddReviewCardLifecycleStateMachine extends Migration
{
    public function up(): void
    {
        // 1. Add lifecycle columns to review_cards.
        Schema::table('review_cards', function (Blueprint $table) {
            $table->string('lifecycle_state', 20)->default('active')->after('fsrs_enabled');
            $table->timestamp('buried_until')->nullable()->after('lifecycle_state');
            $table->unsignedInteger('lifecycle_version')->default(0)->after('buried_until');
            $table->timestamp('lifecycle_changed_at')->nullable()->after('lifecycle_version');

            $table->index('lifecycle_state', 'review_cards_lifecycle_state_index');
            $table->index('buried_until', 'review_cards_buried_until_index');
        });

        // 2. Backfill lifecycle_state from the existing fsrs_enabled mirror.
        //    fsrs_enabled=false is set in exactly two places (WordSenseService::
        //    archiveSense and ReviewCardManageMutationService::setEnabled(false)),
        //    both of which represent user archive. No other meaning was found.
        DB::table('review_cards')
            ->where('fsrs_enabled', false)
            ->update(['lifecycle_state' => 'archived']);
        DB::table('review_cards')
            ->where('fsrs_enabled', true)
            ->update(['lifecycle_state' => 'active']);

        // 3. Create append-only audit table for lifecycle transitions.
        Schema::create('review_card_state_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('language_id');
            $table->unsignedBigInteger('review_card_id');
            $table->string('action', 20);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->string('request_id', 36);
            $table->string('source', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->unique('request_id', 'rcse_request_id_unique');
            $table->index(['review_card_id', 'created_at'], 'rcse_card_index');
            $table->index(['user_id', 'language_id', 'created_at'], 'rcse_user_lang_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_card_state_events');

        Schema::table('review_cards', function (Blueprint $table) {
            $table->dropIndex('review_cards_lifecycle_state_index');
            $table->dropIndex('review_cards_buried_until_index');

            $table->dropColumn([
                'lifecycle_state',
                'buried_until',
                'lifecycle_version',
                'lifecycle_changed_at',
            ]);
        });
    }
};
