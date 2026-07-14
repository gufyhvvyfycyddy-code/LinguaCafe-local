# ADR-0019: Review Time Capture and Study Overview V1

**Status:** Accepted
**Date:** 2026-07-14

## Context

LinguaCafe has review activity and FSRS state but no trustworthy answer-time measurement or consolidated Anki-style management overview. Inferring time from gaps between logs is invalid. Analytics must preserve the audit ledger while excluding undone/reset/non-formal actions from product metrics.

## Decision

1. The third and final migration adds nullable unsigned `review_duration_ms` to `review_logs`; no session table or snapshot table is introduced.
2. Both formal review UIs use one pure `ReviewDurationTracker`: monotonic `performance.now()`, visible-time accumulation, pause/resume across visibility/page lifecycle, no reset on answer reveal, reset on card-generation change or successful rating, and a 600000 ms cap. Failed/stale requests do not retry a rating POST automatically.
3. Both rating boundaries validate nullable integer duration in `[0, 600000]`. `ReviewCardService::recordReview()` stores it in the existing transaction. Custom Study neither imports the tracker nor writes duration.
4. Undone logs retain duration for audit. Every Overview activity metric derives from one formal log scope: confirmed sense card, `source=sense_review`, non-reset, and `undone_at IS NULL`.
5. `StudyOverviewQueryService` builds the current card scope, loads cards/logs in fixed batches, and computes distributions from those in-memory collections without additional database access. It calls the existing queue-order retrievability formula rather than duplicating FSRS math.
6. FSRS-state and lifecycle-state distributions are separate. Overdue is before study-day start; current due is `due_at <= now`; the 30 future buckets begin with later-today cards in the current study day and never mix in current-due or overdue cards.
7. Review Time reports measured/unmeasured coverage. Invalid FSRS stability, last-review, interval, or duration values are unavailable rather than guessed.
8. True Retention uses at most the first eligible formal review per card/study day. A sample is eligible only when the before snapshot proves a prior reviewed timestamp and due timestamp, with a positive planned interval. Intervals at least 21 days are mature. Again fails; Hard/Good/Easy pass. Old or incomplete rows are unavailable.
9. Period is limited to 30/90/365 days. Saved Search scope uses ADR-0017 current-membership semantics. Query counts are constant for 1/100/500-card fixtures; no per-card/per-day SQL is allowed.

## Consequences

- A dedicated Study Overview route/page and navigation item are added.
- Payloads return timezone, study date, scope metadata, coverage, unavailable counts, and explicit denominators.
- No FSRS scheduling, queue ordering, undo, lifecycle, or Custom Study behavior changes.

## Verification

- Tracker unit tests for visibility, reveal, generation, failure, cap, and cleanup.
- Backend boundary/transaction/undo coverage tests.
- Empty/default/Saved Search scopes, all distributions, 30-day future due, duration, retention, and 30/90/365 tests.
- Fixed query-budget tests for 1/100/500 cards and full protected-suite regression.
