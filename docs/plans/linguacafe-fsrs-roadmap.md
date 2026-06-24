# LinguaCafe FSRS / Sense Review Roadmap

> **最后更新**：2026-06-24
> **当前 latest commit**：`da9cc75 feat: move review card actions into more menu`

---

## 一、维护规则

1. 本文件是 LinguaCafe-local 的长期大计划。
2. 每次路线、阶段、优先级、需求新增或延期，都必须修改本文件并 commit。
3. 不以聊天记录作为唯一计划来源。
4. 开发前仍必须基于 GitHub 最新 master 真实代码确认。
5. 禁止使用任何 `--force` 参数。
6. 禁止读取 `.env`。
7. 禁止 `migrate:fresh` 或清空数据库。
8. Laravel 测试必须顺序执行，不要并行跑共享 testing DB 的测试套件。
9. 禁止提交 `.claude/`、临时 patch 文件（`_patch*.py`、`*.ps1`）、`node_modules`、`vendor`、storage 日志、数据库文件、token、`.env`。

---

## 二、当前主线

- **项目方向**：sense-only FSRS 复习系统。
- **WordSense** 是实际复习对象（lemma、surface_form、pos、sense_zh、sense_en、example_sentence_en、example_sentence_zh、source_chapter_id 等字段）。
- **ReviewCard.target_type=sense** 是主线 — 所有复习卡管理、统计、日常复习都围绕 WordSense。
- **EncounteredWord** 只负责阅读页颜色、熟悉度总览和 legacy 兼容。
- **ReviewCard.target_type=word** 是 legacy，不应混入 sense 主线。
- 删除、归档、重置、复习记录都必须区分 WordSense、ReviewCard、ReviewLog、EncounteredWord、WordSenseOccurrence 的不同语义。

---

## 三、已完成阶段

| 任务 | 关键内容 |
|------|----------|
| C.8-a | FSRS 状态页替换旧 SRS 设置 |
| C.8-b | desired retention 配置化 |
| C.8-fix-1 | 删除复习卡/单词时同步 reject 关联词义 |
| C.8-fix-2 | 删除最后一个关联词义后恢复 EncounteredWord 为 New |
| C.9-a | 复习页编辑 + 查看原文 |
| C.9-b | 复习页归档 + 彻底删除 |
| C.10-a | 后端 reset 接口 |
| C.10-b | 管理页 reset 按钮 |
| C.10-c | 复习页 reset 按钮 |
| C.11-a | GET /review-cards/stats 后端统计接口 |
| C.11-b | 管理员复习设置页 FSRS 统计总览 |
| C.11-c | 复习页顶部 FSRS stats chips |
| C.11-d | 管理页顶部 FSRS stats chips |
| C.11-d-cleanup | 清理误提交 patch 文件 |
| C.12-scout | Anki-like 浏览器增强侦察 |
| C.12-a | 管理页展示 FSRS 列（稳定度、难度、复习、遗忘） |
| C.12-b | 管理页服务器端排序（白名单 + tie-breaker） |
| C.12-c | 管理页高级筛选面板（fsrs_states、due_range、reps_min、lapses_min） |
| C.12-d-scout | ReviewLog 简略信息侦察（结论：只用 fsrs_last_reviewed_at，不 join ReviewLog） |
| C.12-d-a | 管理页展示最近复习列（fsrs_last_reviewed_at），开放排序，不 join ReviewLog |
| C.12-e-a | 管理页列显示/隐藏自定义，localStorage 持久化，默认隐藏 释义(英)、例句(英)、例句(中) |
| C.12-e-c | 管理页操作列"更多"菜单，低频操作（查看原文/重置/彻底删除）收入菜单 |
| C.12-e-d | 管理页紧凑模式开关，CSS class 缩小 padding/字体/窄列宽度，standard 1640/compact 1480 |
| C.13-scout | 新词选项侦察，推荐方案 A（keep_new 参数） |
| C.13-a | 添加释义时支持"保持新词"选项，payload 加 keep_new boolean，stage===2 且 keep_new=true 时跳过 setStage(-7) |

---

## 四、当前最新状态

**Latest commit**：`f10bd75 feat: add compact mode to review card manager`

### `/review-cards/manage` 当前能力

