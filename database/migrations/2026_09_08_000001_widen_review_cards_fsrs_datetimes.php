<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #57: review_cards.fsrs_due_at / fsrs_last_reviewed_at were created as MySQL
 * TIMESTAMP, whose representable range ends 2038-01-19. FSRS schedules a card's
 * next due date up to `maximum_interval_days` (default and clamp ceiling 36500,
 * i.e. ~100 years — see ReviewSettingsPresetConfig), so a maturing card's
 * fsrs_due_at can legitimately exceed 2038 and then fail to persist
 * ("Incorrect datetime value"). This aborts the sole rating write path.
 *
 * Widen both columns to DATETIME (range to year 9999) so the application's own
 * scheduling range is storable. Additive and idempotent: it converges every
 * database — fresh builds and long-running installs alike — onto DATETIME.
 *
 * Safety:
 *  - APP_TIMEZONE is UTC and no per-connection time_zone override is set, so
 *    existing values (all <= 2038) convert without a shift.
 *  - This changes only the storable range of two columns. It does NOT touch
 *    FSRS math, ReviewLog, ReviewCard lifecycle, scheduling, or due semantics;
 *    every value that persisted before still persists identically.
 *  - MySQL only; a no-op on other drivers (e.g. the sqlite skeleton default).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `review_cards` MODIFY `fsrs_due_at` DATETIME NULL');
        DB::statement('ALTER TABLE `review_cards` MODIFY `fsrs_last_reviewed_at` DATETIME NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Reverting re-imposes the 2038 ceiling and would fail if any stored
        // value already exceeds it; provided only for migration symmetry.
        DB::statement('ALTER TABLE `review_cards` MODIFY `fsrs_due_at` TIMESTAMP NULL');
        DB::statement('ALTER TABLE `review_cards` MODIFY `fsrs_last_reviewed_at` TIMESTAMP NULL');
    }
};
