# Testing DB Health Playbook

> **Status**: Active
> **Last updated**: 2026-08-07 (R11Z machine-global lease)
> **Governing rules**: `vibe-coding-collaboration-rules.md` §27; `repo-architecture-hotspot-audit.md`

## 1. Purpose

Prevent and diagnose the recurring `SQLSTATE[42S02]: Table 'linguacafe_fsrs_test.migrations' doesn't exist` errors that happen when PHPUnit feature tests share a MySQL testing database.

## 2. What We Did

### 2.1 Machine-global testing database lease

- **Files**: `tests/Support/TestingDatabaseLease.php`, `tests/bootstrap.php`
- **Decision**: ADR-0056.
- **Mechanism**: PHP `flock(LOCK_EX)` on one versioned, hashed lock below the operating-system temporary directory.
- **Identity**: normalized Git common repository + normalized remote identity + the non-secret logical testing database identifier in `phpunit.xml` + protocol version.
- **Effect**: all managed worktrees that share the same testing database compete for the same OS lock. A stale PID or metadata file never counts as active ownership.
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

### 3.2 If health check fails

| Failure | Probable cause | Safe fix |
|---------|---------------|----------|
| `database name does not contain test` | `.env.testing` has wrong `DB_DATABASE` | Check `.env.testing` exists and sets `DB_DATABASE=linguacafe_fsrs_test` |
| `is not production database` | Testing DB = default DB | Ensure `.env.testing` overrides `DB_DATABASE` |
| `migrations table does not exist` | Testing DB not initialized | `php artisan migrate --env=testing` |
| `migrations are empty` | Migrations run but table empty | `php artisan migrate --env=testing` |
| `active lease + old probe label + missing/dead parent` | A diagnostic holder outlived its task | Revalidate the exact PID, command, label, age, task ownership, and official `--status`. If it is a proven orphan, recover only that exact process. Never delete the lock file or infer staleness from metadata age alone. |

### 3.3 What NOT to do

- **Do NOT** run `php artisan migrate:fresh --env=testing` (drops all tables, then recreates — risky if DB is shared)
- **Do NOT** run `php artisan db:wipe --env=testing` (destructive)
- **Do NOT** edit `.env` or `.env.testing` manually unless you know what you're doing
- **Do NOT** bypass `run-with-testing-db-lease.php` for a testing server, migration check, fixture, sentinel, or browser-acceptance server
- **Do NOT** treat a metadata PID as proof that a lease is active; only the OS lock is authoritative
- **Do NOT** run two testing database writers at the same time; the second must fail fast or use an explicitly bounded wait
- **Do NOT** create a live lease probe with direct `acquireForProject(...)` followed by an unbounded `while (true)` / equivalent infinite holder; use `project-probe-hold` with a finite deadline
- **Do NOT** kill a lease owner from PID/age metadata alone, delete the `.lock` file, or steal an active OS lock; `flock` remains the ownership authority

### 3.4 How to report health check failure

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

45 test files use `RefreshDatabase` trait, all operating on `linguacafe_fsrs_test` MySQL database:

```
tests/Unit/FsrsSchedulingServiceTest.php          (uses RefreshDatabase — Unit test)
tests/Feature/ReviewFsrsTest.php                  (uses RefreshDatabase)
tests/Feature/WordSenseTest.php                   (uses RefreshDatabase)
tests/Feature/VocabularySearchTest.php            (uses RefreshDatabase)
... (41 more)
```

The machine-global lease prevents concurrent access across managed worktrees when all testing database writers use the bootstrap or runner. The health check confirms the database is safe before a test mutates it. This protocol does not authorize destructive database commands and does not read environment files.
