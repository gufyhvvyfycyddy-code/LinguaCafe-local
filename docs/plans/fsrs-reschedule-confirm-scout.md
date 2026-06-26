# FSRS-D4-c-scout：正式重排确认机制侦察

> **创建日期**：2026-06-26
> **关联计划**：[linguacafe-fsrs-roadmap.md](./linguacafe-fsrs-roadmap.md) → D.4
> **前置依赖**：D.4-a（预览后端只读）✅、D.4-b（预览前端接入）✅、D.4-b-fix（预览 UI 微调）✅
> **本侦察为只读分析，不实现功能**

---

## 1. 当前状态

### 已完成

| 阶段 | 状态 | 说明 |
|------|------|------|
| D.4-scout | ✅ 已完成 | 重排已有卡片可行性侦察 |
| D.4-a | ✅ 已完成 | 后端只读 preview API（POST /settings/fsrs/reschedule-preview） |
| D.4-b | ✅ 已完成 | 前端预览接入（高级工具面板 + 统计卡片 + samples 表格） |
| D.4-b-fix | ✅ 已完成 | 位置调整、按钮文案优化、skipped_count 提示 |
| D.4-c-a | ✅ 已完成 | 正式重排 confirm preflight API — preview_hash 校验 + 安全阈值 + write_enabled=false |
| D.4-c-b | ✅ 已完成 | 正式重排 ReviewCard 写入 — confirmAndApply() + risk_confirm + preview_hash 参数顺序修复 |
| D.4-c-d | ✅ 已完成 | 前端确认按钮 + 二次确认弹窗 + 风险确认流程 |
| D.4-c-d-fix | ✅ 已完成 | 成功提示保留 + 409 过期预览强制重新预览 |
| D.4-c-e | ✅ 已完成 | 真实数据联调与浏览器 smoke 总验收 — 7 测试 42 断言通过 |
| D.4-d-scout | ✅ 已完成 | 撤销机制侦察 — 推荐快照表方案 C，详见解锁文档 [fsrs-reschedule-undo-scout.md](./fsrs-reschedule-undo-scout.md) |
| D.4-d-a | ✅ 已完成 | 快照表 schema + 创建快照记录 — reschedule_snapshots + reschedule_snapshot_items + 写入集成 |
| D.4-d-b | ✅ 已完成 | 撤销后端 API — POST /settings/fsrs/reschedule-undo + undo 逻辑 + 16 测试 |
| D.4-d-c | ✅ 已完成 | 前端撤销按钮 + 确认弹窗 — AdminReviewSettings.vue 常驻按钮 + 3 秒倒计时弹窗 |
| D.4-final-review | ✅ 已完成 | confirm/apply 阶段已通过最终 review，可收口 |

### 还未做的事

- ❌ 没有写 ReviewLog（留到 D.4-d，但 D.4-d 方案 C 不写 ReviewLog）
- ❌ 没有撤销机制（D.4-d-scout 已完成，推荐 D.4-d-a/b/c 三步开发）

---

## 2. 正式重排的目标

### 用户视角

1. 用户先在高级工具中看到预览统计
2. 预览显示：总候选、会变化、会提前、会延后、今天新增到期等
3. 用户阅读风险提示后点击确认
4. 系统执行重排，更新卡片到期时间

### 技术范围

**处理**：
- 仅 english 语言
- 仅 sense review card（target_type = sense）
- 仅 fsrs_state = review 的卡
- 仅已 confirmed 的 WordSense
- 仅 FSRS 字段完整（stability / difficulty / last_reviewed_at / due_at 均不为 null）

**不处理**：
- ❌ 不处理 new / learning / relearning 状态的卡
- ❌ 不处理 disabled 或 unconfirmed / rejected sense
- ❌ 不处理缺 FSRS 记忆字段的卡
- ❌ 不处理非 english 语言
- ❌ 不处理 word / phrase target_type 的卡
- ❌ 不处理其他用户的卡

---

## 3. 数据写入方案候选

### 方案 A（✅ 推荐第一版）：只更新 ReviewCard，不写 ReviewLog

**做法**：
- 在 `DB::transaction()` 中，对每张候选卡执行：
  - `$card->fsrs_due_at = $previewDueAt`
  - `$card->fsrs_stability = $newStability`
  - `$card->fsrs_difficulty = $newDifficulty`
  - `fsrs_reps` 和 `fsrs_last_reviewed_at` **保持不变**
