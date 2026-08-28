# H-02 Representative Load Acceptance — 2026-08-28

## Verdict

**Accepted / DONE.**

H-02 now has a representative testing runtime built from the current LinguaCafe checkout, staged concurrent load through 100 simultaneous users, H-01 `schema_version=1` observability, formal-rating duplicate protection, and zero-residue cleanup.

Final implementation commit: `bc2cb433101751e8c6922d8cc803137988bc0657`.

## Representative runtime

The accepted runtime preserves the frozen H-02 seam:

- `docker-compose.h02-testing.yml` with exactly `mysql` + `web`.
- `web` is built from the current checkout with `docker/PhpDockerfile` and runs `apache2-foreground`.
- MySQL 8.0 uses disposable `/var/lib/mysql` tmpfs.
- Web exposure is localhost-only at `127.0.0.1:8892`; MySQL has no host port.
- `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`.
- No Redis, Python tokenizer, Horizon, Reverb, backup, scheduler, supervisord or production metrics service was added.
- Apache concurrency proof reports six Apache processes and `capacity_representative=true`.

The final clean-checkout environment gate returned `ready=true` with WSL2, Docker Linux Engine, Docker Compose, frozen Compose config and free port 8892 all verified.

## Exact-commit load ladder

The final readable run was executed from clean commit `bc2cb433101751e8c6922d8cc803137988bc0657` and completed with exit code 0.

| Total VUs | Requests | HTTP failed rate | Checks | p95 | p99 | Max | MySQL connected max | MySQL running max | Rated cards | ReviewLogs | Duplicate-log cards | Invalid FSRS cards |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | 3 | 0 | 1.0 | 130.182 ms | 139.139 ms | 141.378 ms | 2 | 2 | 0 | 0 | 0 | 0 |
| 10 | 30 | 0 | 1.0 | 2058.735 ms | 2492.401 ms | 2492.401 ms | 6 | 3 | 3 | 3 | 0 | 0 |
| 25 | 75 | 0 | 1.0 | 4948.997 ms | 4949.960 ms | 4951.266 ms | 9 | 2 | 8 | 8 | 0 | 0 |
| 50 | 150 | 0 | 1.0 | 5823.257 ms | 5830.234 ms | 5832.041 ms | 17 | 6 | 16 | 16 | 0 | 0 |
| 100 | 300 | 0 | 1.0 | 6872.756 ms | 6884.701 ms | 6889.160 ms | 30 | 4 | 33 | 33 | 0 | 0 |

For every threshold:

- queue backlog max = `0`;
- new Laravel high-severity errors = `0`;
- `capacity_representative=true`;
- Reading / lookup / Sense Review checks passed;
- formal Sense Review produced exactly one ReviewLog per rated card;
- no duplicate formal rating and no invalid FSRS progression was observed.

The ladder remains fail-closed: unexpected HTTP/check failure, Laravel high-severity error, Apache concurrency failure, sampler failure, or formal-rating verification failure stops escalation.

## Correctness and concurrency hardening

H-02 exposed intermittent Sense Review failure under concurrent first-time review-setting initialization. The accepted implementation keeps one canonical owner and hardens two existing seams instead of adding a second scheduler or worker:

- `ReviewSettingsResolver` no longer wraps a missing binding lookup in `lockForUpdate()`; initialization converges through the existing database unique constraints plus `createOrFirst()` behavior in the preset/binding services.
- `ReviewCardService::recordReviewWithLog()` uses Laravel's native transaction deadlock retry argument (`3`) while preserving the existing ReviewCard row lock and ReviewLog/FSRS transaction owner.

A real MySQL multi-process regression test for different users initializing review settings concurrently passed five consecutive runs; each run verified one Default preset and one English binding per user with no cross-user ownership drift.

The H-02 ladder itself now has a non-blocking single-instance file lock. A second concurrent ladder exits with `H02_LADDER_ALREADY_RUNNING` and does not execute `docker compose down`, preventing two validation owners from deleting each other's containers.

## Build / fresh-install tooling fixes required by H-02

The representative clean build also closed required production-tooling defects discovered during H-02:

- preserve `USERPROFILE` in the Windows process environment so Docker CLI can discover the official Compose plugin;
- use bounded Docker probes that do not deadlock Windows `proc_open` pipes on large output;
- detect localhost port availability without treating “no listener” as a PowerShell/CIM failure;
- restore the Windows TCP dynamic range to the modern default and refresh WinNAT so frozen port 8892 is bindable;
- keep `.env*`, host `node_modules`, host `vendor`, Git metadata and old storage logs out of Docker build context;
- pin Laravel Mix-compatible webpack to `~5.99.9` so clean installs do not drift to an incompatible newer webpack;
- cache the npm dependency layer before copying application source;
- allow clean `composer install` / package discovery without requiring runtime broadcast secrets, while supporting `BROADCAST_CONNECTION` first and legacy `BROADCAST_DRIVER` second;
- bound MySQL composite-index string columns so a fresh MySQL 8 migration chain succeeds without changing WordSense text capacity;
- model Laravel's actual Axios CSRF path with `X-XSRF-TOKEN` in k6;
- require k6 thresholds so checks/HTTP failures produce non-zero failure rather than a false-green exit.

No repository `.env` was read or copied into the H-02 runtime.

## Automated verification

Relevant final evidence included:

- focused H01/H02 + ReviewSettings/FSRS/Reader regression: **93 tests / 1213 assertions PASS**;
- H-02 build/concurrency closure subset: **39 tests / 277 assertions PASS**;
- final runtime + ladder contract: **13 tests / 157 assertions PASS**;
- real MySQL concurrent review-settings initialization regression: five consecutive passes, **24 assertions per run**;
- PHP syntax checks: PASS;
- k6 JavaScript syntax: PASS;
- Compose config: PASS;
- `git diff --check`: PASS.

## Cleanup

After the final exact-commit ladder:

- H-02 Compose services: none;
- listener on 8892: `0`;
- `linguacafe-h02-ladder-*` temp directories: `0`;
- testing DB lease: `active=false`, `stale_metadata=false`.

No broad Docker prune, destructive database reset, DCP or notification script was used.

## H-03 input

H-02 proves correctness and 100-user execution, but latency increases materially with concurrency: p95 rises from about 130 ms at 1 VU to about 6.87 s at 100 VU.

This aggregate number is **not yet a sufficient per-flow bottleneck diagnosis**. Each VU currently performs login setup plus one canonical main-flow action, so the global HTTP percentile can mix authentication cost with Reading / lookup / Sense Review cost. H-03 must therefore begin by decomposing latency by request/flow and correlating it with MySQL statement digests / query counts and Apache concurrency before paying for any index, cache, batch or query rewrite. No speculative optimization is authorized by this acceptance report.
