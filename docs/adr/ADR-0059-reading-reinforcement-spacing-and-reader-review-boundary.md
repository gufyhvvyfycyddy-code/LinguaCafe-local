# ADR-0059 — Reading Reinforcement Spacing and Reader Review Boundary

- Status: Accepted product/architecture direction; implementation pending
- Date: 2026-08-18
- Scope: English Reader → WordSense → ReviewCard / ReviewLog / FSRS interaction

## Context

LinguaCafe uses reading to reduce unnecessary flashcard work, but repeated exposure inside one reading session must not become a shortcut that artificially increases familiarity. The user explicitly requires Anki-like spacing semantics: if a learned word/sense is failed in reading, seeing it again immediately in the same article and pressing “认识/记得” must not count as a successful review. The next successful review must happen through the external Sense Review flow after the scheduling interval makes it eligible.

The existing implementation already has useful pieces:

- one canonical Sense ReviewCard / ReviewLog / FSRS scheduling system;
- `reading_passive` and `reading_explicit` ReviewLog sources;
- ReadingSession identity and same-session interaction evidence;
- same-reading marked-unknown suppression for passive Good;
- one passive settlement per ReviewCard per reading session.

The existing product plan also carried an older “Reader can directly do Again/Hard/Good/Easy as a normal formal review surface” direction. That forward-facing product direction is superseded by this ADR where it conflicts with the spacing boundary below. Historical ReviewLogs and accepted implementation evidence remain valid history.

## Decision

### 1. Positive reading reinforcement must respect the formal interval

A learned Sense may receive a positive `reading_passive` Good only when its ReviewCard is currently due under the same canonical Sense Review due semantics used by the external review queue.

Reading must not invent a second cooldown, local interval, or “recently seen” timer. The due/eligibility truth remains the existing ReviewCard/FSRS queue truth.

If a learned Sense is encountered before it is due, the occurrence may still be recorded as reading evidence, but it does not advance FSRS or familiarity.

### 2. Repeated occurrences never stack positive credit

Within one ReadingSession, the same ReviewCard can receive at most one positive reading reinforcement. Multiple occurrences of the same Sense in the same article/session do not create multiple Goods.

A new ReadingSession also cannot bypass the formal due condition. Reopening or rereading immediately before the card is due remains exposure only.

### 3. “不认识” creates a failure boundary

When the user explicitly marks an occurrence as unfamiliar and that occurrence is later resolved to an **already learned existing WordSense**:

- the Reader must not later convert another occurrence of that same ReviewCard in the same ReadingSession into Hard/Good/Easy or passive Good;
- a later “认识/记得” action in the same reading is not a successful review;
- the failure cannot be cancelled by immediate repeated exposure.

Once the exact existing WordSense identity is known, the failure may be committed at most once as `Again` through the existing canonical formal rating writer and `FsrsSchedulingService`. It must not create a second Reader-only scheduling system.

The next successful positive rating for that card must occur in the external Sense Review page when the canonical FSRS schedule makes the card eligible.

### 4. New Sense is not a lapse

If the unfamiliar occurrence resolves to `new_sense`, this is first-time learning rather than failure of an already learned Sense.

Creating/enrolling the new WordSense must not manufacture an `Again` ReviewLog in the same reading. Its later formal review begins through the normal external Sense Review flow.

### 5. Help/opening suppresses passive success

If the user opens the occurrence, asks for help, inspects the answer, or otherwise uses assistance that means the Sense was not naturally recognized, that occurrence/card is not eligible for passive Good in that ReadingSession.

### 6. Reader is not the short-interval relearning surface

The Reader may:

- expose natural reading reinforcement when due and unassisted;
- record explicit unfamiliar/failure evidence;
- help resolve the exact WordSense;
- show that the card now needs later review.

The Reader must not run the short-interval relearning loop after a failure. The ordinary successful Again/Hard/Good/Easy follow-up belongs to `/reviews/senses`.

Existing `reading_explicit` historical rows remain valid. Forward product UI does not need to preserve a generic in-Reader four-button review surface merely for compatibility.

## Consequences

1. `ReadingFinishSettlementService` must eventually reuse the canonical **due** predicate, not only lifecycle/enabled eligibility, before writing passive Good.
2. Same-session unfamiliar evidence must suppress all later positive Reader credit for the same ReviewCard.
3. Existing marked-unknown → same-session passive exclusion is useful and should be reused rather than replaced.
4. If an existing learned Sense is resolved after the user marked it unfamiliar, any formal `Again` must wait until the exact WordSense is known and must go through the existing formal rating writer with idempotency protection.
5. `new_sense` enrollment remains separate from a lapse.
6. Learning history may continue to distinguish `reading_passive`, `reading_explicit`, and `sense_review`, but current UI should explain reading failures and later external review in user language rather than exposing internal source names.
7. No new Reader scheduler, cooldown table, repeated-exposure counter, or second FSRS authority is authorized.

## Superseded forward statements

Where they conflict with this ADR, the following older forward-facing statements are historical only:

- `docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md` PD-012 as a requirement for a generic Reader Again/Hard/Good/Easy review surface;
- `docs/plans/linguacafe-slim-product-master-plan-2026-08-07.md` §6 and Phase B language that lets same-reading explicit positive rating act as the normal post-failure path;
- Mobile/Reader planning language that treats Reader explicit rating as interchangeable with the external spaced-review loop.

Historical code, ReviewLogs, tests, and completed milestone evidence are not invalidated by this product supersession.

## Implementation gate

Implementation is high risk because it touches Reader interaction evidence, ReviewLog semantics, ReviewCard due eligibility, and FSRS scheduling. Before product code changes:

- perform an Architecture Gate against the current committed code;
- prove the canonical due predicate and writer seam;
- define idempotency for delayed resolution of marked-unknown → existing WordSense;
- update the smallest relevant tests/harness;
- use a dedicated testing DB for any write-path browser acceptance;
- use real Reader and external Sense Review UI acceptance; API-only evidence is insufficient.