- 不创建任何 ReviewLog

**优点**：
- 完全无 ReviewLog 污染风险，optimizer 零改动
- 写入路径最短，测试最少
- 符合"重排不是一次复习"的语义

**风险**：
- 历史记录缺口：重排后缺少审计追踪（谁、何时、改变了什么）
- 不可撤销（除非手动记录）

**对 optimizer 的影响**：无。不写 ReviewLog，optimizer 查询不受影响。

**撤销难度**：高。没有 previous 值的记录，只能靠 guessing 或独立备份。

**第一版推荐**：✅ **推荐。** 最小可行方案，规避了所有 optimizer 污染风险。

### 方案 B（第二版推荐）：更新 ReviewCard + 写 source=reschedule 的 ReviewLog

**做法**：
- 同方案 A 更新 ReviewCard 字段
- 同时为每张被更新的卡创建 ReviewLog，记录：
  - `source = 'reschedule'`
  - `previous_due_at` / `new_due_at`
  - `previous_stability` / `new_stability`
  - `previous_difficulty` / `new_difficulty`
  - `rating = null`（或固定 'good'——**需注意污染问题**）

**优点**：
- 完整的审计轨迹，可追踪单张卡的重排历史
- 支持撤销（通过 previous_* 字段恢复）
- 支持 batch id 分组（新增字段或关联表）

**风险**：
- ⚠️ ** optimizer 污染**：当前 optimizer 查询只排除 `source != 'reset'`。`source = 'reschedule'` 会被纳入训练集。必须修改 4 处 optimizer 查询为 `whereNotIn('source', ['reset', 'reschedule'])`：
  1. `app/Services/SettingsService.php` ~line 238
  2. `app/Services/SettingsService.php` ~line 384
  3. `app/Services/SettingsService.php` ~line 581
  4. `app/Services/ReviewStatsService.php` ~line 102

**对 optimizer 的影响**：必须同步修改上述 4 处，否则 optimizer 训练数据被合成 rating 污染。

**撤销难度**：中。需要 batch id 和之前记录的 previous_* 值。

**第一版推荐**：❌ **不推荐。** 污染 optimizer 的风险（致命）大于审计收益。建议在 D.4-d 或 D.4-e 撤销机制中再引入。

### 方案 C（不推荐第一版）：新增 reschedule_batches 表

**做法**：
- 新增 `reschedule_batches` migration（id, user_id, language, preview_hash, card_count, created_at 等）
- 新增 `reschedule_batch_cards` migration（batch_id, review_card_id, previous_due_at, new_due_at 等）
- 完整记录整批重排的元数据和每张卡的变更

**优点**：最完整的审计和撤销能力
**风险**：新增 migration + 两张表，涉及 schema 变更
**对 optimizer 的影响**：无（不写 ReviewLog）
**撤销难度**：低（有完整的变更快照）
**第一版推荐**：❌ **不推荐。** 范太大，违反"第一版不要新增 migration"的原则。

---

## 4. ReviewLog 污染风险

### 核心原则

**ReviewLog 是历史记录也是训练数据**，不能混入未真实发生的评分。

### 污染链

```
用户行为 → ReviewLog(rating=good, source='review')     → optimizer 正确纳入
重排模拟 → ReviewLog(rating=good, source='reschedule')  → optimizer 错误纳入（未排除）
```

### 当前 optimizer 排除逻辑（3 + 1 处）

```php
// SettingsService 第 238 行（countOptimizableFsrsReviews）
->where('review_logs.source', '!=', 'reset')

// SettingsService 第 384 行（computeFsrsTrainingData，构造训练集）
->where('review_logs.source', '!=', 'reset')

// SettingsService 第 581 行（getReviewStatusSummary 的复习计数）
->where('review_logs.source', '!=', 'reset')

// ReviewStatsService 第 102 行（getReviewStats 的复习计数）
->where('source', '!=', 'reset')
```

全部只排除 `'reset'`。如果 ReviewLog 新增 `source='reschedule'`，必须将这 4 处改为 `whereNotIn('source', ['reset', 'reschedule'])`。

### rating 处理

