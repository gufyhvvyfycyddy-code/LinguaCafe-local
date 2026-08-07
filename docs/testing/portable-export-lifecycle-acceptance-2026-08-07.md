# Portable Export Lifecycle Acceptance — 2026-08-07

Status: **Accepted on the R11W delivery branch**

## Accepted scope

This slice hardens the existing M16/M18 portable export paths without changing their package formats or adding database state.

Accepted behavior:

- `HEAD` requests to all four portable export endpoints return `405` at the start of the controller action, before review-card queries, serialization, package creation or temporary workspace creation;
- normal `GET` behavior remains unchanged for APKG, JSON, CSV and full LCPKG exports;
- APKG and LCPKG builds create a task-owned top-level workspace under `storage/app/temp`;
- each workspace has an atomically published marker containing only schema version, export kind, UTC creation time and random UUID;
- normal response streaming and build failures clean the exact owned workspace;
- cleanup validates the top-level parent, UUID-v4 directory name, marker schema/kind/time/ID agreement and refuses unowned or malformed directories;
- nested and top-level symbolic links are never traversed;
- cleanup is idempotent for an already removed owned path;
- `portable:cleanup-export-workspaces` is dry-run by default and requires `--apply` before deletion;
- the cleanup command uses a minimum age threshold, reports stable counts, does not access the database and is not registered in the scheduler.

## Implementation boundaries

The slice adds one filesystem owner:

- `App\Services\PortableExportWorkspaceService`

The APKG and full portable package services delegate workspace creation and deletion to that owner. The controller retains export orchestration and performs the early `HEAD` rejection. No route file, migration, package schema, import behavior or frontend workflow was changed.

## Failure semantics

- If package construction fails, workspace cleanup is attempted and the original construction exception remains authoritative.
- If cleanup itself fails during construction failure handling, the cleanup problem is reported server-side and does not replace the original error.
- A streamed response always calls the package cleanup method in `finally`.
- The maintenance command skips malformed markers, unknown schema/kind, non-UTC timestamps, wrong directory/marker IDs, symlinks and unrelated directories.
- Apply-mode deletion failures are counted as errors instead of being hidden.

## Automated evidence

RED evidence before implementation:

- workspace/command tests: 5 failures because no ownership service or cleanup command existed;
- `HEAD` test: old behavior entered `ReviewCardManageQueryService::buildFromFilterState()` and returned `500` under a no-call mock instead of `405`.

Final focused regression:

```text
35 tests passed
228 assertions
0 failures
```

Covered suites:

- `PortableExportWorkspaceServiceTest`
- `PortableExportCleanupCommandTest`
- `PortableExportLifecycleTest`
- `M16PortableDataTest`
- `M18MediaIntegrityTest`

The R11W worktree used a task-local, gitignored PHPUnit bootstrap because its shared Composer `vendor` directory otherwise mapped `App\` classes to the main checkout. That bootstrap was testing infrastructure only and is not part of the delivery.

Additional checks:

- relevant PHP syntax checks passed;
- M16 frontend UI guard passed;
- `git diff --check` passed;
- command registration was exercised through the Laravel command test;
- no real browser was required because this slice changes HTTP method purity and backend filesystem lifecycle only;
- an independent read-only Reasonix review returned `NO_P0_P1_BLOCKER`; its one P2 observation about cleanup masking a stream-read failure was fixed and regression-tested before delivery.

## Data and cleanup safety

- no migration was added;
- no database cleanup command was used;
- no scheduler entry was added;
- no production or testing user data was created for filesystem unit tests;
- Feature tests ran only after testing-database health and process ownership checks;
- test-owned temporary roots and symlink targets were removed by their test teardown;
- unrelated `storage/app/temp` entries were not deleted.

## Remaining boundaries

This slice does not make process death during an active download fully transactional. A hard process exit can still leave a valid owned workspace; the dry-run/apply command now provides a safe, marker-based orphan cleanup path. Automatic scheduling remains intentionally out of scope.
