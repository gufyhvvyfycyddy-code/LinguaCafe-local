# ReviewCard 管理页危险写操作边界侦查

> **日期**：2026-07-01  
> **任务**：ReviewCardManage-MutationBoundary-Scout-1  
> **性质**：只读架构侦查，不改代码，不抽 Service，不实现功能。

## 1. 侦查目标

- 只读核实 ReviewCardManageController 中所有危险写操作的边界。
- 确认每项操作牵涉的模型、事务、日志保留、权限隔离。
- 为后续可能的 MutationService 提取提供事实依据。
- **本轮不进入任何代码实现。**

## 2. 当前写操作入口

| 操作 | 路由 | Controller 方法 | 单卡/批量 | 是否写 ReviewCard | 是否写 WordSense | 是否写 ReviewLog | 是否写 EncounteredWord | 事务 | 可恢复 |
|------|------|----------------|-----------|--------------|-------------|-------------|-------------------|------|--------|
| 编辑字段 | PATCH `/manage/{rc}` | `update()` | 单卡 | 否 | ✅ 白名单 save | 否 | 否 | 无 | 是（改字段） |
| 归档/恢复 | PATCH `/manage/{rc}/enabled` | `enabled()` | 单卡 | ✅ `fsrs_enabled` | 否 | 否 | 否 | 无 | 是（可切换） |
| 立即到期 | POST `/manage/{rc}/due-now` | `dueNow()` | 单卡 | ✅ `fsrs_due_at` | 否 | 否 | 否 | 无 | 是（可修改） |
| 重置 | POST `/manage/{rc}/reset` | `reset()` | 单卡 | ✅ 7 字段清零 | 否 | ✅ `rating=reset` | 否 | ✅ DB::tx | **否**（FSRS 数据丢失） |
| 彻底删除 | DELETE `/manage/{rc}` | `destroy()` | 单卡 | ✅ `delete()` | ✅ `status=REJECTED` | 默认保留 | 条件性 stage=2 | ✅ DB::tx | **否**（硬删卡） |
| 批量归档/恢复 | POST `/manage/bulk-enabled` | `bulkEnabled()` | 批量 | ✅ `fsrs_enabled` 逐卡 | 否 | 否 | 否 | ✅ DB::tx | 是（可切换） |
| 批量彻底删除 | POST `/manage/bulk-delete` | `bulkDestroy()` | 批量 | ✅ `delete()` 逐卡 | ✅ `status=REJECTED` 逐卡 | 默认保留 | 条件性 stage=2 | ✅ DB::tx | **否**（硬删卡） |

## 3. update 边界

- **可编辑字段白名单**：`EDITABLE_FIELDS = ['pos', 'sense_zh', 'sense_en', 'example_sentence_en', 'example_sentence_zh', 'aliases_zh', 'collocations']`（定义在 `ReviewCardManageMutationService.php`）
- **改了什么模型**：只改 `WordSense`（文本字段），不改 `ReviewCard`。
- **是否有事务**：无。每个字段是单表 update，失败时返回 500。
- **权限隔离**：通过 `findManageableSenseCard()` 保证 user_id / language_id / target_type / WordSense status=CONFIRMED。
- **数组字段**：`aliases_zh` 和 `collocations` 经过 `normalizeArray()` 处理（接受逗号字符串或数组）。
- **风险点**：无显式事务；但因为每次只修改一个 WordSense，单条失败不会造成级联问题。

## 4. enabled / bulkEnabled 边界

- **Archive（归档）语义**：`fsrs_enabled = false`。卡片不会进入复习队列。
- **Restore（恢复）语义**：`fsrs_enabled = true`。卡片重新进入复习队列。
- **是否物理删除**：否。只是标记字段。
- **是否保留 review logs**：是。ReviewLog 仅与 `review_card_id` 关联，不受 `fsrs_enabled` 影响。
- **批量操作与单条操作是否共用逻辑**：
  - 单条：`findManageableSenseCard()` + `mutationService->setEnabled()`。
  - 批量：手工查询 + `DB::transaction` 逐卡 + 跳过不匹配 ID。
  - **两者不共用查询逻辑**。批量有自己的安全查询（user_id/language_id/target_type/sense confirmed）。
