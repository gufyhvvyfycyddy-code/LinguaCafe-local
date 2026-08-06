# ADR-0039: M11 Review Control and Manual Operation Ledger

## Status

Accepted / Implemented / Closed

## Context

M11 must make bury-to-next-learning-day, suspend/resume, due-date changes and
reset-to-new auditable, idempotent and undoable. The repository already has:

- `ReviewCardLifecycleCommandService` as the lifecycle mutation owner;
- `ReviewCardService::resetCard()` as the reset owner;
- Browser due-now as a scheduling-only mutation;
- the M2 `operations` / `operation_changes` linear ledger for Mobile ratings;
- FSRS and lifecycle snapshot services with deliberately separate ownership.

Creating a second history table or allowing clients to write scheduling fields
would split authority. Treating manual changes as ratings would corrupt review
analytics.

Official Anki behavior informs the product vocabulary: bury hides a card until
the next day, suspend hides it until explicit resume, set-due changes queue
placement without recording answer time, reset preserves review history and may
optionally reset repetition/lapse counters, and Card Info identifies rescheduling
as Manual. LinguaCafe keeps those user outcomes but uses its existing
WordSense/FSRS/lifecycle and central-ledger model.

References:

- <https://docs.ankiweb.net/studying.html>
- <https://docs.ankiweb.net/browsing.html>
- <https://docs.ankiweb.net/stats.html>

## Decision

1. M2 `operations` and append-only `operation_changes` remain the only operation
   ledger. M11 adds source/action metadata but no second audit store.
2. Manual operations use one user/language `review_control` stack across web and
   mobile. `operation_id` is the request UUID. The ledger stores a normalized
   request fingerprint; replays return the original operation and a different
   payload for the same UUID fails with conflict.
3. The supported V1 actions are:
   - `bury_next_day`;
   - `suspend`;
   - `resume`;
   - `set_due` with an explicit local calendar date;
   - `due_now`;
   - `reset_new` with explicit `reset_counts`.
4. Every apply request first consumes a server preview. Clients send action
   parameters and the preview's `expected_state_fingerprint`, not `fsrs_due_at`,
   stability, difficulty or lifecycle fields. Apply recomputes under a row lock
   and compares the composite card fingerprint; a stale preview or state conflict
   fails closed. Operation version is used only after creation for undo/redo.
5. Bury/suspend/resume delegate their forward mutation to
   `ReviewCardLifecycleCommandService`. Reset delegates to
   `ReviewCardService`. Due changes have one new manual scheduling owner.
6. A composite operation snapshot combines the existing exact FSRS snapshot
   with lifecycle state, bury time and lifecycle version. It is used only for
   ledger conflict checks and undo/redo. Restoring lifecycle fields during
   ledger undo/redo is the sole reviewed exception to the forward-only lifecycle
   command entrance; it must append a lifecycle state event and preserve the
   `fsrs_enabled` mirror.
7. Rating operations retain their M2 ReviewLog snapshot behavior. Manual
   operations use their `operation_changes` snapshots. Reset continues to append
   one `rating=reset` ReviewLog and never deletes prior history; undo marks only
   that reset log undone. Other manual operations create no ReviewLog.
8. Web and Mobile adapters call the same preview/apply service. Mobile apply is
   wrapped by the existing device-bound idempotency service. Legacy endpoints
   remain compatible; M11 UI moves single-card controls to the unified adapter.
   Existing bulk lifecycle remains outside M11 V1.
9. Card Info exposes the most recent manual operations with operation ID, action,
   status/version, source channel/device, before/after states and undo/redo
   eligibility. It does not reinterpret a manual operation as a rating.
10. Undo/redo stays linear LIFO and optimistic-versioned. A newer manual action
    supersedes the redo branch. Cross-user/language targets are 404; stale state,
    illegal lifecycle restoration and non-latest transitions are stable 409s.

## Compatibility and exclusions

- Existing formal rating entrances and `FsrsSchedulingService` are unchanged.
- Existing ReviewLog rows and analytics meanings are preserved.
- No sibling bury, note-wide action, arbitrary scheduling fields, interval
  rewrite, Deck/Note Type semantics or bulk M11 scheduling.
- No development/production migration execution or data backfill.
- M1 deferred web-rating evidence is not a dependency: M11 uses the already
  accepted M2 ledger and existing independently verified management/lifecycle
  seams.

## Verification

- focused preview/apply/replay/conflict/isolation tests for every action;
- LIFO undo/redo, redo supersede, state conflict and transaction rollback;
- reset-counts true/false and preserved ReviewLog history;
- zero ReviewLog for non-reset operations and zero scheduler calls;
- legacy lifecycle/due-now/reset response regressions;
- Card Info and Mobile envelope/capability tests;
- protected Review FSRS, scheduling, WordSense and operation-ledger tests;
- frontend build and real-browser acceptance for preview, confirmation, history
  and undo/redo;
- migration rollback/restore on an empty testing schema, route inspection,
  syntax and diff checks.
