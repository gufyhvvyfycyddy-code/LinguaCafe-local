# Testing DB Health Playbook

> **Status**: Active
> **Last updated**: 2026-08-24 (parallel validation lanes)
> **Governing rules**: `vibe-coding-collaboration-rules.md` §27; `repo-architecture-hotspot-audit.md`

## 1. Purpose

Prevent and diagnose the recurring `SQLSTATE[42S02]: Table 'linguacafe_fsrs_test.migrations' doesn't exist` errors that happen when PHPUnit feature tests share a MySQL testing database.

## 2. What We Did

### 2.1 Machine-global testing database lease

- **Files**: `tests/Support/TestingDatabaseLease.php`, `tests/bootstrap.php`
- **Decision**: ADR-0056.
- **Mechanism**: PHP `flock(LOCK_EX)` on one versioned, hashed lock below the operating-system temporary directory.
- **Identity**: normalized Git common repository + normalized remote identity + the non-secret logical testing database identifier + protocol version. Normal runs use the identifier in `phpunit.xml`; an isolated validation lane may override only that non-secret logical identifier through the lane-only `LINGUACAFE_TEST_DB_LEASE_DATABASE_ID_OVERRIDE` environment variable.
- **Effect**: all managed worktrees that share the same logical testing database compete for the same OS lock. Different validation-lane databases therefore have different locks and may run concurrently. A stale PID or metadata file never counts as active ownership.
- **Failure policy**: identity, directory, file, or lock failures are fail-closed. PHPUnit must not warn and continue without synchronization.
- **Coverage**: PHPUnit acquires the lease in `tests/bootstrap.php`; testing servers, testing Artisan commands, fixtures, and browser-acceptance servers must use the runner in §3.1.

### 2.2 Database Health Check Test

- **File**: `tests/Feature/TestingDatabaseHealthTest.php`
- **Purpose**: Confirms the testing DB is correctly configured before any feature test runs.
- **Checks**:
  1. `APP_ENV` is `testing`
  2. Database name contains `test`
  3. Database is NOT `linguacafe_fsrs` (production DB)
  4. `migrations` table exists
  5. Migrations are not empty
  6. tests/bootstrap.php contains no destructive commands

### 2.3 Config Static Check Test

- **File**: `tests/Unit/TestingDatabaseHealthConfigTest.php`
- **Purpose**: Statically verifies `phpunit.xml` and `tests/bootstrap.php` configuration without booting Laravel.

## 3. What You Can Do

### 3.1 Run every testing database writer through the lease

PHPUnit automatically acquires the lease through `tests/bootstrap.php`:

```bash
# Static config and lease checks (no database connection)
php artisan test --filter=TestingDatabaseHealthConfigTest

# Read-only database health gate
php artisan test --filter=TestingDatabaseHealthTest

# Feature test after the health gate
php artisan test --filter=ReviewFsrsTest
```

Long-running testing servers and non-PHPUnit testing commands must use the same runner:

```bash
# Fail fast if another testing database owner is active
APP_ENV=testing php tests/Support/run-with-testing-db-lease.php \
  --label=browser-acceptance -- \
  php artisan serve --host=127.0.0.1 --port=88xx

# Explicit finite wait when the task contract permits waiting
APP_ENV=testing php tests/Support/run-with-testing-db-lease.php \
  --label=feature-regression --wait-ms=30000 -- \
  php artisan test --filter=ReviewFsrsTest

# Read-only lease diagnostics; does not release or overwrite the owner
php tests/Support/run-with-testing-db-lease.php --status
```

Do not run a testing server or testing database fixture command directly. The runner must acquire the lease before it starts the child process.

For lease/crash diagnostics that deliberately keep a lease open, use the bounded testing-only probe instead of ad-hoc `php -r` holders:

```bash
php tests/Support/testing-db-lease-worker.php project-probe-hold \
  <project-root> <logical-db-id> <temp-lease-base> <label> <max-hold-ms>
```

`max-hold-ms` is mandatory and limited to 100–60000 ms. The probe prints `READY <pid> OWNED|INHERITED`, self-releases at the deadline, prints `EXPIRED`, and exits. It does not connect to Laravel or MySQL. A parent task may terminate earlier, but the dead-man deadline ensures the probe cannot remain an independent lease owner for hours after its parent disappears.

### 3.2 Parallel validation lanes

For four-window acceptance, prefer the same mature isolation model used by Laravel parallel testing and Playwright workers: each concurrent validation owner gets its own backend data and browser state instead of sharing one mutable test world.

`tests/Support/run-validation-lane.php` defines four bounded lanes:

| Lane | Testing DB | Server port | Browser context | Typical module |
|---|---|---:|---|---|
| 01 | `<base_test_db>_lane01` | 8871 | `linguacafe-validation-01` | Reader / continuity / Library |
| 02 | `<base_test_db>_lane02` | 8872 | `linguacafe-validation-02` | Daily Sense goal / Learning History / export |
| 03 | `<base_test_db>_lane03` | 8873 | `linguacafe-validation-03` | Sense Review / FSRS / reschedule |
| 04 | `<base_test_db>_lane04` | 8874 | `linguacafe-validation-04` | rehomed surfaces / Article Health / Custom Study / responsive UI |

