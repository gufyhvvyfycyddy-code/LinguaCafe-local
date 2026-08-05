# CFH-02B-M6B Responsive Restore Web Acceptance — 2026-08-05

Status: **Accepted (implementation) — awaiting web-side acceptance**
Program: `linguacafe-recovery-publication-2026-08`
Task: `CFH-02B-M6B — Rework And Publish Single-Owner Restore For Responsive Web`
Plan: `docs/plans/linguacafe-recovery-publication-master-plan-2026-08.md`
Contract: `docs/adr/ADR-0055-single-owner-restore-without-user-visible-preview.md`
Manifest: `docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json`

## 1. Baseline

- repository: `D:\Document\lingl\LinguaCafe-main`
- branch: `master`
- start HEAD: `ac847263f7e926798c098d2ccb942a8321e1121a`
- origin/master: `ac847263f7e926798c098d2ccb942a8321e1121a` (ahead 0 / behind 0)
- dirty paths at start: 491 (108 modified + 2 deleted + 381 untracked, all pre-existing assets)
- external snapshot: `D:\Document\lingl\cfh-02b-m6b-snapshot\` (HEAD, origin/master, NUL-safe
  porcelain status, status_sha256, ownership_map_sha256, m6_manifest_sha256, timestamp;
  no `.env*` content or hash read)

## 2. Plan patch

- `LinguaCafe_M6B_plan_correction.patch` was not present in the repository root
  (searched repository, parent directories, Downloads, Desktop). Per the frozen
  task text (section 4) and the supervisor's explicit authorization
  (2026-08-05), the patch content was rebuilt strictly from the frozen product
  decisions in the task text and applied to exactly two governance files:
  - `docs/plans/linguacafe-recovery-publication-master-plan-2026-08.md`
  - `docs/execution/CURRENT_MILESTONE.json`
- `git apply --reverse --check` confirmed the working tree equals
  HEAD + patch (clean application). The patch file itself is not committed.
- Verified after apply: M6A/M6A-R1/M6A-R2 ACCEPTED; active_task=CFH-02B-M6B;
  M6B candidate_not_authorized then authorized; product_code_authorized=true;
  equal-privilege; no user-visible preview; exact RESTORE input + final click;
  desktop and phone responsive web; internal safety checks preserved.

## 3. Old contract removal

- admin references removed: backup/restore routes moved out of the `admin`
  middleware group into `auth`+`auth.session` (routes/web.php);
  `AdminMiddleware`/`is_admin` no longer guard backup/restore; the
  `/admin/{page?}` page route is reachable by every authenticated user and
  non-admin users see only the backup tab (AdminSettingsLayout.vue);
  `BackupRestoreService` operation records now store `user_id` instead of
  `administrator_id`; old admin-only tests replaced
  (BackupManagementTest: non-admin user can access; BackupRestoreManagementTest).
- restore-preview endpoint removed: route, controller method
  (`previewRestore`), service method (`preview`), UI request/state, tests.
- preview token removed: no `preview_token`/`checksum` in the client contract;
  `status_token` capability removed (status is user-scoped by operation id);
  `previewMatches`/`tokenKey`/`invalidPreview` removed from the service.
- preview UI removed: confirmation dialog shows only backup name/time, risk
  notice, RESTORE input, cancel/confirm buttons; no tables/checksums/manifests/
  paths/SQL/warnings; backup list no longer shows the checksum.

## 4. New contract implemented

- `POST /backups/{backupId}/restore` requires only
  `{"confirmation": "RESTORE"}` (exact, case-sensitive, no whitespace).
  Server-side preflight before any operation record: containment, manifest
  format/version/driver/scope, payload size + SHA-256, required tables,
  dangerous SQL, decompression limit, disk headroom. Preflight failure
  creates no executable operation and touches no active database.
  Success returns `202 {restore_operation: {operation_id, backup_id, status,
  created_at}}`.
- `GET /backup-restores/{operationId}` requires login (no admin role), is
  user-scoped, stays readable during maintenance mode (except
  `backup-restores/*` registered via
  `$middleware->preventRequestsDuringMaintenance(['backup-restores/*'])`),
  and exposes no paths/passwords/SQL/commands/cookies/tokens/process output.
- Idempotency: repeated confirmation of the same backup by the same user
  returns the existing operation; another user gets a stable 409; double
  clicks and HTTP timeouts produce at most one operation; dispatch_failed
  operations are re-dispatched on confirm; locks cover the full execution
  period; status lives in the non-database coordination store.
- Internal safety preserved: SqlDumpInspector, DatabaseRestoreProcess,
  RestoreWriteFence, RejectWritesDuringRestore, ExecuteBackupRestore,
  maintenance ownership, safety snapshot, rollback,
  failed_manual_recovery, secret redaction.

## 5. Tests

- M6B suite (rewritten, not appended): BackupServiceTest, SqlDumpInspectorTest,
  DatabaseRestoreProcessTest, BackupRestoreServiceTest, BackupManagementTest,
  BackupRestoreManagementTest, ExecuteBackupRestoreTest, RestoreWriteFenceTest
  — **64 passed (217 assertions)** in the disposable staged-tree worktree
  (APP_ENV=testing, dedicated `linguacafe_fsrs_test` database).
- Coverage: unauthenticated rejection; authenticated non-admin access; no
  is_admin requirement; restore-preview route absent (404); no preview_token;
  confirmation missing/incorrect/case/whitespace/exact; server-side checksum
  revalidation; tampered payload rejected; incompatible manifest rejected;
  missing required table rejected; dangerous SQL rejected; zip-bomb limit;
  disk headroom; no half-created operation; double-click/retry idempotency;
  status polling; safety snapshot precedence; write fence; maintenance mode;
  automatic rollback; rollback failure keeps maintenance mode;
  failed_manual_recovery; credentials never in commands/logs/responses;
  no dev database touched.
- Regressions: ReviewFsrsTest, FsrsSchedulingServiceTest, ReviewCardManageTest,
  TextReaderSmokeTest — **334 passed, 2 skipped (1280 assertions)**.
- Frontend: `npm run development` compiled successfully (main worktree and
  staged-tree worktree).
- Document guards: `node --test tests/js/RecoveryPublicationWorkflowDocsGuard.test.mjs
  tests/js/M6PublicationSliceDocsGuard.test.mjs tests/js/WorkspaceInventory.test.mjs`
  — **16 passed, 0 failed**.

## 6. Exact staged tree verification

- M6B staged tree (23 files, `git diff --cached --check` clean) built with
  `git write-tree` + `git commit-tree`, verified in the disposable worktree
  `D:\Document\lingl\cfh-02b-m6b-staged-tree` (detached):
  - PHP M6B suite 64 passed (connected to `linguacafe_fsrs_test` under
    APP_ENV=testing),
  - `npm run development` compiled successfully,
  - guards pass in the main worktree (16/16); the worktree itself cannot pass
    the guards because it intentionally lacks M6C/M6D unstaged assets and the
    governance updates that ship in the docs commit.
- M6C/M6D/M10–M18 residuals remain unstaged in the main worktree.

## 7. MCP Chrome acceptance (mandatory real-browser channel)

- MCP server: `chrome-devtools` (MCP Chrome). Tools: navigate_page, emulate,
  take_snapshot, evaluate_script (DOM click/input events for Vuetify
  compatibility), fill_form, wait_for, list_network_requests,
  get_network_request, list_console_messages, take_screenshot.
- Invocation/session identifiers: chrome-devtools MCP pages at
  `http://127.0.0.1:8092` (testing server bound to the disposable staged-tree
  worktree, APP_ENV=testing, dedicated `linguacafe_fsrs_test` database,
  fake mysqldump / fake mysql binaries, mock redis on 127.0.0.1:6379,
  queue worker on redis-restore). Testing identity created through the normal
  registration page in the testing database and demoted to `is_admin=0`
  (minimum privilege; password entered only through the UI).
- Desktop (default viewport and 1440×900): login → open /admin/dashboard as
  non-admin → backup list readable → select backup → confirmation dialog
  (backup name/time, risk notice, RESTORE input, cancel/confirm) → invalid/
  lowercase/trailing-space inputs keep the confirm button disabled → `RESTORE`
  enables it → click confirm → exactly one operation (idempotent re-submit
  returns the same operation) → status polling queued → running → succeeded
  (all 200 during the maintenance window after the except fix) → safety
  snapshot appears in the backup list. Dialog centered (720px in 1440 viewport),
  all elements visible.
- Phone (390×844, mobile+touch emulation): no horizontal overflow
  (scrollWidth-clientWidth = 0); dialog fully inside the viewport; input
  focusable; confirm/cancel buttons 44px touch height; loading/error/success
  feedback visible; complete restore flow succeeded; page-refresh state
  recovery verified (localStorage-persisted operation id resumes polling after
  reload and shows the final status).
- Console: no M6B-related JS errors (only local Pusher websocket fallback and
  historical 500/503 noise from earlier debug attempts); no credentials leaked.
- Network: `restore-preview` request count **0**; `preview_token` occurrences
  **0**; restore request bodies contain only `{"confirmation":"RESTORE"}`
  (no checksum/tables/warnings); no credentials in requests/responses.
- Screenshots (SHA-256):
  - desktop restore success (default viewport): `9c8ddabbb76c4cb20bbf6feef92e5a8db00f02c62d1b2fa6d9e798ba8944e505`
  - desktop 1440×900 modal: `66768d5642519c9dc317caa7a38f8075e33e242b63728792b9673d58b03ac1be`
  - phone restore success (390×844): `6d8d381780cddbf2381f5992348f5cd6bb2394db2358d9d804483e69dd4c9d94`

## 8. Defects found and fixed during acceptance

1. Maintenance-mode exemption for `backup-restores/*` did not take effect
   (Laravel 11 reads `PreventRequestsDuringMaintenance::$except` middleware
   configuration, not the payload `except`). Fixed in `bootstrap/app.php` by
   `$middleware->preventRequestsDuringMaintenance(['backup-restores/*'])`;
   re-verified: status polling returns 200 during the maintenance window.
2. A page refresh could clear the persisted operation id because the polling
   error handler cleared storage on any failure (including the network error
   caused by navigation). Fixed in `AdminDashboard.vue`: only a 404 clears
   the stored operation id; network/maintenance-window errors keep it so a
   later refresh resumes polling. Re-verified with the persisted-state
   refresh scenario.

## 9. Governance synchronization

- `docs/adr/ADR-0055-single-owner-restore-without-user-visible-preview.md` (new)
- `docs/adr/ADR-0036-...md` supersession note added (history preserved)
- `docs/plans/m6-resilience-health-isolation-implementation-plan.md` M6B
  architecture + public HTTP contract updated
- `docs/plans/cfh-02-m6-publication-plan.md` status/scope/hunks/acceptance updated
- `docs/DOCUMENTATION_INDEX.md` updated
- `docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json` recomputed:
  working-tree SHA-256 for every affected file, patch boundaries and
  hunk SHA-256, M6A/M6B commit sequence, M6B tests/browser steps/stop
  conditions, candidate_shared_file_count (23)
- `tests/js/RecoveryPublicationWorkflowDocsGuard.test.mjs` and
  `tests/js/M6PublicationSliceDocsGuard.test.mjs` extended with the
  CFH-02B-M6B authorized/awaiting state machine and the frozen contract
  assertions; M6B equal-privilege page files (AdminSettingsLayout.vue,
  Layout.vue exact hunk) registered as documented exceptions
- `docs/execution/CURRENT_MILESTONE.json` updated for authorization
  (product_code_authorized=true) and final lock
  (awaiting_web_acceptance, product_code_authorized=false)

## 10. Security notes

- Testing database `linguacafe_fsrs_test` only; no development/staging/
  production database was touched; no real restore was executed (fake mysql
  binary in a repository-external acceptance folder); no migrations were run
  outside the PHPUnit testing database; credentials only entered through the
  browser UI; acceptance processes (server, worker, mock redis) were shut down
  after acceptance.
- Path containment, dangerous-SQL rejection, immutable pin, write fence,
  maintenance ownership, rollback, and secret redaction remain implemented
  and are covered by the rewritten tests.
