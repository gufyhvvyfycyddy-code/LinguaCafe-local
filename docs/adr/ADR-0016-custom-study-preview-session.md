# ADR-0016: Custom Study Preview Session

**Status**: Accepted (architecture complete; **Phase 1 development partially started in Task 2000-16 — `CustomStudyCriteria` + `CustomStudyCriteriaValidator` + `ChapterLocatorInterface` + `CustomStudyValidationException` + 2 unit test files completed. Task 2000-17 fixed the Phase 1 error contract architecture: `CustomStudyCriteria::fromArray()` now throws structured `CustomStudyValidationException` directly with stable `field`/`reason` at each throw site; `CustomStudyCriteriaValidator` no longer parses exception messages. Phase 2A (`TodayForgottenQuery` + `OverdueQuery`) code and tests completed in Task 2000-17. Task 2000-18 completed Phase 2B: `EloquentChapterLocator` (production ChapterLocatorInterface binding in `AppServiceProvider`) + `SourceChapterQuery` (SQL-native Builder, whereExists double-path dedup) + `LeechAttentionQuery` (Policy-derived `list<int>`, reuses `SenseReviewLeechQueryService::describeForCards()` with preloaded cards, no Policy duplication) + `CustomStudyQueryService::candidateIds()` (unified four-mode candidate ID boundary, no new QueryInterface/DTO/Repository/Adapter, no sorting/card_limit/serializer/session/token). Phase 2A 文档旧契约 (CS-3/CS-4 "返回空集合") 在 Task 2000-18 修正为 "返回可组合 Builder". All Phase 1/2A/2B code awaiting web-side acceptance; Phase 3-6 NOT started; overall feature incomplete**; this ADR only defines the architecture and V1 boundary; no Custom Study API, page, or migration is authorized by this ADR alone beyond Phase 1 value objects/validator, Phase 2A read-only candidate queries, and Phase 2B chapter locator + source_chapter/leech_attention queries + unified candidate ID dispatcher)
**Date**: 2026-07-13 (Phase 1 added 2026-07-14 by Task 2000-16; error contract fixed + Phase 2A added 2026-07-14 by Task 2000-17; Phase 2A docs fix + Phase 2B added 2026-07-14 by Task 2000-18)
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
- `version`
- `user_id`
- `language`
- `mode` (preview-only in V1)
- `parameters` (criteria + sub-mode + order override)
- `session_id` (UUID v4)
- `issued_at`
- `expires_at` (V1: 4 hours)
- `ordered_candidate_ids` (ordered snapshot of candidate card IDs at session creation)
- `ready_queue` (card IDs not yet answered, in session order)
- `delayed_repeat_queue` (card IDs that received Again/Hard, with their `available_at` timestamps)
- `completed_count`
- `total_count`
- `current_card_id`
- `step`
- `preview_delay_config` (again_secs, hard_secs, good_secs, easy_secs)

Token rules:
1. Signed via Laravel `Crypt::encryptString()` or the project's existing secure token mechanism.
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
      delayed_repeat_queue, completed_count, total_count, current_card_id, step,
      preview_delay_config)
  → CustomStudySessionTokenService (signs/verifies/rotates token, no DB)
  → CustomStudySessionService (orchestrates: validate token → re-validate eligibility
      → apply CustomStudyPreviewPolicy → pick next card → rotate token; no write)
  → CustomStudyPreviewPolicy (pure function: Again→delayed, Hard→delayed-longer,
      Good→completed, Easy→completed; returns updated SessionState + wait_until)
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

The query budget is based on the **full candidate ID snapshot** model: the criteria query runs **once** at session creation to produce an ordered list of up to `card_limit` (max 500) candidate card IDs. This snapshot is stored inside the encrypted session-state token. Answer and resume do **not** re-run the full criteria query.

| Operation | Queries | Notes |
|---|---|---|
| Create session (issue token) | 1 criteria query + 1 card ID hydration | Executes the criteria query ONCE, fetches up to `card_limit` (max 500) ordered card IDs. Does NOT load full serializer payloads — only IDs + ordering. Only the first card (current_card) is fully serialized. |
| Answer current card | 1 eligibility re-validation (batch window) | Re-validates the next candidate card's eligibility (user, language, target_type=sense, WordSense confirmed, lifecycle, fsrs_enabled). Does NOT re-run the full criteria query. Consecutive ineligible cards use a batch window to avoid N+1. |
| Resume session | 1 eligibility re-validation (batch window) | Same as answer — re-validates the next candidate only. |
| `today_forgotten` | 1 ReviewLog query + 1 card ID hydration | Batch by `review_card_id`. Runs at session creation only. |
| `overdue` | 1 card query | `fsrs_due_at` WHERE + eligibility scope. Runs at session creation only. |
| `source_chapter` | 1 WordSense/WordSenseOccurrence query + 1 card ID hydration | Batch by `word_sense_id`. Runs at session creation only. |
| `leech_attention` | reuses `SenseReviewLeechQueryService` batch path | no Policy duplication, no N+1. Runs at session creation only. |

