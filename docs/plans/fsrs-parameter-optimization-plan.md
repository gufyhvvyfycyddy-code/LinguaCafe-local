# FSRS 参数优化实施计划

> **创建日期**：2026-06-25
> **关联里程碑**：[linguacafe-fsrs-roadmap.md](./linguacafe-fsrs-roadmap.md) → D.3
> **前置侦察**：FSRS-D3-scout（本文件即侦察输出）
> **当前状态**：D.3-a 已完成（后端优化预览），D.3-c（前端预览展示）为下一步

---

## 1. fsrs-rs-php 能力评估

### 1.1 当前安装状态

fsrs-rs-php 已作为**本地编译的 PHP C 扩展**加载（非 Composer 依赖）：
- 文件：`fsrs_rs_php.dll`（Windows 二进制）
- 加载方式：`extension=fsrs_rs_php` in `php.ini`
- 运行时检测：`extension_loaded('fsrs-rs-php')`
- 当前本地环境已加载，`FSRS_ALLOW_INTERNAL_FALLBACK=false`

### 1.2 可用 API

扩展导出以下类和方法：

**类**：`\fsrs\FSRS`, `\fsrs\MemoryState`, `\fsrs\NextStates`, `\fsrs\FSRSItem`, `\fsrs\FSRSReview`, `\fsrs\SimulatorConfig`, `\fsrs\SimulationResult`, `\fsrs\ItemState`

**函数**：
- `get_default_parameters()` — 返回 19 个默认 FSRS 权重（float 数组）
- `simulate(array $w, float $desired_retention, ?fsrs\SimulatorConfig &$config = null, ?int $seed = null): fsrs\SimulationResult` — 用给定参数模拟 365 天复习负担
- `default_simulator_config()` — 返回默认模拟配置（`SimulatorConfig` 对象无 settable PHP 字段，始终使用默认值）

**真实优化器**：
- `$fsrs->compute_parameters($trainSet)` — 接收训练数据，返回优化后的 19 个浮点权重

### 1.3 当前项目调用情况

| 调用 | 调用位置 | 用途 |
|------|---------|------|
| `new \fsrs\FSRS(get_default_parameters())` | `FsrsSchedulingService.php` | 用默认参数初始化 FSRS 调度器 |
| `$fsrs->next_states(...)` | `FsrsSchedulingService.php` | 计算单次复习后的状态变化 |
| `compute_parameters()` | **从未调用** | — |
| `simulate()` | **从未调用** | — |

**结论**：当前项目仅使用默认参数做调度，从未接入参数优化或模拟能力。

### 1.4 是否需要新增依赖

**不需要。** `compute_parameters()` 已包含在已安装的 `fsrs-rs-php` 扩展中，无需安装额外包。

---

## 2. 优化输入数据评估

### 2.1 compute_parameters() 需要的输入格式

```php
$trainSet = [
    new \fsrs\FSRSItem([
        new \fsrs\FSRSReview(rating: 3, delta_t: 0),   // 第一次复习
        new \fsrs\FSRSReview(rating: 4, delta_t: 5),   // 距上次 5 天后
        new \fsrs\FSRSReview(rating: 2, delta_t: 12),  // 距上次 12 天后
        // ...同一张卡的所有连续 review
    ]),
    // ...其他卡
];

// 调用
$fsrs = new \fsrs\FSRS(get_default_parameters());
$optimizedWeights = $fsrs->compute_parameters($trainSet);
// 返回 float[19]
```

**每个 `FSRSItem` 代表一张卡的所有历史复习序列。**
**每个 `FSRSReview` 包含两个字段：**
- `rating`：int，1=Again, 2=Hard, 3=Good, 4=Easy
- `delta_t`：float，距上次复习的天数（第一次 review 为 0）

### 2.2 ReviewLog 是否能提供这些数据

`ReviewLog` 表包含以下相关字段：
- `rating`：string，'again'/'hard'/'good'/'easy'/'reset'
- `reviewed_at`：timestamp，复习时间
- `previous_stability` / `new_stability`：float，复习前后稳定度
- `previous_difficulty` / `new_difficulty`：float，复习前后难度
- `review_card_id`：关联到 `ReviewCard`