如果采用方案 B，`rating` 字段的处理方式：
- 设为 `null`：与模型 `$fillable` 和 migration 允许的 nullable 一致。最安全，optimizer 在 `mapRatingToInt()` 中需要处理 null 值。
- 设为 `'good'`：会伪装成用户真实 good 评分，**绝对不行**。
- 建议：`'scheduled'` 或 `null`。

### 结论建议

**第一版推荐方案 A（不写 ReviewLog）**。保留零污染风险的绝对安全。ReviewLog 留给 D.4-d 撤销机制再引入。

---

## 5. 撤销策略

### 是否需要 batch id

| 方案 | 是否需要 |
|------|---------|
| A（不写 ReviewLog） | **否**。没有可撤销的审计信息 |
| B（写 ReviewLog） | **建议要**。用 batch id 分组同一次 confirm 产生的所有 ReviewLog |
| C（新增表） | **要**。batch id 是核心设计 |

### 暂时方案

第一版（方案 A）无撤销能力。撤销作为 D.4-d 单独阶段。

### D.4-d 撤销设计要点

1. 需要记录 `previous_*` 值（方案 B 通过 ReviewLog，方案 C 通过 batch_cards 表）
2. 如果用户重排后手动复习了某张卡，不应撤销该卡（保持手动复习结果）
3. 建议撤销时间窗口（如 24 小时内可撤销）
4. 撤销需要新接口 `POST /settings/fsrs/reschedule-undo`

---

## 6. 防误点策略

### 三级防误点

| 级别 | 触发条件 | UI 反应 |
|------|---------|---------|
| 0 安全 | `newly_due_today = 0` | 确认按钮可正常点击 |
| 1 警示 | `0 < newly_due_today ≤ 50 且 newly_due_today ≤ 到期队列 × 3` | 按钮红色 + 弹窗 warning |
| 2 强警示 | `newly_due_today > 50 且 newly_due_today > 到期队列 × 3` | 3 秒倒计时 + 按钮置灰 + 二级确认 |

### 服务端阈值

- `newly_due_today` 硬上限：200（超过则拒绝执行）
- `total_changed` 硬上限：2000（超过则拒绝）
- 可在 `config/fsrs.php` 中配置（第一版写死在 Service 中）

### 二次确认

**必须**：使用 v-dialog 二次确认弹窗（而非 window.confirm）。
**不需要**：复选框 / 输入确认词（过度仪式化，与项目现有 DeleteBookDialog 的 3 秒延迟条模式一致即可）。

---

## 7. 并发与事务

### DB transaction

必须使用 `DB::transaction()` 包裹整个操作。

### Chunk 分批

必须使用 `chunkById(200, ...)` 分批处理，避免一次性加载全部卡到内存：
```php
$this->candidateCardsQuery($userId, $language)
    ->chunkById(200, function ($cards) use ($activeParams, $desiredRetention, $now) {
        foreach ($cards as $card) {
            DB::transaction(function () use ($card) {
                $card->lockForUpdate();
                // update fields
                $card->save();
            });
        }
    });
```

### Race condition

- **preview → confirm 窗口**：用户预览后、确认前，数据可能被正常复习/optimizer 改变
- **解决方案**：preview_hash（preview 时对候选卡 ID + 字段值签名，confirm 时校验）
- **confirm 执行中**：`lockForUpdate()` 防止并发修改
- **不匹配**：返回 409 提示重新预览

### preview_hash 设计

```php
// preview 时返回
$previewHash = md5(json_encode([
    'card_ids' => $cards->pluck('id')->sort()->values(),
    'stabilities' => $cards->pluck('fsrs_stability'),
    'difficulties' => $cards->pluck('fsrs_difficulty'),
    'language' => $language,
    'timestamp' => $now->timestamp,
]));

// confirm 时校验
if ($request->preview_hash !== $expectedHash) {
    return response()->json(['error' => '预览已过期，请重新预览'], 409);
}
```

---

## 8. 推荐方案

### D.4-c 第一版推荐

```
方案 A（不写 ReviewLog）+ preview_hash + 二次确认弹窗 + 服务端阈值保护
```

| 决策项 | 选择 |
|--------|------|
| 更新 ReviewCard | ✅ 是（due_at / stability / difficulty） |
| 写 ReviewLog | ❌ 否（D.4-d 再引入） |
| 新增 migration | ❌ 否 |
| preview_hash | ✅ 是 |
| 二次确认弹窗 | ✅ 是（v-dialog，复用 DeleteBookDialog 延时条模式） |
| 服务端阈值 | ✅ 是（newly_due_today ≤ 200, total_changed ≤ 2000） |
| 撤销机制 | ❌ 否（D.4-d 单独阶段） |
| 修改 optimizer 排除 | ❌ 否（不写 ReviewLog 就不需要） |
| 修改 stats 排除 | ❌ 否 |

