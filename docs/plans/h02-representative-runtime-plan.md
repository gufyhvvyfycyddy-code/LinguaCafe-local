# H-02 Representative Runtime Plan

## Status

- H-02 remains ACTIVE（H-02 仍为 ACTIVE）。
- H-01 is DONE at canonical `2df859129d817e53f796687948845237928a5b66`（H-01 已在该 canonical 提交完成）。
- The representative runtime contract, k6 representative workloads, testing fixture lifecycle, capacity-claim gate, and real fixture lifecycle proof are implemented on this branch through HEAD `01a9340`（代表性运行时契约、k6 代表性负载、testing fixture 生命周期、容量声明闸门和真实 fixture 生命周期证明已在本分支截至该 HEAD 实现）。
- No 100-user capacity claim exists（尚不存在 100 用户容量声明）；H-02 is not DONE。

## Completed Evidence

- Focused current verification through HEAD `01a9340`: **14 tests / 307 assertions PASS**, PHP lint **PASS**, and `git diff --check` **PASS**（截至该 HEAD 的当前聚焦验证为 14 个测试 / 307 个断言通过，PHP lint 通过，`git diff --check` 通过）。
- This evidence establishes the representative runtime/load-test and fixture-lifecycle implementation only; it does not constitute a 100-user capacity claim。

## Goal

退出目标：
- run current canonical code under a real concurrent Apache runtime；
- 使用 disposable isolated MySQL；
- H-01 `schema_version=1` JSON remains the only load/observability measurement interface；
- H-02 eventually measures reading / lookup / Sense Review under 100 simultaneous online users；
- no duplicate formal rating / ReviewLog / FSRS write。
## Frozen Runtime Seam

- 使用当前 checkout，并通过 `docker/PhpDockerfile` 构建 current canonical code。
- testing-only Compose file: `docker-compose.h02-testing.yml`。
- exactly two runtime services: `mysql` and `web`。
- `web` clears the repository ENTRYPOINT with `entrypoint: []`；web command is only `apache2-foreground`。
- MySQL is `mysql:8.0` with `/var/lib/mysql` tmpfs。
- web exposed only at `127.0.0.1:8892:80`；MySQL has no host port。
- no host volume, `env_file`, `${...}` interpolation。
- no Redis/Python/Horizon/Reverb/backup/scheduler/supervisord sidecars。
- 运行时固定为 `APP_ENV=testing`、`CACHE_DRIVER=array`、`QUEUE_CONNECTION=sync`。
- 这是 testing runtime adapter，不是 second production architecture。

## Why Windows php -S Is Not Capacity Evidence

- H-01 `php -S` remains smoke-only and `capacity_representative=false`。
- 其 Windows single-worker behavior 不被接受为 H-02 capacity evidence。
- H-02 only makes capacity claims after container Apache concurrency is proven。
## Environment Gate

- [x] 1. confirm hardware virtualization and SLAT
- [x] 2. enable Windows Subsystem for Linux feature
- [x] 3. enable VirtualMachinePlatform feature
- [ ] 4. reboot if Windows reports reboot required
- [ ] 5. verify the WSL2 runtime after reboot
- [ ] 6. install Docker Desktop using WSL2 backend
- [ ] 7. verify `docker version` and `docker compose version`
- [ ] 8. verify no unrelated Docker containers/ports conflict (after Docker CLI/Compose verification)

当前事实：virtualization/SLAT、WSL feature 和 VirtualMachinePlatform feature 均为 Enabled；CBS RebootPending=True；Microsoft.WSL remains 2.7.8.0；WSL runtime remains pre-reboot unusable/unvalidated until reboot。
规则：WSL 2.7.8.0 already exceeds Docker Desktop minimum 2.1.5, so package upgrade is not a pre-reboot gate；re-evaluate update only after post-reboot runtime verification。
## Container Validation Gate

- [ ] 1. `docker compose -f docker-compose.h02-testing.yml config`
- [ ] 2. build only the H-02 web image from the current checkout
- [ ] 3. start only H-02 mysql + web
- [ ] 4. prove web container APP_ENV is testing
- [ ] 5. prove DB_DATABASE is linguacafe_h02_testing and DB host is mysql
- [ ] 6. prove no host MySQL port and only localhost:8892 web exposure
- [ ] 7. prove container process tree has Apache but no Horizon/Reverb/backup/supervisord
- [ ] 8. initialize only the disposable H-02 database using an explicitly approved testing-only path
- [ ] 9. run a one-user application smoke
- [ ] 10. destroy H-02 containers/network/tmpfs and prove no residue

初始化命令须由主窗口在 fresh inspection 后单独批准；本计划不指定或命名任何 destructive migration/reset command。
## Load Escalation Gate

- [ ] 1 VU smoke
- [ ] 10 VU
- [ ] 25 VU
- [ ] 50 VU
- [ ] 100 simultaneous online users

每一步都必须满足：
- zero unexpected HTTP failures；
- H-01 summary JSON produced；
- Laravel new high-severity errors = 0；
- DB connections/threads recorded；
- queue backlog recorded；
- runtime remains `capacity_representative=true` only after Apache concurrency proof；
- stop escalation at first failed threshold。

100 is not attempted until 1/10/25/50 pass（1/10/25/50 未通过前不尝试 100）。
## H-02 Workload Contract

当前 canonical flows 只有三类：
- Reading
- lookup
- Sense Review

本切片不冻结 exact routes/fixtures 和 traffic proportions；写 load script 前，必须从当前 canonical callers 推导它们。
formal rating writes 需要 idempotency/duplicate-proof verification；不得发明新的 rating endpoint。

## Safety and Cleanup

- never read/use repository `.env` for H-02 runtime；
- never touch host production/dev DB；
- never mount host database/storage directories；
- no force push；
- no destructive DB reset/wipe；
- no notification/DCP；
- container cleanup must run even on failed load；
- H-02 measurements must not survive as a new application database/metrics store。
## Exit Evidence

H-02 can become DONE only when all of the following are true：
- representative Apache/MySQL runtime is proven from current canonical code；
- 1/10/25/50/100 ladder is executed；
- H-01 JSON records p95/p99, failures, DB connections/threads, queue backlog, application errors；
- reading/lookup/Sense Review have no main-flow errors；
- formal rating has no duplicate write；
- cleanup leaves no H-02 runtime residue；
- H-03 remains closed until evidence identifies a bottleneck。

## Current Next Gate

Windows reboot is required to activate the already-enabled WSL and VirtualMachinePlatform features. After reboot, the main window must verify WSL2, install and verify the Docker Desktop WSL2 backend, run Compose config/build/up using the current code, and then execute staged `1 → 10 → 25 → 50 → 100` load with H-01 `schema_version=1` observability.

H-02 remains ACTIVE and no 100-user capacity claim may be made until that evidence is complete. H-03 remains explicitly closed until H-02 evidence identifies a bottleneck; no H-03 work starts before H-02 evidence.
