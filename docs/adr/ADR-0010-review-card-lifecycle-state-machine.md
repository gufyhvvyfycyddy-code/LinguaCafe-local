# ADR-0010: Review Card Lifecycle State Machine

**Status**: Accepted
**Date**: 2026-07-12
**Related**: ADR-0007 (Card Management Deep Link), ADR-0008 (Interval Preview), ADR-0009 (Review Action Ledger and Stack Undo)

## Context

LinguaCafe's review card system uses a single boolean column `review_cards.fsrs_enabled` to encode at least four distinct product concepts:

1. **Queue eligibility** — `SenseReviewService::dueSenseReviewCardQuery()` filters `fsrs_enabled=true` to decide which cards appear in the review queue.
2. **Archived** — `WordSenseService::archiveSense()` sets `fsrs_enabled=false` *and* `word_senses.status=STATUS_REJECTED`; `ReviewCardManageMutationService::setEnabled(false)` sets only `fsrs_enabled=false`. Two asymmetric archive paths exist.
3. **Reset side-effect** — `ReviewCardService::resetCard()` force-sets `fsrs_enabled=true`, which silently cancels any archived state.
4. **Restore gap** — `WordSenseService::restoreSense()` flips `status` back to `CONFIRMED` but does **not** restore `fsrs_enabled=true`, so an archived-then-restored sense card stays invisible to the queue.

There is no concept of *temporary* hide (bury), *long-term* pause (suspend), or *out-of-system* (archive) being distinguishable. The Anki product model that LinguaCafe follows has these as first-class concepts.

### Problems this causes

- `fsrs_enabled=false` conflates "user archived", "system disabled", and "deleted-but-row-kept" — queue, stats, undo and management all have to guess.
- A user who wants to *temporarily* hide a card for today has no option other than archive, which then requires manual restore and breaks FSRS continuity expectations.
- `resetCard()` silently un-archives cards, which violates the user's intent to keep a card out of the learning system.
- The undo ledger (ADR-0009) snapshots `fsrs_enabled` and restores it on undo, which would overwrite a concurrent lifecycle change (e.g., user suspends a card, then undoes an old rating — undo would incorrectly flip it back to enabled).
- Frontend cannot present clear state badges or explain the impact of each operation because the backend only exposes one boolean.
- Stats can only split cards into "enabled" vs "archived"; it cannot report buried or suspended counts.

## Decision

### 1. Lifecycle states (four, plus two non-state operations)

| Concept | Persistent? | Queue eligible? | FSRS modified? | ReviewLog written? |
|---|---|---|---|---|
| **Active** | yes | yes | normal rating | yes |
| **Buried** | temporary | no (until local next-day 00:00) | no | no |
| **Suspended** | yes (until Resume) | no | retained, no new writes | no |
| **Archived** | yes (until Restore) | no | retained, no new writes | no |
| **Reset** | *not a state* — scheduling operation | depends on lifecycle state | yes (rebuilds FSRS fields) | existing reset log semantics |
| **Delete** | *not a state* — physical removal | n/a | n/a | n/a |

**Buried** is *temporary*: the card auto-reverts to Active at the user's timezone next natural-day 00:00. No timer or scheduled job is required — the queue query treats `buried_until <= now` as Active. Buried does **not** write a `ReviewLog` and does **not** touch FSRS.

**Suspended** is *long-term*: the card stays out of the queue until the user explicitly resumes. Resume preserves the original `fsrs_due_at` (it does **not** force the card to be due now). Suspended is visible in the management page by default.

**Archived** is *out-of-system*: the card exits the current learning system but retains all history. It is hidden from the management page's default list and visible under an "Archived" filter. Restore returns the card to Active with its prior FSRS data intact.

**Reset** is a scheduling operation, not a lifecycle state. It rebuilds FSRS fields (stability/difficulty/reps/lapses/due_at/state) but does **not** change `lifecycle_state`, `buried_until`, `lifecycle_version`, or `lifecycle_changed_at`. A Suspended card that is reset stays Suspended; an Archived card that is reset stays Archived.

