# ADR-0060 — Reading Opportunistic Early Review and Single-Credit Boundary

- Status: Superseded for forward Reader spacing by ADR-0061
- Date: 2026-08-18
- Scope: English Reader → exact WordSense → ReviewCard / ReviewLog / FSRS interaction
- Supersedes: ADR-0059 forward `due-only` positive-reading rule
- Superseded by: ADR-0061 full-24-hour minimum positive Reader spacing rule

> Historical note: this ADR correctly introduced opportunistic not-due review and same-session single-credit, but deliberately left the cross-ReadingSession minimum interval unresolved. The later R2 proposal allowed an immediate Reader Good after an external positive rating; the user rejected that behavior. Current forward authority is `ADR-0061-reading-early-review-minimum-spacing-boundary.md`. Do not implement this ADR's unresolved/no-fixed-threshold cross-session section as current policy.

## Context

Reading is part of LinguaCafe's real memory practice. A learned WordSense can be encountered naturally before its currently scheduled due time. The user explicitly requires that a genuine early recall still count as a real review instead of being discarded merely because `fsrs_due_at` is in the future.

Example: a ReviewCard is currently scheduled roughly 30 days out. On day 7 the learner meets the exact Sense while reading and genuinely remembers it. That encounter may become one formal `Good`. FSRS must receive the actual review time and recalculate memory state and the next schedule from that event.

At the same time, repeated occurrences inside the same reading must never manufacture multiple reviews. If the same Sense appears ten times in one article, or the user confirms the same Sense again a few sentences later, those repeated occurrences do not become ten ratings.

The current code already supports the important scheduling primitive: `ReviewCardService::recordReviewWithLog()` can formally rate an active Sense card without requiring it to be due, and `FsrsSchedulingService::schedule()` uses the actual `reviewed_at` / elapsed time since the prior formal review when computing the next state. The missing work is Reader eligibility, identity, failure semantics, and deduplication — not a second scheduler.

## Decision

### 1. A genuine reading review may happen early

A learned existing WordSense does **not** have to be currently due before reading can produce a formal review.

When the exact existing Sense is reliably known, reading can create one formal rating at the actual encounter time:

- natural, unassisted recognition settled at Finish Reading → `Good`, source `reading_passive`;
- explicit self-recognition after the learner confirms the exact existing Sense and chooses “认识 / 记得” → `Good`, source `reading_explicit`;
- explicit failure “不认识”, once the exact existing Sense is known → `Again`, source `reading_explicit`.

These ratings reuse the existing canonical ReviewCard / ReviewLog / FSRS writer. Reading must not store a parallel familiarity score or compute its own interval.

### 2. One ReadingSession / ReviewCard has one formal settlement

Within one ReadingSession, one ReviewCard may receive at most one formal reading-derived rating.

All occurrences that resolve to the same ReviewCard collapse to that one settlement. Therefore:

- one article containing the same Sense ten times still creates at most one rating;
- confirming the same Sense again later in another sentence creates no second Good;
- Finish Reading cannot add passive Good after an explicit Good or Again already settled the same card;
- retries and repeated UI actions must replay or no-op instead of creating another ReviewLog.

The existing `reading_session_card_settlements` identity is the preferred DB-level single-credit guard; do not add a second deduplication table merely for this rule.

### 3. Passive recognition and explicit recognition are different evidence paths, not different schedulers

A passive Good means the learner naturally passed the occurrence without using the answer/help flow and the occurrence can be reliably bound to an existing WordSense.

An explicit Good means the learner intentionally opens the recognition flow, first identifies/recalls the meaning, confirms the exact existing WordSense, and chooses “认识 / 记得”. The fact that the user clicked the word does not by itself invalidate this explicit Good.

Opening help, revealing an answer because the learner needs assistance, or otherwise depending on assistance prevents that occurrence from being treated as **passive** recognition. It must not be conflated with an explicit self-reported Good.

### 4. “不认识” on an existing Sense is one Again and wins for that ReadingSession

If the learner marks an occurrence as unfamiliar and it later resolves to an already learned existing WordSense:

- the exact ReviewCard may receive at most one formal `Again` in that ReadingSession;
- after this failure, later occurrences of the same ReviewCard in the same ReadingSession cannot produce Good/Hard/Easy/passive Good;
- an immediate later “现在又认识了” does not erase the failure;
- the Reader must not run its own relearning timer or repeated short-step loop.

