# M11 Review Control and Manual Scheduling Plan

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0039
> Acceptance: `docs/testing/m11-review-control-manual-scheduling-acceptance-2026-07-29.md`

## Goal and non-goals

Close M11 with one previewed, audited and undoable manual-operation path for
bury-next-day, suspend/resume, set-due/due-now and reset-new. Preserve formal
rating, FSRS scheduling, ReviewLog and lifecycle ownership.

Do not add sibling/note bury, arbitrary FSRS writes, interval rewriting,
Deck/Note Type behavior, bulk scheduling, deletion undo or a second ledger.

## Responsibilities and seams

- `ReviewCardManualOperationService`: scoped preview/apply orchestration and
  action validation; delegates existing domain mutations.
- `ReviewCardOperationSnapshotService`: composite capture/match/restore used
  only by ledger conflict/undo/redo.
- `MobileOperationLedgerService`: shared linear operation registration and
  transition semantics; keeps rating compatibility.
- web/mobile controllers: authentication, validation and stable envelopes only.
- Browser/Reviewer surfaces: render preview/confirmation and never compute
  scheduling timestamps.
- Card Info: read-only operation projection.

Data flow:

`UI/Mobile action → preview → apply adapter → scoped manual operation service
→ existing lifecycle/reset or manual due owner → operation/change ledger`.

Undo/redo:

`web/mobile transition adapter → ledger LIFO/version/state checks → composite
snapshot restore → append-only change (+ lifecycle event when applicable)`.

## Ordered slices

### M11A — Composite snapshot and shared manual ledger

- additive operation source/action/request-fingerprint metadata migration;
- action constants, source metadata and composite snapshots;
- manual registration plus generalized rating/manual undo/redo;
- idempotency, isolation, LIFO and rollback tests.

### M11B — Preview/apply domain and web adapters

- server preview for all V1 actions;
- forward mutation delegation and reset-count option;
- unified web preview/apply/undo/redo routes;
- legacy endpoint compatibility tests.

### M11C — Browser, Reviewer and Card Info

- shared preview/confirmation controls;
- single-card Browser and Reviewer actions use the unified endpoint;
- Card Info manual-operation history and undo/redo controls;
- stable loading/error/empty/conflict states.

### M11D — Mobile adapter and closeout

- device-bound preview/apply endpoints and capabilities;
- envelope, replay, isolation and cross-device audit evidence;
- protected regressions, build and official real-browser closeout.

## Exact allowed files

M11 may modify only:

- one new M11 migration under `database/migrations/`;
- `app/Models/Operation.php`, `OperationChange.php`, `ReviewLog.php` and
  `ReviewCardStateEvent.php` only if their relations/casts require it;
- new `app/Services/ReviewCardOperationSnapshotService.php`;
- new `app/Services/ReviewCardManualOperationService.php`;
- new `app/Exceptions/ReviewCardManualOperationException.php`;
- `app/Services/MobileOperationLedgerService.php`;
- `app/Services/ReviewCardLifecycleCommandService.php`;
- `app/Services/ReviewCardService.php`;
- `app/Services/ReviewCardManageMutationService.php`;
- `app/Services/ReviewCardInfoQueryService.php`;
- `app/Http/Controllers/ReviewCardLifecycleController.php`;
- `app/Http/Controllers/ReviewCardManageController.php`;
- new `app/Http/Controllers/ReviewCardManualOperationController.php`;
- `app/Http/Controllers/ReviewCardManageDetailController.php`;
- `app/Http/Controllers/Mobile/MobileOperationController.php` and one new
  focused Mobile manual-operation controller if required;
- `app/Http/Controllers/Mobile/MobileBootstrapController.php`;
- `routes/web.php`, `routes/api.php`;
- `resources/js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue`;
- `resources/js/components/ReviewCards/ReviewCardLifecycleMutationSurface.vue`;
- `resources/js/components/ReviewCards/ReviewCardInfoDrawer.vue`;
- `resources/js/components/ReviewCards/ReviewCardManage.vue`;
- `resources/js/components/ReviewCards/ReviewCardTableSurface.vue`;
- `resources/js/components/Senses/SenseReview.vue` and one focused shared
  manual-operation component if required;
- directly affected lifecycle/reset/detail/Mobile/operation tests;
- new focused M11 tests;
- ADR-0039, this plan, roadmap, current context/handoff, documentation index and
  M11 acceptance evidence.

Files outside this list remain forbidden. Existing unrelated worktree changes
remain user assets.

## Risk controls

- composite snapshot schema is explicit and versioned by operation type;
- forward lifecycle/reset owners remain delegated, not copied;
- unknown action/payload keys and client-supplied scheduling fields fail closed;
- dates are calendar dates resolved with the server-approved user timezone;
- every preview returns a composite state fingerprint; apply locks the card,
  validates confirmed sense/user/language and rejects a stale fingerprint;
- no manual action calls `FsrsSchedulingService`;
- non-reset actions write no ReviewLog; reset appends exactly one and preserves
  all older logs;
- failed ledger registration rolls back the domain mutation.

## Minimum validation

- focused M11 domain/API/UI guards and migration test;
- existing operation ledger, lifecycle, management and Card Info tests;
- `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, WordSense filters and Mobile
  foundation;
- `npm run development`;
- official real-browser preview/apply/history/undo/redo;
- route listing, PHP syntax and allowlist `git diff --check`.