**Delete** is a physical removal operation and is **not** exposed through the unified lifecycle endpoint. It continues to use its own dangerous-confirmation flow and dependency protection. The lifecycle state machine never transitions to a "deleted" state.

### 2. Why `fsrs_enabled` cannot remain the sole state

- It cannot represent four mutually exclusive concepts (active / buried / suspended / archived) in one boolean.
- It is already overloaded with "queue eligible" semantics, so reusing it for "archived" produces contradictions (a buried card is not queue-eligible but is not archived).
- Reset's force-enable behavior silently cancels archived state, violating user intent.
- The undo snapshot/restore path would need to know whether to restore `fsrs_enabled` based on whether the card is "really archived" vs "temporarily disabled" — but the data does not carry that distinction.

### 3. Single additive migration

One migration adds four columns to `review_cards` and creates one new table:

```sql
ALTER TABLE review_cards
  ADD COLUMN lifecycle_state      VARCHAR(20)  NOT NULL DEFAULT 'active',
  ADD COLUMN buried_until         DATETIME     NULL,
  ADD COLUMN lifecycle_version    INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN lifecycle_changed_at DATETIME     NULL;

CREATE INDEX review_cards_lifecycle_state_index ON review_cards(lifecycle_state);
CREATE INDEX review_cards_buried_until_index ON review_cards(buried_until);

CREATE TABLE review_card_state_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  language_id     INT NOT NULL,
  review_card_id  BIGINT UNSIGNED NOT NULL,
  action          VARCHAR(20) NOT NULL,
  previous_state  JSON NULL,
  new_state       JSON NULL,
  request_id      CHAR(36) NOT NULL,
  source          VARCHAR(50) NULL,
  metadata        JSON NULL,
  created_at      DATETIME NOT NULL,
  UNIQUE KEY rcse_request_id_unique (request_id),
  INDEX rcse_card_index (review_card_id, created_at),
  INDEX rcse_user_lang_index (user_id, language_id, created_at)
);
```

**Backfill** (idempotent, no FSRS mutation, no row deletion):

```sql
UPDATE review_cards SET lifecycle_state='archived' WHERE fsrs_enabled=0;
UPDATE review_cards SET lifecycle_state='active'   WHERE fsrs_enabled=1;
```

`fsrs_enabled` is **kept** as a compatibility mirror so that legacy queries and the undo snapshot continue to work without a flag day. The mirror invariant is:

- `lifecycle_state IN ('active','buried')` → `fsrs_enabled=true`
- `lifecycle_state IN ('suspended','archived')` → `fsrs_enabled=false`

`ReviewCardLifecycleCommandService` enforces this invariant on every transition. The `ReviewCardFsrsSnapshotService` continues to snapshot and restore `fsrs_enabled` for legacy compatibility, **but** the undo path is modified to not overwrite `lifecycle_state`/`buried_until`/`lifecycle_version`/`lifecycle_changed_at` — those are owned exclusively by the lifecycle command service.

If investigation had shown that `fsrs_enabled=false` carries multiple product meanings (e.g., "disabled by system" vs "user archived"), the migration would have been blocked. Investigation confirmed that `fsrs_enabled=false` is set in exactly two places: `WordSenseService::archiveSense` (user archive) and `ReviewCardManageMutationService::setEnabled(false)` (management archive). Both map cleanly to `lifecycle_state='archived'`.

### 4. State machine

```
            ┌──────────────────────────────────────┐
            │                                      │
            ▼                                      │
       ┌─────────┐  bury   ┌─────────┐             │
       │ Active  ├────────►│ Buried  │             │
       │         │◄────────┤         │             │
       └────┬────┘  unbury └─────────┘             │
            │                                      │
            │ suspend                              │
            ▼                                      │
       ┌──────────┐  resume  ┌─────────┐           │
       │Suspended │◄─────────┤ Active  │           │
       │          │          │         │           │
       └────┬─────┘          └─────────┘           │
            │ archive                               │
            ▼                                      │
       ┌──────────┐  restore ┌─────────┐           │
       │ Archived │─────────►│ Active  │           │
       └──────────┘          └─────────┘           │
            │                                      │
            │ (Buried auto-expires → Active)       │
            └──────────────────────────────────────┘
```

