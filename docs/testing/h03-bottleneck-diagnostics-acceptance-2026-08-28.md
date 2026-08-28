# H-03 Bottleneck Diagnostics Acceptance — 2026-08-28

## Verdict

**Accepted / DONE.**

H-03 found no measured performance bottleneck in the canonical Reading, lookup, or Sense Review application paths that justifies production query, index, cache, batch, FSRS, ReviewCard, session, or application-code changes.

The H-02 aggregate 100-VU p95 near 6.9 seconds was dominated by the fresh-container Apache prefork cold-burst admission path before the representative business requests ran. H-03 therefore closes with measurement decomposition rather than speculative production optimization.

## Exact implementation evidence

H-03 diagnostic instrumentation commit:

`a3859e2158a926153a5d8f7a27d3565014307fca`

The change is testing/harness-only:

- `tests/load/h02-representative-workloads.js` records one k6 Trend for login page, login POST, Reading, lookup, and Sense Review.
- `tests/Support/run-h01-load-observability.php` exposes those optional flow timings inside the existing H-01 `schema_version=1` JSON as `http.flow_duration_ms`.
- Old H-01 scenarios remain compatible when no H-03 flow metrics exist; the field is an empty map.
- No second metrics endpoint, schema version, database store, monitoring service, production route, or application runtime was created.

Focused H-01/H-02/H-03 regression on the exact commit:

- **51 tests / 1018 assertions PASS**
- `git diff --check`: PASS

## Exact 100-VU representative result

The final run recorded `git_head=a3859e2158a926153a5d8f7a27d3565014307fca` directly in the H-01 JSON.

Correctness and safety:

- total VUs: `100`
- total HTTP requests: `300`
- HTTP failed rate: `0`
- checks rate: `1`
- users/cards: `100 / 100`
- expected formal ratings: `33`
- ReviewLogs: `33`
- duplicate ReviewLog cards: `0`
- invalid ReviewLogs: `0`
- invalid FSRS cards: `0`
- MySQL Threads_connected max: `29`
- MySQL Threads_running max: `4`
- queue backlog max: `0`
- new Laravel high-severity errors: `0`

Latency decomposition:

| Request type | Count | Avg | p95 | p99 | Max |
|---|---:|---:|---:|---:|---:|
| GET `/login` | 100 | 5108.3 ms | **6769.6 ms** | 6783.0 ms | 6791.2 ms |
| POST `/login` | 100 | 177.7 ms | **270.9 ms** | 297.8 ms | 301.9 ms |
| Reading | 34 | 20.0 ms | **36.3 ms** | 43.8 ms | 46.0 ms |
| lookup | 33 | 15.2 ms | **23.1 ms** | 42.5 ms | 51.6 ms |
| Sense Review | 33 | 35.7 ms | **48.8 ms** | 53.6 ms | 55.2 ms |

Aggregate HTTP p95 remained about `6759.3 ms`, but the decomposition proves that this aggregate percentile is not representative of Reading, lookup, or Sense Review request latency.

## Root-cause proof

The representative container uses Apache 2.4 prefork with the image defaults:

- `StartServers 5`
- `MinSpareServers 5`
- `MaxSpareServers 10`
- `MaxRequestWorkers 150`

Apache official prefork behavior grows the process pool when spare processes are insufficient rather than keeping all potential workers resident. This matches the observed fresh-runtime behavior: the run begins with roughly six Apache processes, expands sharply during a simultaneous burst, then shrinks back near the configured spare range after the burst.

Additional isolation evidence:

- Docker Linux runtime exposed 20 CPUs and more than 31 GB RAM, so the container was not starved by the Docker Desktop VM resource ceiling.
- Hot serial GET `/login`: average about `8.6 ms`, p95 about `13 ms`.
- GET `/login` itself performs only the existing auth check, one `User::count()`, and view rendering.
- MySQL Threads_running stayed low during representative load.
- A static `/mix-manifest.json` request also exhibited multi-second tail latency under a fresh 100-request simultaneous burst. This removes Laravel routing, session work, `User::count()`, and application SQL from the root cause.

The observed bottleneck is therefore the fresh prefork runtime's cold-burst admission/spawn behavior, not the canonical learning flows.

## Rejected tuning experiment

A temporary, in-container-only experiment raised prefork startup/spare settings to approximately `StartServers=20`, `MinSpareServers=20`, `MaxSpareServers=40` while keeping `MaxRequestWorkers=150`.

The 100-concurrent login-page p95 became worse, around `13.15 s`. The experiment was reverted immediately and never entered repository source.

This is evidence against solving H-03 by simply pre-spawning a much larger idle mod_php prefork pool. It would also carry substantial resident-memory cost because each warm Apache/PHP child consumes non-trivial memory.

## Architecture decision

H-03 does **not** switch Apache MPM, introduce PHP-FPM, add a reverse proxy, add application caches, alter sessions, or tune production deployment topology.

Those choices belong to H-07, where current infrastructure topology, price, steady-state traffic, cold-start behavior, memory cost, and deployment requirements are evaluated together. At that stage, a deployment candidate may compare the current prefork model against an event-MPM + PHP-FPM style topology if the current infrastructure decision actually requires it.

Until then:

- H-02 remains valid capacity/correctness evidence for 100 simultaneous representative users.
- H-03 proves the actual Reading / lookup / Sense Review paths are not the source of the aggregate multi-second p95.
- Future performance comparisons should consume `http.flow_duration_ms` instead of interpreting the mixed aggregate percentile as a business-flow percentile.

## Cleanup

Final H-03 cleanup proved:

- H-03 Compose containers: `0`
- H-03 network: removed
- H-03 temporary diagnostic files/directories: `0`
- no testing runtime left on port 8892
- no destructive database reset/wipe
- no `.env` read or modification
- no notification script
- no DCP
- no broad Docker prune

H-04 may now open using the existing M6 backup/restore owners and an isolated testing database only.