### 理由

1. 不写 ReviewLog 规避了 optimizer 污染这一致命风险
2. 不新增 migration 保持向后兼容
3. preview_hash 解决 preview→confirm 窗口期的数据变化问题
4. 服务端阈值保护防止用户误操作导致大量卡片同时到期
5. 二次确认弹窗符合产品安全原则

---

## 9. D.4-c 任务拆分

| 编号 | 内容 | 依赖 | 并行 |
|------|------|------|------|
| **D.4-c-a** | 后端 confirm API + preview_hash 校验 + 候选卡查询复用 | 无（可先做） | 与 UI 侦察并行 |
| **D.4-c-b** | ReviewCard 写入逻辑（事务 + chunkById + lockForUpdate） | D.4-c-a | 串行 ✅ 已完成 |
| **D.4-c-c** | 服务端阈值保护（newly_due_today 上限 + total_changed 上限） | D.4-c-a | 与 D.4-c-b 并行 |
| **D.4-c-d** | 前端确认按钮 + v-dialog 二次确认弹窗 | 无（可先做 UI 框架） | 与后端并行 |
| **D.4-c-e** | 前后端联调 + 测试 + 浏览器 smoke + 全量回归 | D.4-c-a/b/c/d | 串行最后 |
| **D.4-d** | 撤销机制（ReviewLog + batch id + undo API） | D.4-c 全部完成 | 后续阶段 |

### 并行策略

```
阶段 1（并行）：
  [D.4-c-a] 后端 confirm API 框架（路由 + Controller + Service 签名 + preview_hash）
  [D.4-c-d] 前端 UI（确认按钮 + v-dialog 弹窗骨架，绑定 API 后测试）

阶段 2（并行）：
  [D.4-c-b] ReviewCard 写入
  [D.4-c-c] 阈值保护

阶段 3（串行）：
  [D.4-c-e] 联调 + 测试 + 浏览器 smoke + 提交
```

---

## 10. 安全审计结论

**NEEDS_CONFIRM**（来自 fs-yun-zhongzi 侦察）

### 致命风险

1. **ReviewLog 污染 optimizer**：如果写 ReviewLog 且 source='reschedule'，当前 optimizer 查询仅排除 'reset'。方案 A 完全规避此风险。

### 高风险

2. **批量写 1000+ ReviewCard**：需 DB::transaction + chunkById + lockForUpdate
3. **无 preview_hash 时 preview→confirm 窗口数据变化**：必须加 preview_hash 校验

### 中风险

4. **无 newly_due_today 上限**：需服务端硬上限保护
5. **无二次确认**：用户可能误操作

### 审计结论

**采用方案 A 时：SAFE（可执行）**
- 不写 ReviewLog → 不触动 optimizer 和 stats 模块
- 不新增 migration → 不改变 schema
- 不涉及受保护模块
- 需用户确认的事项：新增路由和批量 DB 写入

**采用方案 B 时：NEEDS_CONFIRM**
- 必须修改 4 处 optimizer 查询（受保护模块）

---

## 11. Agent 并行建议

### 可并行

- 代码链路侦察 ✅（已完成，见 fs-yang-jian 报告）
- 安全审计 ✅（已完成，见 fs-yun-zhongzi 报告）
- 测试清单设计 ✅（已完成，见 fs-huang-feihu 报告）
- UI/UX 侦查 ✅（已完成，见 fs-nu-wa 报告）
- 产品体验侦查 ✅（已完成，见 fs-da-ji 报告）

### 必须串行

- D.4-c-a confirm API → D.4-c-b ReviewCard 写入（有代码依赖）
- D.4-c-d 前端弹窗 → D.4-c-e 联调
- D.4-c 全部 → D.4-d 撤销

---

## 12. 测试清单摘要

完整测试清单见 fs-huang-feihu 侦察报告。核心 P0 测试：

