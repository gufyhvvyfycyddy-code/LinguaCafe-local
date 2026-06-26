# FSRS-D4-d-scout：FSRS 重排撤销机制侦察

> **创建日期**：2026-06-26
> **起始 commit**：`b857c79`
> **D.4-d-a 实现**：✅ 已完成（`1f29313` 后新增）— 快照表 schema + 创建快照记录
> **D.4-d-b 实现**：✅ 已完成（`bfb06c3` 后新增）— 撤销后端 API（POST /settings/fsrs/reschedule-undo）
> **D.4-d-b-fix 实现**：✅ 已完成（`8063bd1` 后新增）— 补全事务内二次校验（target_type/fsrs_enabled/undone/previous_*）+ 过期提示语义区分
> **D.4-d-c 实现**：✅ 已完成（`4cdf5e8` 后新增）— 前端撤销按钮 + 确认弹窗（AdminReviewSettings.vue +141 行）
> **D.4-d-c-fix 实现**：✅ 已完成（`4f2d77e` 后新增）— 修复成功提示被 v-if 隐藏 + 修正"确认后不可撤销"旧文案 + 写入网状协作报告门禁规则
> **D.4-d-e 验收**：✅ 已完成（`c9393e1` 后新增）— 真实浏览器 smoke + 全链路验收 + 最小文案收口（合并重复段落）；158 tests ✅
> **D.4-final-review**：✅ 已完成（`517de2f` 后新增）— undo 阶段已通过最终 review，可收口。真实浏览器点击链路仍建议人工补验
> **关联**：[fsrs-reschedule-confirm-scout.md](./fsrs-reschedule-confirm-scout.md)、[fsrs-reschedule-real-smoke-report.md](./fsrs-reschedule-real-smoke-report.md)

---

## 1. 侦察结论摘要

**推荐方案 C：快照表（reschedule_snapshots + reschedule_snapshot_items）**。

理由：

- 当前 `confirmAndApply` 只写 3 个 ReviewCard 字段（`fsrs_due_at`、`fsrs_stability`、`fsrs_difficulty`），快照极小。
- ReviewLog 用于 stats 和 optimizer 训练数据，写入 `source=reschedule` 会污染 review history。
- 快照表与 ReviewLog 完全解耦，零污染风险。
- 只撤销最近一次重排，stack 式（LIFO），简单可控。
- 已复习的卡自动跳过，避免 FSRS 状态不一致。

不建议立即开始实现。建议先完成 D.4-d-scout（本轮），再按分阶段步骤进入开发。

---

## 2. 当前代码事实

### 2.1 confirmAndApply 写哪些字段

| 字段 | 是否写入 | 说明 |
|------|----------|------|
| `fsrs_due_at` | ✅ 写入 | 重新计算到期日 |
| `fsrs_stability` | ✅ 写入 | 重新计算稳定度 |
| `fsrs_difficulty` | ✅ 写入 | 重新计算难度 |
| `fsrs_state` | ❌ 不写 | 保持 `review` |
| `fsrs_reps` | ❌ 不写 | 保持原值 |
| `fsrs_lapses` | ❌ 不写 | 保持原值 |
| `fsrs_last_reviewed_at` | ❌ 不写 | 保持原值 |
| `fsrs_enabled` | ❌ 不写 | 保持原值 |
| `target_type` / `target_id` | ❌ 不写 | 不变 |

**撤销只需恢复 3 个字段**：`fsrs_due_at`、`fsrs_stability`、`fsrs_difficulty`。

### 2.2 事务与锁

- `confirmAndApply` 使用 `DB::transaction()` + `lockForUpdate()`
- 当前没有使用 chunkById。它先从 preview 数据中取得 `$candidateIds`，然后在事务中通过 `ReviewCard::whereIn('id', $candidateIds)->lockForUpdate()->get()->keyBy('id')` 锁定候选卡，再按 `candidateIds` 顺序逐张重算和写入
- 最大变更上限：`getMaxTotalChanged()` = 2000（一次事务的最大写入规模受此控制）

