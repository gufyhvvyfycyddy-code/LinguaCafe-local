# ADR-0018: Today-only Review Limits and New-card Introduction Accounting

**Status:** Accepted
**Date:** 2026-07-14

## Context

Permanent daily limits exist, but the queue currently limits only cards that are still in `new` state. After a first rating changes the state, another new card can enter, so the limit is not cumulative. A temporary Anki-style today-only override must not mutate permanent settings and both formal review entry points must consume the same counters.

## Decision

1. One additive, reversible migration creates user/language/study-date overrides containing review/new deltas and `pause_new_cards`.
2. The V1 study day is the global IANA application timezone. One trusted `now` is captured per request and produces DST-safe `[day_start, next_day_start)` boundaries by constructing consecutive local midnights.
3. `ReviewDailyProgressQueryService` is the sole source of `reviewed_today_count` and `introduced_today_count`. Formal rows use `source=sense_review`, non-reset ratings, and `undone_at IS NULL`; introduction additionally requires a sense-card target.
4. A card is introduced only by its first-ever valid formal rating when that row has `previous_state=new`. A `NOT EXISTS` check excludes any card with an earlier valid formal rating, so reset-to-new does not introduce it again.
5. The legacy `/reviews/rate` endpoint already rates the same confirmed sense-card queue; it therefore explicitly records `source=sense_review`. The default `ReviewCardService` source remains unchanged for compatibility and historical logs are not rewritten.
6. `EffectiveReviewLimitsService` combines permanent settings, the current-day override, and both counters. Remaining new capacity is `max(0, effective_new_limit - introduced_today_count)`. Pause wins and forces new capacity to zero while preserving its stored delta. Existing enable flags and new-cards-ignore-review-limit semantics remain intact.
7. `SenseReviewService` remains the shared queue path for both formal review UIs. Custom Study never consumes these overrides.

## Consequences

- GET/PUT/DELETE override APIs derive user, language, and study date server-side.
- The summary exposes permanent, override, effective, consumed, and remaining values plus timezone/study-date metadata.
- Permanent settings rows and FSRS scheduling are not changed.

## Verification

- Cumulative introduction, reset-to-new, undone/reset/non-sense/word exclusions.
- Cross-user/language, midnight, and DST boundaries.
- Pause/unpause, delta, next-day expiry, delete/reset, and both-entrypoint parity.
- Settings fingerprints, undo, lifecycle, queue order, and FSRS regressions.
