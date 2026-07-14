# ADR-0016: Custom Study Preview Session

**Status**: Accepted (architecture complete; **Phase 1 Accepted (Task 2000-16 + Task 2000-17 error contract fix). Phase 2A Accepted (Task 2000-17, Builder contract docs fixed in Task 2000-18). Phase 2B Accepted (Task 2000-18: `EloquentChapterLocator` + `SourceChapterQuery` + `LeechAttentionQuery` + `CustomStudyQueryService`). Phase 3A Accepted (Task 2000-19 + Task 2000-20 docs closure): `CustomStudySessionState` (immutable value object with `completed_ids` + `skipped_ineligible_ids` fields, five-state union + mutual exclusion invariants, no DB/Auth/Request/Crypt) + `CustomStudySessionTokenService` (only encrypts/decrypts/verifies, no rotate/answer/rating/SessionService/PreviewPolicy, injects `Illuminate\Contracts\Encryption\Encrypter`, `MAX_TOKEN_BYTES=65536`, `DEFAULT_TTL_SECONDS=14400`). Phase 3B Accepted / Closed (Task 2000-22 final closure, code/tests completed in Task 2000-20, docs/harness status drift closed in Task 2000-21): `CustomStudyPreviewPolicy` (pure state transition function — `applyRating(state, rating, now)` + `resume(state, now)`; only accepts four lowercase ratings `again`/`hard`/`good`/`easy`; Again/Hard → `delayed_repeat_queue`, Good/Easy → `completed_ids`; picks next `current_card_id` from `ready_queue` first, else earliest mature `delayed_repeat_queue` entry; does NOT touch DB/Auth/Request/Crypt/ReviewLog/FSRS/lifecycle/AI; does NOT call `toArray()`/`fromArray()`, only `withProgress()`) + `CustomStudySessionState::withProgress()` (immutable copy boundary — preserves identity fields, accepts the new five-state, auto-recomputes `completed_count`/`total_count`, auto-increments `step`, rejects `step === PHP_INT_MAX` overflow) + `CustomStudySessionState::waitUntil()` + `CustomStudySessionState::isCompleted()` + `CustomStudyPreviewPolicyException` + Token constants (`VERSION`, `MAX_CANDIDATE_COUNT`) referencing `CustomStudySessionState` (single source of truth). Phase 4A Accepted / Closed (Task 2000-22 final closure, code/tests completed in Task 2000-21): `CustomStudySessionOrder` (pure session-internal ordering service — batch-loads ReviewCard once filtering user+language+target_type=sense; computes one canonical fallback rank via `ReviewQueueOrderService::order()`; per-mode primary sort: source_chapter = canonical, overdue = retrievability ASC, today_forgotten = latest today-again DESC, leech_attention = severity DESC; tie-break on canonical fallback; does NOT apply card_limit, does NOT create SessionState/token, does NOT write any table, does NOT modify Queue Order settings, does NOT re-run Criteria queries). Phase 4B Backend session vertical slice code/tests complete pending web-side acceptance (Task 2000-22): `CustomStudySessionState::available_candidate_count` + `withEligibilityResolution()` same-step immutable boundary, `CustomStudyPreviewPolicy::resolveEligibility()` pure method, `CustomStudySessionEligibilityService` batch eligibility resolver, `CustomStudySessionException` for token-not-found, `CustomStudySessionService` open/answer/resume orchestrator (no Auth/Request/Session/Settings facade access), `CustomStudyController` HTTP boundary, three POST routes `/custom-study/sessions` + `/custom-study/sessions/answer` + `/custom-study/sessions/resume`, `candidate_count` product Gate closed as Option A (full available candidate count, not card_limit-truncated). Phase 5-7 NOT started; overall feature incomplete (no frontend, no chapter picker UI)**; this ADR only defines the architecture and V1 boundary; no Custom Study API, page, or migration is authorized by this ADR alone beyond Phase 1 value objects/validator, Phase 2A read-only candidate queries, Phase 2B chapter locator + source_chapter/leech_attention queries + unified candidate ID dispatcher, Phase 3A immutable session state + encrypted token service, Phase 3B pure state transition policy, Phase 4A session-internal ordering, and Phase 4B backend session orchestration vertical slice)
**Date**: 2026-07-13 (Phase 1 added 2026-07-14 by Task 2000-16; error contract fixed + Phase 2A added 2026-07-14 by Task 2000-17; Phase 2A docs fix + Phase 2B added 2026-07-14 by Task 2000-18; Phase 2B Accepted + Phase 3A SessionState/TokenService + state contract fix + chapter picker future registration added 2026-07-14 by Task 2000-19; Phase 3A Accepted + Phase 3B PreviewPolicy + withProgress + transition contract closure added 2026-07-14 by Task 2000-20; Phase 3B docs/harness status drift closed + Phase 4A `CustomStudySessionOrder` + `candidate_count` future contract registered added 2026-07-14 by Task 2000-21; Phase 3B/4A Accepted/Closed + Phase 4B backend session vertical slice + `available_candidate_count` + `withEligibilityResolution` + `resolveEligibility` + `CustomStudySessionEligibilityService` + `CustomStudySessionService` open/answer/resume + `CustomStudyController` + three POST routes + `candidate_count` Gate closed as Option A + V1 query budget truthfully recorded added 2026-07-14 by Task 2000-22)
**Related**: `docs/adr/ADR-0009-review-action-ledger-and-stack-undo.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`, `docs/adr/ADR-0015-review-queue-order-policy.md`, `docs/plans/custom-study-1a-implementation-plan.md`

## Context

LinguaCafe's next main-line task after Queue Order is Custom Study 1A. Anki's Custom Study feature lets users build ad-hoc review sessions outside the normal due queue. This ADR freezes the LinguaCafe V1 mapping of that feature — specifically, a **preview-only temporary session** that does not move cards, does not build a filtered deck, does not write ReviewLog, and does not run FSRS scheduling.

### Anki official reference

Sources reviewed (2026-07-13):
- Anki Manual: "Filtered Decks & Cramming", "Custom Study", "Home Decks", "Creating Manually", "Order", "Steps & Returning", "Due Reviews", "Reviewing Ahead", "Rescheduling"
- Anki repository: `proto/anki/decks.proto`, `proto/anki/scheduler.proto`, `rslib/src/scheduler/filtered/custom_study.rs`, `rslib/src/scheduler/answering/preview.rs`, `qt/aqt/customstudy.py`, `qt/aqt/filtered_deck.py`
- Anki repository commit: `9863b2f142e9b65e90741ab450fcebfd00f3c6ba` (main branch, 2026-07-13)
- Anki latest stable release: `26.05` (published 2026-06-16, tag `26.05`)

**Anki Custom Study presets** (from `custom_study.rs` `custom_study_inner` + `customstudy.py` `CustomStudyRequest`):

| Preset | What it does | Creates filtered deck? | reschedule default | Order |
|---|---|---|---|---|
| `NewLimitDelta` | Increase today's new card limit | No — only mutates today's deck-config limit | N/A | N/A |
| `ReviewLimitDelta` | Increase today's review limit | No — only mutates today's deck-config limit | N/A | N/A |
| `ForgotDays` | Review cards forgotten in the last N days | Yes | `false` (preview mode) | `RANDOM` |
| `ReviewAheadDays` | Review cards due in the next N days | Yes | `true` | `DUE` |
| `PreviewDays` | Preview cards added in the last N days | Yes | `false` (preview mode) | `ADDED` |
| `Cram` | Cram arbitrary search string | Yes | varies by `CramKind` | varies |

Key facts:
- Only `NewLimitDelta` and `ReviewLimitDelta` mutate today-only limits without creating a filtered deck. These are out of scope for Custom Study 1A (they belong to the later `today-only limits` main-line).
- `ForgotDays`, `PreviewDays`, and `Cram` create filtered decks. `ForgotDays` and `PreviewDays` hard-code `reschedule=false` (preview mode); `ReviewAheadDays` defaults `reschedule=true`; `Cram` reschedule depends on `CramKind` (New/Due/Review = true, All = false).
- **Cram card limit**: `cram_config` passes `Some(cram.card_limit)` to `custom_study_config`; other presets use `None` which defaults to `99_999` in `custom_study_config`.
- **Preview mode delays** (from `custom_study_config` defaults): `preview_again_secs = 60`, `preview_hard_secs = 600`, `preview_good_secs = 0` (return card), `preview_delay = 10`. Easy always returns the card (from `preview.rs` test: `scheduled_secs: 0, finished: true`).
- **Preview mode revlog behavior** (from `preview.rs` `apply_preview_state`): Anki **constructs a `RevlogEntryPartial`** on every preview answer, including Again/Hard/Good/Easy. When `reschedule=false` (preview mode), the revlog entry is created with `ease_type` reflecting the filtered state but the card is restored to its original queue/due on exit. **LinguaCafe intentionally deviates** by not writing any ReviewLog in preview-only sessions (see §1.1 below).
- **Filtered deck exclusions** (always): suspended, buried, and already-filtered cards are excluded via `-is:suspended -is:buried -deck:filtered` appended to the user search.
- **Filtered deck order options** — official `Deck.Filtered.SearchTerm.Order` enum from `proto/anki/decks.proto` (11 values):

| Enum name | Number | Description |
|---|---|---|
| `OLDEST_REVIEWED_FIRST` | 0 | Oldest reviewed first |
| `RANDOM` | 1 | Random |
| `INTERVALS_ASCENDING` | 2 | Intervals ascending |
| `INTERVALS_DESCENDING` | 3 | Intervals descending |
| `LAPSES` | 4 | Lapses |
| `ADDED` | 5 | Added |
| `DUE` | 6 | Due |
| `REVERSE_ADDED` | 7 | Reverse added |
| `RETRIEVABILITY_ASCENDING` | 8 | Retrievability ascending |
| `RETRIEVABILITY_DESCENDING` | 9 | Retrievability descending |
| `RELATIVE_OVERDUENESS` | 10 | Relative overdueness |

Note: older Anki versions used different enum names (`introducedAsc`, `introducedDesc`, `oldestReviewedFirst`, `latestReviewedFirst`, `relativeOverduenessAsc`, `relativeOverduenessDesc`, `dueAsc`, `dueDesc`, `easeAsc`, `easeDesc`). These have been replaced by the current proto enum above. This ADR references the **current** enum.

### LinguaCafe current capability reconnaissance

