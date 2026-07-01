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
| `bulkEnabled()` | ✅ 已抽入 MutationService（Complex-1 完成） | `bulkSetEnabled()` 方法，Controller 只保留校验/组装 |
| `bulkDestroy()` | ✅ 已抽入 MutationService（Phase20-1 完成） | `bulkDestroy()` 方法，共享 `findManageableSenseCardForMutation()` helper |
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

## 9. bulkEnabled 抽取前契约锁定

> 本节只锁定契约，不进入实现。下一轮若 CodeBuddy 复核通过，可进入 bulkEnabled 最小实现。

### 9.1 输入契约

| 字段 | 类型 | 说明 |
|------|------|------|
| `ids` | `int[]` | 要批量操作的交卡 ID 列表，必填 |
| `enabled` | `bool` | `true` = 恢复，`false` = 归档 |

- **ids 为空时**：返回 422，`{"message": "请选择至少一张复习卡。"}`。
- **ids 含不存在 / 不属于当前用户 / 非 sense card / 非 confirmed sense 的卡**：跳过，计入 `skipped`，不报错。**保留当前 skip 语义**。
- **重复 ids**：允许。每条 id 都会尝试查询；重复成功 id 会被多次切换 fsrs_enabled（幂等操作，无副作用）。
- **ids 上限**：当前不限制。若未来遇到性能问题，可考虑在 Controller 层校验。第一轮实现不新增限制，与当前行为一致。
- **输入校验**：`is_array($ids)` 且 `!empty($ids)` 否则 422。

### 9.2 输出契约

**成功时 HTTP 200：**

