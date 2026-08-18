# ADR-0061 — Reading Early Review Minimum Spacing Boundary

- Status: Accepted product/architecture direction; implementation pending
- Date: 2026-08-18
- Scope: English Reader → exact WordSense → ReviewCard / ReviewLog / FSRS positive-rating eligibility
- Supersedes: ADR-0060 as the forward Reader spacing authority

## Context

ADR-0060 correctly replaced the former due-only rule: a learned exact Sense may be genuinely recalled in reading before its current `fsrs_due_at`, and that early recall may become a formal `Good` through the existing ReviewCard / ReviewLog / FSRS writer.

However, ADR-0060 intentionally left cross-ReadingSession anti-farming unresolved. The first R2 architecture proposal then allowed one Reader `Good` immediately after a non-reading positive formal rating, because it tried to avoid adding any time threshold. The user has now explicitly rejected that behavior.

The required product behavior is:

- article/session boundaries do not reset eligibility;
- a card that was just formally reviewed cannot immediately receive another positive Reader rating merely because it appears again in another article;
- after a meaningful minimum interval has elapsed, a genuine reading recall may count as an early review even when the FSRS due time is still farther away.

Current Anki/FSRS evidence supports keeping same-day repetition separate from meaningful longer-term spacing:

- Anki's current FSRS guidance recommends keeping same-day learning/relearning repetitions minimal because repeated same-day reviews contribute little to long-term memory compared with spending that time elsewhere;
- Anki's Review Ahead behavior acknowledges early reviews, but warns that reviewing shortly after a card was scheduled has little scheduling impact and is not appropriate for repeated use;
- Anki's True Retention table counts only the first review of a card per day;
- FSRS-6 can mathematically consume same-day reviews, so a one-day product boundary is not an FSRS limitation. It is a LinguaCafe anti-farming/product-evidence rule.

References:

- Anki Manual — Deck Options / Learning and Relearning Steps: https://docs.ankiweb.net/deck-options
- Anki Manual — Filtered Decks / Reviewing Ahead: https://docs.ankiweb.net/filtered-decks.html
- Anki Manual — Statistics / True Retention: https://docs.ankiweb.net/stats.html
- Open Spaced Repetition — ABC of FSRS: https://github.com/open-spaced-repetition/awesome-fsrs/wiki/ABC-of-FSRS
- Open Spaced Repetition — The Algorithm: https://github.com/open-spaced-repetition/awesome-fsrs/wiki/The-Algorithm

## Decision

### 1. Reader positive ratings have a full 24-hour minimum spacing floor

A Reader-derived positive `Good` is eligible only when at least **24 elapsed hours** have passed since the card's latest effective non-undone formal rating.

This is an elapsed-duration rule, not a local-calendar-day rule.

Therefore:

- 23 hours 59 minutes after the latest formal rating → no Reader Good;
- 24 hours or more after the latest formal rating → Reader Good may be eligible;
- crossing midnight does not by itself reset eligibility;
- the rule is identical across the same article, different articles, the same ReadingSession, and different ReadingSessions.

The 24-hour floor is not user-configurable in this phase. Changing it later requires an explicit product/ADR decision backed by evidence.

### 2. The 24-hour anchor is the latest effective formal rating, regardless of source

The anti-farming anchor is the latest non-undone formal ReviewLog that belongs to the card's current FSRS lineage and actually represents a rating event.

The source may include:

- `sense_review`;
- `reading_passive`;
- `reading_explicit`;
- `special_study` or another existing formal rating source that uses the canonical writer.

The source does not grant an exception. In particular:

- external Sense Review Good → Reader 30 seconds later: blocked;
- Reader Good → different article 2 hours later: blocked;
- Reader Good → different article 25 hours later: may be eligible even if current due is still in the future.

Reset/admin/non-rating audit rows are not positive-review anchors unless the existing formal-rating contract already treats them as actual ratings.

Implementation must verify that the selected ReviewLog anchor is consistent with the current ReviewCard `fsrs_last_reviewed_at`; inconsistent legacy state fails closed for an early positive until the canonical state is repaired or the card becomes eligible through the normal review path.

### 3. After 24 hours, genuine early Good remains allowed before due

The 24-hour floor does not restore the old ADR-0059 due-only rule.

For an active learned exact Sense in normal `review` state:

- if `eventAt - lastEffectiveFormalRatingAt >= 24h`, and the Reader evidence otherwise qualifies, a formal Reader `Good` may occur even when `fsrs_due_at > eventAt`;
- the canonical writer receives the real `eventAt`;
- FSRS recalculates stability/difficulty/next due from that real review event;
- the new formal rating becomes the next 24-hour anchor.

Example:

- last formal review: day 0;
- current FSRS forecast due: day 30;
- genuine reading recall: day 7;
- day 7 is more than 24 hours after the last formal review, so one early Good is allowed;
- FSRS then recalculates the next schedule from day 7;
- another Reader encounter on day 7 or day 7.5 is blocked by the new 24-hour floor;
- a genuine encounter on day 8+ may again be considered for early Good even if the newly calculated due remains later.