Legal transitions:
- `active + bury → buried`
- `buried + unbury → active`
- `active + suspend → suspended`
- `suspended + resume → active`
- `suspended + archive → archived`
- `active + archive → archived`
- `archived + restore → active`

Illegal transitions (return 409):
- `buried + suspend` — must unbury first
- `buried + archive` — must unbury first
- `archived + bury` — must restore first
- `archived + suspend` — must restore first
- `suspended + bury` — must resume first
- `suspended + restore` — must resume first (Suspended is not Archived)

**Buried auto-expiry** is **not** a transition written to the database. The queue query and the policy descriptor treat `buried_until <= now` as `effective_state=active`. No scheduled job is required.

### 5. Pure policy service

`ReviewCardLifecyclePolicy` is a pure service (no DB access, no I/O). It exposes:

- `describe(ReviewCard $card, Carbon $now, string $timezone): array`
- `canTransition(string $from, string $action): bool`
- `availableActions(array $descriptor): array`

The descriptor returned by `describe()` contains at minimum:

```php
[
    'persistent_state'    => 'active|buried|suspended|archived',
    'temporarily_buried'  => bool,           // true only if buried AND not expired
    'effective_state'     => 'active|suspended|archived',  // expired buried → active
    'queue_eligible'      => bool,           // active state AND not temporarily buried
    'blocked_reason'      => string|null,    // why not queue eligible
    'buried_until'        => Carbon|null,
    'available_actions'   => ['bury','suspend','archive','reset'],  // depends on state
    'version'             => int,
]
```

The frontend **does not** replicate the state machine. It receives the descriptor (or a projection of it) from the backend and renders state badges, action buttons, and help text based on server-provided data.

### 6. Unified queue scope

`ReviewCard::scopeSenseReviewEligible($query, $userId, $language, $now)` is the **single** place that decides whether a card participates in the sense review queue. It enforces:

- `user_id`, `language_id`, `target_type = sense`
- joined `word_senses.status = confirmed`
- `lifecycle_state = 'active'`
- `buried_until IS NULL OR buried_until <= $now`
- `fsrs_enabled = true` (compatibility mirror)

The due filter (`fsrs_due_at <= now`) is added by the caller (`SenseReviewService::dueSenseReviewCardQuery`), because the scope is "queue eligible" not "due now".

All other services that previously inlined their own `fsrs_enabled=true` check (`ReviewStatsService`, `FsrsReschedulePreviewService`, etc.) are updated to either use this scope or consult the policy descriptor.

### 7. Command service (single mutation entry point)

`ReviewCardLifecycleCommandService` is the **only** service allowed to mutate `lifecycle_state`, `buried_until`, `lifecycle_version`, and `lifecycle_changed_at`. It supports six actions:

- `bury` — sets `lifecycle_state='buried'`, `buried_until=nextLocalDayBoundary(timezone, now)`
- `unbury` — sets `lifecycle_state='active'`, `buried_until=null`
- `suspend` — sets `lifecycle_state='suspended'`, `buried_until=null`
- `resume` — sets `lifecycle_state='active'`, `buried_until=null`, preserves `fsrs_due_at`
- `archive` — sets `lifecycle_state='archived'`, `buried_until=null`
- `restore` — sets `lifecycle_state='active'`, `buried_until=null`

Each action:

1. `DB::transaction()` + `ReviewCard::lockForUpdate()->find($id)` (row lock).
2. Validate `user_id`, `language_id`, `target_type=sense`, joined `word_senses.status=confirmed`.
3. **Idempotency check**: look up `review_card_state_events` by `request_id`. If found, return `already_applied=true` with the original `event_id` and resulting lifecycle descriptor.
4. **Optimistic lock**: compare `expected_version` (from request) to `$card->lifecycle_version`. Mismatch → 409 `version_conflict`.
5. **Policy check**: `Policy::canTransition($effectiveState, $action)`. False → 409 `illegal_transition`.
6. Capture `previous_state` snapshot via `ReviewCardLifecycleSnapshotService`.
7. Apply transition: update `lifecycle_state`, `buried_until`, `lifecycle_version = lifecycle_version + 1`, `lifecycle_changed_at = now`. Synchronize `fsrs_enabled` mirror.
8. Save card.
9. Capture `new_state` snapshot.
10. Insert `review_card_state_events` row with `request_id`, `source`, `previous_state`, `new_state`, `metadata`.
11. Commit.