- **风险点**：
  - 批量操作的事务会锁住所有匹配的卡片行（InnoDB 行级锁）。
  - 批量不限制 ids 数组大小，大数据量可能导致 PHP 内存超限或事务超时。
  - 批量跳过不匹配 ID 时不报告具体哪个 ID 跳过，只给总数。

## 5. dueNow / reset 边界

### dueNow
- **影响字段**：仅 `fsrs_due_at = Carbon::now()`。
- **不清零**：不修改 `fsrs_enabled`、`fsrs_state`、`fsrs_stability` 等。
- **是否保留日志**：否。
- **事务**：无。单表 save。
- **风险点**：无（最低风险写操作）。

### reset
- **影响字段**：`fsrs_state='new'`, `fsrs_due_at=now()`, `stability=null`, `difficulty=null`, `reps=0`, `lapses=0`, `last_reviewed_at=null`, `fsrs_enabled=true`。
- **是否保留日志**：是。创建一条 `rating='reset', source='reset'` 的 ReviewLog，记录全部前后状态。
- **事务**：`ReviewCardService::resetCard()` 内部有 `DB::transaction` + `lockForUpdate()`。
- **权限隔离**：内部重新查询 user_id/language_id/target_type/sense confirmed。
- **风险点**：
  - **FSRS 记忆参数丢失**（stability/difficulty 清零）。
  - **强制解归档**（`fsrs_enabled=true`）—— 不会检查卡片原本是否被归档。
  - Controller 的 `findManageableSenseCard()` 和 `resetCard()` 内部做了两次身份校验（冗余安全）。

## 6. destroy / bulkDestroy 边界

### destroy（单卡彻底删除）
- **调用链**：`findManageableSenseCard()` → `wordSenseService->removeSenseFromReviewSystem($sense, true, false)`。
- **WordSense**：`status = REJECTED`（保留记录，不再出现在阅读页）。
- **ReviewCard**：`delete()`（硬删）。
- **ReviewLog**：默认**保留**（`$deleteReviewLogs = false`）。
- **ReviewCardManagement view bulk archive 列表**：卡片从管理页消失（因为删了）。
- **WordSenseOccurrence**：`auto_fsrs_allowed = false`, `review_card_id = null`。
- **EncounteredWord**：条件性 `setStage(2)`（仅当该 WordSense 的 encountered_word 处于 Learning 且再无其他已确认 sense）。
- **事务**：`DB::transaction` 包裹。
- **是否可恢复**：**否**。ReviewCard 硬删，WordSense 标记 REJECTED。

### bulkDestroy（批量彻底删除）
- **调用链**：手工逐卡查询 + `wordSenseService->removeSenseFromReviewSystem($sense, true)` 逐条。
- **与单卡区别**：不共用 `findManageableSenseCard()`。有自己的安全查询 + skip 计数。
- **权限隔离**：逐卡检查 user_id/language_id/target_type/sense confirmed。
- **事务**：`DB::transaction` 包裹整个 foreach。
- **批量安全保障**：已有确认列表显示（BulkDeleteList-1 实装）。

### removeSenseFromReviewSystem 底层行为

```
DB::transaction {
    sense.status = STATUS_REJECTED
    sense.save()
    
    if card = sense.reviewCard:
        if deleteReviewLogs (default false): 删除 ReviewLog
        if deleteReviewCard (default true): card->delete()
        else: card->fsrs_enabled = false; save()
    
    WordSenseOccurrence: auto_fsrs_allowed = false, review_card_id = null
    
    if deleteReviewCard: restoreEncounteredWordIfNoActiveSenses()
}
```

