# ADR-0009: Review Action Ledger and Stack Undo

**Status**: Accepted
**Date**: 2026-07-11
**Related**: ADR-0007 (Card Management Deep Link), ADR-0008 (Interval Preview)

## Context

LinguaCafe's sense review flow allows users to rate cards with `again`/`hard`/`good`/`easy`. Each rating writes a `ReviewLog` row and updates the `ReviewCard`'s FSRS fields. The previous architecture had no undo capability — once a rating is committed, the only way to reverse it is to manually adjust the card or re-rate it.

Users sometimes mis-click a rating button. Without undo, the card's FSRS state is permanently altered, the daily report counts an erroneous review, and the review log records a wrong action. Anki Desktop provides "Undo" (Ctrl+Z) to reverse the last review action; LinguaCafe needs the same capability.

### Why not simply delete the ReviewLog?

Deleting `ReviewLog` rows is dangerous because:
1. **Audit trail loss** — the fact that a rating happened is valuable for debugging and analytics.
2. **Cascade complexity** — downstream consumers (daily report, 7-day trend, stats) already counted the review; deleting the log retroactively creates inconsistent historical snapshots.
3. **Data integrity** — hard-deleting rows can break foreign key relationships and make time-series queries non-deterministic.

Instead, we mark the `ReviewLog` as `undone` via `undone_at` timestamp. Product analytics queries exclude undone logs; audit queries (management page, diagnostics) retain them with an `undone` flag.

### Why only stack-based undo (not arbitrary rollback)?

1. **FSRS reversibility** — to undo a rating, we restore the card's complete FSRS snapshot from *before* that rating. This is only safe if no subsequent rating has occurred on the same card. Stack-based undo (last action first) guarantees this for the session's latest action.
2. **Simplicity** — arbitrary rollback would require replaying the entire rating history, which is complex and error-prone.
3. **Anki alignment** — Anki Desktop only supports undoing the last review action, not arbitrary history rollback.
4. **Multi-card sessions** — in a review session with cards A, B, C (each rated), undoing must proceed C → B → A. Skipping B to undo A would leave B's FSRS state inconsistent.

### Why no redo?

1. **Complexity** — redo requires storing the undone state separately and re-applying it, doubling the state management surface.
2. **Low value** — if a user undoes by mistake, they can simply re-rate the card (which creates a new ReviewLog).
3. **Anki alignment** — Anki Desktop does not support redo for review actions.

## Decision

### 1. Additive Migration (single)

Add 6 nullable columns to `review_logs`:
- `review_session_id` (string, nullable, indexed) — UUID identifying the browser tab session.
- `before_card_snapshot` (JSON, nullable) — complete FSRS state of the card *before* this rating.
- `after_card_snapshot` (JSON, nullable) — complete FSRS state of the card *after* this rating.
- `undone_at` (timestamp, nullable, indexed) — when this action was undone (null = active).
- `undo_request_id` (string, nullable, unique) — idempotency key for the undo request.
- `undo_source` (string, nullable) — which UI entry triggered the undo (`sense_review_snackbar`, `sense_review_history`, `sense_review_hotkey`).

Old logs (pre-migration) have all 6 fields as null. They continue to participate in product analytics normally but cannot be undone (no snapshot).

### 2. FSRS Snapshot Service

`ReviewCardFsrsSnapshotService` captures/restores 8 FSRS fields:
- `fsrs_state`, `fsrs_due_at`, `fsrs_stability`, `fsrs_difficulty`, `fsrs_last_reviewed_at`, `fsrs_reps`, `fsrs_lapses`, `fsrs_enabled`

Methods:
- `capture(ReviewCard): array` — pure, no DB query.
- `restore(ReviewCard, array): void` — sets attributes on the model, does NOT save.
- `matches(ReviewCard, array): bool` — pure, compares current card state to snapshot.
- `fingerprint(array): string` — stable hash for comparison.
- `validate(array): void` — rejects snapshots missing required fields.

### 3. Transactional Rating

`ReviewCardService::recordReview()` is refactored to:
1. `DB::transaction()` + `lockForUpdate()` on ReviewCard (already exists).
2. Capture `before` snapshot via `ReviewCardFsrsSnapshotService::capture()`.
3. Call `FsrsSchedulingService::schedule()` (unchanged pure computation).
4. Apply schedule result to card + save.
5. Capture `after` snapshot.
6. Create ReviewLog with `review_session_id`, `before_card_snapshot`, `after_card_snapshot`.
7. Any failure rolls back the entire transaction.

The rating request accepts an optional `review_session_id` (UUID). If absent, the ReviewLog is created with `review_session_id = null` and is not undoable via the session stack.

### 4. ReviewLog Query Boundary

`ReviewLog::scopeNotUndone($query)` adds `whereNull('undone_at')`. This is the single place where undone exclusion is enforced.

