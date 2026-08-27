# H-01 Load / Observability Harness Acceptance — 2026-08-27

## Verdict

**Accepted / DONE.**

H-01 now provides one testing-only, machine-readable measurement path for H-02. It measures HTTP tail latency/failures, MySQL connection pressure, configured Laravel queue backlog, and new high-severity Laravel log entries without adding a production metrics endpoint, persistent metrics store, monitoring daemon, schema migration, or production queue change.

## Design

The harness intentionally reuses existing owners:

- k6 owns HTTP request count, failed rate, checks and latency percentiles.
- MySQL `SHOW GLOBAL STATUS` supplies `Threads_connected` and `Threads_running`.
- Laravel's configured queue connection supplies queue `size()` / backlog.
- Validation-lane storage supplies run-local Laravel logs.
- Existing Validation Lane 04 + PAB + TestingDatabaseLease + testing sentinel own database/server isolation.

The output interface is one JSON document with `schema_version=1`. H-02 consumes this interface instead of introducing a second report format.

## Files

- `tests/load/h01-sentinel-smoke.js`
- `tests/Support/run-h01-load-observability.php`
- `tests/Unit/H01LoadObservabilityHarnessTest.php`
- `docs/plans/h01-load-observability-harness-plan.md`

No production Controller, Service, route, database schema, queue configuration, or logging configuration changed.

## Tooling setup

Grafana k6 2.2.0 was installed through the official Windows Winget package `GrafanaLabs.k6`. It remains a machine development tool; it is not a Composer/npm application dependency.

The existing long-running DevSpace process had inherited PATH before k6 was installed. System PATH already contained `C:\Program Files\k6\`, so DevSpace was restarted through the existing stable restart mechanism. After restart, both DevSpace shell execution and PHP `getenv('PATH')` resolved `k6.exe` normally. No application fallback to a hard-coded install path was added.

## Tool-layer bugs found and fixed

1. **Failed runs destroyed diagnostics.** The initial runner removed temporary server logs even when sentinel readiness failed. Failure paths now emit bounded server stdout/stderr tails before cleanup.
2. **Readiness probe created a false failure.** A 0.5-second per-request timeout was shorter than a cold Laravel request on the Windows single-worker development server. Repeated aborted requests queued behind the single worker and made the harness report `H01_SENTINEL_NOT_READY` against a healthy testing server. The probe now allows up to 5 seconds per request within a 15-second bounded readiness deadline.
3. **k6 could outlive a sampling failure.** If MySQL or queue sampling threw after k6 started, the child had no guaranteed cleanup path. `h01RunK6AndSample()` now owns the k6 child through `finally` and terminates it on every exceptional exit.
4. **Required k6 metrics could silently become null.** The JSON builder now fails closed with `H01_K6_REQUIRED_METRIC_MISSING` if request/failure/check/avg/p95/p99/max metrics are missing.

During diagnosis one PAB server was deliberately force-terminated. The next PAB run automatically reconciled exactly one stale testing sentinel, proving the previously added forced-termination recovery still works.

## Final testing-bound smoke

Runtime:

- Validation Lane: `04`
- Database: `linguacafe_fsrs_test_lane04`
- Queue driver in the testing run: `sync`
- k6: `2.2.0`
- VUs: `4`
- Load duration: `3s`
- Observability sample interval: `250ms`
- Server profile: `php_builtin_windows_single_worker`
- `capacity_representative`: **false**

Final smoke summary:

- HTTP requests: `5`
- HTTP failed rate: `0`
- checks rate: `1.0`
- HTTP avg: `6139.39ms`
- HTTP p95: `8898.76ms`
- HTTP p99: `8905.52ms`
- HTTP max: `8907.21ms`
- observability samples: `50`
- MySQL `Threads_connected`: min/max/last `2`
- MySQL `Threads_running`: min/max/last `1`
- queue backlog: min/max/last `0`
- newly appended Laravel high-severity errors: `0`

These latency numbers are **not H-02 capacity evidence**. PHP's Windows built-in development server has one worker and is not a production-representative concurrent runtime. H-01 only proves that the measurement interface works.

## Automated verification

Final combined tool regression:

- H-01 harness
- PAB browser acceptance harness
- Validation Lane runner
- TestingDatabaseLease contract

Result: **57 tests / 270 assertions PASS**.

Additional gates:

- PHP syntax: PASS
- `git diff --check`: PASS
- real Validation Lane 04 + PAB + k6 smoke: PASS
- final port 8874: closed
- final `k6.exe` process: none
- final Lane04 lease: `active=false`, `stale_metadata=false`

## H-02 entry condition

H-02 must not use Windows `php -S` as the 100-concurrent-user capacity runtime. The repository's existing `docker-compose.yml` currently runs an upstream prebuilt GHCR webserver image, so it also cannot automatically prove performance of the current Goal worktree. H-02 begins by establishing a representative testing runtime that executes the current canonical source with concurrent PHP workers, while preserving the testing-database/sentinel boundary. Only then may the 100-user reading / lookup / Sense Review scenarios produce capacity conclusions.
