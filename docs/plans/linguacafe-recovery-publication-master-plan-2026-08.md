---
document_status: current
program_id: linguacafe-recovery-publication-2026-08
authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md
active_task: CFH-02B-M6B-R1
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

`CFH-02B-M6B — Rework And Publish Single-Owner Restore For Responsive Web`

当前阶段：

* `CFH-02B-M6B-R1 — Close MCP Trace And Governance Evidence`：M6B 产品代码、测试结构和桌面/手机页面行为已被网页端 GPT 阶段性接受；M6B 整体仍 **Incomplete**，等待 MCP trace/evidence 收口（真实 MCP 调用凭证、测试数字校正、绝对路径移除、manifest 矛盾表述修正、Guard 加强）；R1 只做证据与治理收口，不修改产品代码；
* M6C/M6D 未授权。

状态：

* CFH-01、CFH-02A、CFH-02A-R1 保持 ACCEPTED；
* CFH-02B-M6A 产品提交（`82b2cf856350561abc54b6e05e51d7a19f120388`）、CFH-02B-M6A-R1（MCP Chrome 恢复与强制验收）、CFH-02B-M6A-R2（MCP 调用追踪契约补正）均保持 ACCEPTED（不回滚）；
* CFH-02B-M6B 是当前唯一任务（执行前处于 `candidate_not_authorized`，未按第六节授权前不修改产品代码）：重新发布单所有者恢复流程——所有已登录用户拥有相同的备份与恢复能力（不再区分管理员）、未登录用户仍不可访问、无用户可见恢复预览、确认必须精确输入 `RESTORE`（区分大小写、无多余空格）并再次点击"确认恢复"、桌面与手机响应式网页均须支持；
* 本任务冻结产品契约（网页端 GPT 已确认，不得重新讨论或扩展）：equal-privilege（无 admin/is_admin 权限边界）、no user-visible preview（取消用户预览不等于取消后台安全检查）、exact RESTORE input + final click、desktop and phone responsive web、internal safety checks preserved；
* M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`，**不自动进入**（`auto_advance: false`）。

规则：

* 移除 restore-preview endpoint / preview_token / 用户可见技术预览（数据库表、checksum、manifest、SQL、warnings 均不展示）；`POST /backups/{backupId}/restore` 只接受 `{"confirmation": "RESTORE"}`，必须通过 auth + auth.session，不检查 is_admin；
* 服务端在任何活动数据库写入前自动完成：备份验证、路径 containment、checksum/required tables/危险 SQL/解压上限/磁盘空间检查、operation-private immutable pin、不可猜测且幂等的 operation record、安全快照、隔离验证、RestoreWriteFence、维护模式、失败自动回滚（回滚失败保持维护模式并标记 failed_manual_recovery）；
* 状态接口 `GET /backup-restores/{operationId}` 保持可读（已登录、不检查管理员角色、maintenance mode 期间仍可读取、不暴露敏感细节）；
* 同一备份的重复确认、双击最终确认、HTTP 超时后再次提交必须幂等，只产生一个 operation；backup lock 与 restore lock 覆盖完整执行期；
* 旧 admin/preview 测试必须重写（不得只追加新测试）；旧 M6B 代码不得原样提交；
* 只允许修改 M6B 冻结范围（新 HTTP 契约、网页 UI、响应式、对应测试与治理文件）；不随意扩大或重写 manifest、ownership map，不修改 M6A 已接受代码；验收证据提交允许同步 manifest 的实际 M6B 文件边界、SHA、测试和浏览器步骤（已提交的 manifest 变更属于 M6B 验收同步）；CFH-02B-M6B-R1 本轮不再修改 manifest；
* 不自动进入下一任务（`auto_advance: false`）；M6C/M6D 未授权。

## 5. Candidate Queue

登记但不授权：

* `M6C` — 内容健康（依赖 M6B 发布验收与单独授权）
* `M6D` — 隔离收口（依赖 M6B/M6C 发布验收与单独授权）
* `CFH-03` — M1—M5 dependency-ordered publication
* `CFH-04` — M7—M8 Android and offline publication
* `CFH-05` — iOS capability closure
* `M10—M18 publication decomposition` — 必须等待 CFH-01 归属图，不提前冻结文件列表

每项状态均为：`status: candidate_not_authorized`

## 6. Dependency Order

1. CFH-02B-M6B（当前，单所有者恢复重做与发布；依赖网页端 GPT 已冻结的产品契约）。
2. M6C/M6D 依次依赖 M6B 发布验收与网页端 GPT 单独授权。
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