The short-interval follow-up after Again remains owned by the existing formal Sense Review / FSRS scheduling system.

### 5. A genuinely new Sense is first learning, not a lapse

If the occurrence resolves to `new_sense`, first enrollment does not create an `Again`. It also does not receive a Good merely because the new card was created in the same reading.

The new WordSense enters the existing Sense Review system as new learning.

### 6. The current due time is not a prerequisite for the first qualifying reading rating

ADR-0059's rule “not due = exposure only” is superseded.

The original scheduled due is an FSRS forecast, not a prohibition against a genuine earlier review. If reading creates an early formal Good/Again, the canonical scheduler recalculates the card from the actual event time and persists a new `fsrs_due_at`.

Historical decision at this point: no Reader-specific “7 days”, “half the interval”, fixed cooldown, or retrievability threshold was frozen here. ADR-0061 later freezes a full 24-hour elapsed positive-rating floor as product anti-farming policy.

### 7. Cross-session anti-farming must reuse existing review/FSRS facts

This ADR fully freezes same-ReadingSession single-credit behavior. It also freezes the product principle that a user must not be able to farm familiarity by repeatedly opening new reading sessions around the same card.

The exact minimal cross-session eligibility predicate is an implementation Architecture Gate question because the user has explicitly allowed meaningful early review. The implementation must:

- preserve the day-7-of-a-30-day-forecast early-Good example;
- reject immediate/redundant repeated reading ratings that would merely stack credit;
- derive the decision from existing ReviewLog / ReviewCard / FSRS facts where possible;
- avoid a second scheduler, second due truth, arbitrary Reader cooldown table, or new persisted “reading eligibility date” unless current facts prove insufficient.

This unresolved Architecture Gate question has now been superseded by ADR-0061: positive Reader Good requires a full 24 elapsed hours since the latest effective formal rating, across articles and ReadingSessions.

### 8. Reader ordinary rating UX is narrower than formal Sense Review

The ordinary Reader need not expose the full engineering-style Again / Hard / Good / Easy four-button surface.

Forward product intent is centered on the reading decision:

- `认识 / 记得` → Good after exact existing-Sense confirmation;
- `不认识` → Again after exact existing-Sense confirmation;
- uncertain identity → resolve/ambiguous path, no rating yet;
- new Sense → first learning, no lapse.

Hard/Easy remain valid formal FSRS ratings in the external Sense Review experience. Historical `reading_explicit` four-rating ReviewLogs remain valid historical data and must not be rewritten.

The exact Reader UI migration is part of G-06B R2 Architecture Gate before implementation.

## Consequences

1. `ReadingFinishSettlementService` must no longer gain the ADR-0059 due-only predicate as a prerequisite for the first legitimate reading Good.
2. The current Reader explicit rating infrastructure can be reused because it already reaches the canonical formal writer and does not require `fsrs_due_at <= now`.
3. Same-session formal settlement must be card-level, not occurrence-level, so multiple sentences cannot stack ratings.
4. Marked-unknown needs durable ReadingSession failure evidence so removing a visible marker cannot erase a failure already made in that session.
5. Passive Good, explicit Good, and explicit Again are different evidence/source cases but all use the same scheduler.
6. `new_sense` remains separate from failure/review.
7. Cross-session anti-farming must be solved without resurrecting due-only as the first-review prerequisite.
8. Mobile/offline must reuse the same server-owned semantics and existing idempotent action infrastructure; no client-local FSRS scheduler is authorized.

## Superseded forward statements

The following ADR-0059 forward rules are superseded by this ADR:

- positive reading reinforcement only when the card is already due;
- not-due reading is always exposure-only;
- a new ReadingSession before due can never produce a legitimate early Good.

ADR-0059 remains historical evidence for the still-valid principles that repeated same-session exposure must not stack credit, existing-Sense unfamiliar failure cannot immediately flip to success, new Sense is not a lapse, and Reader must not create a second scheduler.

## Implementation gate

G-06B implementation remains high risk. Before code changes, a corrected R2 Architecture Gate must prove:

- current early-rating behavior through `ReviewCardService` / `FsrsSchedulingService`;
- passive vs explicit Good evidence boundaries;
- durable `marked_unknown` session evidence and exact-Sense Again;
- one settlement per ReadingSession / ReviewCard;
- the smallest cross-session anti-farming predicate that still allows meaningful early review;
- Web and Mobile/offline idempotency;
- focused automated tests and real Reader browser acceptance using a dedicated testing database.
