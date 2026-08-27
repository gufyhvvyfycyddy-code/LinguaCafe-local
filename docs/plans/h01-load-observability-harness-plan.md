# H-01 Load / Observability Harness Plan

> Status: DONE / Accepted
> Date: 2026-08-27
> Milestone: H-01

## Goal

Create one small, repeatable testing-only harness that H-02 can use to measure HTTP tail latency and failures together with MySQL connection pressure, Laravel queue backlog, and application error-log entries.

The harness must produce one machine-readable JSON summary and must not add a production monitoring endpoint, persistent metrics store, worker, dashboard, migration, or background service.

## Mature reference choices

- Use Grafana k6 as the load driver. k6 already owns HTTP request count, failure rate, and latency trends including configurable p95/p99 summary statistics and machine-readable `handleSummary()` output.
- Use MySQL server status variables `Threads_connected` and `Threads_running` for connection pressure. They are read-only server status counters.
- Use Laravel's current queue connection and its queue-size contract for backlog. Laravel's own `queue:monitor` is built on queue size rather than a second application-specific backlog model.
- Keep Laravel application errors observable from the validation lane's isolated log directory; do not add an HTTP diagnostics endpoint or new logging sink.
- Do not install/enable Horizon as the H-01 solution. Horizon already exists as a package but requires Redis runtime/metrics snapshots and would create a monitoring subsystem when the roadmap explicitly asks for a minimal harness first.

## Architecture gate

### Owner and seam

- Load generation owner: k6 scenario under `tests/load/`.
- Process / sampling / report owner: one testing-only PHP runner under `tests/Support/`.
- Existing safety owners reused unchanged: `run-validation-lane.php`, `run-pab-r3-browser-acceptance.php`, `TestingDatabaseLease`, testing acceptance sentinel, lane-local storage/logs.
- Production Laravel routes, controllers, services, queue configuration, logging configuration, and database schema are not changed.

### Data flow

`validation lane 04 -> PAB lease + sentinel -> H-01 runner -> local PHP testing server + k6 child`

While k6 is running, the H-01 runner samples the already configured Laravel testing runtime:

`MySQL SHOW GLOBAL STATUS -> Threads_connected / Threads_running`

`Laravel configured queue connection -> queue size`

`lane-local storage/logs -> newly appended ERROR/CRITICAL/ALERT/EMERGENCY entries`

At the end, the runner combines those samples with the k6 end-of-test summary into one JSON document. No metric is written back to Laravel/MySQL.

## Runtime boundary

The H-01 runner must fail closed unless all of the following are true:

1. `APP_ENV=testing`.
2. It inherited a PAB testing sentinel whose value has the existing sentinel prefix.
3. The local server sentinel returns HTTP 200 with `environment=testing`, `database_is_testing=true`, and `sentinel_present=true` before load starts.
4. k6 is resolved to an actual executable path. On Windows the runner resolves `k6.exe` from PATH instead of relying on extensionless `proc_open` behavior; this prevents recurrence of the Composer-proxy/Windows process-start bug already seen in validation tooling.

## Allowed files

- `docs/plans/h01-load-observability-harness-plan.md`
- `tests/load/h01-sentinel-smoke.js`
- `tests/Support/run-h01-load-observability.php`
- `tests/Unit/H01LoadObservabilityHarnessTest.php`
- `docs/plans/LinguaCafe_Goal_Mode_All_Milestones_Sol_Medium_2026-08-09.md` after acceptance
- `docs/CURRENT_AI_CONTEXT.md` after acceptance
- documentation guards only if their current-state assertions need the H-01 transition

## Explicitly not doing

- No production metrics endpoint.
- No Prometheus, Grafana, Pulse, Telescope, or new Horizon runtime configuration.
- No schema migration or metrics table.
- No Redis requirement added for H-01.
- No queue worker topology change.
- No application performance fix before H-02 provides bottleneck evidence.
- No real-user, development, staging, or production database load.
- No H-02 100-user claim from the H-01 smoke run.

## H-01 smoke acceptance

The runner must complete through validation lane 04 + PAB and emit a JSON summary containing:

- k6 version / scenario identity;
- HTTP request count, failed rate, p95, p99, average and max duration;
- sampled MySQL `Threads_connected` and `Threads_running` min/max/last;
- queue connection/driver/name and backlog min/max/last;
- newly appended Laravel error-entry count;
- sample count and run duration.

Acceptance requires:

- Unit tests for parsing/aggregation/runtime boundaries and Windows `k6.exe` resolution;
- actual k6 smoke against the server-bound testing sentinel;
- at least two observability samples;
- HTTP failed rate 0 and Laravel error entries 0 for the smoke;
- PHP lint and `git diff --check` pass;
- no product/source route changes.

H-02 will replace the sentinel smoke with real authenticated reading / lookup / Sense Review scenarios and raise concurrency to 100. H-01 only proves the measurement harness.

Acceptance evidence: `docs/testing/h01-load-observability-harness-acceptance-2026-08-27.md`.