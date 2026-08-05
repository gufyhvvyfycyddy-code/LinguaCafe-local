---
document_status: current
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: CFH-02B-M6A-R1
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

`CFH-02B-M6A-R1 — Restore MCP Chrome And Complete Mandatory Browser Acceptance`

状态：

* CFH-01、CFH-02A、CFH-02A-R1 保持 ACCEPTED；CFH-02B-M6A 产品提交（`82b2cf856350561abc54b6e05e51d7a19f120388`）已推送，保持 `PUSHED_AWAITING_ACCEPTANCE`（不回滚）；
* 网页端 GPT 结论：M6A 产品提交/精确 staged-tree 测试/前端构建阶段性接受；M6A 整体 Incomplete——原因：上一轮使用 Playwright 降级通道，不满足 MCP Chrome 硬验收规则；
* CFH-02B-M6A-R1（MCP Chrome 强制验收补正）**已完成**：MCP Chrome 已恢复（chrome-devtools-mcp 官方安装，toolCount=29 验证）并对产品提交 `82b2cf856350561abc54b6e05e51d7a19f120388` 完成真实页面验收（登录/备份列表/创建备份 POST 201/刷新持久化/零 restore 请求/无凭据泄漏）；机器可读证据 `docs/testing/cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json`（conclusion=PASS）；M6A 保持 `PUSHED_AWAITING_ACCEPTANCE`，等待网页端 GPT 最终验收；
* 本轮不修改任何产品代码；Playwright 旧证据保留但**不构成最终网页端验收**（browser_channel 强制 `mcp_chrome`）；
* M6B（恢复安全）、M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`，**不自动进入 M6B**（`auto_advance: false`）。

规则：

* MCP Chrome 必须真实调用（server 名称、tool 名称、invocation/session 证据、页面 URL、DOM 操作、Console、Network、截图哈希）；不得用 Playwright/curl/fetch/PHPUnit 代替。
* 只允许修改 5 个治理文件并新建 1 个 evidence JSON（见任务 §八）；不修改产品代码、manifest、ownership map、M6B 残余。
* MCP 配置修复位于仓库外，先备份、最小修改、不写凭据；修复后必须重启/刷新实际 MCP host 并实际调用只读能力确认。
* 验收使用 M6A 产品 commit 的仓库外 disposable worktree（`git worktree add --detach`），testing DB、fake mysqldump、临时 backup storage。
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

1. CFH-02B-M6A-R1（当前，MCP Chrome 强制验收补正）。
2. M6B（恢复安全）依赖 M6A 网页端验收与网页端 GPT 单独授权；M6C/M6D 依次依赖前序发布验收。
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