- **搜索**：`q` 参数，搜索 Lemma / Surface / 中文释义 / 英文释义 / 英文例句。
- **预设筛选**：`filter` 参数，支持 全部、到期、未来到期、未归档、已归档、缺释义、缺例句、缺溯源。
- **高级筛选**：`fsrs_states[]`（new/learning/review/relearning 多选）、`due_range`（all/overdue/today/next7/future/none）、`reps_min`、`lapses_min`。
- **排序**：`sort_by` + `sort_dir`，服务器端白名单排序 + tie-breaker。
- **分页**：`page` + `per_page`（默认 20，最大 100）。
- **FSRS stats chips**：总词义卡、启用中、已归档、当前到期、新卡、学习中、复习中、重新学习、今日已复习、今日重置。
- **表格列**：checkbox、ID、Lemma、Surface、POS、中文释义、英文释义、英文例句、中文例句、溯源、状态（fsrs_state + 归档 chip）、稳定度、难度、复习（reps）、遗忘（lapses）、最近复习、到期（due_at）、操作。
- **操作**：行内编辑（POS/释义/例句白名单）、查看原文、归档/恢复、立即到期、重置为新学卡、彻底删除、批量归档/恢复/删除。
- **列显示自定义**：列设置按钮，可隐藏/显示列，localStorage 持久化。常驻列（checkbox、Lemma、释义(中)、状态、操作）不可隐藏。默认隐藏 释义(英)、例句(英)、例句(中)。支持恢复默认和全部显示。

### 安全边界（强制）

1. 所有 ReviewCard 查询必须限定 `user_id` + `language_id`。
2. 主线统计与管理只统计 `target_type = 'sense'`。
3. 必须排除 `status = 'rejected'` 的 WordSense（`whereHas('sense', where('status', STATUS_CONFIRMED))`）。
4. 禁止 legacy word card（`target_type = 'word'`）混入。
5. ReviewLog 没有 target_type 字段；如需用 ReviewLog，必须 join review_cards 再过滤 `target_type = 'sense'`。
6. 排序字段必须白名单（`array_key_exists`）。
7. 高级筛选字段必须白名单/枚举/int 转换。
8. 禁止拼接用户输入进 SQL（无 `orderByRaw` 用户输入、无 `whereRaw` 用户输入）。

---

## 五、下一阶段计划

### C.12-d — ReviewLog 简略信息 / 最近复习信息

**状态**：C.12-d-a 已完成，后续扩展延后

**已完成**：
- C.12-d-a：管理页展示"最近复习"列，使用 `ReviewCard.fsrs_last_reviewed_at`。
- 后端 `data()` 和 `serializeCard()` 均返回 `fsrs_last_reviewed_at`（ISO 8601 string，null 时为 null）。
- 前端新增"最近复习"列（位于"遗忘"与"到期"之间），支持排序（默认 desc）。
- null 值显示为 `—`，reset 后变为 `—`。
- 后端 SORTABLE_COLUMNS 已有 `fsrs_last_reviewed_at`（C.12-b 已加入），前端 sortableColumns 同步开放。

**决策记录**：
1. 第一版只展示 `ReviewCard.fsrs_last_reviewed_at`，不 join ReviewLog。
2. ReviewLog 聚合（review_log_count、reset_count、last_rating 等）延后到后续阶段。
3. ReviewLog 删除语义留给 C.14-scout。
4. 不新增最近复习高级筛选。

---

### C.12-e — 复习卡管理页列显示 / 列宽自定义

**状态**：C.12-e-a 已完成；C.12-e-c 已完成；C.12-e-d 已完成；C.12-e-b 延后/不做拖拽

**已完成**：
- C.12-e-a：列显示/隐藏自定义，localStorage 持久化，默认隐藏 释义(英)、例句(英)、例句(中)。
  - 列设置按钮（v-menu），可配置列 checkbox 列表。
  - 常驻列（checkbox、Lemma、释义(中)、状态、操作）不可隐藏。
  - 支持"恢复默认"和"全部显示"。
  - 动态 colspan 和动态 table min-width。
  - 隐藏当前排序列时自动回到 id desc。
  - 使用 `DefaultLocalStorageManager.saveSetting('reviewCardManageColumnSettings', ...)`。
  - 不改后端，不改 API，不改测试。
- C.12-e-c：操作列"更多"菜单。
  - 低频操作（查看原文、重置、彻底删除）收入 v-menu。
  - 外显保留：编辑、归档/恢复、立即到期、更多。
  - 操作列 min-width 从 220px 降到 160px。
  - tableMinWidth 基础值从 1700px 降到 1640px。
  - 编辑状态不变（只显示保存、取消，不显示更多）。
- C.12-e-d：紧凑模式开关。
  - 列设置菜单中的 v-switch，默认关闭。
  - localStorage key `reviewCardManageCompactMode`（boolean）。
  - 通过 CSS class `table--compact` 缩小 padding、字体、窄列宽度和操作列宽度。
  - tableMinWidth 标准 1640，紧凑 1480。
  - "恢复默认"同时关闭紧凑模式；"全部显示"不改变紧凑模式。
  - 不做列宽拖拽，不改后端。

