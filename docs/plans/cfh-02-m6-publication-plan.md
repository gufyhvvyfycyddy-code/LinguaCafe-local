# CFH-02 M6 Publication Plan — Closed Record

> Status: `ACCEPTED / PUBLISHED`<br>
> Closed: 2026-08-06<br>
> Manifest: `docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json`<br>
> Current M6B contract: `docs/adr/ADR-0055-single-owner-restore-without-user-visible-preview.md`

## 1. Purpose

CFH-02 separated a large dirty worktree into reviewable M6 publication slices,
protected existing user assets, verified each slice, staged exact paths or
hunks, and published without force push or history rewriting.

The publication sequence is complete. This file is audit history, not an active
authorization document.

## 2. Published order

1. M6A — safe backup inventory and creation.
2. M6B — server-preflighted restore with equal authenticated privilege, no
   user-visible preview, exact `RESTORE` confirmation, idempotent operation,
   write fence, safety snapshot, isolated validation, rollback, and responsive
   web UI.
3. M6C — read-only article health reporting.
4. M6D — user/language/file/process isolation closeout.

M6A–M6D are `Accepted / Closed`. They are not candidate tasks and must not be
republished as a second copy of the same milestone.

## 3. M6B supersession

The original M6B design used administrator-only access and a client preview
token. ADR-0055 superseded that public contract. The effective behavior is:

- backup and restore routes require authentication but no admin role;
- the restore-preview route and preview-token client state are absent;
- the client submits only exact `RESTORE` confirmation;
- all technical preflight remains server-side;
- status polling is user-scoped and remains available during maintenance;
- desktop and phone web flows have real MCP Chrome evidence.

Historical references to admin-only preview behavior remain only where they
explain what was superseded.

## 4. Published boundaries

The exact whole-file and patch boundaries are preserved in the machine-readable
manifest. The key ownership split remains:

- M6A owns backup publication, list/create UI, dump process, schedule, manifest,
  checksum, atomic publish, and retention.
- M6B owns restore preflight/orchestration/process, write fence, restore job,
  maintenance/status behavior, confirmation UI, and restore tests.
- M6C owns article-health service, page, routing, fixtures, and tests.
- M6D owns safe-path and cross-user/cross-language isolation fixes and tests.
- FSRS, ReviewCard scheduling, WordSense learning semantics, and unrelated
  mobile/media features were protected regressions, not M6 publication owners.

## 5. Verification summary

- M6A focused tests, frontend build, testing-bound MCP Chrome backup creation,
  reload persistence, fake mysqldump, and machine-readable trace evidence pass.
- M6B focused suite: 66 passed / 227 assertions.
- M6B protected regressions: 334 passed / 2 skipped / 1280 assertions.
- M6B frontend build and document guards pass.
- M6B desktop 1440×900 and phone 390×844 MCP Chrome flows pass with no
  restore-preview requests, no preview tokens, no credential leaks, and fake
  restore processes against the dedicated testing database.
- M6C and M6D acceptance reports retain their focused, protected-regression,
  browser, and isolation evidence.

Detailed evidence remains in `docs/testing/` and the M6 machine-readable JSON
files; this plan does not duplicate invocation ledgers or raw logs.

## 6. Safety boundaries retained

- No development, staging, production, or user-data restore is authorized.
- No migration, backfill, drop/truncate, clean/reset/stash, `.env` access,
  notification script, DCP, or force push is authorized by this record.
- Local account details, passwords, cookies, raw session logs, screenshots, and
  patch transport files remain outside Git.
- Future repairs must open a new bounded task and use the current ADR/plan/test
  authority; they must not reactivate this publication plan.

## 7. Final state

`CFH-02_M6_PUBLICATION_ACCEPTED`

Active task: none. Auto-advance: false. New work is selected from the current
Open Work Registry and requires its own scope, verification, and authorization.