### 2.3 ReviewLog 当前角色

| source 值 | 用途 | 被 optimizer/stats 过滤？ |
|-----------|------|---------------------------|
| `sense_review` | 正常复习 | 否（计入 stats 和训练数据） |
| `review` | 默认值 | 否（计入 stats） |
| `reset` | 重置卡片 | ✅ 是（`source != 'reset'`） |

**关键发现**：`source='reset'` 在 `SettingsService.php` 和 `ReviewStatsService.php` 中被显式过滤（`->where('review_logs.source', '!=', 'reset')`）。任何新增的 source 值（如 `reschedule`）**不会自动被过滤**，需要手动添加过滤条件。

### 2.4 D.4-c-e 验收事实

- `FsrsRescheduleRealSmokeTest`（7 测试，42 断言）验证了完整链路
- 确认不写 ReviewLog、reps/lapses/last_reviewed 不变
- 确认 word/disabled/ai_suggested/rejected/other_user 卡不受影响

---

## 3. 方案对比

| 维度 | 方案 A：不做撤销 | 方案 B：ReviewLog 撤销 | 方案 C：快照表 | 方案 D：Setting JSON |
|------|------------------|----------------------|----------------|---------------------|
| **复杂度** | 零 | 中 | 中低 | 低 |
| **新表/迁移** | 无 | 无（用现有表） | 1-2 张新表 | 无 |
| **数据量上限** | — | 无上限 | 2000 行/次 | 受 Setting text 列限制 |
| **ReviewLog 污染** | 无 | **高风险**（必须显式过滤 `reschedule`） | 无（独立表） | 无 |
| **optimizer 污染** | 无 | **高风险**（Stats 查询也会计入） | 无 | 无 |
| **已复习卡跳过** | — | 可行 | 可行 | 可行 |
| **多次重排支持** | — | 困难（需 batch_id） | 容易（batch_id） | 只存最后一次 |
| **历史可见性** | 无 | 有（ReviewLog 可见） | 需额外查询 | 无 |
| **事务安全** | — | 同 snapshot | `lockForUpdate` | 无事务 |
| **推荐度** | ❌（用户要求撤销） | ❌（ReviewLog 污染） | ✅ **推荐** | ⚠️（仅适合小数据） |

### 方案 A：不做撤销
- 最简单，零代码变更
- 用户误操作后不可恢复
- 当前 UI 已显示 3 次"不可撤销"警告
- **结论**：用户明确要求撤销能力，不可接受

### 方案 B：使用 ReviewLog 记录重排，再从 ReviewLog 撤销
- 不新增表，复用现有 ReviewLog
- **致命问题**：ReviewLog 被 optimizer/stats 查询使用。写入 `source='reschedule'` 后，必须修改所有 ReviewLog 查询来过滤新 source 值。`SettingsService.php` 中 5 处 ReviewLog 查询、`ReviewStatsService.php` 中 2 处都需要更新。
- 即使只过滤 `source != 'reschedule'`，未来开发者也容易忘记过滤
- 长期维护复杂度高
- **结论**：不推荐。风险远超收益。

### 方案 C：快照表（推荐）
- 独立表，零 ReviewLog 污染
- 无需修改现有 ReviewLog 查询
- 撤销逻辑简单直接：读取快照 → 恢复字段
- 支持 batch_id，可追踪多次重排
- 每批最多 2000 行，数据量可控
- 最小 schema 只需 3 个字段 + batch_id
- **结论**：最稳妥方案

### 方案 D：Setting JSON 快照
- 将最近一次重排的快照存入 settings 表的 JSON 字段
- **不可行**：单次重排最多 2000 张卡，JSON 序列化后体积过大（2000 × 3 字段 × UUID ≈ 100KB+），可能超出 text 列承载范围
- 只保存最近一次，不支持历史记录
- 无事务保护
- **结论**：不可行，仅适合极少量数据

---

## 4. 推荐方案：快照表（方案 C）

### 为什么推荐快照表

