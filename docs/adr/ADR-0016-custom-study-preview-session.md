# ADR-0016: Custom Study Preview Session

**Status**: Accepted (architecture complete; development not started — this ADR only defines the architecture and V1 boundary; no Custom Study business code, API, page, or migration is authorized by this ADR alone)
**Date**: 2026-07-13
**Related**: `docs/adr/ADR-0009-review-action-ledger-and-stack-undo.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`, `docs/adr/ADR-0015-review-queue-order-policy.md`, `docs/plans/custom-study-1a-implementation-plan.md`

## Context

LinguaCafe's next main-line task after Queue Order is Custom Study 1A. Anki's Custom Study feature lets users build ad-hoc review sessions outside the normal due queue. This ADR freezes the LinguaCafe V1 mapping of that feature — specifically, a **preview-only temporary session** that does not move cards, does not build a filtered deck, does not write ReviewLog, and does not run FSRS scheduling.

### Anki official reference

Sources reviewed (2026-07-13):
- Anki Manual: "Filtered Decks & Cramming", "Custom Study", "Home Decks", "Creating Manually", "Order", "Steps & Returning", "Due Reviews", "Reviewing Ahead", "Rescheduling"
- Anki repository: `qt/aqt/customstudy.py`, `qt/aqt/filtered_deck.py`, `rslib/src/search/`, `rslib/src/deckconfig/`, `rslib/src/scheduler/filtered/`
- Anki repository commit: `e5ea3fb40af139fa1f7bcf9513dc49426047c5a1`
- Anki release: 25.07 (current main at review time)

**Anki Custom Study presets** (from `customstudy.py` `CustomStudyRequest`):

| Preset | What it does | Creates filtered deck? | reschedule default |
|---|---|---|---|
| `new_limit_delta` | Increase today's new card limit | No — only mutates today's deck-config limit | N/A |
| `review_limit_delta` | Increase today's review limit | No — only mutates today's deck-config limit | N/A |
| `forgot` | Review cards forgotten in the last N days | Yes | `false` (preview mode) |
| `ahead` | Review cards due in the next N days | Yes | `true` |
| `preview` | Preview cards added in the last N days | Yes | `false` (preview mode) |
| `cram` (general) | Cram arbitrary search string | Yes | `true` |

Key facts:
- Only `new_limit_delta` and `review_limit_delta` mutate today-only limits without creating a filtered deck. These are out of scope for Custom Study 1A (they belong to the later `today-only limits` main-line).
- `forgot`, `preview`, and `cram` create filtered decks. `forgot` and `preview` hard-code `reschedule=false` (preview mode); `ahead` and `cram` default `reschedule=true`.
- **Preview mode** in Anki: cards in `FilteredState::Preview`. Again/Hard/Good have configurable delays (0 = return card immediately); Easy always returns the card. Returned cards are restored byte-for-byte — no ReviewLog, no FSRS reschedule, no card movement.
- **Filtered deck exclusions** (always): suspended, buried, and already-filtered cards are excluded via `-is:suspended -is:buried -deck:filtered` appended to the user search.
- **Filtered deck order options** (11): `random`, `introducedAsc`, `introducedDesc`, `oldestReviewedFirst`, `latestReviewedFirst`, `relativeOverduenessAsc`, `relativeOverduenessDesc`, `dueAsc`, `dueDesc`, `easeAsc`, `easeDesc`.

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

#### Option B: Server-signed criteria token, no DB session (RECOMMENDED for V1)

The frontend receives a server-signed opaque token encoding the criteria. On every "next card" or "open session" request, the frontend sends the token; the server re-validates user + language + criteria + eligibility, re-runs the query, and returns the next card. No `custom_study_sessions` table.

Token payload (server-signed, opaque to client):
- `version`
- `user_id`
- `language`
- `mode` (preview-only in V1)
- `parameters` (criteria + sub-mode + order override)
- `issued_at`
- `expires_at` (V1: 4 hours)
- `nonce` / `session_id` (UUID v4)

