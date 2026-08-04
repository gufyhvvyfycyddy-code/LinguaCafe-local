---
document_status: current
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: CFH-02A-R1
auto_advance: false
product_code_authorized: false
supervisor_unlock_required: true
---

# LinguaCafe Recovery And Publication Master Plan

## 1. Current Reality

* 本计划不永久写死"最新远端"。每个任务开始时必须 `git fetch origin --prune`，以实际 `origin/master` 为准；当前任务的授权起点（`authorized_from_commit`）记录在 `docs/execution/CURRENT_MILESTONE.json`。
* 本地工作区存在 454 个 Codex 之前留下的资产（110 tracked modified、2 tracked deleted、342 expanded untracked；CFH-01 开始时实测为 110/2/350）。350 条 untracked 中含 11 条本会话自身 `.reasonix/` 运行元数据（7 条 autoresearch + 4 条 desktop-topic），归属 `local_agent_metadata`（不提交）。
* M0—M18 的本地实现不等于已提交、已推送或正式接受。归属图（`docs/audits/cfh-01-worktree-ownership-map-2026-08-04.json`，schema v2）是每个路径所有者的唯一机器可读依据。
* 交接文档 `docs/plans/codex-final-handoff-2026-08-04.md` 是当前恢复入口。
* 产品功能开发暂时冻结，直到既有资产完成归属、验证、精确提交与正常推送。

## 2. Program Goal

1. 保护本地资产，不覆盖、不丢弃任何既有修改。
2. 建立文件归属（CFH-01 归属图）。
3. 按依赖拆分后续任务（CFH-02/03/04/05、M10—M18）。
4. 重新验证（测试、保护回归、真实浏览器/设备验收）。
5. 精确 staging 与提交（禁止 bulk staging）。
6. 正常推送（push 前 fetch 复查）。
7. 最后再恢复产品开发。

## 3. Status Vocabulary

只允许以下状态：

* `LOCAL_UNATTRIBUTED`
* `LOCAL_ATTRIBUTED`
* `READY_FOR_VERIFICATION`
* `VERIFIED_NOT_PUSHED`
* `PUSHED_AWAITING_ACCEPTANCE`
* `ACCEPTED`
* `BLOCKED_EXTERNAL`
* `DO_NOT_COMMIT`

不得继续使用模糊表述（done、finished、closed、almost complete、90%、100%），除非明确引用历史报告并标记为历史表述。

## 4. Active Task

当前唯一任务：

`CFH-02A-R1 — Apply Supervisor Ownership Decision And Close M6 Slice Contract`

状态：

* CFH-01（含 CFH-01A 工作区完整清点与治理骨架、CFH-01B 完整归属契约与提交组元数据）保持 ACCEPTED；
* CFH-02A（冻结 M6 精确提交边界、共享文件最小代码片段与验证矩阵，产物为 publication plan + manifest + M6 guard）分析交付已由网页端 GPT 阶段性接受，CFH-02A 整体尚未关闭；
* CFH-02A-R1 是当前唯一授权任务：应用 supervisor 对 `AdminDashboard.vue` 的归属决定（M13 → CFH-02，`M6_SHARED`）并关闭 M6 切片契约（`decision.status = READY_FOR_CFH02B`）；
* supervisor 归属决定：`resources/js/components/Admin/AdminDashboard.vue` 正式归属 CFH-02（`primary_slice: CFH-02`、`related_milestones: ["M6"]`、`readiness: needs_browser`、`commit_group: CFH-02`）；M6A/M6B 只精确暂存各自 UI 片段，每次暂存后检查完整 cached diff；
* CFH-02B（M6 实施与提交）继续为 `candidate_not_authorized`，必须等待网页端 GPT 验收 CFH-02A-R1 并单独授权；
* 产品代码未授权（`product_code_authorized: false`）；本轮不改变任何产品功能状态。

规则：

* 只读产品代码，禁止修改任何产品源码。
* 只允许修改本轮允许的治理文件（M6 publication plan、M6 manifest、M6 guard、`RecoveryPublicationWorkflowDocsGuard`、本计划、里程碑锁；归属图仅允许 supervisor 决定驱动的归属修正）。
* 不提交产品代码，不推送未验证资产。
* 不自动进入下一任务（`auto_advance: false`）。
* 网页端 GPT 验收通过后才解锁下一任务；完成后停在 `awaiting_web_acceptance`。

## 5. Candidate Queue

登记但不授权：

* `CFH-02B` — M6 实施与提交（依赖 CFH-02A-R1 验收与网页端 GPT 单独授权）
* `CFH-03` — M1—M5 dependency-ordered publication
* `CFH-04` — M7—M8 Android and offline publication
* `CFH-05` — iOS capability closure
* `M10—M18 publication decomposition` — 必须等待 CFH-01 归属图，不提前冻结文件列表

每项状态均为：`status: candidate_not_authorized`

## 6. Dependency Order

1. CFH-02A-R1（当前）。
2. CFH-02B（M6 实施与提交）依赖 CFH-02A-R1 验收与网页端 GPT 单独授权。
3. 根据归属图判断 CFH-03 与后续切片是否可独立执行。
4. M1—M5 foundation 已推送后再执行 CFH-04。
5. M10—M18 根据共享文件重新拆分。
6. CFH-05 需要外部 Mac/Xcode/Apple 能力（`BLOCKED_EXTERNAL` 能力簇）。

## 7. Acceptance Gates

每个产品切片必须同时满足：

* 精确文件归属（归属图覆盖全部路径）。
* 当前测试通过（相关最小 PHPUnit / `node --test`）。
* 适用保护回归（AGENTS.md §6 稳定不变量）。
* 页面任务真实浏览器验收（DOM/用户事件，保留登录、Console、Network 证据）。
* Android/iOS 任务真实设备或模拟器验收。
* 精确 staging（禁止 `git add .` / `git add -A`）。
* cached diff 审查。
* 正常 push（push 前 fetch 复查，无落后）。
* 网页端 GPT 最终验收。

## 8. Stop Rules

遇到以下情况立即停止：

* 文件具有多个无法分离的所有者（归属图 `SHARED_UNRESOLVED` 且无法消解）。
* 需要新产品决定。
* 需要新 ADR（本轮原则上不创建 ADR；若必须则停止报告）。
* 需要 migration 执行、数据回填、drop/truncate。
* 需要修改 `.env`。
* 需要清理、覆盖或丢弃用户资产。
* 测试失败暴露新的独立根因。
* 当前范围无法完整验证。
* 远端发生前进或冲突。

## 9. Historical Plan Treatment

* 原 `docs/plans/linguacafe-master-plan.md` 暂时作为历史总账，只读。
* 它的旧"当前阶段"陈述不再控制接手执行（低权威历史状态按 AGENTS.md §1 消解）。
* 本轮不删除、不重写旧历史。
* 后续另开文档收敛任务，将稳定规则、历史记录和当前计划分离。