| 测试 | 优先级 |
|------|--------|
| confirm 成功更新 eligible card | P0 |
| confirm 拒绝 preview_hash 缺失 | P0 |
| confirm 拒绝非 english 语言 | P0 |
| confirm 不修改其他用户卡片 | P0 |
| confirm 不修改 word / phrase / new / learning / disabled 卡 | P0 |
| confirm 事务失败整体回滚 | P0 |
| confirm newly_due_today 超上限拒绝 | P0 |

---

## 13. UI/UX 建议摘要

完整 UI 建议见 fs-nu-wa 侦察报告。核心要点：

1. **确认按钮**：放在预览卡片内部底部 `v-card-actions` 居中，`v-btn depressed color="error"`
2. **二次确认弹窗**：v-dialog persistent max-width=560px，复用 DeleteBookDialog 的 3 秒延迟条
3. **成功状态**：预览卡片替换为 v-alert type="success"
4. **不引入**：复选框、输入确认词、撤销按钮
5. **色联动**：按钮颜色随 newly_due_today > 0 在 warning/error 间切换

---

## 14. 下一阶段建议

1. **D.4-c-a**：后端 confirm API 框架（路由 + Controller + Service 签名 + preview_hash）✅ 已完成
2. **D.4-c-b**：ReviewCard 写入逻辑 ✅ 已完成
3. **D.4-c-d**：前端确认按钮 + v-dialog 二次确认弹窗（🔄 开发中）
4. **D.4-c-e**：前后端联调 + 测试 + 浏览器 smoke + 全量回归（建议下一步）
5. 独立阶段 D.4-d 做撤销机制

---

## 15. D.4-c-a 执行勘误与边界说明

### 15.1 preview_hash 设计勘误

section 7 中的 preview_hash 示例代码包含 `'timestamp' => $now->timestamp`，**这是错误的**。

**正确设计**：preview_hash **不得包含任何时间戳**（包括 `now()`、`timestamp`、`Carbon::now()`）。hash 必须是纯数据驱动的、稳定可复现的签名，以便 confirmPreflight() 重新计算时能够精确匹配。

**实际实现**（D.4-c-a）：
- payload 包含：`user_id`、`language`、`desired_retention`、`parameters_hash`（对排序后的 activeParams 做 sha256）、`cards` 数组（按 review_card_id 排序，每张卡包含 review_card_id / word_sense_id / fsrs_due_at / fsrs_stability / fsrs_difficulty / fsrs_last_reviewed_at / fsrs_state / fsrs_enabled）
- 最终 hash = `sha256(json_encode(payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))`
- **不含 timestamp**

### 15.2 D.4-c-a 执行边界

D.4-c-a 的实现范围严格限定为 **confirm preflight 只读校验**，不涉及任何写入：

| 行为 | 是否包含 | 说明 |
|------|---------|------|
| 路由 + Controller + Service 签名 | ✅ 是 | POST /settings/fsrs/reschedule-confirm |
| preview_hash 校验 | ✅ 是 | 服务端重新计算并比对 |
| 安全阈值检查 | ✅ 是 | MAX_NEWLY_DUE_TODAY=200, MAX_TOTAL_CHANGED=2000 |
| ReviewCard 写入 | ❌ 否 | 留给 D.4-c-b |
| ReviewLog 写入 | ❌ 否 | 留给 D.4-d 或更晚阶段 |
| write_enabled | ❌ 否 | 硬编码为 `false`，不可切换 |

### 15.3 write_enabled=false 硬编码

confirmPreflight() 返回值中 `write_enabled` 字段永远为 `false`。D.4-c-a 只做“预览仍然有效”的校验，不做实际写入。前端在收到 `confirm_available=true` 且 `write_enabled=false` 时，应提示用户“正式写入将在后续阶段开放”。

### 15.4 下一步

**D.4-c-b**：正式 ReviewCard 写入逻辑（事务 + chunkById + lockForUpdate）。✅ 已完成

### 15.5 D.4-c-b 产品决策记录

D.4-c-b 实现过程中经用户确认的产品决策：

| 决策项 | 决策内容 |
|--------|---------|
| newly_due_today > 200 | 允许通过二次确认后继续（原侦察方案为硬拒绝） |
| total_changed > 2000 | 仍拒绝执行（稳定性优先） |
| 成功消息文案 | "已重排 X 张卡片，其中 Y 张今天到期" |
| 撤销机制 | 延后至 D.4-d，不在 D.4-c-b 实现 |