**Product analytics** (exclude undone):
- Daily report, 7-day trend, 30-day calendar → via `SenseReviewQueryService::nonResetSenseReviewLogQuery()` which now also applies `->notUndone()`.
- FSRS stats, session summary, learning feedback → same path.
- FSRS optimization train set → `SettingsService` queries add `->notUndone()`.

**Audit** (include undone):
- Management page per-card logs (`ReviewCardManageController::logs`) — includes undone with `undone`/`undone_at`/`undo_source` fields.
- Session action timeline — includes undone with `undoable`/`blocked_reason`.
- Diagnostics — includes all logs for total count.

### 5. Undo Policy

`SenseReviewUndoPolicy` is a pure service (no DB access) that evaluates whether a ReviewLog can be undone:

Blocked reasons:
- `wrong_session` — the log's `review_session_id` doesn't match the request.
- `not_latest_action` — a newer active action exists in the session.
- `already_undone` — `undone_at` is not null.
- `missing_snapshot` — `before_card_snapshot` is null (legacy log).
- `card_state_changed` — current card state doesn't match `after_card_snapshot`.
- `legacy_target` — card is not a sense card.
- `sense_not_confirmed` — WordSense status is not `confirmed`.
- `card_archived` — `fsrs_enabled` is false.
- `unsupported_rating` — rating is not `again`/`hard`/`good`/`easy` (e.g., `reset`).
- `unsupported_source` — source is not a review source.

### 6. Undo Transaction

`SenseReviewUndoService::undo()` flow:
1. Validate UUIDs (`review_session_id`, `undo_request_id`).
2. `DB::transaction()` + `lockForUpdate()` on ReviewLog and ReviewCard.
3. Query session's latest active action.
4. Call `SenseReviewUndoPolicy` → if blocked, return 409 with `blocked_reason`.
5. Verify current card state matches `after_card_snapshot` (via `matches()`).
6. Restore `before_card_snapshot` to ReviewCard via `restore()`.
7. Save ReviewCard.
8. Mark ReviewLog: `undone_at`, `undo_request_id`, `undo_source`.
9. Commit.

**Idempotency**: same `undo_request_id` returns 200 with `already_applied=true`. Different `undo_request_id` on an already-undone log returns 409.

### 7. Stack Undo Semantics

Given session actions A → B → C (all active):
- Only C is `undoable` (it's the latest active).
- B and A are `blocked_reason: not_latest_action`.
- After undoing C: C is `undone`, B becomes `undoable`, A remains blocked.
- After undoing B: B is `undone`, A becomes `undoable`.
- After undoing A: all three are `undone`.

Cannot skip: undoing A while C is active returns 409 `not_latest_action`.

### 8. Session ID Strategy

- Frontend uses `sessionStorage` (per-tab, survives refresh, dies on tab close).
- UUID v4 generated client-side via `crypto.randomUUID()` or fallback.
- Not derived from user ID.
- Not stored in localStorage.
- Passed as `review_session_id` in rating POST and undo POST.

### 9. Multi-tab / Multi-device Conflict

- Each browser tab has its own `review_session_id`.
- Tab 1 cannot undo Tab 2's actions (wrong_session).
- If Tab 1 undoes a rating on Card X, and Tab 2 tries to undo a later rating on Card X, Tab 2's `matches()` check fails → 409 `card_state_changed`.
- Row-level locks (`lockForUpdate`) prevent concurrent undo transactions from corrupting state.

### 10. Browser/API Failure Behavior

- Network failure on undo POST → frontend shows "撤销失败，请检查网络后重试。" and does NOT locally modify any state.
- 409 conflict → frontend shows "无法撤销：卡片状态已在其他页面发生变化。" and refreshes timeline + queue.
- 404 (session mismatch) → frontend shows "无法撤销：该操作不属于当前复习会话。"
- The frontend never performs optimistic local restore — all FSRS changes come from the backend response.

## Consequences

### Positive
- Users can undo mis-clicked ratings.
- Audit trail is preserved (undone logs are kept).
- Product analytics correctly exclude undone ratings.
- FSRS state is fully and safely restored.
- Anki-aligned UX (Ctrl+Z, stack undo, last-action-only).

### Negative
- `review_logs` table grows by 6 columns (storage cost is minimal for JSON snapshots).
- Undo is limited to the current session — users cannot undo yesterday's ratings.
- Legacy logs (pre-migration) are not undoable.
- No redo capability.

### Migration Rollback

The migration's `down()` method drops the 6 new columns. Existing `ReviewLog` rows and `ReviewCard` data are unaffected. After rollback, all undo endpoints return 404/500 (columns missing), but rating and analytics continue to work normally.

## Rollback Plan

1. Revert the frontend commit (removes undo UI).
2. Revert the backend commit (removes undo endpoints, services, scope).
3. Run `php artisan migrate:rollback` (drops the 6 columns).
4. Rating and analytics continue to work with the original `recordReview()` logic.
5. No data loss — the 6 columns being dropped only contain undo metadata.