| Capability | Status | Source |
|---|---|---|
| `ReviewLog.source = 'sense_review'` + `rating = 'again'` + `undone_at IS NULL` | Exists | `ReviewLog` model, ADR-0009 |
| `SenseReviewLeechPolicy` (stable/struggling/leech) | Exists, pure function, no DB | ADR-0011 |
| `WordSense.source_chapter_id` | Exists | `WordSenses` table |
| `WordSenseOccurrence.chapter_id` (status=bound) | Exists | `WordSenseOccurrences` table |
| `review_session_id` (UUID per tab) + sessionStorage | Exists | ADR-0009 |
| Browser Search parser/criteria/applier | Exists | ADR-0012/0013, supports `is:leech`, `is:struggling`, `rated:again`, `prop:lapses` |
| `ReviewQueueOrderService` (unified order) | Exists | ADR-0015 |
| `ReviewStudyTimezoneService` (unified learning timezone) | Exists | ADR-0015 §19 (2000-10A) |
| **Card marker / flag / tag** | **Does NOT exist** | No `flag`/`marker`/`tag` columns on `review_cards` or `word_senses`; no marker model/table |

The "marked" criterion from the task spec is preserved as a **Custom Study 1B prerequisite: Card Marker**. This ADR does not fabricate it, does not reuse lifecycle state as a marker proxy, and does not reuse leech status as a marker proxy.

## Decision

### 1. Preview-only temporary session

Custom Study 1A is a **preview-only temporary session** — the LinguaCafe mapping of Anki's preview mode. The session:

1. Does **not** move ReviewCard.
2. Does **not** build a deck or filtered deck (LinguaCafe has no deck model).
3. Does **not** change the normal due queue membership.
4. Does **not** modify `lifecycle_state` (ADR-0010).
5. Does **not** modify `fsrs_due_at` or any FSRS field.
6. Does **not** write `ReviewLog`.
7. Does **not** run formal FSRS scheduling.
8. Defaults to **preview-only**.
9. On session exit, the normal queue is completely unchanged.
10. Session is scoped to current user + current language + sense cards only.

This is the LinguaCafe V1 interpretation of Anki's `FilteredState::Preview` — "look at these cards in this order, then put everything back".

### 1.1 LinguaCafe intentional deviations from Anki

The following are **deliberate product deviations** from Anki's preview mode, not claims that Anki works the same way:

| Dimension | Anki behavior | LinguaCafe 1A behavior | Reason |
|---|---|---|---|
| **Revlog** | `apply_preview_state` constructs a `RevlogEntryPartial` on every preview answer | **No ReviewLog written at all** | LinguaCafe guarantees preview-only safety by zero DB writes; the normal queue and daily counts are provably unchanged. |
| **Card movement** | Cards are temporarily moved into a filtered deck; `remove_from_filtered_deck_restoring_queue` restores them on exit | **No card movement** — no deck model exists in LinguaCafe | LinguaCafe has no deck/filtered-deck data model; session state is held in a rotating encrypted token, not in card rows. |
| **`today_forgotten` ordering** | `forgot_config` uses `FilteredSearchOrder::Random` | LinguaCafe may choose "most recent Again first" as a **product choice** (see §7) | Anki's `RANDOM` order is not recency-ordered. If LinguaCafe uses recency, it is a product deviation, not an Anki default. |
| **Session state** | Filtered deck is a DB row; card list is materialized in the deck's search results | Rotating encrypted session-state token (see §5) | No migration, no DB session table, no cleanup job for V1. |

These deviations are **frozen for 1A**. A future rescheduling mode ADR may revisit them.

### 2. Future rescheduling mode

A future **rescheduling mode** (where Custom Study sessions write ReviewLog and run FSRS) is explicitly out of scope for 1A. If it is ever added:
- It requires a separate ADR.
- It requires explicit risk confirmation.
- It must distinguish its ReviewLog from normal rating ReviewLog.
- It is not part of 1A and is not authorized by this ADR.

### 3. Frozen 1A scope — four criteria

Custom Study 1A supports exactly four criteria:

| Criterion | Definition (frozen) |
|---|---|
| `today_forgotten` | `ReviewLog.source = 'sense_review'` AND `ReviewLog.rating = 'again'` AND `ReviewLog.undone_at IS NULL` AND `reviewed_at` within current learning-timezone natural day. Distinct `review_card_id`. Card is still current user's + current language's confirmed sense card. Suspended/archived/buried-not-expired excluded. |
| `overdue` | `fsrs_due_at < start_of_local_natural_day` (strict — cards merely due later today are NOT included). Card is eligible (`scopeSenseReviewEligible`). Confirmed sense only. Default order: ascending retrievability OR current Queue Order config (see §7). |
| `source_chapter` | `WordSense.source_chapter_id` matches the selected chapter, OR a `WordSenseOccurrence` with `status=bound` and `chapter_id` matching exists. Chapter must belong to current user + current language. Distinct `review_card_id`. No per-card query. |
| `leech_attention` | Reuses `SenseReviewLeechQueryService` (does NOT duplicate Leech Policy). Supports `leech only` and `leech + struggling` sub-modes. Card is currently eligible. Suspended/archived cards are NOT auto-added to the study session (they remain diagnosable but not studyable). |

### 4. Excluded from 1A

The following are **explicitly excluded** from Custom Study 1A (they belong to later main-lines or to 1B):

1. Increasing today's new card limit (`new_limit_delta`) — belongs to `today-only limits`.
2. Increasing today's review limit (`review_limit_delta`) — belongs to `today-only limits`.
3. Review Ahead — later main-line.
4. Preview recently added new cards — later sub-mode.
5. Arbitrary Browser Search string as Custom Study source — belongs to Saved Search.
6. Saved Search — later main-line.
7. today-only limits — later main-line.
8. Preset — later main-line (FSRS-Anki-Mgmt-9).
9. deck / filtered deck data model — LinguaCafe has no deck model; not planned.
10. rescheduling mode — future, requires separate ADR.
11. Custom Study business page — this ADR does not authorize implementation.
12. Custom Study API — this ADR does not authorize implementation.
13. **Card marker / flag / tag** — preserved as `Custom Study 1B prerequisite: Card Marker`. No migration, no lifecycle-as-marker, no leech-as-marker.

### 5. Target architecture — three options compared

#### Option A: Pure frontend temporary ID list

The frontend holds an array of `review_card_id` values in memory + sessionStorage. No backend session.

| Dimension | Verdict |
|---|---|
| Security | Weak — client controls the list; no server re-validation of each card. |
| User isolation | Weak — depends entirely on frontend discipline. |
| Language isolation | Weak — same. |
| URL/refresh recovery | OK via sessionStorage. |
| Card state changes | Not handled — if a card becomes suspended mid-session, frontend won't know. |
| Concurrency | Poor — multiple tabs can diverge. |
| Expiry | Poor — no server-side expiry. |
| DB cost | None. |
| Test difficulty | Hard — frontend state is hard to unit-test for security. |
| Saved Search boundary | Blurred — easily becomes "saved ID list". |
| Future rescheduling mode | Hard — no server session to attach ReviewLog to. |

**Rejected** for V1: security and isolation are too weak for a feature that touches the review queue.

#### Option B: Server-signed rotating session-state token, no DB session (RECOMMENDED for V1)

The frontend receives a server-signed opaque token encoding **the full session state** — not just the criteria. On every answer, the server rotates the token: it updates the session state (moves the current card to delayed-repeat or completed, picks the next card) and returns a **new encrypted token**. The client must use only the latest token. No `custom_study_sessions` table.

This design replaces the earlier "criteria-only token" concept. A criteria-only token that re-runs the query on every "next card" request cannot prevent A→B→A loops when `exclude=last_card_id` is the only de-duplication mechanism. The rotating session-state token solves this by holding the full ordered candidate snapshot + ready queue + delayed-repeat queue inside the token.

Token payload (server-signed, opaque to client):
- `version` (positive int; V1 = 1)
- `user_id`
- `language`
- `mode` (one of the four Custom Study criteria modes: `today_forgotten` / `overdue` / `source_chapter` / `leech_attention`; V1 is preview-only, no second meaning-ambiguous mode field)
- `parameters` (criteria parameters: `chapter_id` for source_chapter, `sub_mode` for leech_attention, empty for today_forgotten/overdue)
- `session_id` (UUID v4, strict — non-v4 UUID rejected)
- `issued_at` (UTC Unix seconds, positive int)
- `expires_at` (UTC Unix seconds, positive int, strictly greater than `issued_at`; V1 default TTL = 14400 = 4 hours)
- `ordered_candidate_ids` (ordered snapshot of candidate card IDs at session creation; the result of applying `card_limit` AFTER full-candidate ordering; max 500)
- `available_candidate_count` (snapshot of the full available candidate count BEFORE `card_limit` truncation; MUST be `>= 0` and `>= count(ordered_candidate_ids)`; added to V1 in Task 2000-22 — see §18 invariant 16/17. Because Custom Study has no public API yet, this field is added to V1 without bumping VERSION.)
- `ready_queue` (card IDs not yet answered, in session order)
- `delayed_repeat_queue` (items: `{card_id, available_at}` where `available_at` is UTC Unix seconds; card IDs that received Again/Hard)
- `completed_ids` (card IDs that received Good/Easy — explicit ID list, not just a count)
- `skipped_ineligible_ids` (card IDs that failed eligibility re-validation mid-session — explicit ID list)
- `completed_count` (MUST equal `count(completed_ids)` — redundant but kept for convenience; mismatch is an invariant violation)
- `total_count` (MUST equal `count(ordered_candidate_ids)` — the count of cards actually in the session AFTER `card_limit` truncation)
- `current_card_id` (the card currently being shown, or null if session exhausted)
- `step` (non-negative int; token revision number — initial value 0, incremented on each answer/resume rotation via `withProgress()`; NOT incremented by `withEligibilityResolution()` — Task 2000-22 adds the same-step eligibility resolution boundary)
- `preview_delay_config` (`again_secs`, `hard_secs`, `good_secs`, `easy_secs`; V1 defaults: 60/600/0/0; all must be non-negative ints)