**Guarantees**:
- No `ReviewLog` is created.
- FSRS scheduling fields (`fsrs_state`, `fsrs_stability`, `fsrs_difficulty`, `fsrs_reps`, `fsrs_lapses`, `fsrs_due_at`, `fsrs_last_reviewed_at`) are **never** modified.
- Same `request_id` retry is idempotent.
- Stale `expected_version` returns 409.
- Illegal transition returns 409.
- Concurrent rating/undo on the same card is safe because both also acquire `lockForUpdate` on `review_cards`.

### 8. Bury time service

`ReviewCardBuryTimeService::nextLocalDayBoundary(string $timezone, Carbon $now): Carbon` computes the user's local next natural-day 00:00.

- Normal date: `now` in user tz → next day 00:00 → convert back to UTC.
- Month-end / year-end: handled natively by Carbon date arithmetic.
- DST forward (spring): the missing hour is handled by computing in the user's tz then converting — Carbon picks a valid instant.
- DST backward (fall): the ambiguous hour is resolved by `Carbon::startOfDay()` semantics on the next day.
- Invalid timezone: throws `InvalidArgumentException` (the controller returns 422).
- User tz vs server tz different: server tz is irrelevant — computation is always in user tz.

The frontend **never** computes `buried_until`. It always sends `action=bury` and the backend computes the timestamp.

### 9. API surface

New endpoints (all under authenticated middleware):

```
GET  /review-cards/{reviewCard}/lifecycle
     → returns current descriptor (state, buried_until, version, available_actions)

POST /review-cards/{reviewCard}/lifecycle-actions
     body: { action, request_id, expected_version, source, reason? }
     → 200 { review_card_id, lifecycle, request_id, already_applied, event_id }
     → 409 { error: 'version_conflict'|'illegal_transition' }
     → 404 { error: 'not_found' }
     → 422 { error: 'invalid_action'|'invalid_timezone' }

GET  /review-cards/{reviewCard}/lifecycle-events
     → returns last 20 state events (action, previous_state, new_state, source, created_at, request_id prefix)

POST /review-cards/manage/bulk-lifecycle
     body: { ids: int[], action, source, reason? }
     → 200 { results: [{ id, success, already_applied, conflict, forbidden, not_found, event_id? }] }
```

Reset and Delete continue to use their existing endpoints (`POST /review-cards/manage/{id}/reset`, `DELETE /review-cards/manage/{id}`). They are **not** routable through `/lifecycle-actions`. Internally they call the same `ReviewCardManageAccessService` boundary so user/language/confirmed-sense checks remain consistent.

**Legacy endpoint compatibility**:

- `PATCH /review-cards/manage/{id}/enabled` with `{enabled:false}` → internally calls `CommandService->act($card, 'archive', ...)`.
- `PATCH /review-cards/manage/{id}/enabled` with `{enabled:true}` → internally calls `CommandService->act($card, 'restore', ...)`.
- `POST /review-cards/manage/bulk-enabled` with `{enabled:false}` → internally calls bulk archive.
- `POST /review-cards/manage/bulk-enabled` with `{enabled:true}` → internally calls bulk restore.

A second mutation logic is **not** maintained. The legacy endpoints are thin wrappers that synthesize a `request_id` (server-side UUID) and an `expected_version` (read from the card) before delegating.

### 10. Boundary with rating (ADR-0009)