```json
{
  "affected": 3,
  "skipped": 1,
  "enabled": false,
  "message": "已归档 3 张复习卡。它们不会进入日常复习。"
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `affected` | `int` | 实际成功切换的卡片数 |
| `skipped` | `int` | 被跳过（不匹配权限/类型/状态）的卡片数 |
| `enabled` | `bool` | 本次操作的目标状态 |
| `message` | `string` | 用户可见提示文案 |

- **不新增字段**。
- **不删除现有字段**。
- **不改变 HTTP status code**。
- 文案在 Controller 中组装（因涉及语言国际化），Service 不返回文案。

### 9.3 权限和过滤契约

必须保持以下全部安全条件：

1. **user_id** 隔离：`card.user_id === Auth::user()->id`。
2. **language_id** 隔离：`card.language_id === Auth::user()->selected_language`。
3. **target_type**：`card.target_type === ReviewCard::TARGET_SENSE`。
4. **WordSense 确认状态**：`card.sense.status === WordSense::STATUS_CONFIRMED`，通过 `whereHas` 检查。
5. **legacy word cards 不受影响**：`TARGET_SENSE` 条件自动排除 `TARGET_WORD` 类型的旧卡。
6. **其他用户卡片不受影响**：`user_id` 条件自动隔离。

### 9.4 事务契约

| 项目 | 当前行为 | 第一轮实现 |
|------|----------|-----------|
| 是否使用 DB::transaction | ✅ 是 | ✅ 保持 |
| 事务范围 | 覆盖整个 foreach | 保持 |
| 行锁 | 无 | 第一轮不加 |  
| 部分成功语义 | ✅ 跳过不匹配 ID，其他照常 | 保持 |
| 失败回滚 | 匹配的卡已保存（逐条 save 在事务内） | 保持 |

- 当前事务逐条查询 + 逐条 save，不锁整表。
- 跳过不会触发回滚，只是不处理该 ID。
- 如果某条 save 失败，整个事务回滚（含之前已 save 的卡片）。

### 9.5 实现记录（ReviewCardManage-MutationService-Complex-1 完成）

> `bulkEnabled()` 已在本轮抽取到 MutationService。以下为实际实现记录：

**Service 方法签名**：

```php
public function bulkSetEnabled(array $ids, bool $enabled, int $userId, string $language): array
// Returns ['affected' => int, 'skipped' => int]
```

**Controller 职责（保留）**：
- request 校验（空数组 422）
- Auth user / language 获取
- 调用 service
- message 文案组装（affected 数量嵌入）
- response JSON 组装

**保持不变的契约**：
- user_id / language_id / target_type=TARGET_SENSE / WordSense confirmed 过滤
- DB::transaction 包裹
- skipped 语义
- response shape: affected/skipped/enabled/message

**后续验收闭环（ReviewCardManage-ComplexSmokeAndCompliance-1 完成）**：
- MCP Chrome 真实验收：批量归档 → "已归档 N 张复习卡。它们不会进入日常复习。"；批量恢复 → "已恢复 N 张复习卡。它们会重新进入日常复习。"；dueNow → "已设为立即到期。该卡会进入复习队列。"；reset 弹窗 → 标题"重置复习进度"，按钮"确认重置复习进度"；批量删除弹窗 → 显示 lemma/zh 列表。
- skipped > 0 场景：MCP Chrome 无法构造（需要混合其他用户卡 ID，UI 不支持跨用户选择），已由自动测试 `test_bulk_enabled_skips_other_user_cards` 等覆盖。
- destroy/bulkDestroy 核心删除语义仍未改，下一阶段才进入独立 Phase。

**UX 反馈补强（本轮同时完成）**：
- bulkEnabled skipped>0 时 snackbar 追加 "其中有 N 张跳过处理。"
- bulkDestroy skipped>0 时 snackbar 追加 "其中有 N 张跳过处理。"
- dueNow 成功后显示 "已设为立即到期。该卡会进入复习队列。"
- reset 文案统一为"重置复习进度"（弹窗标题、正文、按钮、Controller message 均已更新）
- destroy / bulkDestroy 核心删除语义未改
- reset 核心 FSRS 语义未改

### 9.7 实现记录（ReviewCardManage-BulkDestroyPhase20-1 完成）

> `bulkDestroy()` 已在本轮抽取到 MutationService。共享权限查询 helper 已提取。

**Service 方法签名**：

```php
public function bulkDestroy(array $ids, int $userId, string $language, WordSenseService $wordSenseService): array
// Returns ['deleted' => int, 'skipped' => int]
```

**Controller 职责（保留）**：
- request 校验（空数组 422）
- Auth user / language 获取
- 调用 service（传入 `$this->wordSenseService`）
- message 文案组装（deleted 数量嵌入）
- response JSON 组装

**共享 helper**（MutationService 内部 private）：

```php
private function findManageableSenseCardForMutation(int $id, int $userId, string $language): ?ReviewCard
```

**保持不变的契约**：
- user_id / language_id / target_type=TARGET_SENSE / WordSense confirmed 过滤
- DB::transaction 包裹
- deleted/skipped 语义
- response shape: deleted/skipped/message
- 核心语义未改：ReviewCard 硬删、WordSense rejected、ReviewLog 保留、WordSenseOccurrence 清关联、EncounteredWord 条件性恢复
- 仍调用 WordSenseService::removeSenseFromReviewSystem()

**自动测试补强**（6 个新测试）：
- `test_bulk_destroy_response_shape_after_extraction` — 抽取后 response shape 不变
- `test_bulk_destroy_deletes_review_card` — 卡片被硬删
- `test_bulk_destroy_rejects_word_sense` — WordSense 标记 REJECTED
- `test_bulk_destroy_preserves_review_logs_after_extraction` — ReviewLog 保留
- `test_bulk_destroy_skips_other_user_card_after_extraction` — 其他用户隔离 + skipped 正确
- `test_bulk_enabled_regression_with_shared_helper` — bulkSetEnabled 回归（共享 helper 未破坏归档/恢复）

**MCP Chrome 验收**：
- 管理页 25 张测试卡可见（lemma 前缀 `smoke_p20_`）
- 批量彻底删除弹窗显示 lemma/zh 列表（20 条，与超限提示逻辑保持）
- 未执行真实删除

**测试数据保留策略**：
- 25 张 `smoke_p20_*` 测试卡保留给 WorkBuddy 后置验收
- 清理命令：`php cleanup_phase20_smoke_data.php`
- 数据文件：`.phase20_smoke_card_ids.json`

### 9.6 下一轮测试验收草案（历史保留）

下一轮实现时至少需通过以下验收：

| # | 场景 | 期望 |
|---|------|------|
| 1 | 批量归档 selected ids | 200，affected>0，卡片 fsrs_enabled=false |
| 2 | 批量恢复 selected ids | 200，affected>0，卡片 fsrs_enabled=true |
| 3 | ids 含无效卡（不存在/其他用户/非 sense/非 confirmed） | skipped 增加，affected 不变 |
| 4 | ids 为空数组 | 422 |
| 5 | 重复有效 ids | 每张都被计数（幂等），affected=len(unique_ids) |
| 6 | legacy word card 不受影响 | 过滤正确，不会影响 TARGET_WORD 卡 |
| 7 | 其他用户卡片不受影响 | user_id 条件过滤，不会修改 |
| 8 | response shape 不变 | JSON 含 affected/skipped/enabled/message |
| 9 | 归档后不进入复习队列 | 复习接口不再返回该卡 |
| 10 | 恢复后重新进入复习队列 | 复习接口重新返回该卡 |
| 11 | MCP Chrome 批量归档 | 选中多卡 → 批量归档弹窗 → 确认 → snackbar 提示 → 卡片消失 |
| 12 | MCP Chrome 批量恢复 | 筛选 disabled 卡 → 选中多卡 → 批量恢复弹窗 → 确认 → snackbar 提示 |

## 10. reset / destroy / bulkDestroy 高风险写操作契约锁定

> 本节只锁定契约，不进入实现。删除类逻辑必须独立 Phase，每轮必须 MCP Chrome 真实验收。

### 10.1 reset 契约

#### 输入契约

- **输入**：reviewCard id（路径参数）。
- **权限过滤**：`findManageableSenseCard()` 保证 user_id / language_id / target_type=TARGET_SENSE / WordSense status=CONFIRMED。
- **不处理**：legacy word card（TARGET_WORD 类型）、已拒绝的 sense、其他用户卡片。
- **越权/不存在**：404。

#### 数据影响契约

reset 会影响以下 `review_cards` 字段：

| 字段 | 变化 |
|------|------|
| `fsrs_state` | → `'new'` |
| `fsrs_due_at` | → `now()` |
| `fsrs_stability` | → `null` |
| `fsrs_difficulty` | → `null` |
| `fsrs_reps` | → `0` |
| `fsrs_lapses` | → `0` |
| `fsrs_last_reviewed_at` | → `null` |
| `fsrs_enabled` | → `true`（强制解归档） |

**不修改**：WordSense 文本、EncounteredWord、ReviewCard 记录本身不删除。

**新增 ReviewLog**：是。会创建一条 `rating='reset', source='reset'` 的 ReviewLog，记录全部 FSRS 前后状态。

**不可逆后果**：
- FSRS 稳定性/难度参数丢失。
- 被归档的卡片会强制重新进入复习队列。
- 现有 ReviewLog 保留（日志保留，不删除）。

#### UX 契约

- **文案固定**：`"消息" => "已重置复习进度。该卡会重新进入复习队列。"`。
- **弹窗说明**：清空该卡的复习进度（FSRS 记忆参数），不会删除释义、例句、复习历史。
- **不使用**："重新开始学习"、"归档"、"彻底删除" 等混淆术语。
- **不与归档操作在同一 UI 分组内并列**（重置弹窗和归档弹窗应有文案区分）。

#### 后续实现边界

下一轮如果优化 reset：
- 只允许优化 Controller 编排或文案补强。
- 不得改变 `ReviewCardService::resetCard()` 的事务语义。
- 不得改变 ReviewLog 保留策略。
- 不得删除或新增 ReviewCard 字段。
- 不得与 destroy / bulkDestroy 同轮编码。
- 不改 FSRS 算法。

### 10.2 destroy 契约

#### 输入契约

- **输入**：reviewCard id（路径参数）。
- **权限过滤**：`findManageableSenseCard()` 保证 user_id / language_id / target_type / WordSense status=CONFIRMED。
- **越权/不存在**：404。

#### 数据影响契约

| 模型 | 操作 |
|------|------|
| ReviewCard | **硬删**（`delete()`） |
| WordSense | `status = STATUS_REJECTED`（保留记录，不出现在阅读页候选） |
| ReviewLog | **默认保留**（`$deleteReviewLogs = false`） |
| WordSenseOccurrence | `auto_fsrs_allowed = false`，`review_card_id = null` |
| EncounteredWord | **条件性**恢复为 New（`stage=2`），仅当该 encountered_word_id 再无其他已确认 sense 且当前为 Learning 状态 |

**删除链路**：`removeSenseFromReviewSystem($sense, deleteReviewCard=true, deleteReviewLogs=false)`
**不可恢复**：ReviewCard 硬删，WordSense 标记 REJECTED。

#### UX 契约

- **文案固定**：`"已彻底删除词义复习卡。该释义不会再出现在阅读页，阅读记录和复习历史已保留。"`。
- **必须有 confirm 弹窗**（已在前端实现）。
- **弹窗说明**：此操作不可恢复，不会只是不进入复习，而是会从复习系统移除。
- **弹窗显示待删除 lemma / 中文释义**（BulkDeleteList-1 在批量弹窗已实现；单卡弹窗目前已有三段风险文案）。
- **不要求输入确认词**。
- **不与归档混淆**（归档不会删除卡片，只是不进入复习队列）。
- **不与重置混淆**（重置不会删除卡片，只是清除 FSRS 参数）。

#### 后续实现边界

下一轮如果优化 destroy：
- **必须独立 Phase**，不与 reset 同轮编码。
- 不得改变 `removeSenseFromReviewSystem` 的核心语义。
- 不得删除 ReviewLog。
- 不得改变 EncounteredWord 条件性恢复逻辑。
- 不得清库。

### 10.3 bulkDestroy 契约

#### 输入契约

| 字段 | 类型 | 说明 |
|------|------|------|
| `ids` | `int[]` | 要批量删除的卡片 ID 列表，必填 |
| `enabled` | `bool` | 不用于删除接口 |

- **ids 为空时**：返回 422，`{"message": "请选择至少一张复习卡。"}`。
- **ids 含无效 / 越权 / 非 sense / 非 confirmed 的卡**：跳过，计入 `skipped`，不报错。**保留当前部分成功语义**。
- **重复 ids**：允许。每条 id 都会尝试查询 + 删除，重复 id 会在第二次遇到时跳过（已被删除）。
- **ids 上限**：当前不限制。若未来遇到性能问题，可考虑在 Controller 层限制。第一轮不新增。

#### 数据影响契约

对每张合法卡调用 `removeSenseFromReviewSystem($sense, true)`：

| 模型 | 每张卡操作 |
|------|-----------|
| ReviewCard | 硬删 |
| WordSense | `status = REJECTED` |
| ReviewLog | 默认保留 |
| WordSenseOccurrence | 清关联 |
| EncounteredWord | 条件性恢复 |

**不可恢复**：所有成功删除的卡均不恢复。

#### 输出契约

当前代码返回结构（Controller 内联实现）：

```json
{
  "deleted": 3,
  "skipped": 1,
  "message": "已彻底删除 3 张词义复习卡。对应释义不会再出现在阅读页，阅读记录和复习历史已保留。"
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `deleted` | `int` | 实际删除成功的卡片数 |
| `skipped` | `int` | 被过滤跳过（不匹配权限/类型/状态/已删除）的卡片数 |
| `message` | `string` | 用户可见提示文案 |

**注意**：bulkDestroy 的 `deleted` 字段名与 bulkEnabled 的 `affected` 不同。前端已适配当前字段名。后续抽取 Service 不得改变字段名。

#### UX 契约

- **已有确认列表保护**：BulkDeleteList-1 已实装，列表显示 lemma + 中文释义，最多 20 条，超限显示 "还有 N 张未显示"。
- **不要求输入确认词**。
- **必须有弹窗确认**。
- **文案必须和归档明显区分**：归档是 "不会进入日常复习"，删除是 "不可恢复，删除后不会出现在阅读页"。
- **文案必须和重置明显区分**：重置是 "清空复习进度"，删除是 "彻底移除"。

#### 后续实现边界

下一轮如果优化 bulkDestroy：
- **必须独立 Phase**。
- 不与 bulkEnabled 同轮编码。
- 不与 reset 同轮编码。
- 不得改变 `removeSenseFromReviewSystem` 核心语义。
- 不得改变 ReviewLog 保留策略。
- 不得新增清库 / migration / 数据重建。
- 不得改变 deleted/skipped/message 字段名。

### 10.4 实现顺序建议

| 编码轮次 | 内容 | 风险级别 | 理由 |
|---------|------|----------|------|
| 第 1 轮 | `bulkEnabled` 最小抽取到 MutationService | 低 | 可恢复，单字段 `fsrs_enabled`，已有契约锁定 |
| 第 2 轮 | `dueNow` 成功反馈或 reset UX 文案补强 | 中低 | 不改变行为，只改提示 |
| 第 3 轮 | `reset` 边界优化（编排或安全确认） | 中 | 丢 FSRS 参数，强制解归档，不可逆 |
| 第 4 轮 | `destroy` 单卡边界优化（安全确认） | 高 | ReviewCard 硬删，WordSense rejected，不可恢复 |
| 第 5 轮 | `bulkDestroy` 边界优化（安全确认） | 高 | 批量硬删，不可恢复 |

**原则**：
- 每轮都是独立 Phase，不合并高风险操作。
- 每轮必须 MCP Chrome 真实验收。
- 每轮必须由 CodeBuddy 复核 diff 范围，确保没越界。
- 删除类操作（destroy / bulkDestroy）必须走在最安全方向：不改核心 Semantics，只抽编排层。
