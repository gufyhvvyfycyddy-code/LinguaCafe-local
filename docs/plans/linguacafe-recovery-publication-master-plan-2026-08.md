---
document_status: current
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: CFH-01B
auto_advance: false
product_code_authorized: false
supervisor_unlock_required: true
---

# LinguaCafe Recovery And Publication Master Plan

## 1. Current Reality

* 本计划不永久写死"最新远端"。每个任务开始时必须 `git fetch origin --prune`，以实际 `origin/master` 为准；CFH-01B 的授权起点（`authorized_from_commit`）记录在 `docs/execution/CURRENT_MILESTONE.json`。
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

`CFH-01B — Complete Ownership Contract And Commit-Group Metadata`

状态：

* CFH-01A（工作区完整清点与治理骨架）已阶段性接受；
* CFH-01 整体尚未关闭；
* CFH-01B 仍是纯治理任务：补齐 462 条归属记录的完整机器契约、冻结 commit group 与验证准备度、加强 Harness、修正计划基线表述、更新里程碑锁；
* 产品代码未授权（`product_code_authorized: false`）。

规则：

* 只读产品代码，禁止修改任何产品源码。
* 只允许修改四个治理文件（归属图、`RecoveryPublicationWorkflowDocsGuard`、本计划、里程碑锁）。
* 不提交产品代码，不推送未验证资产。
* 不自动进入下一任务（`auto_advance: false`）。
* 网页端 GPT 验收通过后才解锁下一任务；完成后停在 `awaiting_web_acceptance`。

## 5. Candidate Queue

登记但不授权：

* `CFH-02` — M6 restore safety revalidation and commit
* `CFH-03` — M1—M5 dependency-ordered publication
* `CFH-04` — M7—M8 Android and offline publication
* `CFH-05` — iOS capability closure
* `M10—M18 publication decomposition` — 必须等待 CFH-01 归属图，不提前冻结文件列表

每项状态均为：`status: candidate_not_authorized`

## 6. Dependency Order

1. CFH-01B（当前）。
2. 根据归属图判断 CFH-02 与 CFH-03 是否可独立执行。
3. M1—M5 foundation 已推送后再执行 CFH-04。
4. M10—M18 根据共享文件重新拆分。
5. CFH-05 需要外部 Mac/Xcode/Apple 能力（`BLOCKED_EXTERNAL` 能力簇）。

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