Rules:
1. The full criteria query runs **once** at session creation, fetching up to 500 ordered card IDs.
2. The server does **not** load 500 full serializer payloads — only card IDs + ordering metadata go into the token.
3. Only `current_card` is fully serialized (via `SenseReviewCardSerializerService`).
4. Answer and resume do **not** re-run the full criteria query.
5. Answer and resume re-validate the next candidate card's eligibility (user, language, target_type=sense, WordSense confirmed, lifecycle, fsrs_enabled) using a batch window to avoid N+1.
6. The implementation plan must include a query-count test to verify the budget.

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

The session state inside the token MUST satisfy the following invariants. These are testable properties and the implementation plan must include unit tests for each.

1. `current_card_id` must NOT simultaneously exist in `ready_queue` or `delayed_repeat_queue`.
2. When creating a session, the first card is popped from `ready_queue` and becomes `current_card_id`.
3. Each candidate card belongs to exactly one of: `current`, `ready`, `delayed`, `completed`, `skipped_ineligible`. No card is in two states at once.
4. No card is lost and no card is duplicated across the five states.
5. `completed_count` equals the number of cards in the `completed` state.
6. After Good or Easy, `current_card_id` moves to `completed`.
7. After Again or Hard, `current_card_id` moves to `delayed` (with an `available_at` timestamp).
8. A card in `delayed` NEVER re-enters `ready_queue`.
9. `wait_until` is the earliest `available_at` in `delayed_repeat_queue`, or null if `delayed_repeat_queue` is empty.
10. On resume:
    - If `current_card_id` is set: return the same current card.
    - If `current_card_id` is null and `ready_queue` is non-empty: pop the first card from `ready_queue` as the new current card.
    - If `ready_queue` is empty and a delayed card has reached `available_at`: pop the earliest-available delayed card as the new current card.
    - If only un-delayed cards remain in `delayed_repeat_queue`: return `wait_until`, no current card.
    - If all states are empty: `completed = true`.
11. A card whose eligibility has failed (suspended, archived, un-confirmed, fsrs_disabled, wrong user/language) moves to `skipped_ineligible` and NEVER re-appears in the session.
12. The session MUST be able to reliably end (reach `completed = true` or token expiry).

### 19. File list (frozen for implementation plan)

The implementation plan must include the following files. This list is authoritative — the implementation must not silently add or remove files without updating this ADR and the implementation plan.

#### 19.1 Backend (create)

- `app/CustomStudy/CustomStudySessionState.php` — value object holding the full session state (ordered_candidate_ids, ready_queue, delayed_repeat_queue, completed_count, total_count, current_card_id, step, preview_delay_config).
- `app/CustomStudy/CustomStudyPreviewPolicy.php` — pure function: maps (current_state, rating) → (updated_state, wait_until). Again→delayed(60s), Hard→delayed(600s), Good→completed, Easy→completed.
- `app/CustomStudy/CustomStudySessionTokenService.php` — signs, verifies, and rotates the encrypted session-state token. No DB.
- `app/CustomStudy/CustomStudySessionService.php` — orchestrates: validate token → re-validate eligibility → apply PreviewPolicy → pick next card → rotate token. No write.
- `app/CustomStudy/CustomStudySessionOrder.php` — pure function: mode-specific order override applied at session creation only.

#### 19.2 Frontend (create)

- `resources/js/components/CustomStudy/CustomStudy.vue` — top-level page component.
- `resources/js/components/CustomStudy/CustomStudySession.vue` — session orchestration component (token management, answer/resume calls).
- `resources/js/components/Senses/SenseStudyCard.vue` — shared card presentation component (see §20 for boundary).

#### 19.3 Tests (create)

Backend:
- `tests/Unit/CustomStudy/CustomStudySessionStateTest.php`
- `tests/Unit/CustomStudy/CustomStudyPreviewPolicyTest.php`
- `tests/Unit/CustomStudy/CustomStudySessionTokenServiceTest.php`
- `tests/Feature/CustomStudy/CustomStudyOpenSessionTest.php`
- `tests/Feature/CustomStudy/CustomStudyAnswerTest.php`
- `tests/Feature/CustomStudy/CustomStudyResumeTest.php`
- `tests/Feature/CustomStudy/CustomStudyRoutesTest.php`

Frontend (Node guard tests):
- `tests/js/SenseStudyCardGuard.test.mjs`
- `tests/js/CustomStudyPageGuard.test.mjs`
- `tests/js/CustomStudySessionUiGuard.test.mjs`

#### 19.4 Files NOT created (prohibited in 1A)

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
18. It defines the 12 Session State invariants (§18).
19. It freezes the file list for the implementation plan (§19).
20. It defines the shared component boundary for SenseStudyCard.vue (§20).

All 20 are satisfied. The accompanying implementation plan `docs/plans/custom-study-1a-implementation-plan.md` defines the TDD breakdown for a future authorized implementation round.