Token rules:
- Signed via Laravel `Crypt::encryptString()` or the project's existing secure token mechanism.
- Token does **not** contain sensitive card content — only criteria + metadata.
- Server re-validates `user_id` + `language` on every request (token is bound to issuer, not bearer-permissive).
- Candidate cards are re-run through eligibility on every request — token does not "freeze" a card list.
- Client cannot pass arbitrary `card_id` to bypass permission — the server always picks the next card from the re-run query.
- No migration.
- No AI call.
- No ReviewLog.
- No FSRS change.

| Dimension | Verdict |
|---|---|
| Security | Strong — server re-validates every request. |
| User isolation | Strong — `user_id` bound in token, re-checked. |
| Language isolation | Strong — `language` bound in token, re-checked. |
| URL/refresh recovery | OK — token can go in URL query. |
| Card state changes | Handled — eligibility re-run each request. |
| Concurrency | OK — tokens are stateless; multiple tabs get independent tokens. |
| Expiry | OK — `expires_at` checked server-side. |
| DB cost | Minimal — no session table; only criteria queries. |
| Test difficulty | Good — server-side logic is unit-testable. |
| Saved Search boundary | Clean — criteria are not user-free-text; they are structured enum + chapter id. |
| Future rescheduling mode | Extensible — a future rescheduling mode can add a session table without changing the token contract. |

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
  → CustomStudyCriteriaValidator (pure, no DB)
  → CustomStudyQueryService (builds the candidate query, applies criteria, no write)
  → CustomStudySessionTokenService (signs/verifies token, no DB)
  → CustomStudySessionService (orchestrates: validate token → re-run query → apply order → return next card; no write)
  → SenseReviewCardSerializerService (serializes the next card, same shape as /reviews/senses)
  → Custom Study page (not yet implemented; not authorized by this ADR)
```

This pipeline is the **recommended target**. It reuses `SenseReviewCardSerializerService` so the Custom Study card looks identical to a normal sense review card. It does not duplicate the serializer, does not duplicate Leech Policy, does not duplicate Queue Order logic.

### 7. Session-internal ordering

The Custom Study session has a **mode-specific order override** that applies only within the session and does not modify the global Queue Order setting:

| Mode | Default order override | Rationale |
|---|---|---|
| `today_forgotten` | Most recent Again first, fallback to Queue Order | Anki `forgot` uses `rated:days:1` which is implicitly recency-ordered. |
| `overdue` | Ascending retrievability (most forgotten first), fallback to Queue Order | Aligns with the "most at-risk first" intent. |
| `source_chapter` | Current Queue Order (no override) | No strong reason to deviate. |
| `leech_attention` | Severity DESC (leech before struggling), then Queue Order | Surface the worst cards first. |

Rules:
1. Override only affects the temporary session.
2. Does not modify global Queue Order settings.
3. Does not write settings.
4. Does not enter user-defined arbitrary ordering.
5. Does not merge with Saved Search.

The final override table is decided in this ADR; implementation is not authorized by this ADR alone.

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

| Operation | Queries | Notes |
|---|---|---|
| Create session (issue token) | 1-2 | criteria validation + candidate count (does not fetch full cards). |
| Get next card | 2-4 | re-run criteria + eligibility + order + serialize. No N+1. |
| `today_forgotten` | 1 ReviewLog query + 1 card query | batch by `review_card_id`. |
| `overdue` | 1 card query | `fsrs_due_at` WHERE + eligibility scope. |
| `source_chapter` | 1 WordSense/WordSenseOccurrence query + 1 card query | batch by `word_sense_id`. |
| `leech_attention` | reuses `SenseReviewLeechQueryService` batch path | no Policy duplication, no N+1. |

The server never fetches the full candidate list on every "next card" — it fetches only enough to determine the next card (page size 1-2, ordered by the mode-specific override).

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

All 15 are satisfied. The accompanying implementation plan `docs/plans/custom-study-1a-implementation-plan.md` defines the TDD breakdown for a future authorized implementation round.