**结论：ReviewLog 包含所有必要字段。** `delta_t` 可由连续两条 `reviewed_at` 之差算出。

### 2.3 Rating 映射

| ReviewLog.rating | FSRS rating 值 | 说明 |
|---|---|---|
| 'again' | 1 | 再次学习 |
| 'hard' | 2 | 困难 |
| 'good' | 3 | 正常 |
| 'easy' | 4 | 简单 |
| 'reset' | **排除** | 不是真实评分 |

### 2.4 必须排除的数据

| 排除条件 | 原因 |
|---------|------|
| `rating = 'reset'` | 重置操作，不是真实评分 |
| `source = 'reset'` | 重置产生的日志 |
| `ReviewCard.target_type != 'sense'` | 只做 sense 主线，排除 legacy word/phrase |
| `user_id != 当前用户` | 用户隔离 |
| `language_id != 当前语言` | 语言隔离 |
| `word_senses.confirmed = 0` | 只取 confirmed WordSense 的 review |

**当前 `countOptimizableFsrsReviews()` 已覆盖以上所有过滤条件。**

### 2.5 数据阈值

当前阈值 `FSRS_OPTIMIZATION_MIN_REQUIRED = 300`（总 review log 条数）。

**建议**：维持 300 不变。FSRS 社区建议至少 400+ reviews 才能产生稳定参数，300 是一个保守的入门门槛。后续可增加"最少 50 张不同卡片"的附加条件。

---

## 3. 参数保存方案

### 3.1 当前状态

- 唯一 FSRS 相关 setting：`fsrsDesiredRetention`（user_id=-1, key='fsrsDesiredRetention', value='0.90'）
- 参数存储：**无**

### 3.2 建议新增 Setting Keys

| Key | 类型 | 说明 | 示例值 |
|-----|------|------|--------|
| `fsrs_parameters` | JSON (float[19]) | 当前使用的 FSRS 权重 | `[0.4,0.6,2.4,5.8,...]` |
| `fsrs_parameters_source` | string | 参数来源：'default' / 'optimized' / 'manually_edited' | 'optimized' |
| `fsrs_parameters_optimized_at` | ISO 8601 string | 优化时间 | '2026-06-25T14:30:00+08:00' |
| `fsrs_parameters_previous` | JSON (float[19]) or null | 优化前的参数备份 | 同 fsrs_parameters 格式 |

- 以上 key 均存为 **user_id=-1 全局设置**（与 fsrsDesiredRetention 一致）
- 不做 per-user 参数（Anki 也是全局参数 + per-deck retention）

### 3.3 参数版本管理

不引入独立的参数版本号。参数变更历史通过 `fsrs_parameters_optimized_at` 时间戳隐式追踪。备份通过 `fsrs_parameters_previous` 保存，一次只保留最近一次优化前的参数。

---

## 4. 用户确认流程设计

### 4.1 记录不足（< 300 条）

继续显示当前提示：
> "复习记录还不够，先继续复习一段时间再来优化。"

按钮文字："根据我的复习记录优化"（保持可点击但不执行真实优化）。

### 4.2 记录足够（≥ 300 条）

**步骤 1：用户点击"根据我的复习记录优化"**

后端执行：
1. 调用 `countOptimizableFsrsReviews()` 确认阈值。
2. 查询当前用户、当前语言的所有符合条件 ReviewLog。
3. 按 `review_card_id` 分组，每组按 `reviewed_at` 排序。
4. 构造 `FSRSItem` 数组（每个 `FSRSItem` 对应一张卡的全部 review 序列）。
5. 计算每个 review 的 `delta_t = (当前 reviewed_at - 上一条 reviewed_at) / 86400` 天。
6. 调用 `$fsrs->compute_parameters($trainSet)`。
7. 返回结果给前端。

**步骤 2：前端展示优化结果**

在"高级工具"区展示 Preview 卡片：

```
📊 参数优化预览

来源：根据你最近 N 条复习记录计算（共 M 张不同的词义卡）

当前参数（默认）→ 优化后参数（个性化）
[简洁对比表，只显示变化 > 1% 的参数行]

预计影响：
• 不会重排已有卡片 — 新参数仅影响未来的新评级
• 预计每天复习量：约 XX ± YY 张（基于模拟）
• 恢复默认参数：可以随时恢复

[确认应用] [取消]
```