### findManageableSenseCard 底层行为

- `ReviewCard::where(id=rcId, user_id, language_id, target_type=TARGET_SENSE)->first()`。
- `WordSense::where(id=card->target_id, user_id, language_id, status=CONFIRMED)->first()`。
- 任一找不到则 `abort(404)`。
- 返回 `[$card, $sense]`。

## 7. 可拆分 Service 边界候选

**注意：以下只写候选方向，不进入实现。**

### 适合继续进 MutationService 的操作

| 操作 | 评估 | 理由 |
|------|------|------|
| `update()` | ✅ 已抽入 MutationService（1B 完成） | WordSense 文本编辑 + normalizeArray |
| `enabled()` | ✅ 已抽入 MutationService（1A 完成） | 单字段 save |
| `dueNow()` | ✅ 已抽入 MutationService（1A 完成） | 单字段 save |
| `reset()` | ⏳ 已委托 ReviewCardService | 涉及事务 + ReviewLog，边界安全 |
| `destroy()` | ⏳ 已委托 WordSenseService | 涉及多表 + 事务 + EncounteredWord |
| `bulkEnabled()` | ❓ 未抽取 | 逐卡查询 + 事务 + skip 计数 |
| `bulkDestroy()` | ❓ 未抽取 | 逐卡查询 + 事务 + skip 计数 |
| `findManageableSenseCard()` | ⛔ 保持 Controller 私有 | 编排层辅助，不应暴露 |

### 候选提取方向

**方向 A：提取 bulkEnabled 到 MutationService**

当前 bulkEnabled 已在 Controller 中内联实现（逐卡查询 + 事务 + save）。可以迁入 MutationService 做一个批量方法，但需要处理：
- ids 数组输入
- 逐卡权限过滤
- skip/affected 计数
- 返回结构（目前返回 affected/skipped/enabled）

**方向 B：提取 bulkDestroy 到 MutationService**

当前 bulkDestroy 已在 Controller 中内联。迁入 MutationService 需要考虑：
- 与 `removeSenseFromReviewSystem()` 的调用关系
- 事务边界（现有最外层 tx + 内部 tx = savepoint，无大问题）
- skip 计数
- 已实装的确认列表保护（前端）

**方向 C：reset / destroy / bulk 的抽取前置条件**

reset/destroy/bulk 有以下共同点：
- 都涉及 `DB::transaction`
- 都涉及 WordSense 或 ReviewCard 的不可逆操作
- 都涉及 skip/affected 计数逻辑
- 都有前端确认弹窗保护

建议抽取策略：
1. **最低风险**：先把 `bulkEnabled()` 的内联逻辑迁入 MutationService。
2. **中等风险**：把 `bulkDestroy()` 的内联逻辑迁入 MutationService（保留 `removeSenseFromReviewSystem` 接口不变）。
3. **高风险**：reset/destroy/bulk 都保持当前委托方式到独立 Service，**不迁入 MutationService**，因为它们已经调用了 `ReviewCardService` / `WordSenseService`，不需要再包装一层。

## 8. 后续实现前置条件

在进入真正的 Service Extraction 前，必须满足：

1. **CodeBuddy 事实复核**：本轮报告由 CodeBuddy 复核，确认无遗漏或无错误描述。
2. **WorkBuddy 产品风险复核**：逐操作确认弹窗文案、通知文案、用户风险提示。
3. **MCP Chrome 真实测试脚本**：确认每个操作的浏览器端行为（特别是批量 skip、空 ids 等边界）。
4. **不清库**：所有测试用现有数据。
5. **不 migrate:fresh / db:wipe**。
6. **不 DCP**。
7. **不越界改动**：每个 Phase 只能改允许文件列表中的文件。
8. **删除类逻辑必须独立 Phase**：reset/destroy/bulk 不能与字段编辑混在同一任务。