- Rating (`ReviewCardService::recordReview`) **must** verify `queue_eligible=true` via `Policy::describe()` before accepting a rating. A non-queue-eligible card returns 409 `card_not_queue_eligible`.
- Rating continues to `lockForUpdate` on `review_cards`, so it cannot race with a concurrent lifecycle transition.
- Rating does **not** modify lifecycle fields.
- Rating continues to write `ReviewLog` rows (with before/after FSRS snapshots) as before.

### 11. Boundary with undo (ADR-0009)

- `SenseReviewUndoPolicy` is updated: `card_archived` is split into `card_suspended` and `card_archived` based on `lifecycle_state`. Both block undo.
- A buried-but-not-expired card does **not** block undo (effective state is active after the bury expires, but undo is about the rating log, not the queue).
- `ReviewCardFsrsSnapshotService::restore()` is modified: it restores the 8 FSRS fields including `fsrs_enabled` **only if** the lifecycle state has not changed since the snapshot was taken. If `lifecycle_version` differs from the snapshot's `lifecycle_version`, the restore is aborted with 409 `lifecycle_changed_during_undo`.
- Concretely: if the user suspends a card and then tries to undo an old rating on that card, the undo is blocked because the card is no longer queue-eligible and the lifecycle has moved on.

### 12. Boundary with reset

- `ReviewCardService::resetCard()` is modified: it no longer force-sets `fsrs_enabled=true`.
- Reset only modifies FSRS scheduling fields. `lifecycle_state`, `buried_until`, `lifecycle_version`, `lifecycle_changed_at` are untouched.
- After reset, the `fsrs_enabled` mirror reflects the current lifecycle state (Suspended/Archived remain `false`).
- A reset on a Suspended card keeps it Suspended — the user must explicitly resume before it re-enters the queue.

### 13. Boundary with delete

- Delete continues to use `WordSenseService::removeSenseFromReviewSystem($sense, true)`.
- Delete is **not** available via `/lifecycle-actions`.
- The lifecycle state machine never transitions to a "deleted" state.
- Existing dependency protection (rejected sense, preserved occurrences/logs) is unchanged.

### 14. Batch operations

`POST /review-cards/manage/bulk-lifecycle` supports:

- `suspend`, `resume`, `archive`, `restore`, `unbury`

**Not** supported in batch (must be single-card operations):
- `bury` (each card needs its own `buried_until` computation and the user should consciously bury one at a time)
- `reset` (high-risk, single-card only)
- `delete` (high-risk, single-card only, existing `bulk-delete` endpoint is unchanged for backward compatibility but Task A does not add new delete batch UI)

Each item in the response carries its own status:

```json
{
  "results": [
    { "id": 1, "success": true, "event_id": 101 },
    { "id": 2, "already_applied": true, "event_id": 102 },
    { "id": 3, "conflict": "version_conflict" },
    { "id": 4, "forbidden": true },
    { "id": 5, "not_found": true }
  ]
}
```

Partial failure is **not** disguised as full success. The frontend renders per-item outcomes.

### 15. Audit events

