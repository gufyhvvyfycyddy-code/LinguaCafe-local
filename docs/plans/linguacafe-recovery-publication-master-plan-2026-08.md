---
document_status: current
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: CFH-02B-M6A
auto_advance: false
product_code_authorized: true
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

`CFH-02B-M6A — Publish And Verify Safe Backup Slice`

状态：

* CFH-01（含 CFH-01A 工作区完整清点与治理骨架、CFH-01B 完整归属契约与提交组元数据）保持 ACCEPTED；
* CFH-02A（冻结 M6 精确提交边界、共享文件最小代码片段与验证矩阵）与 CFH-02A-R1（supervisor 归属决定与 M6 切片契约关闭）均已由网页端 GPT ACCEPTED；manifest `decision.status = READY_FOR_CFH02B`；
* CFH-02B-M6A 是当前唯一授权任务：从本地 dirty 资产精确提取已存在的 M6A 安全备份切片，验证构建/运行，真实浏览器验收，只提交并推送 M6A（manifest 中 M6A 的 whole files 与精确 patch）；
* 当前**只授权 M6A**：M6B（恢复安全）、M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`，不得进入；
* 本轮不允许修改产品代码：默认禁止修改现有产品实现，只允许从现有脏工作区精确提取 M6A 内容；失败时保留证据并停止，不自动修复；
* M6A 发布完成后停在 `awaiting_web_acceptance`，等待网页端 GPT 验收；`product_code_authorized` 在验收阶段回到 false。

规则：

* 只发布 manifest 中 M6A 的 whole files 与精确 patch；禁止 bulk staging（`git add .` / `git add -A`）。
* 不修改产品代码；M6A 代码、测试或验收失败时不修复、不扩大范围，保留准确证据并停止，后续修复另开任务。
* 精确 staged-tree 验证（`git write-tree` + `commit-tree` + 仓库外 disposable worktree），不得在脏工作区测试后冒充精确验证。
* 真实浏览器验收（manifest 指定 M6A fixture，APP_ENV=testing、专用 testing DB、fake mysqldump、临时 backup storage）；不得用 API 结果冒充。
* 不自动进入下一任务（`auto_advance: false`）；M6B/M6C/M6D 均未授权。

## 5. Candidate Queue

登记但不授权：

* `M6B` — 恢复安全（restore preview/confirm/polling；依赖 M6A 发布验收与网页端 GPT 单独授权）
* `M6C` — 内容健康（依赖 M6A/M6B 发布验收与单独授权）
* `M6D` — 隔离收口（依赖 M6A/M6B/M6C 发布验收与单独授权）
* `CFH-03` — M1—M5 dependency-ordered publication
* `CFH-04` — M7—M8 Android and offline publication
* `CFH-05` — iOS capability closure
* `M10—M18 publication decomposition` — 必须等待 CFH-01 归属图，不提前冻结文件列表

每项状态均为：`status: candidate_not_authorized`

## 6. Dependency Order

1. CFH-02B-M6A（当前，M6A 安全备份发布）。
2. M6B（恢复安全）依赖 M6A 发布验收与网页端 GPT 单独授权；M6C/M6D 依次依赖前序发布验收。
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