1. **零污染**：与 ReviewLog 完全隔离，不影响 stats、optimizer、学习历史
2. **最小化**：只需保存 3 个字段（due_at, stability, difficulty），因为这就是 `confirmAndApply` 改的全部内容
3. **batch 语义清晰**：一次重排 = 一个 batch，每张卡 = 一个 item
4. **撤销原子性**：同一次 undo 请求中，进入 restore set 的卡片要么全部恢复，要么全部回滚；已复习或不可恢复的卡片在事务前计入 skipped，不影响原子性
5. **已复习卡可跳过**：检查 `fsrs_last_reviewed_at > batch.created_at` 即可
6. **与现有架构一致**：不改变 ReviewLog 的设计契约（仅记录人工复习和 reset）
7. **易于审计**：快照表本身就是完整的审计轨迹

---

## 5. 最小 schema 草案

### reschedule_snapshots（批次头表）

| 列名 | 类型 | 说明 |
|------|------|------|
| `id` | bigint PK | |
| `user_id` | bigint FK | 操作用户 |
| `language_id` | varchar(10) | 语言（撤销只影响当前语言） |
| `batch_id` | varchar(64) | UUID，标识一次 reschedule 操作 |
| `preview_hash` | varchar(64) | 重排时的 preview_hash（用于审计/去重） |
| `total_cards` | int | 本次重排变更的卡片总数 |
| `applied_count` | int | 实际执行变更数（排除 skipped） |
| `newly_due_today` | int | 重排后今天到期的卡片数 |
| `created_at` | timestamp | 快照创建时间（= 重排执行时间） |

### reschedule_snapshot_items（明细表）

| 列名 | 类型 | 说明 |
|------|------|------|
| `id` | bigint PK | |
| `snapshot_id` | bigint FK → reschedule_snapshots.id | 所属批次 |
| `review_card_id` | bigint FK → review_cards.id | 被变更的卡片 |
| `previous_due_at` | timestamp nullable | 重排前的到期日 |
| `previous_stability` | double | 重排前的稳定度 |
| `previous_difficulty` | double | 重排前的难度 |
| `undone` | boolean default false | 是否已撤销 |
| `undone_at` | timestamp nullable | 撤销时间 |

### 为什么不合并为一张表

- 批次头表存储元数据（preview_hash、统计、时间戳），只一行
- 明细表存储每张卡的旧值，每行一条
- 头表独立便于查找"最近一次重排"（`WHERE user_id=? AND language_id=? ORDER BY created_at DESC LIMIT 1`）
- 明细表按 snapshot_id 分组，撤销时用 `batch_id` 或 `snapshot_id` 关联
- 两张表符合第三范式，查询高效

---

## 6. 撤销边界规则

| 规则 | 说明 |
|------|------|
| **只撤销当前用户** | `WHERE user_id = auth()->id()` |
| **只撤销当前语言** | `WHERE language_id = current_language` |
| **只撤销 sense card** | `target_type = 'sense'`（不做 undo word card） |
| **不碰 word card** | 重排不改变 word card，撤销也不应涉及 |
| **已复习卡跳过** | `fsrs_last_reviewed_at > batch.created_at` 的卡不恢复 |
| **只撤销最近一次** | `ORDER BY created_at DESC LIMIT 1` |
| **不污染 ReviewLog** | 撤销时也不写 ReviewLog |
| **不影响 optimizer** | 不修改任何 ReviewLog/optimizer 相关表 |
| **事务保护** | 使用 `DB::transaction` + `lockForUpdate` |
| **原子性** | restore set 内的卡片要么全部恢复，要么全部回滚；已复习卡在事务前判定为 skipped，不进入 restore set |

### 已复习卡跳过策略

推荐 **"部分跳过"** 方案：

- 一批卡中，如果部分已被用户复习，跳过这些卡
- 只撤销未复习的卡
- 撤销成功后显示："已恢复 X 张卡片，跳过 Y 张（已复习）"
- 如果所有卡都已复习，全部跳过，显示错误消息并停止
- **不推荐**：全部拒绝（用户体验差）、让用户选择（增加复杂度）