Every lifecycle transition writes exactly one `review_card_state_events` row. The table is append-only. Rows are **never** updated or deleted (except by the migration's `down()` method).

- `previous_state` and `new_state` are JSON snapshots captured by `ReviewCardLifecycleSnapshotService`, containing `lifecycle_state`, `buried_until`, `lifecycle_version`, `lifecycle_changed_at`, `fsrs_enabled`.
- `request_id` is a client-supplied UUID. Same `request_id` retry returns the original event.
- `source` is a stable string identifying the UI entry point (`sense_review_more`, `review_card_manage`, `review_card_manage_bulk`, `legacy_enabled_endpoint`).
- `metadata` is nullable JSON for future extension (e.g., user-supplied reason).

The management page's "state history" view (Task A) reads from this table. Internal audit metadata (e.g., `lifecycle_version`) is **not** exposed in the user-facing state explanation UI.

### 16. Timezone handling

- The user's timezone is sourced from `Auth::user()->timezone` (or the application default if unset).
- The server timezone is **never** used for `buried_until` computation.
- `BuryTimeService::nextLocalDayBoundary()` takes `($timezone, $now)` and returns a UTC `Carbon` representing the user's next local midnight.
- Invalid timezones throw `InvalidArgumentException`; the controller returns 422.
- The queue scope uses the request's `$now` (passed in by the service, which uses `Carbon::now()`), so all comparisons are consistent within a request.

### 17. Optimistic lock and idempotency

- `lifecycle_version` is an unsigned integer starting at 0, incremented on every transition.
- The frontend reads the current version from `GET /lifecycle` and passes it as `expected_version` in the POST.
- Stale version → 409 `version_conflict`. The frontend refreshes lifecycle and queue, then lets the user retry.
- `request_id` is a client-generated UUID. Same `request_id` retried within a window returns 200 `already_applied=true` with the original `event_id`.
- Different `request_id` on a card that has since moved on returns 409 `version_conflict` (the `expected_version` check fails first).

### 18. Concurrency model

- All lifecycle mutations acquire `ReviewCard::lockForUpdate()`.
- All rating mutations acquire `ReviewCard::lockForUpdate()` (already the case per ADR-0009).
- All undo mutations acquire `ReviewCard::lockForUpdate()` and `ReviewLog::lockForUpdate()` (already the case per ADR-0009).
- Therefore: lifecycle ‖ rating ‖ undo are serialized per-card at the row level. No lost updates.
- Two browser tabs attempting to suspend the same card simultaneously: the first wins, the second sees `expected_version` mismatch → 409.

## Consequences

### Positive

- Four distinct product concepts (Active / Buried / Suspended / Archived) are now first-class and queryable.
- `fsrs_enabled` conflation is resolved — it becomes a mirror, not a source of truth.
- Reset no longer silently un-archives cards.
- Undo no longer overwrites concurrent lifecycle changes.
- Frontend can render clear state badges, action menus, and help text.
- Stats can report buried/suspended/archived counts separately.
- Audit trail for lifecycle changes is complete and immutable.
- Idempotency and optimistic locking make multi-tab usage safe.

### Negative

- `review_cards` grows by 4 columns (storage cost negligible).
- New `review_card_state_events` table grows with usage (rows are small JSON; retention policy is "never delete" for audit).
- `fsrs_enabled` mirror must be maintained by the command service — any direct mutation of `fsrs_enabled` elsewhere is a bug. A test asserts the mirror invariant.
- Legacy `enabled` endpoint must synthesize `request_id` and `expected_version`, adding a small amount of complexity.
- The undo path gains a `lifecycle_changed_during_undo` 409 case, which is a new failure mode users may encounter.

### Migration Rollback

The migration's `down()` method:
1. Drops the `review_card_state_events` table.
2. Drops the 4 new columns from `review_cards`.
3. Does **not** restore `fsrs_enabled` to any particular value (the mirror was already maintained during the migration's lifetime, so `fsrs_enabled` remains correct for the active/archived split that the legacy code expects).

After rollback:
- All `/lifecycle*` endpoints return 500 (columns missing) — they should be removed by reverting the backend commit.
- Legacy `enabled`/`bulk-enabled`/`reset`/`destroy` endpoints continue to work with their original (pre-ADR-0010) semantics.
- Rating, undo, daily report, and stats continue to work with the original `fsrs_enabled`-only logic.
- No data loss — the dropped columns only contain lifecycle metadata; `fsrs_enabled` continues to reflect the last-known active/archived split.

## Rollback Plan

1. Revert the Task A commit (removes lifecycle UI from SenseReview and ReviewCardManage).
2. Revert the Task B commit (removes lifecycle endpoints, services, policy, scope; restores original `fsrs_enabled`-only logic in `SenseReviewService`, `ReviewCardService::resetCard`, `ReviewCardFsrsSnapshotService::restore`, `SenseReviewUndoPolicy`, `ReviewStatsService`, `FsrsReschedulePreviewService`).
3. Run `php artisan migrate:rollback` (drops the 4 columns and the `review_card_state_events` table).
4. Rating, undo, daily report, stats, and management continue to work with the original `fsrs_enabled`-only logic.
5. No data loss — `fsrs_enabled` already reflects the active/archived split because the command service maintained the mirror invariant.
