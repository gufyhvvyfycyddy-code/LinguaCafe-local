# Testing DB Health Playbook

> **Status**: Active
> **Last updated**: 2026-07-03 (OpenCode-TestingDbHealthHardening-1)
> **Governing rules**: `vibe-coding-collaboration-rules.md` §27; `repo-architecture-hotspot-audit.md`

## 1. Purpose

Prevent and diagnose the recurring `SQLSTATE[42S02]: Table 'linguacafe_fsrs_test.migrations' doesn't exist` errors that happen when PHPUnit feature tests share a MySQL testing database.

## 2. What We Did

### 2.1 PHPUnit Process Lock

- **File**: `tests/bootstrap.php`
- **Mechanism**: PHP `flock(LOCK_EX)` on `storage/framework/testing/phpunit-db.lock`
- **Effect**: Only one PHPUnit process can run feature tests against the MySQL testing DB at a time. A second concurrent process waits until the first finishes.
- **Coverage**: 45 test files use `RefreshDatabase` and benefit from this lock.

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

### 3.1 Before running feature tests

```bash
# Run the health check first (no DB modifications)
php artisan test --filter=TestingDatabaseHealthTest

# Run the config check (no Laravel app, no DB)
php artisan test --filter=TestingDatabaseHealthConfigTest

# Then run any feature tests
php artisan test --filter=ReviewFsrsTest
```

### 3.2 If health check fails

| Failure | Probable cause | Safe fix |
|---------|---------------|----------|
| `database name does not contain test` | `.env.testing` has wrong `DB_DATABASE` | Check `.env.testing` exists and sets `DB_DATABASE=linguacafe_fsrs_test` |
| `is not production database` | Testing DB = default DB | Ensure `.env.testing` overrides `DB_DATABASE` |
| `migrations table does not exist` | Testing DB not initialized | `php artisan migrate --env=testing` |
| `migrations are empty` | Migrations run but table empty | `php artisan migrate --env=testing` |

### 3.3 What NOT to do

- **Do NOT** run `php artisan migrate:fresh --env=testing` (drops all tables, then recreates — risky if DB is shared)
- **Do NOT** run `php artisan db:wipe --env=testing` (destructive)
- **Do NOT** edit `.env` or `.env.testing` manually unless you know what you're doing
- **Do NOT** run two `php artisan test` commands at the same time (the lock prevents this, but it's better to wait)

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

The process lock prevents concurrent access. The health check confirms the DB is safe before any test mutates it.
