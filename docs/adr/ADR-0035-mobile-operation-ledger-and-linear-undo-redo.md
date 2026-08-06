# ADR-0035：Mobile Operation Ledger 与线性 Undo/Redo

> 状态：Accepted
> 日期：2026-07-28
> 决策者：用户通过 M0–M18 目标模式 roadmap 授权
> 适用范围：M2 Operation Ledger 与统一撤回基础

## 1. 背景

M1 已为 Mobile Sense Review 提供稳定 `operation_id`、设备身份、正式评分
幂等和 ReviewLog 前后 FSRS 快照，但 `mobile_client_actions` 只是请求 claim/
replay 存储，不能回答最近操作、当前状态、撤回、重做和冲突。

既有 Web Sense Review 已有基于 ReviewLog 的 session undo。M1 的真实 Web 页面
评分验收仍处于 `Acceptance Deferred — Not Complete`，因此 M2 必须只依赖
已经自动验证的 Mobile API/幂等契约，不修改或依赖该 Web UI seam。

Anki Manual 的 Browser 行为把 Undo 定义为撤销最近执行的操作。M2 保留这一
线性历史核心语义，但采用 Laravel 中央数据库权威、设备身份、请求幂等和显式
version 冲突，而不复制 Anki 的本地 collection/transaction 实现。

官方参考：

- Anki Manual — Browsing / Edit / Undo:
  <https://docs.ankiweb.net/browsing.html>
- Anki 官方源码：
  <https://github.com/ankitects/anki>

## 2. M1 deferred seam 依赖证明

M2 只依赖以下已验证事实：

1. `/api/v1/mobile` envelope、Sanctum token 和 active device middleware；
2. `MobileIdempotencyService` 的 claim/replay/409/rollback；
3. Mobile rating 返回的稳定 `operation_id` 和精确 `review_log_id`；
4. `ReviewCardService::recordReviewWithLog()` 产生的 ReviewLog 前后快照；
5. `ReviewCardFsrsSnapshotService` 的 capture/matches/restore；
6. testing MySQL 中通过的 M1 rating、重试、隔离和回滚测试。

M2 不修改：

- `/reviews/senses` Vue 页面、DOM、按钮或 Network 行为；
- `SenseReviewController`、`SenseReviewUndoService` 或旧 Web undo route；
- 现有 Web rating request/response payload；
- FSRS 算法、评分含义、ReviewLog 分析口径或 lifecycle owner。

因此 M2 不触及 M1 缺失的真实 Web 页面验收 seam，可以按 ADR-0034 进入实施；
M1 仍保持未完成状态。

## 3. 决定

### 3.1 两层存储责任

- `mobile_client_actions` 继续只负责客户端 mutation 的幂等 claim 和原结果 replay。
- `operations` 是用户/语言隔离的领域操作主记录。
- `operation_changes` 是 append-only 状态变化记录，保存 apply/undo/redo/
  supersede 的版本、执行设备和前后状态。

评分 operation 使用 M1 已生成的同一个 `operation_id`，不会建立第二套身份。

### 3.2 First adopter

M2 只把 **Mobile 正式 Sense rating** 纳入 operation ledger。评分仍只能进入：

`MobileSenseReviewController → MobileIdempotencyService →
ReviewCardService::recordReviewWithLog → FsrsSchedulingService`

ledger 注册发生在同一事务中，引用精确 ReviewLog 和 ReviewCard 快照。M2 不把
删除、导入、批量操作、legacy word rating 或 Web rating 一次性迁入。

### 3.3 Stack scope

每个 operation 属于一个线性 stack：

- 请求提供 `review_session_id`：scope 为当前 user + language + session UUID；
- 未提供：scope 为当前 user + language + mobile device UUID。

最近操作查询可按 `review_session_id` 过滤；不提供时返回当前账号/语言的最近
Mobile operations。设备是来源和审计字段，不削弱 user/language 隔离。

### 3.4 Undo/redo

- Undo 只能作用于目标 stack 中当前最新的 `applied` operation。
- Redo 只能作用于该 stack 中最近一次转为 `undone` 且尚未失效的 operation。
- 新评分进入某个 stack 时，该 stack 内尚未 redo 的 operation 变为
  `superseded`；新分支不会保留可重做的旧未来。
- Undo 恢复 ReviewLog `before_card_snapshot`，并保留 ReviewLog、设置
  `undone_at`/`undo_request_id`/`undo_source`。
- Redo 恢复 `after_card_snapshot`，清除 ReviewLog 的当前 undone 标记；完整
  undo/redo 历史保留在 append-only `operation_changes`。
- Undo/redo 不重新调用 FSRS scheduler，也不创建第二条 ReviewLog。

### 3.5 并发和版本

Undo/redo 请求必须包含：

- `client_action_id`：UUID，用 M1 幂等服务确保重试只执行一次；
- `expected_version`：最近查询返回的 operation version。

服务在同一事务中锁定 operation、ReviewCard 和 ReviewLog。以下情况返回稳定
409，不覆盖当前状态：

- expected version 过时；
- 目标不是当前 LIFO 候选；
- operation 状态不允许该 transition；
- ReviewCard 当前 FSRS 状态不匹配预期 snapshot；
- 当前 card/sense lifecycle 已不允许恢复。

### 3.6 API

新增 additive endpoints：

- `GET /api/v1/mobile/operations`
- `POST /api/v1/mobile/operations/{operationId}/undo`
- `POST /api/v1/mobile/operations/{operationId}/redo`

响应继续使用 M1 envelope。列表 `limit` 为 1–100，默认 20，并使用
`before_sequence`/`next_before_sequence` 做稳定 cursor 分页；可选
`review_session_id` 必须是 UUID。所有 lookup 先限定当前 user 和
selected language；跨用户/语言 operation 对调用方表现为 404。

Bootstrap 在 M2 关闭后新增 capability：

- `operation_ledger: true`
- `operation_undo_redo: true`

`offline_queue` 继续为 false。

## 4. 兼容与禁止

- 旧 Web rating 和 undo route 保持不变；
- 旧 Web undo 仅继续管理 Web action，不属于 Mobile API 契约；M2 不尝试把
  Web 入口对 Mobile ReviewLog 的越界调用同步回 operation 状态。若卡状态已被
  其他入口改变，后续 Mobile transition 必须以 `OPERATION_STATE_CHANGED` 拒绝；
- `mobile_client_actions` 的 M1 replay 结果保持不变；
- 不运行开发/生产 migration；
- 不删除或重写 ReviewLog；
- 不修改 FSRS scheduler；
- 不把 operation ledger 扩展为任意事件溯源框架；
- 不迁移删除、导入、批量操作；
- 不实现 M3 package、M4 offline queue 或原生 UI。

## 5. 验证

- M2 feature tests：注册、最近查询、user/language/device/session 隔离；
- LIFO 多步 undo/redo、新评分使 redo superseded；
- request replay、changed-payload conflict、version/state conflict；
- 一次 transition 不新增 ReviewLog，不重复推进 FSRS；
- 事务失败回滚 operation/change/card/log；
- M1 `MobileApiFoundationTest`；
- `SenseReviewActionTransactionTest`、`ReviewFsrsTest`、
  `FsrsSchedulingServiceTest` 和 `WordSense` 相关回归；
- route inspection、PHP syntax、`git diff --check`。
