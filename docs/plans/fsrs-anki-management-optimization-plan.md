# FSRS Anki 管理优化计划

> 本文档描述 FSRS 管理区中长期优化目标，逐步接近 Anki 的管理体验。

---

## 1. 当前目标

让 FSRS 管理区逐步接近 Anki 的体验，包括参数优化、重排控制、参数诊断、工作量模拟和预设分组管理。

---

## 2. 已确认事实

- FSRS 默认参数保持 19 个。
- 19 个是算法参数数量，不是学习材料、复习卡或复习记录数量。
- 删除 / rejected 的词义卡旧 review_logs 不参与参数优化（通过 `word_senses.status = CONFIRMED` 过滤保障）。
- 恢复默认参数只删除 FSRS 参数 settings，不删除学习数据。

---

## 3. 与 Anki 的主要差距

| 差距 | 说明 |
|------|------|
| 缺少 preset / 分组参数体系 | Anki 支持不同 preset 管理不同组的参数设置 |
| 缺少参数优化诊断面板 | 当前只展示参数数量，缺少诊断信息 |
| Desired Retention 工作量模拟还不够直观 | 需更接近 Anki Help Me Decide 模拟器 |
| 重排风险展示还可以更接近 Anki | 当前风险提示已实现但还有改进空间 |
| 缺少恢复默认参数按钮 | Anki 参数界面有恢复默认按钮 |

---

## 4. 后续优化阶段

| 阶段 | 内容 | 状态 |
|------|------|------|
| FSRS-Anki-Mgmt-1 | 恢复默认参数按钮 | ✅ 已完成 |
| FSRS-Anki-Mgmt-2 | 参数优化诊断面板 | ✅ 已完成 |
| FSRS-Anki-Mgmt-3 | 重排风险面板优化 + 诊断计数一致性修复 | ✅ 已完成 |
| FSRS-Anki-Mgmt-4 | Desired Retention 工作量模拟器 | ✅ 已完成 |
| FSRS-Anki-Mgmt-5 | 每日学习上限 / 新卡与复习上限侦察 | ✅ 已完成 |
| FSRS-Anki-Mgmt-6 | 每日上限设置页第一版（含 fix：默认复习上限开启 + 校验收口） | ✅ 已完成 |
| FSRS-Anki-Mgmt-7 | 复习队列每日上限接入 + 超额复习入口 + 修复 `/reviews/senses` 绕过 | ✅ 已完成 |
| Reader-FSRS-Highlight-1 | 阅读页绿色高亮改为 FSRS 熟悉度驱动 | ✅ 已完成 | TextBlockService 根据 ReviewCard stability/due_at/state 计算 level 1-7 |
| Reader-Visual-Semantics-1 | 绿色十档 + 紫色搜索命中 + 计划保全 | ✅ 已完成 | FSRS 10 档 (10%-100%) + AI 预览详情页紫色高亮 + 文档明确保留未完成计划 |
| Plan-Integrity-1 | 总控大计划 + 未完成任务总表入库 | ✅ 已完成 | 新增 `linguacafe-master-plan.md`，汇总所有任务线 |
| Reader-UI-1-a | 查词侧栏第一轮瘦身：隐藏旧 SRS 1-7 按钮 + "选择释义" + 词典默认收起 | ✅ 已完成 | 前端改动，不删 setStage 逻辑，不删数据 |
| Reader-UI-6-a | "删除词条"→"回归为新词"语义收口 + 确认弹窗如实告知行为 | ✅ 已完成 | 全前端改动，后端行为未改（当前 hardDeleteWord 物理删除数据） |
| Reader-UI-6-b | 回归为新词时删除释义复习卡与复习记录 | ✅ 已完成 | 后端删除 sense ReviewLog + legacy word ReviewLog，删除 sense+legacy ReviewCard，WordSense rejected，EncounteredWord 删除 |
| Mgmt-7-c | 自动提升词汇等级改为 FSRS 复习记录 | ✅ 已完成（2026-07-15，ADR-0021） | content edit 无 legacy card/bridge 副作用；默认 confirmed sense 为 stage=-1 最低学习绿色；`keep_new` 保持 stage=2；explicit legacy stage 兼容保留 |
| FSRS-Anki-Mgmt-8 | 今日临时上限 / 暂停新卡 | ✅ 已完成（2026-07-14，ADR-0018） |
| FSRS-Anki-Mgmt-9 | Preset / 分组参数长期评估 | 📋 计划中 |