---

## 7. UI / 产品流程

### 撤销入口

推荐放在 **成功提示消息旁** 和 **FSRS 高级工具区域**：

```
[成功提示] 已重排 X 张卡片，其中 Y 张今天到期    [撤销上次重排]
```

撤销按钮条件：
1. 用户有最近一次未撤销的重排（`SELECT 1 FROM reschedule_snapshots WHERE ... ORDER BY created_at DESC`）
2. 最近一次重排距今不超过 N 天（建议 N=7 天，或不过期）

### 撤销确认流程

1. 点击"撤销上次重排"
2. 弹出确认对话框，显示：
   - "将恢复 X 张卡片到重排前的状态"
   - "其中 Y 张卡片因已被复习，不会恢复"
   - "此操作不可再次撤销"
3. 3 秒倒计时后启用确认按钮
4. 点击"确认撤销"
5. 成功显示："已恢复 X 张卡片，跳过 Y 张（已复习）"
6. 失败显示错误原因

### 不允许撤销时

| 场景 | 提示文案 |
|------|----------|
| 没有可撤销的重排 | "当前没有可撤销的重排操作" |
| 所有卡都已复习 | "上次重排涉及的所有卡片均已被复习，无法撤销" |
| 重排记录已过期 | "重排操作已超过可撤销期限" |

---

## 8. 测试验收计划

### 自动化测试（PHPUnit）

| # | 测试 | 优先级 |
|---|------|--------|
| 1 | 重排时创建快照 → 验证 snapshot_items 数量 = changed_cards | P0 |
| 2 | 撤销成功 → 验证 due/stability/difficulty 恢复为旧值 | P0 |
| 3 | 撤销后 reps/lapses/last_reviewed 不变 | P0 |
| 4 | 撤销不写 ReviewLog | P0 |
| 5 | 已复习的卡跳过撤销 | P0 |
| 6 | 只允许撤销最近一次（再次调用失败） | P0 |
| 7 | 其他用户不能撤销 | P0 |
| 8 | 其他语言不影响 | P1 |
| 9 | 重复撤销返回错误 | P1 |
| 10 | 事务失败时全部回滚 | P1 |

### 浏览器 smoke

| # | 测试 | 优先级 |
|---|------|--------|
| 1 | 重排成功后出现撤销按钮 | P0 |
| 2 | 撤销确认弹窗显示正确统计 | P0 |
| 3 | 撤销成功后显示正确消息 | P0 |
| 4 | 撤销后按钮消失或禁用 | P0 |
| 5 | 已复习卡跳过时显示正确消息 | P1 |
| 6 | Console 无错误 | P0 |
| 7 | Network 无重复触发 | P0 |

---

## 9. 分阶段开发建议

### D.4-d-a：快照表 schema + 创建快照（只读记录）

**范围**：
- 新增 migration（`reschedule_snapshots` + `reschedule_snapshot_items`）
- 在 `confirmAndApply` 中，写入 ReviewCard **之前**，先创建快照记录
- 快照在同一个事务中写入，失败则全部回滚
- 不实现撤销路由、控制器、前端
- 新增 `FsrsRescheduleSnapshotService`（只读写入）

**测试**：
- 创建快照条目数与变更卡数一致
- 快照字段与原值一致
- 不写 ReviewLog
- 不写快照时不影响重排（如果快照写入失败，重排也应回滚）

### D.4-d-b：撤销后端 API

**范围**：
- 新增 `POST /settings/fsrs/reschedule-undo` 路由
- 新增 `SettingsController::rescheduleUndo()` 方法
- `FsrsRescheduleSnapshotService::undo()` 方法
- 实现撤销逻辑：读取快照 → 验证 → 恢复字段 → 标记 undone
- 实现边界规则（只撤销最近一次、已复习卡跳过、锁保护）

**测试**：
- 全部 P0 自动化测试
- 事务安全测试

### D.4-d-c：撤销前端按钮 + 确认弹窗

