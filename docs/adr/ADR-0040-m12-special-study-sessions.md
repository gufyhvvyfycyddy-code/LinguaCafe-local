# ADR-0040: M12 Special Study Sessions

## Status

Accepted / Implemented / Closed

## Context

LinguaCafe already has a stateless, encrypted-token Custom Study flow for five
preview-only queues. M10 established the shared ReviewCard search, WordSense
Tag, Card Marker, source and lifecycle vocabulary. M2/M11 established the
operation ledger and the only reviewed mutation boundaries for formal ratings
and manual scheduling.

M12 must add saved/rebuildable sessions, richer filters and ordering, preview,
formal and early-review behavior without creating a second scheduler or
copying Anki's temporary deck ownership model.

The current Anki manual distinguishes three outcomes that are useful here:

- increasing today's new/review limits changes today's limit instead of
  creating a filtered deck;
- preview mode returns cards without changing their scheduling;
- rescheduling filtered study and review-ahead are formal reviews that change
  scheduling, while recently added cards can be previewed without converting
  them into review cards.

LinguaCafe adopts those outcomes, but not home-deck movement, filtered-deck
ownership or an arbitrary deck tree.

References:

- <https://docs.ankiweb.net/filtered-decks.html>
- <https://docs.ankiweb.net/searching.html>
- <https://docs.ankiweb.net/editing.html>

## Decision

1. M12 introduces a server-authoritative `special_study_sessions` aggregate.
   It owns a normalized definition, an ordered candidate-ID snapshot, progress,
   revision, saved name and active/completed/ended state. It never owns or
   moves ReviewCard rows.
2. The existing `/custom-study/*` preview-token endpoints remain compatible.
   The Custom Study UI moves to the new aggregate API. No existing token is
   silently converted into a formal session.
3. Session execution modes are:
   - `preview`: answers only advance session-local progress and never create a
     ReviewLog, operation, lifecycle event or FSRS write;
   - `formal`: each answer calls
     `ReviewCardService::recordReviewWithLog()` and therefore
     `FsrsSchedulingService::schedule()`, creates exactly one ReviewLog and one
     operation-ledger entry, then advances the session;
   - `early_review`: the same formal rating path, limited to active eligible
     review/relearning cards due after now and within the requested future-day
     window. It explicitly changes the normal queue according to FSRS.
4. `recent_new` is always preview-only. A request that combines it with a
   writing execution mode fails validation. Preview answers never promote a new
   card or change lifecycle/FSRS state.
5. V1 session scopes are `today_forgotten`, `backlog`, `review_ahead`,
   `recent_new` and `filtered`. Filters may include WordSense Tag (all selected
   tags), exact Card Marker values 0–7, source article, source chapter,
   lifecycle and FSRS state.
   Every query is scoped by authenticated user, selected language,
   `target_type=sense` and confirmed WordSense.
6. V1 orders are `most_overdue`, `most_lapses`,
   `lowest_retrievability`, `random` and `source`. Ordering is applied to the
   complete scoped candidate set before the 1–500 session limit. Random order is
   generated once per build and persisted in the candidate snapshot; resume
   does not reshuffle it.
7. Rebuild reruns the saved normalized definition, replaces only the session
   candidate/progress snapshot and increments the revision. It never restores,
   rewrites or deletes prior formal ratings. End marks the session ended and
   rejects later answers. Rebuilding a saved completed or ended session
   explicitly reopens it as active with a new snapshot.
8. A session may be named/saved at creation or later. Saved names are unique
   per user/language after trimming and are capped at 100 characters. Unsaved
   completed/ended rows may remain as audit-supporting session records; M12 adds
   no automatic deletion policy.
9. Answer requests carry `client_action_id` and `expected_revision`.
   `special_study_session_actions` reserves and locks the request, stores a
   canonical request hash and the completed response in the same transaction.
   Exact retries replay; identifier reuse with another payload fails; stale
   revisions fail before any rating.
10. Formal session ratings are registered in the existing `operations` /
    `operation_changes` ledger with `source_channel=web`,
    `operation_type=sense_review.rating` and the Special Study session UUID as
    scope. The ledger registration and ReviewLog/card/session progress update
    share one database transaction. No second operation history is introduced.
11. Formal sessions are a single pass over the built candidate snapshot.
    Again/Hard update FSRS normally but do not inject an extra private learning
    step into the session. If the card becomes due again, rebuilding or the
    normal queue can select it. This avoids a second scheduler with delays that
    disagree with FSRS.
12. Special Study candidate collection is intentionally outside normal daily
    limits. A formal/early answer still creates a normal, non-undone ReviewLog,
    so it counts in today's reviewed/new consumption and immediately affects
    later normal-queue visibility. Preview answers do not.
13. Today's temporary new/review increases reuse
    `ReviewTodayLimitsController`, `ReviewDailyLimitOverrideService` and
    `EffectiveReviewLimitsService`. M12 only presents that existing capability;
    it does not store limit overrides in a session or mutate permanent settings.
14. Eligibility is rechecked under the answer transaction. All modes reject
    missing, cross-scope or unconfirmed targets. Preview may display the
    lifecycle states explicitly requested by the definition, including buried,
    suspended or archived cards, because it is read-only. Formal/early answers
    require the existing active, unburied, FSRS-enabled eligibility and skip
    anything else without a formal write. A session snapshot remains stable even
    if later tag/source metadata changes.
15. Undoing a formal rating through the shared operation ledger restores the
    card/ReviewLog but does not rewind the already advanced Special Study
    progress snapshot. Rebuild is the explicit way to collect the card again.

## Compatibility and exclusions

- Existing normal review, ReviewLog, FSRS, lifecycle, undo and Custom Study
  preview contracts remain compatible.
- No Deck, Note Type, home-deck, filtered-deck movement, sibling bury,
  arbitrary search language or custom scheduling.
- No client writes due/stability/difficulty, no AI writes and no real provider.
- No development/production migration execution or backfill.
- M12 does not change permanent daily-limit settings.

## Verification

- migration rollback/restore on an empty testing schema;
- criteria validation, user/language isolation, complete-set ordering and
  stable rebuild tests;
- preview zero-write tests for every rating and `recent_new`;
- formal/early rating tests proving exactly one ReviewLog, scheduler call and
  ledger operation, including exact replay, conflict, stale revision and
  transaction rollback;
- saved-name, rebuild, end and eligibility-race tests;
- legacy Custom Study, unified search/tag/marker/source and today-limit
  regressions;
- protected Review FSRS, scheduling, WordSense and operation-ledger tests;
- frontend build and real-browser preview, formal-warning, save/rebuild/end,
  filters, ordering and today-limit acceptance on a server-bound testing
  database.