> **注意**：每日上限接入前，需注意阅读页新词创建与 lemma/study_base 归并质量；`Lemma-Origin-1` 将单独跟踪英文原型识别回归问题。

---

## 5. 当前下一步

当前下一步不再是已经完成的 Mgmt-7 / Mgmt-8 / Mgmt-7-c。FSRS-Anki-Mgmt-9 Preset / 分组参数仍未开始，继续作为独立 Product Gate，本轮不自动进入。

本阶段已完成：
- 后端 `dueCardsWithLimits()` — 对 `/reviews` 队列应用每日复习上限 + 新学上限
- `reviewedTodayCount()` — 基于 ReviewLog 统计今日已复习数
- `ignore_daily_limits` — 允许今天超额复习
- 前端超额复习入口 + localStorage 今日状态
- 设置页文案更新
- 编程协作规则文档新增
- `Lemma-Origin-1` 仍为独立后续任务
- Mgmt-8（今日临时上限）已完成（ADR-0018）

本轮目标：只做侦察和计划，不实现功能。

侦察结论：
- Anki 支持 New Cards/Day + Maximum Reviews/Day + per-deck 三层限制。
- LinguaCafe 当前没有每日上限设置，所有 due 卡全部进入复习队列。
- LinguaCafe 的 sense review 基于 `fsrs_due_at <= now()` 取卡，无上限过滤。
- 每日上限可通过已有 `settings` 表保存，无需新增 migration。
- 第一版建议：全局每日复习上限（默认 200）+ 每日新学上限（默认 20），新卡受复习上限影响。
- 风险：复习上限会隐藏部分 due 卡（必须 UI 提示），但不影响历史数据、ReviewLog、管理页导出。
- 属于中高风险功能，必须分阶段实施。

后续阶段：
| 阶段 | 内容 | 状态 | 风险 |
|------|------|------|------|
| FSRS-Anki-Mgmt-6 | 每日上限设置页第一版（全局上限） | ✅ 已完成 | 低 |
| FSRS-Anki-Mgmt-7 | 复习队列每日上限接入 | ✅ 已完成 | 高 |
| FSRS-Anki-Mgmt-8 | 今日临时上限 / 暂停新卡 | ✅ 已完成（ADR-0018） | 中 |
| FSRS-Anki-Mgmt-9 | Preset / 分组参数长期评估 | 📋 计划中 | 低 |

后端：
- `SettingsService::restoreFsrsDefaultParameters()` — 删除 4 个 global settings
- `SettingsController::restoreFsrsDefaultParameters()` — 接口方法
- `routes/web.php` — `POST /settings/fsrs/restore-default`

前端：
- `AdminReviewSettings.vue` — 参数来源区域增加恢复默认参数按钮
- `AdminReviewSettings.vue` — 高级工具区新增参数优化诊断面板

按钮行为：
- "恢复默认参数"按钮**始终显示**，文字始终为"恢复默认参数"。
- 即使当前已经是默认参数，点击后也只返回安全提示，不删除任何学习数据。
- 点击后弹出确认弹窗，确认后调用后端接口。

诊断面板展示：
- 有效复习记录数 / 门槛。
- 可训练卡片数。
- 当前状态（empty / insufficient / needs_more_card_history / ready）。
- 排除记录明细：reset 记录数、已删除/已停用卡片记录数。
- 没有排除记录时显示"没有发现会被排除的旧记录"。
- 底部安全说明："删除或拒绝的词义卡不会参与参数优化。"

测试：
- 恢复后 settings 被删除
- 恢复后状态回到 default
- 恢复后调度使用默认参数
- 不删除学习数据
- 未保存参数时安全调用

---

## 6. 禁止事项

- 禁止删除 review_logs。
- 禁止删除 review_cards。
- 禁止删除 word_senses。
- 禁止删除 encountered_words。
- 禁止删除词典表。
- 禁止清库。
- 禁止自动重排卡片。