Token rules:
1. Encrypted via the injected `Illuminate\Contracts\Encryption\Encrypter` contract (Laravel default binding). The implementation MUST NOT call the static `Crypt::encryptString()` / `Crypt::decryptString()` facade directly — the Encrypter is injected in the `CustomStudySessionTokenService` constructor so the key/cipher can be substituted in tests.
2. Token does **not** contain sensitive card content — only card IDs + session metadata.
3. Server re-validates `user_id` + `language` on every request (token is bound to issuer, not bearer-permissive).
4. Server re-validates each card's eligibility (confirmed sense, lifecycle, fsrs_enabled) before showing it — if a card has become ineligible mid-session, it is silently skipped.
5. Client cannot pass arbitrary `card_id` to bypass permission — the server always picks the next card from the token's `ready_queue` or `delayed_repeat_queue`.
6. Every answer returns a **new encrypted token**; the previous token becomes **client-obsolete** — the client discards it and uses only the new token. The server does **not** maintain a session table and therefore **cannot** actively invalidate (revoke) old tokens. Old tokens are called **client-obsolete**, not server-invalidated. Stale responses that carry an old token are rejected by the client's stale-response guard.
7. Even if a client-obsolete token is replayed: no DB writes, no ReviewLog, no FSRS change, no lifecycle change — the replay can only form an independent preview branch that does not affect normal learning data. Tampered or expired tokens are still rejected server-side (signature validation + `expires_at` check).
8. No migration.
9. No AI call.
10. No ReviewLog.
11. No FSRS change.
12. Token default expiry: 4 hours.
13. Token stored in `sessionStorage` (not `localStorage`) — refresh within the same tab recovers; multi-tab sessions are independent.
14. URL carries only `session_id` or route info, not the full token.
15. Token size must have a reasonable max (candidate count capped; oversized tokens rejected at creation).

**Why no A→B→A loop**: The `ready_queue` is consumed in order. When a card receives Again/Hard, it moves to `delayed_repeat_queue` with an `available_at` timestamp — it does NOT go back to `ready_queue`. The server only pulls from `delayed_repeat_queue` when (a) `ready_queue` is empty, or (b) a delayed card's `available_at` has passed. Good/Easy moves the card to `completed` (removed from both queues). The session ends when both queues are empty.

| Dimension | Verdict |
|---|---|
| Security | Strong — server re-validates every request. |
| User isolation | Strong — `user_id` bound in token, re-checked. |
| Language isolation | Strong — `language` bound in token, re-checked. |
| URL/refresh recovery | OK — `session_id` in URL; full token in `sessionStorage`. |
| Card state changes | Handled — eligibility re-run each request; ineligible cards skipped. |
| Concurrency | OK — each tab gets an independent token; no shared DB state. |
| Expiry | OK — `expires_at` checked server-side; `sessionStorage` cleared on tab close. |
| DB cost | Minimal — no session table; only criteria queries at creation. |
| Test difficulty | Good — server-side logic is unit-testable with injected clock. |
| Saved Search boundary | Clean — criteria are structured enum + chapter id, not user free-text. |
| Future rescheduling mode | Extensible — a future rescheduling mode can add a DB session table without changing the token contract. |
| A→B→A loop | Prevented — ready_queue is consumed; delayed cards don't re-enter ready_queue. |

**Recommended V1 direction** — this is what the implementation plan should target. The decision is recorded here; implementation is not authorized by this ADR alone.

#### Option C: `custom_study_sessions` data table

A new DB table stores each session: user_id, language, criteria, created_at, expires_at, status.

| Dimension | Verdict |
|---|---|
| Security | Strong. |
| Isolation | Strong. |
| URL/refresh recovery | Strong — session id in URL. |
| Card state changes | Can be cached or re-run. |
| Concurrency | Strong — DB row locking. |
| Expiry | Strong — DB cleanup job. |
| DB cost | Requires migration + cleanup job. |
| Test difficulty | Good. |
| Saved Search boundary | Clean. |
| Future rescheduling mode | Strong — easy to attach ReviewLog. |

**Rejected for V1**: requires a migration and a cleanup job; the preview-only V1 does not need server-side state persistence. Can be revisited if rescheduling mode is added.

### 6. Target service pipeline (recommended, not yet implemented)

```
CustomStudyCriteria (value object, no DB)
  → CustomStudyCriteriaValidator (pure, no DB, no Auth)
  → CustomStudyQueryService (builds the candidate query, applies criteria, no write)
  → CustomStudySessionState (value object: ordered_candidate_ids, ready_queue,
      delayed_repeat_queue, completed_ids, skipped_ineligible_ids, completed_count,
      total_count, current_card_id, step, preview_delay_config)
  → CustomStudySessionTokenService (issue/verify only, no DB; signs and verifies the
      encrypted token via injected Illuminate\Contracts\Encryption\Encrypter — does NOT
      implement rotate(answer), rating, or any state transition)
  → CustomStudyPreviewPolicy (pure state transition function: applies a rating or resume
      to the current state and returns a new immutable CustomStudySessionState via
      withProgress(); Again/Hard → delayed_repeat_queue, Good/Easy → completed_ids;
      picks the next current_card_id from ready_queue first, else the earliest
      available delayed_repeat_queue entry whose available_at <= now; returns
      waitUntil() + isCompleted() via the new state — NOT a token signer)
  → CustomStudySessionService (future orchestrator: validate token → re-validate
      eligibility → call CustomStudyPreviewPolicy → call
      CustomStudySessionTokenService::issue(newState) → return refreshed_token;
      no write to ReviewLog / FSRS / lifecycle / DB)
  → CustomStudySessionOrder (pure function: mode-specific order override applied
      at session creation only; does not modify global Queue Order)
  → SenseReviewCardSerializerService (serializes the next card, same shape as /reviews/senses)
  → shared card presentation component (e.g. SenseStudyCard.vue — see impl plan)
  → Custom Study page (not yet implemented; not authorized by this ADR)
```

This pipeline is the **recommended target**. It reuses `SenseReviewCardSerializerService` so the Custom Study card looks identical to a normal sense review card. It does not duplicate the serializer, does not duplicate Leech Policy, does not duplicate Queue Order logic. The `CustomStudyPreviewPolicy` is a pure function that can be unit-tested with an injected clock (no real sleep). The `CustomStudySessionOrder` applies the mode-specific order override (§7) at session creation time only — it does not modify global Queue Order settings.

### 7. Session-internal ordering

The Custom Study session has a **mode-specific order override** that applies only within the session and does not modify the global Queue Order setting:

| Mode | Default order override | Rationale |
|---|---|---|
| `today_forgotten` | Most recent Again first, fallback to Queue Order | **LinguaCafe product choice** — Anki's `forgot_config` uses `FilteredSearchOrder::Random`; recency ordering is a LinguaCafe deviation that surfaces the most recently forgotten cards first for immediate re-exposure. |
| `overdue` | Ascending retrievability (most forgotten first), fallback to Queue Order | Aligns with the "most at-risk first" intent. Corresponds to Anki's `RETRIEVABILITY_ASCENDING`. |
| `source_chapter` | Current Queue Order (no override) | No strong reason to deviate. |
| `leech_attention` | Severity DESC (leech before struggling), then Queue Order | Surface the worst cards first. |

Rules:
1. Override only affects the temporary session.
2. Does not modify global Queue Order settings.
3. Does not write settings.
4. Does not enter user-defined arbitrary ordering.
5. Does not merge with Saved Search.

The final override table is decided in this ADR; implementation is not authorized by this ADR alone.

### 7.1 Preview four-button semantics

Custom Study 1A uses four in-session buttons, mirroring Anki's preview mode:

| Button | Behavior | Anki default delay | LinguaCafe 1A |
|---|---|---|---|
| **Again** | Move current card to `delayed_repeat_queue` with short delay | `preview_again_secs = 60` (1 min) | Same; card re-appears after 60s |
| **Hard** | Move current card to `delayed_repeat_queue` with longer delay | `preview_hard_secs = 600` (10 min) | Same; card re-appears after 600s |
| **Good** | Complete current card (remove from both queues) | `preview_good_secs = 0` (return card) | Same; card is completed |
| **Easy** | Complete current card (remove from both queues) | `0` (always returns card) | Same; card is completed |

Rules:
1. All four operations write **no formal ReviewLog**.
2. All four operations run **no FSRS scheduling**.
3. All four operations modify **no ReviewCard** row.
4. All four operations do **not** advance daily reviewed count.
5. Only the encrypted session-state token's internal state is updated.
6. When only delayed-repeat cards remain and none have reached `available_at`, the page shows the next available time. The user can exit. Time-to-continue uses an injected clock in tests (no real `sleep`).
7. Completed cards are never re-shown in the same session.
8. The session can be reliably ended by the user or by token expiry.

### 8. Daily limits

Daily limits **do not apply** to Custom Study 1A preview sessions:
- Preview-only sessions do not consume the due queue.
- They do not write ReviewLog.
- They do not advance daily reviewed count.
- Applying daily limits would block a preview session from showing cards the user explicitly asked to preview, which defeats the purpose.

If a future rescheduling mode is added, daily limits must be revisited in that mode's ADR.

### 9. Lifecycle

Custom Study 1A only shows cards that are **currently eligible** per `scopeSenseReviewEligible` (ADR-0010):
- Active: included.
- Buried-not-expired: excluded.
- Buried-expired: included.
- Suspended: excluded.
- Archived: excluded.
- `fsrs_enabled=false`: excluded.

For `leech_attention`: suspended/archived leech cards remain **diagnosable** via the existing `SenseReviewLeechQueryService` (they show up in leech filters on the management page), but they are **not auto-added** to the Custom Study session. The user must un-suspend/un-archive them first.

The Custom Study session **does not change** lifecycle state. No suspend, no archive, no bury, no restore.

### 10. Undo inapplicability

The ADR-0009 stack-based undo does **not** apply to Custom Study 1A:
- Preview-only sessions write no ReviewLog.
- There is nothing to undo.
- The "undo" of a preview session is simply "exit the session".
- The normal queue is unchanged, so there is no "restore previous queue" operation.

If a future rescheduling mode writes ReviewLog, undo must be revisited in that mode's ADR.

### 11. Permissions

- Only the authenticated user can create/view their own Custom Study sessions.
- Only the current selected language is allowed.
- Only sense cards (`target_type = sense`) with confirmed `WordSense` are included.
- Legacy word cards are excluded.
- Cross-user/cross-language access returns 404 (not 403 — do not leak existence).
- Server re-validates user + language on every request, even with a valid token.
- No client-supplied `card_id` is trusted to bypass the criteria query.

### 12. Query budget

The query budget is based on the **full candidate ID snapshot + full-candidate ordering** model (Task 2000-22 truthfully recorded; the earlier "fetch only `card_limit` IDs" wording was inaccurate). The criteria query runs **once** at session creation to produce the **full** candidate ID list (no `card_limit` truncation at query time). `CustomStudySessionOrder` then hydrates and orders the **full** candidate set so that `available_candidate_count` reflects the true available count. `card_limit` is applied **after** full ordering; only the first `card_limit` (max 500) ordered IDs go into the token as `ordered_candidate_ids`. Answer and resume do **not** re-run the criteria query or the full ordering.