Each lane has its own MySQL database, lease identity, Laravel storage/session/log directory and browser context. The databases persist between runs; `--prepare` only creates a missing lane database and applies normal pending migrations. It never fresh-resets, wipes or drops an existing database.

```bash
# One-time / migration-change preparation for a lane
php tests/Support/run-validation-lane.php --lane=01 --prepare --describe

# Run a DB-writing test inside that lane
php tests/Support/run-validation-lane.php --lane=01 -- \
  php vendor/bin/phpunit tests/Feature/ReadingContinuityProgressTest.php

# Start that lane's browser server; use the lane's documented port
SESSION_DRIVER=file php tests/Support/run-validation-lane.php --lane=01 -- \
  php artisan serve --host=127.0.0.1 --port=8871
```

Parallel rules:

- Different lanes may write concurrently because database + lease + Laravel storage are isolated.
- Two owners must never use the same lane concurrently; that lane's lease remains exclusive and fail-closed.
- Browser validation uses a separate Playwright/browser context per lane. Do not make four windows drive one Chrome page/context.
- A build output directory is still a shared writer surface. Run one root/mobile build owner first or in parallel with non-build work; validation lanes consume built assets read-only rather than launching four builds into the same output.
- A stateless tokenizer may be shared, but only one owner controls its process lifecycle. Validation windows must not independently restart it.
- For server-state-changing browser tests, use a lane-local testing identity/account. The same human-readable fixed testing identity may exist independently in each lane database because the databases are isolated; browser cookies/storage are still separate.
- The final integration owner should run only a short cross-module smoke plus report/diff reconciliation. It must not repeat every module's full browser matrix after the module lanes already passed on the same integrated tree.

### 3.3 If health check fails

| Failure | Probable cause | Safe fix |
|---------|---------------|----------|
| `database name does not contain test` | `.env.testing` has wrong `DB_DATABASE` | Check `.env.testing` exists and sets `DB_DATABASE=linguacafe_fsrs_test` |
| `is not production database` | Testing DB = default DB | Ensure `.env.testing` overrides `DB_DATABASE` |
| `migrations table does not exist` | Testing DB not initialized | `php artisan migrate --env=testing` |
| `migrations are empty` | Migrations run but table empty | `php artisan migrate --env=testing` |
| `active lease + old probe label + missing/dead parent` | A diagnostic holder outlived its task | Revalidate the exact PID, command, label, age, task ownership, and official `--status`. If it is a proven orphan, recover only that exact process. Never delete the lock file or infer staleness from metadata age alone. |

### 3.4 What NOT to do

- **Do NOT** run `php artisan migrate:fresh --env=testing` (drops all tables, then recreates — risky if DB is shared)
- **Do NOT** run `php artisan db:wipe --env=testing` (destructive)
- **Do NOT** edit `.env` or `.env.testing` manually unless you know what you're doing
- **Do NOT** bypass `run-with-testing-db-lease.php` for a testing server, migration check, fixture, sentinel, or browser-acceptance server
- **Do NOT** treat a metadata PID as proof that a lease is active; only the OS lock is authoritative
- **Do NOT** run two writers against the same testing database/lane at the same time; the second must fail fast or use an explicitly bounded wait. Different prepared validation lanes are intentionally allowed to run concurrently.
- **Do NOT** create a live lease probe with direct `acquireForProject(...)` followed by an unbounded `while (true)` / equivalent infinite holder; use `project-probe-hold` with a finite deadline
- **Do NOT** kill a lease owner from PID/age metadata alone, delete the `.lock` file, or steal an active OS lock; `flock` remains the ownership authority

### 3.5 How to report health check failure

If a health check test fails, collect this in the report:

```
FAILED: TestingDatabaseHealthTest::test_*
- Error: <exact error message>
- DB name: <from config>
- APP_ENV: <current value>
- migrations table exists: yes/no
- migrations count: <number>
```

Do NOT write the failure as passing. Do NOT skip the health check and proceed to other tests.

## 4. Architecture Context

Many tests use `RefreshDatabase`. The default non-lane path still operates on the shared `linguacafe_fsrs_test` MySQL database, while parallel validation lanes opt into separate suffixed databases:

```
tests/Unit/FsrsSchedulingServiceTest.php          (uses RefreshDatabase — Unit test)
tests/Feature/ReviewFsrsTest.php                  (uses RefreshDatabase)
tests/Feature/WordSenseTest.php                   (uses RefreshDatabase)
tests/Feature/VocabularySearchTest.php            (uses RefreshDatabase)
... (41 more)
```

The machine-global lease prevents concurrent access to the same logical database across managed worktrees. Lane-specific logical database identifiers preserve that protection while allowing different isolated databases to run concurrently. The health check confirms the selected database is safe before a test mutates it. This protocol does not authorize destructive database commands and does not read environment files.
