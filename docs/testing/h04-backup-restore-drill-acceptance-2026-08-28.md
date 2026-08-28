# H-04 Backup / Restore Drill Acceptance — 2026-08-28

## Verdict

**Accepted / DONE.**

H-04 proved the existing M6 Backup/Restore owners against a disposable, production-shaped MySQL/Redis/Web runtime. It did not create a second backup system and did not touch development, production, or user data.

## Exact implementation evidence

H-04 implementation commit:

`e2cfc4427fb49dd2806ad4d105100164296e998d`

The production changes are limited to failures exposed by the first real restore drill:

- `docker/PhpDockerfile` now uses the Oracle MySQL Community 8.4 client instead of Debian `default-mysql-client`, which had resolved to MariaDB 11.8 while the application server runtime is MySQL 8.
- `SqlDumpInspector` accepts only the three additional charset session directives emitted by the real MySQL 8 `mysqldump`; arbitrary executable comments, extra assignments, `SET PERSIST`, filesystem operations, cross-database identifiers, and other unsupported grammar remain rejected.
- `backup.restore_required_tables` no longer requires the historical `languages` and `texts` tables, which are not created anywhere in the current migration chain. A DB-backed contract now proves every configured required table exists in the current schema.
- `DatabaseRestoreProcess::waitForQuiescence()` uses the existing dedicated restore/validation identity rather than the Web application identity. It reads Performance Schema instead of requiring global `PROCESS` on the Web account.
- The quiescence gate checks that the `transaction` instrument and `events_transactions_current` consumer are enabled. Missing monitoring fails closed with `BACKUP_CONFIGURATION_INVALID`; absence of telemetry is never interpreted as zero writers.

No production route, controller, queue type, backup format version, database table, scheduler, alternate restore owner, blanket retry, or fallback path was added.

## Representative testing runtime

H-04 uses `docker-compose.h04-testing.yml` with exactly:

- MySQL 8.0 on tmpfs, with no host MySQL port;
- Redis 7.2 for the existing restore coordination store and `redis-restore` queue;
- the current checkout Web image on `127.0.0.1:8894`.

The drill fails before backup creation unless both client binaries identify themselves as Oracle MySQL Community Server 8.x. The exact accepted runtime reported:

- `mysql  Ver 8.4.11 ... (MySQL Community Server - GPL)`;
- `mysqldump  Ver 8.4.11 ... (MySQL Community Server - GPL)`.

The H-04 container uses a testing-only MySQL root identity for isolated validation/quiescence because the database is disposable and the execution platform does not permit this task to install GRANT fixtures. This is not the production credential design.

For production deployment, the existing `BACKUP_RESTORE_VALIDATION_*` identity remains dedicated and separate from the Web application DB account. It requires:

- the existing rights needed to create/import/inspect/remove `linguacafe_restore_test_%` validation databases; and
- read access to `performance_schema.setup_consumers`, `performance_schema.setup_instruments`, `performance_schema.threads`, and `performance_schema.events_transactions_current`.

The Web application DB account does **not** need global `PROCESS`.

## Exact successful restore drill

The final successful drill recorded `git_head=e2cfc4427fb49dd2806ad4d105100164296e998d` directly in its JSON result.

The real sequence was:

1. start a fresh tmpfs MySQL + Redis + current Web image;
2. apply the normal migration chain without destructive reset commands;
3. create an administrator and a representative Sense/ReviewCard fixture;
4. create a real compressed backup using `BackupService` and Oracle MySQL `mysqldump`;
5. mutate user names, change `ReviewCard.fsrs_reps` from `0` to `7`, and add a post-backup user;
6. confirm restore through `BackupRestoreService`, enqueue the existing Redis restore job, and run the real queue worker;
7. observe the external write fence and prove a real Laravel DB write is rejected with `BACKUP_RESTORE_WRITE_FENCE_ACTIVE`;
8. run isolated restore validation, create the automatic safety backup, replace the active schema, and verify exact inventory;
9. verify restored data and cleanup.

Final result:

- operation status: `succeeded`;
- administrator name restored to `H04 before backup`;
- representative study user restored to the backup value;
- `ReviewCard.fsrs_reps`: `7 → 0`;
- post-backup user removed by the restore;
- target backup + automatic safety backup both present during verification;
- write fence released;
- maintenance mode released;
- validation temporary databases: `0`.

## Exact automatic safety rollback drill

The rollback drill used the same exact commit and runtime. After isolated validation and fence activation, the testing harness changed only the operation-private pinned target copy. This makes the existing `assertPinnedPayload()` fail after the safety snapshot exists, without corrupting the original backup or safety snapshot.

Final result:

- operation status: `rolled_back`;
- error code: `BACKUP_RESTORE_FAILED_ROLLED_BACK`;
- the automatic safety snapshot was restored successfully;
- administrator/study-user names remained at their pre-restore mutated values;
- `ReviewCard.fsrs_reps` remained `7`;
- the post-backup user remained present;
- target backup + safety backup count: `2`;
- write fence released;
- maintenance mode released;
- validation temporary databases: `0`.

This proves the recovery path preserves the state that existed immediately before the attempted restore when the target restore cannot safely proceed.

## Automated regression

On the exact implementation commit:

- Backup/Restore focused suite: **75 tests / 279 assertions PASS**;
- `git diff --check`: PASS;
- PHP syntax checks for both H-04 support scripts: PASS;
- H-04 runtime contract locks Oracle MySQL client usage, disposable service topology, success + rollback coverage, fail-closed behavior, and the absence of destructive reset/wipe commands.

Existing unit coverage also retains the `failed_manual_recovery` path for the case where both target restore and automatic safety rollback fail. H-04 does not intentionally destroy both copies in a live-style drill because that would add no new product behavior beyond the existing fail-closed state-machine test.

## Cleanup

Final H-04 cleanup proved:

- H-04 Compose containers: `0`;
- H-04 network: removed;
- port `8894` listener: `0`;
- H-04 temp/lock residue: `0`;
- TestingDatabaseLease: `active=false`, `stale_metadata=false`;
- no validation database residue;
- no development or production restore;
- no `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe`;
- no `.env` read or modification;
- no notification script;
- no DCP;
- no broad Docker prune.

No new browser-facing behavior was introduced in H-04; the existing M6B restore confirmation UI was already browser-accepted. H-04's required evidence is the real isolated database/queue/recovery drill, so a new browser run is not applicable to this milestone.

H-05 may now open for user/language isolation, account deletion, synchronized-device revocation, and privacy-boundary verification.