| Operation | Queries | Notes |
|---|---|---|
| Create session (issue token) | 1 criteria query + 1 full-candidate ID hydration + 1 full-candidate ordering hydration + 1 WordSense eager-load (per-mode) | Executes the criteria query ONCE to fetch the **full** candidate ID list. `CustomStudySessionOrder` then batch-loads ReviewCard for the **full** candidate set (1 batch query filtering user+language+target_type=sense) + per-mode ordering hydration (today_forgotten: 1 ReviewLog query; leech_attention: 1 `describeForCards()` call; source_chapter/overdue: no extra query beyond the ReviewCard batch). `card_limit` is applied AFTER full ordering — `available_candidate_count` = full ordered count, `total_count` = after-truncation count. Only the first card (current_card) is fully serialized via `SenseReviewCardSerializerService`. |
| Answer current card | 1 batch eligibility re-validation + 1 WordSense eager-load | `CustomStudySessionEligibilityService` re-validates the active candidate card IDs (current + ready + delayed) via 1 batch ReviewCard + eligibility query (reusing `confirmedSenseCardQuery` + `senseReviewEligible` scope) + 1 WordSense eager-load. Does NOT re-run the criteria query. Does NOT re-run `CustomStudySessionOrder`. |
| Resume session | 1 batch eligibility re-validation + 1 WordSense eager-load | Same as answer — batch re-validates active IDs only. |
| `today_forgotten` | 1 ReviewLog query + 1 full-candidate ReviewCard hydration | Batch by `review_card_id`. Runs at session creation only. Full-candidate hydration is needed for the latest-today-again DESC sort key. |
| `overdue` | 1 full-candidate ReviewCard hydration | `fsrs_due_at` WHERE + eligibility scope. Runs at session creation only. Full-candidate hydration is needed for retrievability ASC sort key. |
| `source_chapter` | 1 WordSense/WordSenseOccurrence query + 1 full-candidate ReviewCard hydration | Batch by `word_sense_id`. Runs at session creation only. Source_chapter uses canonical fallback order so the ReviewCard hydration is for canonical rank only. |
| `leech_attention` | reuses `SenseReviewLeechQueryService` batch path + 1 full-candidate ReviewCard hydration | no Policy duplication, no N+1. Runs at session creation only. Full-candidate hydration is needed for severity DESC sort key. |

Rules:
1. The full criteria query runs **once** at session creation, fetching the **full** candidate ID list (no `card_limit` truncation at query time). `card_limit` truncation happens AFTER `CustomStudySessionOrder` finishes the full ordering.
2. `CustomStudySessionOrder` batch-loads ReviewCard for the **full** candidate set (1 batch query) so the per-mode sort key can be computed. Row count of this hydration grows with the full candidate count; SQL query count stays constant (no N+1).
3. The server does **not** load `card_limit` full serializer payloads — only the current card is fully serialized via `SenseReviewCardSerializerService`. Card IDs + ordering metadata go into the token.
4. Answer and resume do **not** re-run the criteria query or the full ordering. They batch re-validate active IDs only via `CustomStudySessionEligibilityService` (1 ReviewCard + eligibility query + 1 WordSense eager-load).
5. The implementation plan must include a query-count test verifying: (a) 1 candidate ID query, (b) 1 full-candidate ReviewCard hydration at session creation, (c) constant SQL count across 1/100/500-candidate fixtures, (d) no N+1.
6. The implementation MUST NOT truncate candidate IDs before `CustomStudySessionOrder` to fake a smaller query budget — the full candidate set is required for correct ordering and correct `available_candidate_count`.

### 13. Rollback

Since 1A is preview-only and writes nothing:
- Exit session = rollback.
- No DB rows to delete.
- No ReviewLog to undo.
- No FSRS to restore.
- No lifecycle to restore.
- Token expiry (4h) is the automatic rollback for abandoned sessions.

If a future rescheduling mode is added, its ADR must define a real rollback path.

### 14. V1 boundary

This ADR authorizes **architecture only**. The following are **not authorized** by this ADR:
- Custom Study business code.
- Custom Study API endpoints.
- Custom Study Vue page.
- Custom Study migration.
- Card marker migration.

Implementation requires:
1. The implementation plan `docs/plans/custom-study-1a-implementation-plan.md` (created alongside this ADR).
2. A separate task authorization from the网页端总流程设计师.
3. Architecture Gate review (per AGENTS.md).
4. TDD execution per the implementation plan.

### 15. Marker follow-up

The "marked" criterion from the original task spec is **preserved** as `Custom Study 1B prerequisite: Card Marker`. This ADR:
- Does not delete the "marked" requirement.
- Does not fabricate a marker implementation.
- Does not use lifecycle state as a marker proxy.
- Does not use leech status as a marker proxy.
- Records that no `flag`/`marker`/`tag` column or table exists today.
- A future Card Marker ADR will define the schema and semantics.

### 16. Frozen API contract — three routes

Custom Study 1A exposes exactly **three** POST routes. All three require auth middleware (not admin-only). The full token is passed in the request body, never in the URL or query string.

#### 16.1 Create session

`POST /custom-study/sessions`

Request body:
- `mode` (preview-only in V1)
- `parameters` (criteria + sub-mode + order override)
- `card_limit` (default 100, min 1, max 500 — see §17)

Response:
- `token` (encrypted session-state token)
- `session_id` (UUID v4, also inside the token)
- `current_card` (fully serialized card, same shape as `/reviews/senses`)
- `summary` (session summary: total_count, completed_count, etc.)
- `expires_at` (ISO 8601, V1: 4 hours from creation)

#### 16.2 Answer current card

`POST /custom-study/sessions/answer`

Request body:
- `token` (the current encrypted session-state token)
- `rating` (again / hard / good / easy)

Response:
- `refreshed_token` (new encrypted token with updated session state)
- `current_card` (the next card, or null if session is complete)
- `summary`
- `wait_until` (ISO 8601, if delayed-repeat cards are pending and no card is ready)
- `completed` (boolean — true when both ready_queue and delayed_repeat_queue are empty)

#### 16.3 Resume / advance session

`POST /custom-study/sessions/resume`

Request body:
- `token` (the current encrypted session-state token)

Response:
- `refreshed_token`
- `current_card` (or null)
- `summary`
- `wait_until`
- `completed`

#### 16.4 Prohibited route patterns

The following are **prohibited** and must NOT appear in the implementation:
- `GET /custom-study/sessions/next` — no GET-based card advancement.
- `exclude=card_id` as a query parameter — de-duplication is handled inside the token's session state, not via URL parameters.
- Full token in the URL path or query string — the token is too large and sensitive for URL transport; it goes in the request body only.

#### 16.5 Error responses

All three routes share the same error contract:
- Unauthenticated → `401`.
- Tampered / expired token → `404` (not 403 — do not leak existence).
- Token belonging to a different user → `404`.
- Token belonging to a different language → `404`.
- Invalid `criteria` / `rating` / `card_limit` → `422` with validation errors.

**Internal exception contract (frozen by Task 2000-17)**:
- `CustomStudyValidationException` is the single structured exception type used by `CustomStudyCriteria` and `CustomStudyCriteriaValidator`.
- `field` and `reason` are the **machine protocol** — they are set at each throw site and must remain stable regardless of message text changes.
- `message` is for **human reading only** — callers MUST NOT parse `message` text to derive `field`/`reason`.
- The old `translateCriteriaException()` / `str_contains($message, ...)` control flow has been abolished and is guarded against by source-level tests.
- Stable `field`/`reason` pairs: `mode/missing_mode`, `mode/unknown_mode`, `criteria/invalid_parameters`, `chapter_id/missing_chapter_id`, `chapter_id/invalid_chapter_id`, `chapter_id/chapter_not_owned`, `sub_mode/missing_sub_mode`, `sub_mode/invalid_sub_mode`, `user_id/invalid_user_id`, `language/invalid_language`.

### 17. V1 card_limit freeze

The `card_limit` parameter controls how many candidate cards are fetched at session creation and stored in the encrypted token.

| Constraint | Value | Reason |
|---|---|---|
| Default | 100 | Reasonable preview session size; keeps token size manageable. |
| Minimum | 1 | A session must have at least one card. |
| Maximum | 500 | Token size cap; 500 card IDs + session metadata is the practical limit for an encrypted token transported in a request body. |

**Anki's default of 99,999 is NOT used.** Anki's 99,999 relies on a database-backed filtered deck that materializes cards on demand. LinguaCafe's V1 uses a stateless encrypted token containing the full ordered candidate ID snapshot, so 99,999 would produce an unusably large token.

The `card_limit` validation:
- `card_limit < 1` → 422.
- `card_limit > 500` → 422.
- `card_limit` not an integer → 422.
- `card_limit` omitted → uses default 100.

### 18. Session State invariants

The session state inside the token MUST satisfy the following invariants. These are testable properties and the implementation plan must include unit tests for each. Task 2000-19 added the explicit `completed_ids` and `skipped_ineligible_ids` lists to the V1 payload so that the five-state union + mutual exclusion invariants are fully verifiable from the state alone (the previous payload only carried `completed_count`, which was insufficient to prove nothing was lost or duplicated). Task 2000-22 added invariant 16 (`available_candidate_count >= 0`) and invariant 17 (`available_candidate_count >= total_count`) and the same-step `withEligibilityResolution()` boundary.

1. `current_card_id` must NOT simultaneously exist in `ready_queue`, `delayed_repeat_queue`, `completed_ids`, or `skipped_ineligible_ids`.
2. When creating a session, the first card is popped from `ready_queue` and becomes `current_card_id`.
3. Each candidate card belongs to exactly one of five states: `current` (i.e. `current_card_id`), `ready` (i.e. `ready_queue`), `delayed` (i.e. `delayed_repeat_queue.card_id`), `completed` (i.e. `completed_ids`), `skipped_ineligible` (i.e. `skipped_ineligible_ids`). No card is in two states at once.
4. No card is lost and no card is duplicated across the five states.
5. The union of all five states MUST equal `ordered_candidate_ids` exactly — every ordered candidate appears in exactly one state, and every state ID is a member of `ordered_candidate_ids`.
6. `completed_count` MUST equal `count(completed_ids)` — mismatch is an invariant violation. The count is kept for convenience only and is redundant by construction.
7. `total_count` MUST equal `count(ordered_candidate_ids)`.
8. After Good or Easy, `current_card_id` moves to `completed_ids`.
9. After Again or Hard, `current_card_id` moves to `delayed_repeat_queue` (with an `available_at` timestamp).
10. A card in `delayed_repeat_queue` NEVER re-enters `ready_queue`.
11. `wait_until` is the earliest `available_at` in `delayed_repeat_queue`, or null if `delayed_repeat_queue` is empty.
12. On resume:
    - If `current_card_id` is set: return the same current card.
    - If `current_card_id` is null and `ready_queue` is non-empty: pop the first card from `ready_queue` as the new current card.
    - If `ready_queue` is empty and a delayed card has reached `available_at`: pop the earliest-available delayed card as the new current card.
    - If only un-delayed cards remain in `delayed_repeat_queue`: return `wait_until`, no current card.
    - If all states are empty: `completed = true`.