**步骤 3：用户确认后保存**

1. 备份当前参数到 `fsrs_parameters_previous`。
2. 保存新参数到 `fsrs_parameters`。
3. 更新 `fsrs_parameters_source = 'optimized'`。
4. 更新 `fsrs_parameters_optimized_at`。
5. 后续复习自动使用新参数（`FsrsSchedulingService` 从 settings 读取参数而非硬编码 `get_default_parameters()`）。

### 4.3 重排已有卡片

**不在 D.3 做。** 重排需要逐卡调用 `reschedule()`，会直接影响到期时间，必须作为独立任务（D.4）并需要强确认。

D.3 只做参数优化与保存，明确告知用户"不会重排已有卡片"。

### 4.4 恢复默认参数

在"高级工具"区增加"恢复默认参数"按钮：
- 当 `fsrs_parameters_source == 'optimized'` 时可见。
- 点击后弹窗确认："恢复 fsrs-rs-php 默认参数？不会重排已有卡片。"
- 确认后清除 `fsrs_parameters`、重置 `fsrs_parameters_source='default'`、清除 `fsrs_parameters_optimized_at`。
- 保留 `fsrs_parameters_previous`（仍可在优化历史中看到上次优化结果）。

---

## 5. 风险清单与防护

| # | 风险 | 概率 | 影响 | 防护措施 |
|---|------|------|------|---------|
| 1 | 参数优化输入不完整 | 低 | 输出参数质量差 | 严格按上述过滤条件构造训练集，加测试覆盖 |
| 2 | rating 映射错误 | 低 | 参数完全错误 | 测试中验证映射表；code review |
| 3 | reset log 混入训练集 | 低 | 参数偏差 | `where('rating', '!=', 'reset')` 已在现有过滤中 |
| 4 | legacy word card 混入 | 低 | 参数混入错误数据 | `where('target_type', 'sense')` + join word_senses |
| 5 | 其他用户/语言混入 | 低 | 参数不适合当前用户 | `where('user_id', ...)` + `where('language_id', ...)` |
| 6 | 参数保存后影响未来调度 | 中 | 优化参数可能导致不合理间隔 | 前端展示对比+用户确认；保留备份；提供恢复 | 默认按钮 |
| 7 | 真实优化耗时过长 | 中 | PHP 请求超时 | 加 timeout 保护（30s），后端先评估输入量是否合理；如果 > 5000 条可异步任务 |
| 8 | 优化参数异常（全 0 / NaN） | 低 | 调度崩溃 | 保存前校验 19 个值均在合理范围 [0.01, 100] |
| 9 | 用户误以为会重排已有卡片 | 高 | 用户预期不符 | 预览页明确说明 + 按钮文案不含"重排" + FAQ |
| 10 | 和 D.4 重排任务混在一起 | 中 | 功能边界模糊 | D.3 和 D.4 严格隔离；D.3 完成后不自动进入 D.4 |

---

## 6. 影响范围分析

### 6.1 需要修改的文件

| 文件 | 改动类型 | 说明 |
|------|---------|------|
| `app/Services/SettingsService.php` | 新增方法 | `optimizeFsrsParameters(user_id, language_id)` 真实实现 |
| `app/Http/Controllers/SettingsController.php` | 修改方法 | 返回优化结果预览 |
| `app/Services/FsrsSchedulingService.php` | 修改 | 从 settings 读取参数而非硬编码默认值 |
| `resources/js/components/Admin/AdminReviewSettings.vue` | 新增 UI | 优化结果预览卡片 + 确认/取消 + 恢复默认按钮 |
| `app/Http/Controllers/SettingsController.php` | 新增路由 | `POST /settings/fsrs/optimize`（真实优化）<br>`POST /settings/fsrs/reset-parameters`（恢复默认） |
| `database/migrations/` | 无需 migration | 使用已有 Settings 表，不新增 schema |

### 6.2 需要新增的测试