**范围**：
- `AdminReviewSettings.vue` 新增"撤销上次重排"按钮
- 撤销确认弹窗（复用现有倒计时模式）
- 成功后消息显示
- 各种边界状态处理

**测试**：
- 浏览器 smoke 全部 P0

### 不推荐 "先做历史可见性，再做撤销"

理由：历史可见性依赖快照表（D.4-d-a），而撤销依赖快照表 + 回滚逻辑（D.4-d-b）。用户真正需要的是撤销能力，历史可见性只是辅助。**最小可行 = 创建快照 + 撤销 API + 前端按钮**，不需要额外"历史可见性"功能。成功后提示和撤销入口已在 UI 流程中涵盖。

---

## 10. 风险与禁止事项

### 禁止

1. ❌ 禁止使用 ReviewLog 做撤销
2. ❌ 禁止写 ReviewLog 的 `source` 为 `reschedule` 或 `reschedule_undo`
3. ❌ 禁止修改 optimizer 查询来过滤新的 ReviewLog source 值（因为根本不写）
4. ❌ 禁止 undo 已经用户复习过的卡
5. ❌ 禁止 undo 非最近一次重排
6. ❌ 禁止 undo 非当前用户/非当前语言的卡
7. ❌ 禁止使用 `--force`、`--force-with-lease`、`amend`、`rebase`

### 风险提示

1. ⚠️ 快照表会随每次重排增长。按每批 2000 行、每条约 80 字节计算，100 次重排约 16MB。无需过期清理，但可考虑 90 天后自动归档
2. ⚠️ 撤销事务中 `lockForUpdate` 可能短时间锁定卡片，影响并发复习。撤销是低频操作，可接受
3. ⚠️ `confirmAndApply` 中写入快照会增加事务持续时间，但写入 2000 行快照（批量 insert）通常 < 100ms

---

## 11. 最终建议

1. **确认方案 C（快照表）为推荐方案**
2. **按以下顺序进入开发**：
   - D.4-d-a：快照表 schema + 创建快照（1-2 天）
   - D.4-d-b：撤销后端 API（1-2 天）
   - D.4-d-c：撤销前端按钮 + 确认弹窗（1 天）
3. **本质仍不写 ReviewLog，不影响 optimizer**
4. **进入 D.4-d-a 前需用户确认是否新增 migration**

### 下一步 OpenCode 任务建议

```
任务：D.4-d-a — 重排快照表 schema + 创建快照
内容：
1. 新增 migration（reschedule_snapshots + reschedule_snapshot_items）
2. 新增 FsrsRescheduleSnapshotService
3. 在 FsrsReschedulePreviewService::confirmAndApply 中创建快照
4. 不新增路由/控制器/前端/撤销
5. 测试：快照条目匹配变更卡、字段正确、不写 ReviewLog
6. 仍不写 ReviewLog，不影响 optimizer
7. DCP_ALLOWED=false

**前置条件**：D.4-d-a 开始前，必须以当前 `whereIn + lockForUpdate + get + keyBy + foreach` 事务模型为基线，**不得假设已有 chunkById**。若要改为 chunkById 遍历，需要单独 scout 或单独任务评估，不应在 D.4-d-a 顺手改。快照 item 写入应发生在 ReviewCard 字段更新之前，同一事务内完成。

## 网状协作报告门禁规则（所有后续任务必须遵守）

1. 如果任务使用多个辅助 Agent 或背景泳道，辅助报告必须在 commit 之前完成。
2. 主执行 Agent 必须在最终 diff 和 commit 前整合辅助报告。
3. 如果辅助 Agent 仍显示 running，主执行 Agent 不得 commit。
4. 如果辅助报告无法及时完成，本轮必须明确写：
   “某某辅助报告未纳入本轮实现依据。”
   并停止等待人工判断，不能假装已经整合。
5. 最终报告中禁止写“背景泳道运行中”同时又宣布任务完成。
6. commit 前报告必须包含：
   * 已收到哪些辅助报告
   * 哪些建议被采纳
   * 哪些建议未采纳及原因
7. 这个规则以后适用于所有网状协作任务。
```
