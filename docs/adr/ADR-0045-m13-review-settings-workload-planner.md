# ADR-0045: M13 Review Settings and Workload Planner V2

## Status

Accepted / Implemented / Closed

## Context

M10 provides shared review-card scope and ordering, M2 provides the operation
ledger, M6 provides guarded backups, and M11 provides preview/apply/undo
operations. The current review Preset only stores desired retention, FSRS
parameters, daily limits and queue order. The scheduler rounds every FSRS
interval to at least one day, so it cannot represent same-day learning or
relearning steps.

M13 must add Anki-aligned, ordinary-user settings without adding arbitrary
custom scheduling, deck inheritance or sibling bury. It must preserve the only
formal-rating path and keep changes to old cards explicit and reversible.

Reference:

- <https://docs.ankiweb.net/deck-options.html>

## Decision

1. Review Preset schema V2 adds two bounded sections:
   - `scheduling`: learning/relearning step minutes, maximum interval,
     minimum relearning interval and seven Easy Day modes;
   - `experience`: question/answer timers, explicit auto-advance enablement,
     timer visibility and audio autoplay/replay preferences.
2. V1 Presets are accepted and normalized in memory to V2 defaults. They are
   persisted as V2 only when next mutated. No database JSON backfill is
   required.
3. Default learning steps are 10 and 30 minutes; the default relearning step is
   10 minutes. Every configured step must be below one day. Blank arrays skip
   that phase. This follows the FSRS guidance that same-day steps should remain
   short.
4. `review_cards.fsrs_step_index` is an additive nullable unsigned field.
   `fsrs_state` remains the phase authority; the index only identifies progress
   inside `learning` or `relearning` and is null in `new` or `review`.
5. A formal rating still calls only
   `ReviewCardService::recordReviewWithLog()` and
   `FsrsSchedulingService::schedule()`. FSRS computes memory changes for every
   rating. The M13 step policy may replace only state and due time with the
   configured same-day step; graduation keeps the FSRS memory result.
6. Again restarts the phase at step zero. Good advances and graduates after the
   final step. Easy graduates immediately. Hard repeats a bounded midpoint
   delay without advancing. Relearning graduation applies the configured
   minimum interval. Review graduation is capped by maximum interval. A lapse
   increments only when Again moves a review-state card into relearning; Again
   on a new/learning/relearning card does not fabricate another lapse.
7. Easy Days is future-only. For an interval of at least two days, the
   scheduler may choose a date within a bounded ±2-day window, never before the
   next day and never beyond the maximum interval. `normal`, `reduced` and
   `minimum` are preference weights, not guarantees. Existing due dates are
   never changed merely by saving the setting.
8. FSRS snapshots add optional `fsrs_step_index`. New snapshots contain it.
   Legacy eight-field snapshots remain valid: match ignores the absent field,
   restore clears the index, and their existing fingerprint algorithm remains
   stable.
9. Auto advance is opt-in, requires a non-zero question or answer timer, and
   never selects a rating. M13 stores and exposes the preference; M17 owns the
   review-screen execution and accessibility behavior.
10. The workload planner is read-only. It returns 30/90/365-day daily and
    aggregate projections using the current scoped eligible cards, due dates,
    stability, difficulty, calculated retrievability, desired retention,
    maximum interval and daily review limit. It reports assumptions and never
    writes ReviewCard, ReviewLog or operation rows.
11. FSRS health diagnostics report insufficient history, Hard/Again misuse
    signals, rating imbalance and incomplete card history. They are warnings,
    never automatic rating corrections or parameter mutations.
12. Parameter application remains future-only. Rescheduling old cards stays
    off by default and continues through the existing preview hash, risk
    confirmation, M6 backup, apply snapshot and undo endpoint.

## Compatibility and exclusions

- Existing V1 Presets, legacy ReviewLog snapshots, Word/Sense queues, mobile
  rating idempotency and review undo remain compatible.
- No arbitrary custom scheduling, sibling bury, deck inheritance, CMRR,
  automatic rating, retroactive Easy Day rewrite or client-authored FSRS state.
- No development/production migration execution or real-user data writes.

## Verification

- V1-to-V2 config normalization, validation and Preset clone/mutate tests;
- migration round-trip and legacy/new snapshot compatibility tests;
- learning/relearning, min/max interval and Easy Day scheduler unit tests;
- protected formal rating, undo/redo, FSRS and WordSense suites;
- read-only 30/90/365 workload and health-diagnostic API tests;
- UI state guards, frontend build and server-bound testing-database browser
  acceptance for settings, planner and guarded reschedule controls.