13. A card whose eligibility has failed (suspended, archived, un-confirmed, fsrs_disabled, wrong user/language) moves to `skipped_ineligible_ids` and NEVER re-appears in the session.
14. The session MUST be able to reliably end (reach `completed = true` or token expiry).
15. `step` is a non-negative integer token revision number — initial value 0, incremented on each answer/resume rotation via `withProgress()`. `withEligibilityResolution()` does NOT increment `step` (same-step eligibility resolution — see invariant 18).
16. `available_candidate_count` is a non-negative integer (`>= 0`). It is the snapshot of the full available candidate count BEFORE `card_limit` truncation, captured at session creation. It MUST NOT change after creation — neither `withProgress()` nor `withEligibilityResolution()` may modify it.
17. `available_candidate_count` MUST be `>= total_count` (i.e. `>= count(ordered_candidate_ids)`). The full available count is always at least as large as the truncated session count. `available_candidate_count < total_count` is an invariant violation.
18. `withEligibilityResolution()` is a same-step immutable copy boundary: it returns a new `CustomStudySessionState` with updated five-state (active IDs moved to `skipped_ineligible_ids`), but `step`, `available_candidate_count`, `issued_at`, `expires_at`, `session_id`, and `ordered_candidate_ids` MUST remain unchanged. It MUST NOT increment `step`. It MUST reuse the same five-state validation code as `withProgress()` (no duplicate validation logic) — internally both delegate to a private helper that takes an `incrementStep` bool.

### 19. File list (frozen for implementation plan)

The implementation plan must include the following files. This list is authoritative — the implementation must not silently add or remove files without updating this ADR and the implementation plan.

#### 19.1 Backend — Phase 1 (existing, accepted)

- `app/Services/CustomStudy/CustomStudyCriteria.php` — value object carrying ONE of the four frozen criteria modes (today_forgotten / overdue / source_chapter / leech_attention) and its frozen parameters. Pure — no DB, no Auth, no Request.
- `app/Services/CustomStudy/CustomStudyCriteriaValidator.php` — pure validator that delegates to `CustomStudyCriteria::fromArray()` and lets structured `CustomStudyValidationException` propagate. No message-string parsing.
- `app/Exceptions/CustomStudyValidationException.php` — structured exception with stable `field` + `reason` machine protocol; `message` is human-readable only.

#### 19.2 Backend — Phase 2A (existing, accepted)

- `app/Services/CustomStudy/Queries/TodayForgottenQuery.php` — read-only SQL-native query returning a Builder for today's `again`-rated sense cards. Builder contract (Task 2000-18 docs fix).
- `app/Services/CustomStudy/Queries/OverdueQuery.php` — read-only SQL-native query returning a Builder for strictly-overdue eligible sense cards.

#### 19.3 Backend — Phase 2B (existing, accepted, Task 2000-18)

- `app/Services/CustomStudy/ChapterLocatorInterface.php` — port for resolving a chapter id into a user-owned + language-owned Chapter domain object.
- `app/Services/CustomStudy/EloquentChapterLocator.php` — production binding of `ChapterLocatorInterface`. No write.
- `app/Services/CustomStudy/Queries/SourceChapterQuery.php` — SQL-native read-only query with `whereExists` double-path deduplication (WordSense.source_chapter_id OR bound WordSenseOccurrence.chapter_id). Returns a Builder.
- `app/Services/CustomStudy/Queries/LeechAttentionQuery.php` — Policy-derived query (reuses `SenseReviewLeechPolicy`, no Policy duplication). Returns candidate IDs.
- `app/Services/CustomStudy/CustomStudyQueryService.php` — unified candidate-ID dispatcher for all four modes. Returns ordered candidate IDs only; no card payload hydration.

#### 19.4 Backend — Phase 3A (existing, Accepted / Closed, Task 2000-19 + Task 2000-20 + Task 2000-22 final closure)

- `app/Exceptions/CustomStudySessionStateException.php` — structured exception with stable internal `reason` + `message`; no HTTP Response, no Request, no Auth, no DB.
- `app/Services/CustomStudy/CustomStudySessionState.php` — immutable value object holding the full session state: `version`, `user_id`, `language`, `mode`, `parameters`, `session_id`, `issued_at`, `expires_at`, `ordered_candidate_ids`, `available_candidate_count` (added Task 2000-22), `ready_queue`, `delayed_repeat_queue`, `completed_ids`, `skipped_ineligible_ids`, `completed_count`, `total_count`, `current_card_id`, `step`, `preview_delay_config`. Pure — no DB, no Auth, no Request, no Crypt, no ReviewLog, no FSRS, no AI. Exposes `createInitial()` (now takes `$availableCandidateCount` explicitly) + `fromArray()` + `toArray()` + `withProgress()` + `withEligibilityResolution()` (added Task 2000-22 — same-step immutable copy, no `step` increment) + read-only getters; NO setter; NO answer/rate/resume/nextCard/transition/rotate.
- `app/Services/CustomStudy/CustomStudySessionTokenService.php` — encrypts, decrypts, and verifies the session-state token. Injects `Illuminate\Contracts\Encryption\Encrypter`. Exposes `issue()` + `verify()` only; NO `rotate(answer)`; NO rating/answer branching; NO SessionService/PreviewPolicy/Controller/routes/Vue. `MAX_TOKEN_BYTES=65536`, `DEFAULT_TTL_SECONDS=14400`, `MAX_CANDIDATE_COUNT=500`. Task 2000-22: `verify()` round-trips the new `available_candidate_count` field.

#### 19.5 Backend — Phase 3B (existing, Accepted / Closed, Task 2000-20 + Task 2000-21 docs closure + Task 2000-22 final closure)

- `app/Exceptions/CustomStudyPreviewPolicyException.php` — structured exception with stable internal `reason` + `message`; reasons are `invalid_rating` / `no_current_card`. No HTTP Response, no Request, no Auth, no DB.
- `app/Services/CustomStudy/CustomStudyPreviewPolicy.php` — pure state-transition function: `applyRating(state, rating, now)` + `resume(state, now)` + `resolveEligibility(state, eligibleCardIds, now)` (added Task 2000-22). Again → delayed (again_secs), Hard → delayed (hard_secs), Good → completed, Easy → completed. `resolveEligibility` removes active IDs not in `eligibleCardIds`, moves them to `skipped_ineligible_ids` (preserving `ordered_candidate_ids` order), and calls `withEligibilityResolution()` (same-step). Picks next card via `withProgress()` / `withEligibilityResolution()` only — never calls `toArray()` / `fromArray()`. No DB, no Auth, no Request, no Crypt, no ReviewLog, no FSRS, no AI, no token issue/verify.

#### 19.6 Backend — Phase 4A (existing, Accepted / Closed, Task 2000-21 + Task 2000-22 final closure)

- `app/Services/CustomStudy/CustomStudySessionOrder.php` — pure session-internal ordering service. Takes unordered candidate IDs from `CustomStudyQueryService` + a `CustomStudyCriteria` + trusted `userId` / `language` + `Carbon $now` + `ReviewQueueOrderOptions`, and returns the ordered `list<int>` of sense-card IDs for `CustomStudySessionState::createInitial()`. Batch-loads ReviewCard once (filters user + language + target_type=sense), computes one canonical fallback rank via `ReviewQueueOrderService::order()`, applies per-mode primary sort key (source_chapter = canonical; overdue = retrievability ASC; today_forgotten = latest today-again DESC; leech_attention = severity DESC), tie-breaks on canonical fallback. Does NOT apply card_limit, does NOT create SessionState, does NOT create token, does NOT write any table, does NOT modify Queue Order settings, does NOT re-run Criteria queries. Task 2000-22: the full-candidate hydration performed here is the basis for `available_candidate_count`; `card_limit` truncation happens downstream in `CustomStudySessionService::openSession()` AFTER `order()` returns.

#### 19.7 Backend — Phase 4B (existing, code/tests complete pending web-side acceptance, Task 2000-22)

- `app/Exceptions/CustomStudySessionException.php` — structured exception with stable internal `reason` + `message`; used by `CustomStudySessionService` + `CustomStudyController` for token-not-found / session-expired / session-missing errors. Reasons: `session_not_found` (covers tampered / expired / wrong-user / wrong-language / malformed — the server returns 404 without leaking which). No HTTP Response object construction inside the exception; the Controller maps it to 404.
- `app/Services/CustomStudy/CustomStudySessionEligibilityService.php` — batch eligibility resolver. Takes a `CustomStudySessionState` + trusted `userId` / `language` + `Carbon $now`, collects active IDs (current + ready + delayed), runs ONE batch ReviewCard + eligibility query (reusing `SenseReviewQueryService::confirmedSenseCardQuery` + `ReviewCard::scopeSenseReviewEligible`) + ONE WordSense eager-load, returns the set of IDs still eligible. Does NOT write. Does NOT call PreviewPolicy. Does NOT issue/verify token. Does NOT call Criteria queries.
- `app/Services/CustomStudy/CustomStudySessionService.php` — orchestrator. Exposes `openSession($userId, $language, $criteria, $cardLimit, $now)` + `answer($token, $rating, $now)` + `resume($token, $now)`. `openSession`: Criteria → QueryService::candidateIds → SessionOrder::order (full set) → available_candidate_count = full ordered count → apply card_limit → SessionState::createInitial → TokenService::issue → serialize current card. `answer`: TokenService::verify → EligibilityService::resolve → PreviewPolicy::resolveEligibility (same-step) → PreviewPolicy::applyRating (increment-step) → TokenService::issue → serialize next card. `resume`: TokenService::verify → EligibilityService::resolve → PreviewPolicy::resolveEligibility (same-step) → PreviewPolicy::resume (increment-step) → TokenService::issue → serialize current card. Does NOT access `Auth` / `Request` / `Session` / `Settings` facades — caller passes trusted `userId` / `language` / `cardLimit`. Does NOT write ReviewLog / FSRS / lifecycle.
- `app/Http/Controllers/CustomStudyController.php` — HTTP boundary. Three methods: `store` (POST `/custom-study/sessions`), `answer` (POST `/custom-study/sessions/answer`), `resume` (POST `/custom-study/sessions/resume`). Uses `Auth::user()->id` + `Auth::user()->selected_language` (the only Auth usage in the vertical slice). Validates request body (mode / parameters / card_limit / token / rating), catches `CustomStudyValidationException` → 422, catches `CustomStudySessionException` → 404, catches `CustomStudyPreviewPolicyException` → 422. Returns JSON `{token, session_id, current_card, summary, expires_at}` (open) or `{refreshed_token, current_card, summary, wait_until, completed}` (answer/resume). Does NOT contain business logic. Does NOT call PreviewPolicy / EligibilityService / QueryService / SessionOrder directly.
- `routes/web.php` — adds three POST routes inside the existing auth middleware group (NOT admin-only). No new middleware, no new service provider.