**延后**：
- C.12-e-b：列宽预设/列宽拖拽持久化（经侦察建议永不做列宽拖拽）。

---

### C.13 — 手动释义"保持新词"选项

**状态**：C.13-scout 已完成；C.13-a 已完成。

**已完成**：
- C.13-scout：字段语义、前后端调用链、stage 状态机分析。
- C.13-a："保持新词" checkbox。
  - 前端：`WordSensesList.vue` 添加 "保持新词" v-checkbox，默认不勾选。
  - payload：新增 `keep_new` boolean 字段。
  - Controller：验证 `keep_new` 参数（'nullable'/'boolean'）。
  - Service：`createManualSense()` 在 `stage===2` 且 `keep_new=true` 时跳过 `setStage(-7)`。
  - 不改 DB，不改 routes，不改 ReviewCardService，不改 EncounteredWord model。
  - 6 个测试覆盖：keep_new 阻止升级、仍创建 sense & card、默认行为不变、不降级 Learning/Known/Ignored 词。

**实现文件**：WordSensesList.vue、SenseOccurrenceController.php、WordSenseService.php、WordSenseTest.php。

---

### C.14-scout — 删除复习记录语义侦察

**状态**：侦察阶段，待冻结

**来源**：用户希望在删除复习卡时，增加可以删除复习记录的功能。但如果删除释义卡片后，该单词记录已永久删除，则不需要。

**已知代码事实**：
- 当前 `WordSenseService::removeSenseFromReviewSystem()` 明确不删除 review_logs。
- 当前硬删除释义卡时，会 reject WordSense，并删除 ReviewCard，但不删除 ReviewLog。
- 当前不删除 EncounteredWord，只在删除最后一个 active sense 后把 EncounteredWord 恢复为 New。
- ReviewLog 有 review_card_id，但 ReviewLog 没有 target_type 字段。
- 如果 ReviewCard 被删除，ReviewLog 可能保留为历史记录或形成不可直接关联的记录。

**待侦察问题**：
1. 删除 ReviewCard 后 review_logs 是否仍保留。
2. `review_logs.review_card_id` 是否有外键约束，是否 cascade。
3. 当前统计接口是否还会统计已删除卡的 review_logs。
4. 管理页删除提示"复习记录不会被删除"是否准确。
5. 是否要增加"同时删除复习记录" checkbox。
6. 是否要新增接口参数 `delete_review_logs=true`。
7. 是否只允许单卡删除时删除日志，还是批量删除也支持。
8. 删除 review_logs 是否影响 C.11 stats、reviewed_today、reset_count。
9. 删除日志后是否需要审计提示。
10. 是否需要二次确认文案。

---

## 六、延后项目

以下项目暂不做：

| 项目 | 原因 |
|------|------|
| 批量重置 | 非当前优先级 |
| 导出当前筛选结果 | 非当前优先级 |
| 卡片详情抽屉 | 非当前优先级 |
| retrievability 展示 | 非当前优先级 |
| FSRS optimizer/simulator | 非当前优先级 |
| ReviewLog 复杂历史图表 | 非当前优先级 |

---

## 七、决策记录

### Decision 1 — 添加"新词"等级选项

**日期**：2026-06-24

添加"新词"等级选项进入大计划，编号 C.13-scout。它不插队到 C.12-d 之前，必须先侦察，再冻结实现。

### Decision 2 — 删除复习记录

**日期**：2026-06-24

删除复习记录进入大计划，编号 C.14-scout。由于当前硬删除不删除 review_logs，所以这是一个真实新增需求，但必须先确认数据库外键、统计影响和 UI 二次确认语义。

### Decision 3 — 管理页列显示与列宽自定义进入大计划

**日期**：2026-06-24

**原因**：C.12-d-a 后管理页列数达到 18 列，用户浏览器验收时确认关键列需要横向滚动才能看到。用户明确提出 `释义(英)`、`例句(英)`、`例句(中)` 可以不显示，并要求后续支持自定义列显示/隐藏和表头宽度调整。因此加入 C.12-e-scout，不立即开发，先进入大计划。

---

## 八、后续更新规则

以后每次新增、调整、延期、验收大计划项目，都必须同步修改本文件，并在 commit message 中使用 `docs` 或 `chore` 前缀，例如：

```
docs: update fsrs roadmap
```

**禁止**：以聊天记录作为唯一计划来源。所有路线变更必须落盘到本文档。
