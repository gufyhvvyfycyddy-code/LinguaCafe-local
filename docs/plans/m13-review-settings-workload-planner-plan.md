# M13 Review Settings and Workload Planner V2 Plan

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0045

## Goal and non-goals

Add Anki-aligned learning/relearning steps, interval bounds, Easy Days,
review-experience preferences, health diagnostics and a read-only 30/90/365
workload planner. Keep old-card rescheduling behind the existing
preview/backup/risk/undo flow.

Do not add arbitrary custom scheduling, deck inheritance, sibling bury,
retroactive Easy Day rewrites, automatic rating or a second scheduler.

## Responsibilities and seams

- `ReviewSettingsPresetConfig` owns schema V1 compatibility and normalized V2
  scheduling/experience values.
- A focused advanced-settings service owns get/update validation and API
  serialization.
- `FsrsSchedulingService` remains the only scheduling policy and owns steps,
  interval bounds and future-only Easy Day selection.
- `ReviewCardService` persists the returned step index and captures it in the
  existing undo snapshot.
- The workload planner and health diagnostics are read-only projections over
  user/language-scoped confirmed Sense cards and non-undone ReviewLogs.
- Existing reschedule preview/confirm/snapshot/undo services remain the only
  old-card mutation seam.

Data flow:

`Admin UI → bounded advanced-settings request → active Preset V2`.

Formal rating:

`Web/Mobile controller → ReviewCardService → FsrsSchedulingService →
ReviewCard + ReviewLog snapshot → operation ledger`.

Planner:

`Admin UI → scoped cards/logs → read-only projection/diagnostics → response`.

## Ordered slices

### M13A — Preset V2 and API foundation

- V1-compatible schema normalization;
- bounded scheduling and experience settings service/endpoints;
- focused config/API tests.

### M13B — Same-day steps and interval policy

- additive `fsrs_step_index` migration;
- backward-compatible snapshot capture/match/restore;
- learning/relearning progression, min/max interval and Easy Days;
- formal rating and undo regressions.

### M13C — Planner and health diagnostics

- 30/90/365 workload projection with assumptions and daily limits;
- retrievability and rating-use diagnostics;
- prove zero scheduling/history writes.

### M13D — Web settings and guarded reschedule experience

- scheduling, Easy Days, timers/auto-advance and audio preferences;
- planner summaries and health warnings;
- existing optimization/reschedule preview/risk/undo controls remain visible;
- loading, empty, validation, success and error states.

### M13E — Compatibility and closeout

- protected FSRS/WordSense/operation/preset suites and frontend build;
- server-bound real-browser acceptance on the testing database;
- roadmap, current context/handoff, documentation index and acceptance evidence.

Closed on 2026-07-29. Automated, build and server-bound real-browser evidence
is recorded in
`docs/testing/m13-review-settings-workload-planner-acceptance-2026-07-29.md`.

## Exact allowed files

M13 may modify only:

- one additive M13 migration under `database/migrations/`;
- `app/Models/ReviewCard.php`;
- `app/Services/ReviewCardFsrsSnapshotService.php`,
  `ReviewCardService.php`, `FsrsSchedulingService.php`,
  `FsrsRetentionWorkloadSimulationService.php`;
- `app/Services/Settings/Presets/ReviewSettingsPresetConfig.php`;
- one new focused advanced-settings service and its validation exception;
- `app/Services/Settings/FsrsOptimizationSettingsService.php` only for health
  diagnostics;
- `app/Services/SettingsService.php`,
  `app/Http/Controllers/SettingsController.php` and `routes/web.php` only for
  bounded M13 endpoints;
- `resources/js/services/AdminReviewSettingsApi.js`,
  `resources/js/components/Admin/AdminReviewSettings.vue`,
  `FsrsAdvancedToolsPanel.vue` and at most two focused M13 sibling
  components/helpers;
- direct M13 config/migration/scheduler/snapshot/API/UI tests;
- ADR-0045, this plan, roadmap, current context/handoff, documentation index
  and M13 acceptance evidence.

Files outside this list remain forbidden. Existing unrelated worktree changes
remain user assets.

## Risk controls

- step arrays and intervals are server-normalized and bounded;
- clients never submit due time, state, stability, difficulty or step index;
- every formal rating keeps the existing transaction and unique scheduler;
- legacy snapshots remain restorable;
- Easy Days only affects newly calculated future due dates;
- planner and diagnostics never resolve a mutation service;
- reschedule cannot apply without preview hash, risk confirmation, backup and
  undo snapshot;
- all queries retain authenticated user, selected language, confirmed Sense
  and non-undone-log scope.

## Minimum validation

- focused M13 config, migration, snapshot, scheduler, API and UI guards;
- existing Preset, optimization, reschedule preview/confirm/snapshot/undo;
- operation-ledger and formal-rating regressions;
- `ReviewFsrsTest`, `FsrsSchedulingServiceTest` and WordSense tests;
- `npm run development`;
- official-plugin-first real-browser settings/planner/reschedule acceptance on
  a server-bound testing database;
- route listing, PHP/JS syntax, allowlist and `git diff --check`.