| 测试文件 | 测试内容 |
|---------|---------|
| `FsrsOptimizationSettingsTest` | 新增：真实 optimize 返回预期格式；优化后参数保存到 settings；恢复默认参数清除优化；参数校验 |
| `FsrsSchedulingServiceTest` | 新增：从 settings 读取优化参数调度；参数不存在时回退到默认参数 |

### 6.3 不需要修改的文件

- `ReviewCard` / `ReviewLog` model — 不改 schema
- `ReviewCardService` — 不改排程逻辑
- `routes/web.php` — 只加 1-2 条新路由
- 测试工厂 — 现有 helper 够用
- migration — 不需要

---

## 7. 任务拆解（D.3-a ~ D.3-d）

### D.3-a：参数优化后端（最小实现）

**目标**：`POST /settings/fsrs/optimize` 返回真实优化结果（不保存）。

**具体步骤**：
1. `SettingsService::optimizeFsrsParameters()` — 查询 ReviewLog、构造 FSRSItem 训练集、调用 `compute_parameters()`、返回新旧参数对比。
2. 校验返回值：19 个 float，值在 [0.01, 100] 范围。
3. 返回 JSON：`{optimized: true, current_parameters:[...], optimized_parameters:[...], review_count: N, card_count: M}`
4. 测试：至少 3 条 review log 能产生非全 0 参数。

### D.3-b：优化结果保存

**目标**：用户确认后保存到 Settings 表。

**具体步骤**：
1. `POST /settings/fsrs/optimize` 接受 `confirm: true` 参数。
2. 保存前备份：`fsrs_parameters_previous = 当前 fsrs_parameters 或默认值`。
3. 更新 4 个 setting keys。
4. 失败回滚：Settings 表更新是原子的吗？用 `DB::transaction()`。

### D.3-c：前端优化预览

**目标**：在 AdminReviewSettings.vue 增加优化结果预览卡片。

**具体步骤**：
1. 点击"根据我的复习记录优化"→ 调用优化 API → 展示预览。
2. 预览卡片：对比表 + 预计影响 + 确认/取消按钮。
3. 确认后调用 `confirm: true` → 保存 → 显示成功提示。
4. "恢复默认参数"按钮（source=optimized 时可见）。

### D.3-d：调度器集成

**目标**：FsrsSchedulingService 从 settings 读取参数。

**具体步骤**：
1. 读取 `fsrs_parameters_source`：如果是 'optimized'，读取 `fsrs_parameters` JSON；否则用 `get_default_parameters()`。
2. 缓存参数避免每次请求读 DB。
3. 向后兼容：`fsrs_parameters` 不存在时回退默认参数。
4. 测试：用优化参数调度 vs 默认参数调度，确认排程日期不同。

---

## 8. 不做范围

- ❌ 不重排已有卡片（D.4 单独做）
- ❌ 不实现手动编辑参数（高风险，单独侦察）
- ❌ 不做 `simulate()` 接入（D.2-c 简易版已够用）
- ❌ 不做 per-user 参数（全局参数，与 Anki 一致）
- ❌ 不做异步优化任务队列（第一版同步即可，阈值 300 不会超时）
- ❌ 不做参数版本号（用时间戳隐式追踪）
- ❌ 不修改 `ReviewCard` / `ReviewLog` schema
- ❌ 不做图表化对比（文本对比即可）

---

## 9. 推荐下一步

**立即进入 D.3-c：前端优化预览展示。**

D.3-a 已完成：`computeFsrsOptimizationPreview()` 后端实现 + 17 个测试全部通过。

D.3-c 任务：
1. Vue AdminReviewSettings.vue 扩展进阶工具区，展示优化预览结果。
2. 对比显示 current_parameters vs optimized_parameters（19/21 个权重值）。
3. 展示优化统计（train_card_count、train_review_count、optimized_at）。
4. 如记录不足，继续显示当前提示。
5. 不添加"保存参数"按钮（留给 D.3-b）。

理由：
1. D.3-a 后端优化预览已验证 compute_parameters() 正确调用。
2. 前端展示只需扩展已有 AdminReviewSettings.vue（已完成 D.2-a/D.2-b/D.2-c）。
3. API 返回 JSON 已包含 current_parameters、optimized_parameters、preview_available 等字段。
4. D.3-c 风险低，纯前端只读。
