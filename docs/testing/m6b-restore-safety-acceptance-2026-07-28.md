# M6B Restore Safety Acceptance — 2026-07-28

Status: **Accepted / Closed**
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
Architecture: `docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md`
Implementation plan: `docs/plans/m6-resilience-health-isolation-implementation-plan.md`

## 1. Accepted scope

M6B closes only the previewed, recoverable MySQL restore slice:

- bounded streaming inspection of the contained archive and an exact supported
  dump grammar;
- administrator-, backup- and checksum-bound single-use preview tokens;
- opaque durable operation/status capabilities outside the restored database;
- a dedicated Redis restore queue whose retry window and operation lock exceed
  the job timeout;
- operation-private target and safety pins with checksum verification
  immediately before import;
- isolated validation through a dedicated validation identity and temporary
  database;
- an external write fence enforced at HTTP/console entry and immediately before
  every Laravel non-read-only database query, including connections established
  before fence activation;
- maintenance ownership, stable zero-writer proof including InnoDB transactions,
  full active-schema reset, restore, exact inventory verification and one
  automatic safety rollback;
- distinct `failed_manual_recovery` state that retains maintenance, fence and
  operation pins when safe automatic recovery cannot be proved;
- visible administrator preview, warning and exact `RESTORE` confirmation gate.

No acceptance step executed a restore, rollback, schema reset or migration
against development, production or user data.

## 2. Focused verification

The final M6B run passed:

| Surface | Result |
|---|---|
| Backup publication and inventory | 8 passed |
| Streaming SQL grammar and bypass cases | 23 passed |
| Dump and restore process boundaries | 9 passed |
| Restore orchestration, pins, queue timing and rollback | 10 passed |
| Backup and restore HTTP contracts | 7 passed |
| Dedicated job contract | 2 passed |
| HTTP and per-query write fence | 3 passed |
| Focused total | **62 passed / 205 assertions** |

The tests cover same-line and comment-prefixed statement smuggling, executable
`SET PERSIST`, extra assignments, cross-database foreign keys, file/tablespace/
FEDERATED table options, `CREATE ... SELECT`, non-literal insert tails, expanded
and per-statement limits, isolated cleanup failure, full schema-object reset,
stable quiescence, stale/live leases, timeout ordering, capability secrecy,
target/safety pin tampering, rollback and manual-recovery behavior. The write
fence test resolves a database connection before activation and proves that a
later update is rejected immediately before execution.

## 3. Protected regressions and build

The final closeout checks passed:

- `ReviewFsrsTest`: **63 passed / 375 assertions**;
- `FsrsSchedulingServiceTest`: **9 passed / 46 assertions**;
- WordSense filter: **203 passed / 1 skipped / 873 assertions**;
- `npm run development`: compiled successfully with Laravel Mix 6.0.49.

The build emitted existing Bootstrap/Sass deprecation warnings only.

## 4. Real-browser evidence

Channel: official OpenAI Browser plugin, one automation-owned page against the
task-owned testing listener at `127.0.0.1:8091`.

The final acceptance batch:

1. rendered registration and login, created the first testing-only
   administrator and opened `/admin` through visible UI actions;
2. clicked `创建备份` and observed the published 190-byte archive in the rendered
   inventory;
3. clicked `预览恢复` after the final SQL-inspector changes;
4. observed the complete SHA-256, 468-byte expanded size, 10-table inventory,
   expiry and data-loss/safety-snapshot warning;
5. proved lowercase `restore` kept `排队执行恢复` disabled and exact `RESTORE`
   enabled it;
6. cancelled the dialog and never clicked the execute button.

The batch closed its only page. Closeout then stopped the exact testing
listener, deleted the exact testing user and its registration fixtures, removed
the exact task-created manifest/payload pair, fake dump executable and browser
logs, verified port 8091 closed, verified the backup directory empty and
verified the official browser had no remaining tab. No pre-existing browser
page or backup was removed.

## 5. Three-round adversarial convergence

The required fresh-context review ran for the maximum three rounds. Earlier
rounds found and drove closure of SQL lexer smuggling, schema replacement,
quiescence/fence, queue timing and archive TOCTOU gaps. The third round found
two remaining blockers:

- executable-comment and `CREATE TABLE` rules still admitted dangerous suffixes;
- entry-only fencing did not cover work that entered before activation.

The accepted implementation replaced variable-name detection with anchored
complete mysqldump `SET` forms, rejected dangerous DDL/data tails, and installed
a per-query guard on existing and future Laravel connections. The final focused
and protected runs above passed after those changes. No fourth wording-only
review was started.

Verdict: **Approve M6B.** M6 remains in progress until M6C and M6D close.