#### 19.8 Frontend (NOT created; authorized only by future Phase 6/7 task)

- `resources/js/components/CustomStudy/CustomStudy.vue` — top-level page component.
- `resources/js/components/CustomStudy/CustomStudySession.vue` — session orchestration component (token management, answer/resume calls).
- `resources/js/components/Senses/SenseStudyCard.vue` — shared card presentation component (see §20 for boundary).

#### 19.9 Tests — Phase 1 (existing, accepted)

- `tests/Unit/CustomStudyCriteriaTest.php`
- `tests/Unit/CustomStudyCriteriaValidatorTest.php`

#### 19.10 Tests — Phase 2A (existing, accepted)

- `tests/Feature/CustomStudyTodayForgottenQueryTest.php`
- `tests/Feature/CustomStudyOverdueQueryTest.php`

#### 19.11 Tests — Phase 2B (existing, accepted, Task 2000-18)

- `tests/Feature/CustomStudyChapterLocatorTest.php`
- `tests/Feature/CustomStudySourceChapterQueryTest.php`
- `tests/Feature/CustomStudyLeechAttentionQueryTest.php`
- `tests/Feature/CustomStudyQueryServiceTest.php`

#### 19.12 Tests — Phase 3A (existing, Accepted / Closed, Task 2000-19 + Task 2000-22 final closure)

- `tests/Unit/CustomStudySessionStateTest.php` — 37+ behavior tests covering immutability, five-state union + mutual exclusion invariants, validation matrix, and pure/no-DB/no-Auth/no-Request/no-Crypt guards. Task 2000-22 extends these tests to cover the new `available_candidate_count` field and the `withEligibilityResolution()` same-step boundary.
- `tests/Unit/CustomStudySessionTokenServiceTest.php` — 32+ behavior tests covering issue+verify round-trip, opacity, tamper rejection, expiry, user/language binding, MAX_TOKEN_BYTES, MAX_CANDIDATE_COUNT, no DB, no Auth, no Request, no ReviewLog, no FSRS, no AI, no rotate(answer). Task 2000-22 extends these tests to verify the `available_candidate_count` field survives the round-trip.

#### 19.13 Tests — Phase 3B (existing, Accepted / Closed, Task 2000-20 + Task 2000-21 docs closure + Task 2000-22 final closure)

- `tests/Unit/CustomStudySessionStateProgressTest.php` — 39 behavior tests covering `withProgress()` immutability, identity-field preservation, automatic `completed_count` / `total_count` recompute, automatic `step + 1`, `step_overflow` rejection, five-state invariant re-validation, `waitUntil()`, `isCompleted()`.
- `tests/Unit/CustomStudyPreviewPolicyTest.php` — 51 behavior tests covering Rating (again/hard → delayed with correct secs; good/easy → completed; ready priority; mature-delayed selection; tie stability; step +1; original-state immutability; invalid/uppercase/numeric rating rejection; null-current rejection), Resume (keep current; pop ready; pop mature delayed; immature-only keeps null; waitUntil; isCompleted), and Architecture (no toArray/fromArray; only withProgress; no DB/Auth/Request/Crypt/ReviewLog/FSRS/lifecycle/AI/Token/QueryService; injected Carbon).

#### 19.14 Tests — Phase 4A (existing, Accepted / Closed, Task 2000-21 + Task 2000-22 final closure)

- `tests/Feature/CustomStudySessionOrderTest.php` — 55+ behavior tests covering: empty input; dedup; positive-int filter; cross-user/language/legacy-word filter; single batch ReviewCard load; single canonical fallback computation; per-mode ordering (source_chapter = canonical; overdue = retrievability ASC; today_forgotten = latest today-again DESC; leech_attention = severity DESC); tie-break on canonical fallback; stable determinism; no card_limit; no Criteria query re-run; no SessionState/token creation; no DB writes; no settings mutation; no QueryService call; single `describeForCards()` call; preloaded cards; no `describeForCard()` / `summary()`; no N+1.

#### 19.15 Tests — Phase 4B (existing, code/tests complete pending web-side acceptance, Task 2000-22)

Backend:
- `tests/Feature/CustomStudySessionEligibilityServiceTest.php` — batch eligibility resolver behavior tests (reuses `confirmedSenseCardQuery` + `senseReviewEligible`; 1 ReviewCard query + 1 WordSense eager-load; active IDs only; suspended/archived/un-confirmed/fsrs_disabled/wrong-user/wrong-language moved out; completed_ids + skipped_ineligible_ids NOT re-validated; no write; no PreviewPolicy; no token issue/verify; no Criteria query).
- `tests/Feature/CustomStudyOpenSessionTest.php` — openSession behavior tests (full candidate ID fetch → full-candidate ordering → available_candidate_count = full ordered count → card_limit truncation → total_count = after-truncation → SessionState::createInitial → TokenService::issue → current card serialized; no Auth/Request/Session/Settings facade access; no ReviewLog/FSRS/lifecycle write; 422 on invalid criteria / card_limit; query count constant across 1/100/500-candidate fixtures).
- `tests/Feature/CustomStudyAnswerTest.php` — answer behavior tests (TokenService::verify → EligibilityService::resolve → PreviewPolicy::resolveEligibility (same-step) → PreviewPolicy::applyRating (increment-step) → TokenService::issue → next card serialized; 404 on tampered/expired/wrong-user/wrong-language token; 422 on invalid rating; ineligible current card skipped; no write).
- `tests/Feature/CustomStudyResumeTest.php` — resume behavior tests (TokenService::verify → EligibilityService::resolve → PreviewPolicy::resolveEligibility (same-step) → PreviewPolicy::resume (increment-step) → TokenService::issue → current card serialized; 404 on tampered/expired/wrong-user/wrong-language token; keep current if set; pop ready; pop mature delayed; wait_until if only immature delayed; completed if exhausted; no write).
- `tests/Feature/CustomStudyControllerTest.php` — Controller HTTP boundary tests (three POST routes; 401 unauthenticated; 422 invalid body; 404 tampered/expired token; 200 valid open/answer/resume; response shape; Auth usage limited to `Auth::user()->id` + `Auth::user()->selected_language`; no business logic in Controller).
- `tests/Feature/CustomStudyRoutesTest.php` — route registration tests (three POST routes inside auth middleware group; NOT admin-only; 401 without auth; 405 on GET; route names; no extra routes).

Frontend (Node guard tests, NOT created in Task 2000-22 — authorized only by future Phase 6/7 task):
- `tests/js/SenseStudyCardGuard.test.mjs`
- `tests/js/CustomStudyPageGuard.test.mjs`
- `tests/js/CustomStudySessionUiGuard.test.mjs`

Node architecture guard tests (created in Task 2000-22):
- `tests/js/CustomStudySessionArchitectureDocsGuard.test.mjs` — extended in Task 2000-22 to verify: Phase 3B Accepted/Closed, Phase 4A Accepted/Closed, Phase 4B file list, `available_candidate_count` in payload, `OPEN PRODUCT GATE` removed, candidate_count = Option A (not card_limit-truncated), `withEligibilityResolution` same-step boundary, V1 query budget truthfully recorded (no "card_limit 张" wording).
- `tests/js/CustomStudyBackendVerticalSliceGuard.test.mjs` — new in Task 2000-22. Verifies the Phase 4B source files exist and contain the required architecture boundaries (no Auth/Request/Session/Settings facade in SessionService; no business logic in Controller; three POST routes; no ReviewLog/FSRS/lifecycle write; EligibilityService reuses confirmedSenseCardQuery + senseReviewEligible; PreviewPolicy::resolveEligibility calls withEligibilityResolution not withProgress; SessionState::createInitial takes availableCandidateCount; token payload includes available_candidate_count).

#### 19.16 Files NOT created (prohibited in 1A)

- No migration file.
- No `custom_study_sessions` DB table.
- No `CustomStudyController` route file beyond the three routes in §16.
- No `SenseStudyCard.vue` rating buttons (shared component is presentation-only — see §20).

### 20. Shared card component boundary — SenseStudyCard.vue

`SenseStudyCard.vue` is the shared presentation component used by both the normal sense review (`SenseReview.vue`) and Custom Study (`CustomStudySession.vue`). It renders the card face (question + answer) and is **presentation-only**.

#### 20.1 Allowed responsibilities

- Render `lemma` / `surface` / `pos`.
- Render the question-side example sentence.
- Render the Chinese and English sense definitions.
- Render `aliases` / `collocations`.
- Render supplementary example.
- Render understanding aid.
- Render learning feedback.
- Emit `source-context` entry events (parent decides what to do).

#### 20.2 Prohibited responsibilities

- NO formal rating buttons (those belong to `SenseReviewRatingControls` in normal review, and to `CustomStudySession.vue` in Custom Study).
- NO Custom Study four-button (Again/Hard/Good/Easy) — the parent container renders those.
- NO lifecycle operations (suspend / archive / bury / restore).
- NO axios requests.
- NO ReviewLog writes.
- NO FSRS interactions.
- NO route navigation.
- NO queue state management.

#### 20.3 Props and events contract

Props (input, read-only):
- `card` — the serialized card object (same shape as `/reviews/senses` card).
- `show-answer` — boolean, whether the answer side is visible.
- `font-size` — number.

Events (output, parent handles):
- `reveal` — request to show the answer.
- `view-source` — request to open the source-context dialog.

The parent container (`SenseReview.vue` or `CustomStudySession.vue`) owns the rating buttons, lifecycle menu, undo, interval preview, and all backend communication.

#### 20.4 Future extraction note

The implementation plan must allow future modification of:
- `resources/js/components/Senses/SenseReview.vue` — to extract the shared card face into `SenseStudyCard.vue`.
- Existing related guard tests — to reflect the extraction.

However, the actual component extraction is NOT done in 1A — it is deferred to the implementation round. This ADR only freezes the boundary.

#### 20.5 Reading-style token sentence presentation contract (frozen by Task 2000-15)

