# H-02 Representative Runtime Plan

## Status

- **H-02 is DONE / Accepted.**
- H-01 remains DONE at canonical `2df859129d817e53f796687948845237928a5b66`.
- H-02 implementation commit: `bc2cb433101751e8c6922d8cc803137988bc0657`.
- Final acceptance: `docs/testing/h02-representative-load-acceptance-2026-08-28.md`.
- **H-03 is now ACTIVE**, but no performance optimization is authorized until request/flow-level measurements identify the actual bottleneck.

## Goal and frozen runtime seam

H-02 proves current canonical LinguaCafe code under a real concurrent testing runtime:

- current checkout built with `docker/PhpDockerfile`;
- testing-only `docker-compose.h02-testing.yml`;
- exactly two services: `mysql` + `web`;
- web clears repository ENTRYPOINT and runs only `apache2-foreground`;
- MySQL 8.0 uses disposable `/var/lib/mysql` tmpfs;
- web is localhost-only at `127.0.0.1:8892:80`; MySQL has no host port;
- no host volumes, `env_file`, `${...}` interpolation, Redis, Python, Horizon, Reverb, backup, scheduler or supervisord sidecars;
- `APP_ENV=testing`, `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`;
- H-01 `schema_version=1` JSON remains the only load/observability summary interface;
- canonical flows are Reading / lookup / Sense Review;
- formal rating must not duplicate ReviewLog or FSRS progression.

This Compose runtime is a testing adapter for representative measurement, not a second production architecture.

## Completed environment and container gates

- [x] hardware virtualization / SLAT confirmed
- [x] Windows Subsystem for Linux enabled
- [x] VirtualMachinePlatform enabled
- [x] required Windows restart completed
- [x] WSL2 runtime verified after restart
- [x] Docker Desktop WSL2 backend installed and Linux Engine verified
- [x] `docker version` / `docker compose version` verified
- [x] frozen Compose config verified
- [x] port 8892 verified available after repairing the Windows excluded-port root cause
- [x] current checkout image built cleanly without repository `.env`
- [x] only H-02 `mysql + web` started
- [x] container `APP_ENV=testing` and Docker testing DB identity verified
- [x] no host MySQL exposure and only localhost:8892 web exposure verified
- [x] Apache concurrency proven; final runtime reports six Apache processes
- [x] disposable MySQL initialized through ordinary testing-only migration on fresh tmpfs storage
- [x] one-user application smoke passed
- [x] cleanup leaves no H-02 containers, listener, temp directory or testing DB lease residue

## Completed load escalation gate

Exact total simultaneous users:

- [x] 1 VU
- [x] 10 VU
- [x] 25 VU
- [x] 50 VU
- [x] 100 VU

The final clean-checkout run was executed from exact commit `bc2cb433101751e8c6922d8cc803137988bc0657` and completed with exit code 0.

| Total VUs | Requests | Failed rate | Checks | p95 | p99 | MySQL connected max | MySQL running max | Rated cards | ReviewLogs | Duplicate logs | Invalid FSRS |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | 3 | 0 | 1.0 | 130.182 ms | 139.139 ms | 2 | 2 | 0 | 0 | 0 | 0 |
| 10 | 30 | 0 | 1.0 | 2058.735 ms | 2492.401 ms | 6 | 3 | 3 | 3 | 0 | 0 |
| 25 | 75 | 0 | 1.0 | 4948.997 ms | 4949.960 ms | 9 | 2 | 8 | 8 | 0 | 0 |
| 50 | 150 | 0 | 1.0 | 5823.257 ms | 5830.234 ms | 17 | 6 | 16 | 16 | 0 | 0 |
| 100 | 300 | 0 | 1.0 | 6872.756 ms | 6884.701 ms | 30 | 4 | 33 | 33 | 0 | 0 |

Every threshold also recorded queue backlog max `0`, new Laravel high-severity errors `0`, Apache `capacity_representative=true`, and correct formal-rating verification.

## Safety and cleanup

H-02 retained these rules throughout execution:

- never read/use repository `.env` for the runtime;
- never touch host production/dev DB;
- never mount host database/storage directories;
- no destructive DB reset/wipe;
- no force push;
- no notification script or DCP;
- no broad Docker prune;
- failed runs remain fail-closed and task-owned cleanup is bounded.

Final residue proof after the exact-commit load run:

- H-02 Compose services: none;
- listener on 8892: `0`;
- `linguacafe-h02-ladder-*` temp directories: `0`;
- testing DB lease: `active=false`, `stale_metadata=false`.

## H-03 entry gate

H-02 identifies a real performance problem but not yet its owner: aggregate p95 increases from about 130 ms at 1 VU to about 6.87 s at 100 VU.

The aggregate k6 percentile includes login/setup traffic together with Reading / lookup / Sense Review, so H-03 must **diagnose before optimizing**:

1. decompose latency by request/flow while preserving H-01 `schema_version=1` as the single final summary truth;
2. correlate 25/50/100 VU results with MySQL Performance Schema statement digests/query counts and Apache concurrency;
3. identify the highest-contributing request/service/query;
4. only then pay for a query rewrite, batch, index, cache or runtime tuning change;
5. rerun the representative ladder to prove improvement without weakening correctness gates.

H-03 must not add speculative cache/index/Redis/workers, switch server architecture, or change ReviewLog/FSRS semantics merely because aggregate latency is high.