### 4. Reader does not take over same-day learning/relearning

The positive 24-hour Reader rule applies to learned review-state cards.

New / learning / relearning short-step behavior remains owned by the existing external Sense Review / FSRS path. Reader must not become a same-day learning-step runner.

This avoids two contradictory product behaviors:

- allowing meaningful natural early review of a learned card;
- turning normal reading into a short-interval drill loop.

### 5. Again remains genuine failure evidence and is not suppressed by the 24-hour positive floor

The 24-hour floor gates **positive Reader Good**, not truthful failure evidence.

If the user explicitly says `不认识` and the occurrence is reliably resolved to an already learned exact WordSense:

- one formal `Again` may still be recorded even if fewer than 24 hours have passed since the previous rating;
- same ReadingSession / ReviewCard still has at most one formal Reader settlement;
- later positive evidence in that same ReadingSession cannot overwrite the Again;
- positive follow-up/relearning stays in the external Sense Review flow, not Reader.

A genuinely `new_sense` remains first learning: zero Again and zero same-ReadingSession Good.

### 6. Same-session one-credit remains a stronger local guard

ADR-0060's same-ReadingSession single-credit rule remains part of this ADR:

- one ReadingSession / ReviewCard → at most one active formal reading-derived rating;
- ten occurrences do not become ten ratings;
- explicit Good prevents Finish Reading from adding passive Good;
- Again prevents later same-session positive credit;
- retry/replay must not duplicate ReviewLog rows.

The cross-session 24-hour floor and same-session settlement guard are complementary, not alternative systems.

### 7. Passive and explicit Good keep distinct evidence semantics

`reading_passive Good` requires reliable exact-Sense identity and natural unassisted recognition.

`reading_explicit Good` is allowed when the learner deliberately opens the recognition flow, decides `认识 / 记得` before relying on an answer, and confirms the exact existing Sense.

Opening a word is not itself help. Actual answer/definition assistance before self-recognition blocks that occurrence from claiming passive or explicit self-recognition Good.

Both positive paths must also pass the 24-hour floor.

### 8. No second scheduler or persisted Reader due is authorized

The 24-hour rule is an eligibility predicate over existing formal history, not a second scheduling system.

Do not add:

- `reader_due_at`;
- a Reader cooldown table;
- a second FSRS scheduler;
- a half-interval formula;
- an independent retrievability cutoff;
- a user-configurable early-review timer;
- calendar-day state.

The implementation may compute `lastEffectiveFormalRatingAt + 24h` transiently from existing ReviewLog / ReviewCard facts. It does not persist a second due date.

### 9. Mobile/offline uses the same event-time rule

For Web, `eventAt` is the accepted server review time.

For Mobile queued actions, `eventAt` is the already validated canonical `occurred_at` used by the existing offline rating pipeline and FSRS writer.

The server is final authority. The client must not independently decide that 24 hours have elapsed and mutate FSRS locally.

Existing replay, future-time, stale-event, and out-of-order protections remain in force.

## Consequences

1. The G-06B R2 source-aware exception that allowed one Reader Good immediately after an external positive formal rating is rejected and must not be implemented.
2. G-06B implementation needs one locked, reusable positive-eligibility check close to the canonical formal writer so concurrent ReadingSessions cannot both pass a stale preflight.
3. UI preflight may explain `本次只记录阅读遇见，不计复习` when the 24-hour floor has not elapsed, but the locked server check remains authoritative.
4. Same-session card settlement remains the local dedupe owner.
5. Existing FSRS mathematics are unchanged.
6. Existing ReviewLog becomes the audit source for the 24-hour anchor; no new temporal storage is required unless an implementation Architecture Gate proves current history cannot safely identify the latest effective formal rating.
7. Automated tests must cover 23h59m blocked, exactly 24h allowed, 25h allowed, cross-article/session cases, external→Reader immediate block, Reader→Reader immediate block, concurrency, undo, Again, and Mobile offline event time.

## Supersession map

ADR-0061 preserves ADR-0060's following decisions:

- not-due genuine review may count early;
- passive Good / explicit Good / explicit Again all reuse the canonical writer;
- same ReadingSession / ReviewCard has one settlement;
- opening is not automatically help;
- `不认识` existing Sense → at most one Again;
- `new_sense` is first learning, not lapse;
- Reader does not create a second scheduler.

ADR-0061 replaces ADR-0060's unresolved / R2-derived cross-session policy with the fixed full-24-hour minimum positive spacing rule.

ADR-0059 remains older historical due-only evidence and is not forward authority.

## Implementation gate

G-06B remains high risk. Before implementation, a corrected R3 Architecture Gate must prove against current code:

- exact latest-effective-formal-rating selection and undo semantics;
- current ReviewCard / ReviewLog lineage consistency;
- 24-hour elapsed-time comparison at the locked writer;
- review-state vs learning/relearning boundary;
- passive / explicit Good and Again interactions;
- same-session settlement unification;
- Web and Mobile/offline event-time idempotency;
- focused tests and real Reader + external Sense Review acceptance in the dedicated testing database.