> **Name frozen**: `阅读式 token 句子展示` (reading-style token sentence presentation). Do NOT refer to this as "rich text editor", "WYSIWYG", or "editable sentence". This is **read-only, safe token-array rendering**, not a rich text editor.

1. **Shared component**: Both normal Sense Review (`SenseReview.vue`) and Custom Study (`CustomStudySession.vue`) MUST share the **same** sentence presentation sub-component when displaying the example sentence in `SenseStudyCard.vue`. Question side and answer side MUST reuse the same sub-component — no two separate implementations.
2. **Reuse existing component**: The implementation MUST prefer reusing the existing `resources/js/components/Review/SenseSentencePreview.vue`. The implementation round MUST NOT create a parallel token rendering component with a different style.
3. **Data source — use existing serializer fields** (no new API, no new migration, no tokenizer re-call):
   - `card.example_sentence_tokens`
   - `card.example_sentence_en`
   - `card.surface_form`
   - `card.lemma`
4. **When tokens exist**:
   - Each token is rendered with the existing reading-page `stage` color (no new color system, no duplicated token style).
   - Inter-token spaces and punctuation MUST be preserved.
   - The current review target word MUST receive an extra positioning style (e.g. underline / highlight chip).
   - Target identification: prefer `token.is_target` when present; fall back to matching `surface` / `lemma` when `is_target` is not available.
5. **When tokens are missing**: fall back to plain-text `example_sentence_en`. Do NOT render a blank card face. Do NOT block rating because of missing tokens.
6. **Read-only**: The token display is strictly presentation-only.
   - No click-to-look-up.
   - No opening dictionary.
   - No changing `EncounteredWord`.
   - No changing word familiarity.
   - No writing `ReviewLog`.
   - No changing FSRS state.
7. **No `v-html`**: Rendering MUST use Vue's safe `v-for` over the token array. Use of `v-html` for sentence rendering is prohibited in `SenseStudyCard.vue` and its sentence sub-component.
8. **No tokenizer re-call**: The component MUST NOT call the tokenizer again. It only consumes tokens already present in the serialized payload.
9. **No new API**: No new endpoint is introduced for sentence rendering.
10. **No new migration**: No schema change is required for this contract.
11. **No new token style**: The token color system is the existing reading-page `stage` color. The implementation MUST NOT fork a new color palette.
12. **Question / answer side reuse**: When the example sentence is shown on both the question side and the answer side, both MUST reuse the same sub-component instance (or the same component definition with the same props), to avoid two positions drifting in style.
13. **LinguaCafe project adaptation (not Anki default)**: Anki supports custom HTML/CSS for cards, but there is no unified default "target word color highlight" style in Anki. Therefore: **"Anki 没有冻结该视觉样式；以下为 LinguaCafe 项目适配。"** The reading-style token color is a LinguaCafe-specific adaptation, not an Anki default design.

#### 20.6 Show-answer empty-field hiding contract (frozen by Task 2000-15)

> Aligned with Anki Manual — Card Generation / Conditional Replacement: Anki supports conditional replacement so that a field (and its surrounding label/wrapper) is rendered only when the field is non-empty. This section is the component-level implementation of that Anki semantic.

**Before "Show Answer" (`show-answer === false`)**:
1. Only question-side information is shown.
2. Chinese sense definition (`sense_zh`) is NOT shown.
3. English sense definition (`sense_en`) is NOT shown.
4. Aliases / near-synonym translations (`aliases_zh`) are NOT shown.
5. Collocations (`collocations`) are NOT shown.
6. Learning-feedback answer content is NOT shown.
7. The Anki "Question first, Answer after Show Answer" semantic MUST be preserved.

**After "Show Answer" (`show-answer === true`)**:
1. **`sense_zh` is always shown.** (It is the primary answer content and is required for a sense card.)
2. **`sense_en` block**:
   - When `sense_en` (after `trim()`) is non-empty → show the block with its label and content.
   - When `sense_en` (after `trim()`) is empty → hide the **entire** block, including the label. Do NOT show "暂无英文释义。". Do NOT show a "无" placeholder.
3. **`aliases_zh` block**:
   - When the normalized array contains at least one non-empty item → show the block with its label and chips/items.
   - When the normalized array is empty (or all items empty after trim) → hide the **entire** block, including the label. Do NOT show "无".
4. **`collocations` block**:
   - When the normalized array contains at least one non-empty item → show the block with its label and chips/items.
   - When the normalized array is empty (or all items empty after trim) → hide the **entire** block, including the label. Do NOT show "无".
5. Non-empty blocks render normally with their existing labels, text, or chips.
6. **Do NOT delete any database field.** This contract only governs rendering, not schema.
7. **Do NOT auto-generate missing content.** Empty fields stay empty; no AI call, no fill-in.
8. **Do NOT call AI to fill empty fields.** No external AI provider is invoked by `SenseStudyCard.vue`.
9. **Do NOT make `sense_en` / `aliases_zh` / `collocations` required.** They remain optional in the data model.
10. **Scope limit**: This contract applies to `sense_zh`, `sense_en`, `aliases_zh`, `collocations` only. Supplementary example, understanding aid, and learning feedback continue to follow their existing conditional display rules.

#### 20.7 Anki alignment statement

1. Anki supports conditional templates that render a field and its label only when the field has content (Anki Manual — Card Generation / Conditional Replacement). Therefore LinguaCafe adopts **"有内容才显示整个区块"** (render the entire block only when content is present) for optional answer fields.
2. LinguaCafe no longer uses "暂无英文释义" / "无" placeholders to occupy card attention for empty fields.
3. This is a component-level implementation of the Anki conditional replacement semantic.
4. The reading-style token color highlight (§20.5) is a LinguaCafe project adaptation. Anki supports custom HTML/CSS but does not freeze a default "target word color highlight" visual style. LinguaCafe does NOT misrepresent this as an Anki default design.
5. The Anki "Question first, Show Answer after" flow (Anki Manual — Studying / Questions) is preserved by §20.6's `show-answer === false` contract.

#### 20.7.1 AI translation card display requirement (registered for future CS-11.5, NOT implemented in Task 2000-16)

> **Status**: Registered as a future product requirement for CS-11.5. **NOT implemented in Task 2000-16.** Task 2000-16 does NOT research the AI-translation data chain, does NOT implement any translation display, and does NOT call any AI provider. This section is a placeholder so the requirement is not lost; the actual data-chain Gate and implementation happen in a future CS-11.5 round.

**Product requirement (registered, not implemented)**:

Before "Show Answer" (`show-answer === false`):
1. Main example sentence translation is NOT shown.
2. Supplementary example sentence translations are NOT shown.
3. Answer must not be leaked early.

After "Show Answer" (`show-answer === true`):
1. When the current main example sentence has a corresponding Chinese translation, the translation is displayed directly beneath the English sentence.
2. When supplementary example sentences have corresponding translations, each translation is displayed directly beneath its own English sentence.
3. When no translation exists, no empty block is rendered (conditional replacement semantic, same as §20.6).
4. Do NOT show "AI 译文：" / "暂无译文" / "无" placeholders.
5. Main example sentence translation is shown only once. Do NOT duplicate it on both question side and answer side.
6. Both translation text and English tokens are read-only — not clickable.
7. Do NOT open dictionary. Do NOT change familiarity. Do NOT call AI. Do NOT create learning data. Do NOT change FSRS. Do NOT write ReviewLog.
8. Use the existing reading-page vertical-stacked translation visual style.
9. This visual is a LinguaCafe project adaptation, NOT an Anki fixed default visual.

**Pre-implementation Gate (MUST be satisfied before CS-11.5 implementation can touch this feature)**:
1. This requirement is NOT implemented in Task 2000-16.
2. Before entering CS-11.5, the real translation data chain MUST be investigated.
3. Do NOT assume `example_sentence_zh` is the AI translation without verification.
4. The currently-displayed rotating example sentence and its translation MUST be precisely correlated — no cross-occurrence mismatch.
5. Do NOT guess translations by lemma or surface.
6. If AI Reading Assist fallback is needed, a separate data-contract Gate MUST be done first.
7. Do NOT temporarily call external AI to display translations.
8. Do NOT auto-write WordSense / WordSenseOccurrence / ReviewCard to fill translations.
9. Only a future CS-11.5 implementation prompt is allowed to handle this feature.

**Current status (Task 2000-16)**:
```
AI 译文卡面显示：
已登记到未来 CS-11.5；
本轮未研究数据链；
本轮未实现；
不属于 Phase 1。
```

#### 20.8 Future implementation test matrix (registered, not yet executed)

> These tests are registered for the future implementation round (CS-11.5). They MUST be created and passed when `SenseStudyCard.vue` is actually implemented. The current round (Task 2000-15) does NOT create any test file.

Functional tests:
1. `SenseStudyCard.vue` uses `SenseSentencePreview` (or equivalent shared sub-component) for sentence rendering.
2. Token payload present → renders tokens.
3. Target token is marked (via `token.is_target` or surface/lemma fallback).
4. Token `stage` color is preserved.
5. No tokens → falls back to plain-text `example_sentence_en`.
6. No use of `v-html` anywhere in `SenseStudyCard.vue` or its sentence sub-component.
7. Token display is read-only — no click-to-look-up, no dictionary open, no `EncounteredWord` change.
8. `sense_en` empty → entire block hidden (including label).
9. `aliases_zh` empty → entire block hidden (including label).
10. `collocations` empty → entire block hidden (including label).
11. All three field types with content → blocks render normally with labels and chips/text.
12. `sense_zh` is always shown after Show Answer.
13. `show-answer === false` → all answer fields hidden.
14. Normal Sense Review and Custom Study share the same `SenseStudyCard.vue` component.

Non-regression tests:
15. No changes to rating, undo, interval preview, FSRS, ReviewLog, or lifecycle in either Sense Review or Custom Study.
16. MCP Chrome acceptance uses both a real empty-field card and a real populated-field card to verify §20.6 hiding behavior.
17. Two viewports:
    - 1920×1080
    - 900×900

AI translation tests (registered by Task 2000-16 for future CS-11.5, NOT executed in Task 2000-16):
18. `show-answer === false` → main example sentence translation hidden.
19. `show-answer === false` → supplementary example sentence translations hidden.
20. `show-answer === true` + main sentence has translation → translation rendered directly beneath English sentence.
21. `show-answer === true` + supplementary sentence has translation → translation rendered beneath its own English sentence.
22. `show-answer === true` + no translation → no empty block, no "暂无译文" / "无" placeholder.
23. Main example sentence translation shown only once (not duplicated on question side and answer side).
24. Translation text and English tokens are read-only — no click, no dictionary open, no familiarity change.
25. No AI provider call, no WordSense/WordSenseOccurrence/ReviewCard write, no FSRS change, no ReviewLog write.
26. Translation visual uses existing reading-page vertical-stacked style (LinguaCafe adaptation, not Anki default).

