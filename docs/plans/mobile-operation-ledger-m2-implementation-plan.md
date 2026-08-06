# M2 Operation Ledger 实施切片

> 状态：Accepted / Closed
> 日期：2026-07-28
> Architecture：ADR-0035
> Acceptance：`docs/testing/mobile-operation-ledger-acceptance-2026-07-28.md`

## 1. 当前目标

为 Mobile 正式 Sense rating 建立中央 operation ledger、最近操作查询、线性
LIFO undo/redo 和版本/状态冲突保护，并保持 M1 Web seam 与既有 Web undo
完全不变。

## 2. 明确不做

- 不修改任何 Vue 文件或 Web endpoint；
- 不接入 legacy word rating；
- 不迁移删除、导入、批量操作；
- 不实现 article/review package、offline queue、Android/iOS UI；
- 不运行 development/production migration；
- 不改变 FSRS、ReviewLog 分析或 ReviewCard lifecycle 语义。

## 3. 模块责任与数据流

1. `MobileSenseReviewController` 仍是 Mobile rating adapter；在 M1 幂等事务
   callback 内把精确 rating outcome 注册到 ledger。
2. `MobileOperationLedgerService` 唯一拥有 operation 注册、查询、LIFO
   transition、snapshot conflict 和 change append。
3. `MobileOperationController` 只验证 HTTP input、调用 M1 idempotency 和序列化
   M1 envelope，不直接写 ReviewCard/ReviewLog。
4. `Operation` 保存当前状态；`OperationChange` 保存 append-only transition。
5. `ReviewCardFsrsSnapshotService` 继续唯一拥有 snapshot 比较与恢复。

数据流：

`Mobile rating → M1 idempotency claim → formal ReviewCardService rating →
ledger register (same transaction) → response/replay`

`Mobile undo/redo → validation → M1 idempotency claim → ledger lock/version/
LIFO/snapshot checks → restore snapshot + ReviewLog marker + change append →
response/replay`

## 4. 允许文件

- `database/migrations/2026_07_28_000002_create_operation_ledger_tables.php`
- `app/Models/Operation.php`
- `app/Models/OperationChange.php`
- `app/Exceptions/MobileOperationException.php`
- `app/Services/MobileOperationLedgerService.php`
- `app/Http/Controllers/Mobile/MobileOperationController.php`
- `app/Http/Controllers/Mobile/MobileSenseReviewController.php`
- `app/Http/Controllers/Mobile/MobileBootstrapController.php`
- `routes/api.php`
- `tests/Feature/MobileOperationLedgerTest.php`
- `tests/Feature/MobileApiFoundationTest.php`（仅 M1 兼容断言）
- `docs/adr/ADR-0035-mobile-operation-ledger-and-linear-undo-redo.md`
- `docs/plans/mobile-operation-ledger-m2-implementation-plan.md`
- `docs/plans/mobile-api-v1-contract.md`
- `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
- `docs/testing/mobile-operation-ledger-acceptance-2026-07-28.md`
- `docs/DOCUMENTATION_INDEX.md`

## 5. 禁止文件

- `resources/js/**`
- `routes/web.php`
- `SenseReviewController.php`
- `SenseReviewUndoService.php`
- `ReviewCardService.php`
- `FsrsSchedulingService.php`
- 既有 M1 migration
- `.env`、开发/生产数据库和任务外用户文件

## 6. 接口与错误

契约正文写入 `mobile-api-v1-contract.md`。稳定错误：

- `OPERATION_NOT_FOUND` — 404
- `OPERATION_VERSION_CONFLICT` — 409
- `OPERATION_NOT_LATEST` — 409
- `OPERATION_NOT_UNDOABLE` — 409
- `OPERATION_NOT_REDOABLE` — 409
- `OPERATION_STATE_CHANGED` — 409
- `OPERATION_TARGET_UNAVAILABLE` — 409
- M1 `IDEMPOTENCY_KEY_REUSED` / `VALIDATION_ERROR` 保持不变

## 7. 最小验收

成功标准：

- rating 与 operation/change 同事务创建且共享 `operation_id`；
- replay 不创建第二份 operation/change/log；
- session/device stack LIFO 正确；
- undo/redo 只恢复 snapshot，不调用 scheduler、不新增 ReviewLog；
- 新评分使同 stack 的 redo 失效；
- expected version、card state、user/language/device 冲突均拒绝；
- Web rating/undo route 和 payload 不变；
- M1 与受保护 FSRS/Sense 回归通过。

失败标准：

- 任一跨用户/语言可见或可写；
- 非最新 operation 可撤回；
- stale state 被覆盖；
- replay 产生额外副作用；
- Web seam、FSRS owner 或旧 undo 发生变化。
