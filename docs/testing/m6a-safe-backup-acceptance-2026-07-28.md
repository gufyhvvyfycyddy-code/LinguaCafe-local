# M6A Safe Backup Acceptance — 2026-07-28

Status: **Accepted / Closed**  
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`  
Architecture: `docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md`  
Implementation plan: `docs/plans/m6-resilience-health-isolation-implementation-plan.md`

## 1. Accepted scope

M6A closes only the safe backup inventory and automatic-creation slice:

- one `BackupService` owner with a bounded process runner;
- argument-array MySQL dump with the password confined to the child environment;
- task-unique temporary dump, gzip archive, immutable manifest, SHA-256 and
  payload-first/manifest-last publication;
- failure cleanup that preserves every earlier successful backup;
- post-publication retention under a creation lock;
- admin-only GET inventory and POST creation, with the legacy GET mutation
  removed;
- fail-closed configured scheduling;
- visible admin loading, error, empty, success and inventory states.

Restore preview/execution, safety snapshots and rollback belong to M6B. M6A
inventory reports the checksum recorded at publication; M6B must recompute and
verify archive integrity before any restore is eligible.

## 2. Focused verification

The final focused run passed:

| Surface | Result |
|---|---|
| PHP syntax for service, runner, schedule, exception, command, controller, config and routes | Pass |
| `DatabaseDumpProcessTest` | 3 passed |
| `BackupServiceTest` | 8 passed |
| `BackupScheduleTest` | 3 passed |
| `BackupManagementTest` | 4 passed |
| Focused total | **18 passed / 67 assertions** |
| `php artisan route:list --path=backups` | GET `/backups`; POST `/backups`; no GET mutation |

The focused tests prove argument boundaries, credential exclusion, unsupported
driver rejection, sanitized process/runner/lock-backend failures, empty and
failed dump cleanup, manifest/payload publication, path and UUID validation,
post-success retention, invalid-retention fail-closed behavior, creation lock,
admin authorization, stable HTTP errors and removal of `/backups/create`.

## 3. Protected regressions and build

The closeout regression run passed:

- Tokenizer doctor and text-block fallback;
- Mobile API Foundation and Mobile Operation Ledger;
- Review FSRS and `FsrsSchedulingService`;
- testing-database runtime/config health;
- combined protected group: **127 passed / 883 assertions**;
- WordSense filter: **203 passed / 1 skipped / 873 assertions**;
- `npm run development`: compiled successfully with Laravel Mix 6.0.49.

The build emitted existing Bootstrap/Sass deprecation warnings only.

## 4. Real-browser evidence

Channel: official OpenAI Browser plugin, one automation-owned page.

The acceptance batch:

1. rendered `/admin` with a real authenticated administrator session;
2. loaded the backup inventory through GET `/backups`;
3. clicked the visible `创建备份` button through a DOM/user event;
4. observed POST `/backups` return success and the UI alert
   `备份创建成功：linguacafe_20260728_064609_1a612fa9-dc17-4937-91a3-bf5ab4ecf4e3.sql.gz`;
5. observed that archive at the top of the rendered list with `92 B` and
   checksum prefix `d40ba6d29379`;
6. observed no Console error/warn entries after the successful action.

The listener was PID 57076 with command line
`php -S 127.0.0.1:8091 tests/Fixtures/m6a-browser-server.php`. That testing-only
router sets `APP_ENV=testing`, the fake dump binary, disabled scheduling, array
cache and file sessions before Laravel boot. The successful POST depended on
that testing-only binary, tying the rendered action to the dedicated acceptance
server rather than the ordinary development server.

The browser batch finalized its page. Closeout then verified:

- port 8091 has no listener;
- all three task-created manifest/payload pairs were removed;
- the compiled fake dump executable and task logs were removed;
- `storage/backup` is empty again;
- the diagnostic local/testing users created during server diagnosis were
  deleted, with no retained credentials.

No pre-existing browser tab, user backup or unrelated workspace artifact was
removed.

## 5. Five-axis quality review

- **Correctness:** normal, failure, malformed input, retention, locking,
  authorization and method contracts are executable.
- **Readability:** orchestration remains in `BackupService`; process and schedule
  policies are small named collaborators.
- **Architecture:** Controller and command are adapters; no restore behavior is
  leaked into M6A.
- **Security:** no shell concatenation, runtime service `env()` access, response
  secret, GET mutation, traversal alias or ordinary-user access remains.
- **Performance:** streaming dump/compression avoids whole-database buffering;
  inventory and retention are bounded by the validated maximum of 1–1000.

Verdict: **Approve M6A.** No acceptance-blocking finding remains. M6 itself
remains in progress until M6B–M6D close.