### 21. Chapter picker future contract (registered by Task 2000-19; candidate_count display requirement + Option A decision frozen by Task 2000-21 / Task 2000-22; NOT implemented in Task 2000-19, Task 2000-21, or Task 2000-22)

> **Status**: Registered as a future product requirement for Phase 5 / CS-11 (chapter picker product contract) and Phase 6 (chapter options data delivery Gate). **Task 2000-19 does NOT implement the chapter list, page, or endpoint. Task 2000-21 registered the `candidate_count` display contract and the open product Gate. Task 2000-22 closes the product Gate by freezing Option A (full available candidate count, NOT card_limit-truncated). Task 2000-22 does NOT implement any chapter-picker code, endpoint, migration, or Vue component.** This section is a placeholder so the requirement is not lost; the actual implementation happens in a future Phase 5/6 round after an Architecture Gate.

**Product requirement (registered, not implemented)**:

The `source_chapter` mode chapter picker MUST only show chapters (for the current user + current language) that currently have at least one candidate card satisfying `SourceChapterQuery` eligibility. Chapters that would produce an empty session (`candidate_count = 0`) MUST NOT be shown.

**`candidate_count` display contract (registered by Task 2000-21; Option A frozen by Task 2000-22; Phase 5/6 implementation deferred)**:

1. Each chapter option returned by the future chapter picker MUST include a `candidate_count` field.
2. `candidate_count` MUST be a non-negative integer.
3. `candidate_count = 0` chapters MUST NOT be returned and MUST NOT be displayed.
4. The display position of `candidate_count` MUST be in the same option area as the chapter title, so the user can see the count without entering the chapter.
5. Task 2000-21 / Task 2000-22 do NOT implement the candidate_count query, endpoint, or Vue rendering.
6. Task 2000-21 / Task 2000-22 do NOT decide the final visual style (chip, badge, parenthetical count, etc.) — that is a Phase 5/6 Architecture Gate decision.

**`candidate_count` semantics — Option A frozen (Task 2000-22 closes the Gate)**:

```
candidate_count = 当前全部符合资格的候选卡总数，
不受本次 card_limit 截断。
```

示例：

```
某章节当前有 268 张符合资格的卡。
章节选项显示："第三章 · 268 张可用"
用户本次 card_limit = 100 时：
会话只纳入排序后的前 100 张，
但 candidate_count 仍然显示 268。
```

Task 2000-22 freezes Option A. The earlier A-vs-B open product Gate is **closed**. The implementation MUST distinguish:

- `total_candidates` (= `state.available_candidate_count`) = the full available candidate count BEFORE `card_limit` truncation.
- `total_count` (= `state.total_count`) = the count of cards actually in the session AFTER `card_limit` truncation.

The implementation MUST follow this order:

1. Fetch the full candidate ID set (no truncation at query time).
2. `CustomStudySessionOrder::order()` orders the **full** candidate set.
3. `available_candidate_count` = `total_candidates` = count of the full ordered set.
4. Apply `card_limit` to the full ordered set → `ordered_candidate_ids` (max 500).
5. `total_count` = count of `ordered_candidate_ids`.

The implementation MUST NOT truncate candidate IDs before `CustomStudySessionOrder::order()` — that would fake a smaller query budget and produce incorrect `available_candidate_count`.

Eligibility reuses `SourceChapterQuery`'s exact criteria — no separate filter:
1. Confirmed `WordSense` only.
2. `lifecycle_state` eligible per `scopeSenseReviewEligible` (active / buried-expired; NOT suspended / archived / buried-not-expired).
3. `fsrs_enabled = true`.
4. Direct `WordSense.source_chapter_id` match OR bound `WordSenseOccurrence.chapter_id` match (whereExists double-path deduplication).
5. User-scoped + language-scoped isolation (no cross-user / cross-language chapters).

**Performance contract**:
1. The chapter picker data source MUST be a single **batched / grouped read query** — NOT N+1 calls to `SourceChapterQuery` per chapter.
2. The query MUST reuse the same eligibility predicates as `SourceChapterQuery` (no duplicate policy logic, no divergent filter).
3. The page MUST display `candidate_count` per chapter (Task 2000-21 registered contract; Option A frozen by Task 2000-22).
4. Task 2000-19 / Task 2000-21 / Task 2000-22 do NOT add the chapter picker endpoint, do NOT modify `Chapter`, do NOT implement any frontend picker.

**Bootstrap-vs-dedicated-endpoint decision**: deferred to the Phase 5/6 Architecture Gate. Task 2000-19 / Task 2000-21 / Task 2000-22 do NOT decide whether the picker data is delivered via page bootstrap JSON or via an authenticated dedicated GET endpoint.

**Current status (Task 2000-19 + Task 2000-21 + Task 2000-22)**:
```
章节选择器只显示存在候选卡的章节：
已登记到未来 Phase 5/6；
Task 2000-21 已登记 candidate_count MUST display 契约；
Task 2000-21 已登记 candidate_count = 0 章节禁止显示；
Task 2000-22 已关闭 candidate_count 语义 OPEN PRODUCT GATE — 冻结 Option A（全部可用候选数，不受 card_limit 截断）；
本轮不实现章节列表；
本轮不实现章节选项 endpoint；
本轮不修改 Chapter；
本轮不实现前端选择器；
本轮不实现 candidate_count 查询或渲染。
```

**Pre-implementation Gate (MUST be satisfied before Phase 5/6 implementation can touch this feature)**:
1. The batched query MUST be reviewed for N+1 safety before implementation.
2. The query MUST NOT duplicate `SourceChapterQuery` eligibility logic — it must share the predicate source.
3. Page bootstrap vs. authenticated GET endpoint MUST be decided in a Phase 5/6 Architecture Gate.
4. ~~The OPEN PRODUCT GATE on `candidate_count` semantics (A vs B) MUST be resolved by the user before implementation.~~ **CLOSED by Task 2000-22 — Option A frozen (full available candidate count, not card_limit-truncated).**
5. This section does NOT authorize any chapter-picker code, endpoint, migration, or Vue component in Task 2000-19, Task 2000-21, or Task 2000-22.

## Prohibited scope (this ADR)

- No Custom Study business code.
- No Custom Study API.
- No Custom Study Vue page.
- No Custom Study migration.
- No Card Marker migration.
- No Saved Search integration.
- No today-only limits integration.
- No Study Overview integration.
- No Preset integration.
- No filtered deck data model.
- No rescheduling mode (preview-only).
- No FSRS algorithm / parameter change.
- No ReviewLog schema change.
- No lifecycle state machine change.
- No Leech Policy change.
- No Browser Search syntax change.
- No Card Info read model change.
- No external AI provider call.
- No `.env` change.
- No `AGENTS.md` change.
- No force push.

## Acceptance criteria for this ADR

This ADR is "architecture complete" when:
1. It documents the Anki official reference (§Context).
2. It documents the LinguaCafe current capability reconnaissance (§Context).
3. It freezes the 1A scope (§3, §4).
4. It defines the preview-only product rules (§1, §2).
5. It compares three architectures and recommends Option B (§5).
6. It defines the four criteria (§3).
7. It defines the session-internal ordering (§7).
8. It documents daily limits inapplicability (§8).
9. It documents lifecycle interaction (§9).
10. It documents undo inapplicability (§10).
11. It documents permissions (§11).
12. It documents query budget (§12).
13. It documents rollback (§13).
14. It documents the V1 boundary (§14).
15. It documents the marker follow-up (§15).
16. It freezes the three API routes and error contract (§16).
17. It freezes the V1 card_limit (default 100, min 1, max 500) (§17).
18. It defines the Session State invariants (§18, expanded to 15 invariants by Task 2000-19 to cover explicit `completed_ids` + `skipped_ineligible_ids` + five-state union + mutual exclusion + `step` revision semantics; further expanded to 18 invariants by Task 2000-22 to cover `available_candidate_count >= 0`, `available_candidate_count >= total_count`, and the `withEligibilityResolution()` same-step immutable copy boundary).
19. It freezes the file list for the implementation plan (§19, reorganized into Phase 1 / 2A / 2B / 3A / 4+ / Frontend / Tests by Task 2000-19; all paths canonicalized to `app/Services/CustomStudy/`).
20. It defines the shared component boundary for SenseStudyCard.vue (§20).
21. It registers the chapter picker future contract (§21, registered by Task 2000-19; implementation deferred to Phase 5/6).

All 21 are satisfied. The accompanying implementation plan `docs/plans/custom-study-1a-implementation-plan.md` defines the TDD breakdown for a future authorized implementation round.

## Implementation update — 2026-07-14, Custom Study 1A Phase 5A

The first Phase 5 data-contract slice is implemented and remains read-only.

- `GET /custom-study/chapter-options` is an authenticated setup endpoint, separate from the frozen three POST session routes. It returns only current-user/current-language chapters with at least one eligible source-chapter card, with stable book/chapter ordering and full pre-limit `candidate_count` values.
- `CustomStudyChapterOptionsService` reuses `SenseReviewQueryService` eligibility and obtains direct `source_chapter_id` plus bound occurrence matches in one grouped query. A card matched by both paths is counted once.
- A serialized card now aligns its displayed sentence, token payload, and translation to the selected example. Translation priority is explicit occurrence/card-fallback text, then one exact persisted `ChapterAiReadingAssist` sentence match; it never borrows a sense-level translation for another occurrence.
- `serializeMany()` batches the persisted reading-assist lookup. This slice adds no migration, no AI call, no ReviewLog write, no FSRS/lifecycle change, and no change to the existing session POST contract.

Phase 5B now provides `SenseStudyCard.vue`: a presentation-only component
using `SenseSentencePreview.vue` on both faces. It owns no request, rating,
queue, lifecycle, or scheduling behavior; containers supply the normal-review
menu, panels, FSRS details, and formal rating controls through slots.

## Implementation update — 2026-07-14, Custom Study frontend

The preview workflow is now available at `/custom-study`. The setup screen
uses the four frozen criteria and reads `GET /custom-study/chapter-options`
only for the source-chapter picker. It stores only the opaque rotating token
in `sessionStorage`; neither a URL nor persistent browser storage carries a
token. The session screen reuses `SenseStudyCard.vue` and the existing source
context dialog, calls only the frozen answer/resume session routes, handles
expired tokens by clearing the temporary token, and labels its four actions as
preview-only. It does not render formal review controls or write review data.
