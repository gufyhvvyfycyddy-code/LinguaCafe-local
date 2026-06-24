# LinguaCafe FSRS / Sense Review Roadmap

> **最后更新**：2026-06-25
> **当前 latest commit**：`a6f8be0 docs: correct keep-new visual validation status`

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
| C.14-scout | 复习日志删除语义侦察，确认无外键、JOIN 自动排除孤立日志、UI 文案准确，推荐不做删除功能 |
| C.18-a | "保持新词"HTTP/DB 回归验证，确认后端行为、payload、数据库闭环正确；真实浏览器视觉验收待完成 |
| C.18-b-partial | "保持新词"Playwright/Network/DB 验证，checkbox 与 payload 通过；token 颜色截图、词 A 词汇页显示、等级按钮 7 浏览器动作待补 |
| C.18-c | 补齐"保持新词"视觉验收与测试账户隔离，确认 token 颜色、词汇页显示、等级按钮 7 浏览器动作 |

---

## 四、当前最新状态

**Latest commit**：`83e0a14 docs: add scout for review log deletion semantics`

### `/review-cards/manage` 当前能力

- **搜索**：`q` 参数，搜索 Lemma / Surface / 中文释义 / 英文释义 / 英文例句。
- **预设筛选**：`filter` 参数，支持 全部、到期、未来到期、未归档、已归档、缺释义、缺例句、缺溯源。
- **高级筛选**：`fsrs_states[]`（new/learning/review/relearning 多选）、`due_range`（all/overdue/today/next7/future/none）、`reps_min`、`lapses_min`。
- **排序**：`sort_by` + `sort_dir`，服务器端白名单排序 + tie-breaker。
- **分页**：`page` + `per_page`（默认 20，最大 100）。
- **FSRS stats chips**：总词义卡、启用中、已归档、当前到期、新卡、学习中、复习中、重新学习、今日已复习、今日重置。
- **表格列**：checkbox、ID、Lemma、Surface、POS、中文释义、英文释义、英文例句、中文例句、溯源、状态（fsrs_state + 归档 chip）、稳定度、难度、复习（reps）、遗忘（lapses）、最近复习、到期（due_at）、操作。
- **操作**：行内编辑（POS/释义/例句白名单）、归档/恢复、立即到期，以及"更多"菜单（查看原文、重置为新学卡、彻底删除），批量归档/恢复/删除。
- **列显示自定义**：列设置按钮，可隐藏/显示列，localStorage 持久化。常驻列（checkbox、Lemma、释义(中)、状态、操作）不可隐藏。默认隐藏 释义(英)、例句(英)、例句(中)。支持恢复默认和全部显示。
- **紧凑模式**：列设置菜单中的 v-switch，localStorage 持久化。通过 CSS class `table--compact` 缩小 padding/字体/窄列宽度。标准 tableMinWidth 1640，紧凑 1480。"恢复默认"同时关闭紧凑模式。

### 手动添加释义（阅读页）

- **保持新词**：手动添加释义表单中的 "保持新词" v-checkbox。勾选后保存释义和复习卡，但不把该词的 EncounteredWord 从 New (stage=2) 升级为 Learning 7 (stage=-7)。
- **实现**：前端 `WordSensesList.vue` → `keep_new` boolean payload → Controller 验证 `'keep_new' => ['nullable', 'boolean']` → `WordSenseService::createManualSense()` 在 `stage===2` 且 `keep_new=true` 时跳过 `setStage(-7)`。
- **安全边界**：keep_new 仅对 stage===2 (New) 生效；已进入 Learning (stage<0)、Known (stage=0)、Ignored (stage=1) 的词不受影响。

### 复习日志删除语义（C.14）

- **结论**：不做 ReviewLog 删除功能。当前设计已正确且安全。
- **关键事实**：`review_logs.review_card_id` 无外键约束（删除 ReviewCard 不 cascade 删除日志）。`ReviewStatsService::baseLogQuery()` 通过 JOIN review_cards 自动排除孤立日志。`WordSenseService::removeSenseFromReviewSystem()` 明确保留 ReviewLog。
- **UI 准确性**：删除弹窗文案 "复习历史会保留" 与代码行为完全一致，无需修正。

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

**C.18-a HTTP/DB 回归验证**（2026-06-24）：
- 基础检查：134 个 WordSense 测试全部通过，60 个 ReviewFsrsTest 全部通过，npm run development 编译成功。
- 验收 A（默认行为 keep_new=false）：通过 HTTP 层集成测试 — payload `keep_new` 不传或 false，response `updated_word.stage=-7`，`stage_changed=true`。DB 确认 stage 从 2 变为 -7。
- 验收 B（保持新词 keep_new=true）：通过 HTTP 层集成测试 — payload `keep_new=true`，response `updated_word.stage=2`，`stage_changed=false`。DB 确认 stage 保持 2。后续手动 setStage(-7) 成功将词从 New 升级为 Learning 7。
- 数据库核验：WordSense 创建正确（status=confirmed, encountered_word_id 正确），ReviewCard 创建正确（target_type=sense, fsrs_state=new, fsrs_enabled=1）。
- 结论：C.13-a 的保持新词功能在后端行为上完全正确，默认行为未退化。
- ⚠️ **未完成真实浏览器视觉验收**：未验证 checkbox 真实默认状态截图、阅读页 token 颜色变化、/vocabulary/search 视觉显示。这些需要真实浏览器（C.18-b）补充。

**C.18-b-partial Playwright/Network/DB 验证**（2026-06-25）：
- 使用 Playwright + Chrome headless 进行浏览器交互验证。
- 验收 A（默认行为，词 sharply）：checkbox 默认 UNCHECKED ✅ → payload `keep_new: false` ✅ → response `stage: -7, stage_changed: true` ✅ → DB 确认 sharply stage=-7 ✅。
- 验收 B（保持新词，词 stores）：checkbox 默认 UNCHECKED ✅ → 勾选后 CHECKED ✅ → payload `keep_new: true` ✅ → response `stage: 2, stage_changed: false` ✅ → DB 确认 stores stage=2 ✅ → /vocabulary/search 显示"新词" ✅。
- 数据库核验：WordSense 创建正确、sense ReviewCard 创建正确（fsrs_state=new, fsrs_enabled=1）。
- ❌ **未完成项**：
  - 未提供 token 颜色变化截图（词 A 从 New→Learning 7 绿色，词 B 保持 New 颜色）。
  - 未提供词 A 在 /vocabulary/search 的浏览器显示证据。
  - 未在浏览器中执行词 B 的等级按钮 7 点击动作。
  - ⚠️ 修改了真实用户 5（1816529781@qq.com）的密码为临时密码 `test123456`，原 bcrypt hash 未完整保存，需用户手动恢复或重置密码。

	**C.18-c 补齐视觉验收与测试账户隔离**（2026-06-25）：
	- **账号隔离方式**：方式 A — 复用用户 5 既有 session（`R2XqJGnAUzRzZrsF3TMdJnoE0d3zDafmJbxAe5yN`），通过 `CookieValuePrefix` 正确加密后注入 Playwright cookie，浏览器全程 headless，无需密码。
	- **未修改真实用户 5 密码**：本轮未写入或修改用户 5 的 password hash。
	- **词 A（sharply, id=2811, stage=-7）**：
	  - token 绿色截图：`c18c-token-a-green.png` ✅
	  - /vocabulary/search 等级显示截图：`c18c-vocabulary-a-learning.png` ✅
	- **词 B（stores, id=2812, stage=2→-7）**：
	  - 保持 New 颜色截图：`c18c-token-b-keep-new.png` ✅
	  - 浏览器点击等级按钮 7 成功，DB 确认 stage 从 2 变为 -7 ✅
	  - 点击后 token 绿色截图：`c18c-token-b-after-click-7.png` ✅
	- **数据库只读核验**：`SELECT id, word, base_word, study_base, stage FROM encountered_words WHERE id IN (2811, 2812)` — 2811 stage=-7，2812 stage=-7 ✅
	- **结论**：C.13-a 保持新词功能浏览器视觉验收通过。token 颜色、词汇页等级显示、等级按钮 7 浏览器动作均已截图验证。

---

### C.14 — 删除复习记录语义

**状态**：C.14-scout 侦察完成，推荐 A — 不做删除 ReviewLog 功能。

**侦察关键事实**：
1. `review_logs.review_card_id` **无外键约束**（migration 仅建 index，无 `->constrained()` 或 `->onDelete()`）。删除 ReviewCard 时 ReviewLog **不会被 cascade 删除**，日志作为孤立记录保留在 DB。
2. `ReviewStatsService::baseLogQuery()` 通过 `join('review_cards')` 过滤 ReviewLog → **已删除 ReviewCard 的日志自动被统计排除**（JOIN 无匹配行）。
3. `WordSenseService::removeSenseFromReviewSystem()` 注释明确 "Does NOT delete review_logs"，控制器 `destroy()` 返回消息 "复习历史已保留"。
4. 测试 `test_destroy_preserves_review_logs()` 和 `test_bulk_destroy_preserves_review_logs()` 断言删除后 ReviewLog 数量不变。
5. **当前 UI 文案准确**："阅读材料、原文位置和复习历史会保留" — 与代码行为一致。
6. **当前系统已正确且安全**：孤立日志不污染统计、不造成数据异常、不引入额外风险。

**推荐方案 A — 不做删除 ReviewLog 功能**。

理由：
- 数据安全：当前设计天然防止 stats 污染，加入删除日志功能会引入误删风险。
- 统计正确性：`baseLogQuery()` 的 JOIN 保证只有活跃 ReviewCard 的日志被统计，是最优雅的设计。
- 不可恢复性：ReviewLog 记录了 FSRS 状态快照（previous_*/new_* 字段），是不可替代的历史数据，硬删除后无法重建。
- 最低风险：不新增 API 参数、不新增 checkbox、不需要 migration、不修改测试。
- 文案已准确：无需修正。

**如果未来确实需要删除日志**（例如清理 3 年以上的孤立日志），建议作为独立的 `review-logs:prune` artisan 命令开发，而非嵌入删除卡片流程。

---

### 下一阶段候选任务

以下任务为候选，均未冻结实现。C.18-a 已完成，C.18-b-partial 部分完成。

| 优先级 | 编号 | 内容 | 类型 | 理由 |
|--------|------|------|------|------|
| ★★★★ | — | （C.18-c 已完成，见 C.13 段落） | — | C.18-c 已补齐视觉证据，账号隔离完成 |
| ★★★ | C.15-scout | 复习卡管理页详情抽屉侦察 | 功能侦察 | 管理页已有大量列、编辑、筛选、隐藏、紧凑模式；下一步增强管理页时，详情抽屉比继续塞列更合理 |
| ★★☆ | C.16-scout | 当前筛选结果导出侦察 | 功能侦察 | 用户可能希望把管理页筛选后的复习卡导出给 GPT/Anki/备份；当前延后中，需重新侦察范围 |
| ★★☆ | C.17-scout | ReviewLog 历史展示侦察 | 功能侦察 | C.14 确认 ReviewLog 保留但不删除；如未来要利用日志，应先侦察只读展示而非删除 |
| ★☆☆ | — | 卡片详情抽屉实现 | 功能实现 | 延后，等待 C.15-scout 侦察结果 |
| ★☆☆ | — | 导出当前筛选结果实现 | 功能实现 | 延后，等待 C.16-scout 侦察结果 |
| ★☆☆ | — | ReviewLog 历史展示实现 | 功能实现 | 延后，等待 C.17-scout 侦察结果 |

**建议下一步**：先做 **C.15-scout** — 复习卡管理页详情抽屉侦察。理由：C.18-c 已补齐所有视觉证据，账号隔离流程已确立；C.13 保持新词功能全链路验证通过（HTTP + DB + Playwright + 视觉截图）。

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

### Decision 4 — C.14 不做 ReviewLog 删除功能

**日期**：2026-06-24

- C.14-scout 确认 `review_logs.review_card_id` 无外键约束，删除 ReviewCard 后日志保留为孤立记录。
- `ReviewStatsService::baseLogQuery()` 通过 JOIN review_cards 自动排除孤立日志，统计不受影响。
- `WordSenseService::removeSenseFromReviewSystem()` 明确保留 ReviewLog，控制器消息 "复习历史已保留" 与代码行为一致。
- 当前 UI 文案准确："复习历史会保留"。
- 因此不在删除卡片流程中加入 `delete_review_logs`，不加 checkbox，不加 API 参数。
- 如未来需要清理历史孤立日志，应做独立 `review-logs:prune` artisan 命令，默认 dry-run，远离核心删除流程。

### Decision 5 — 验收证据分级

**日期**：2026-06-24

- **后端/数据闭环证据**：GitHub 代码侦查、自动化测试（php artisan test）、HTTP 层集成测试（Auth::login + Controller 调用）、数据库核验（SQL 查询验证）可以证明后端行为和数据闭环正确。
- **这些不能替代真实浏览器验收**：自动化测试和 HTTP 层测试无法验证 UI 颜色、checkbox 默认状态、Network DevTools payload、页面跳转行为、词汇页视觉显示。
- **涉及 UI 的任务必须提供真实浏览器证据**：包括但不限于 checkbox 视觉状态截图、token 颜色变化截图、DevTools Network 面板截图、/vocabulary/search 页面截图。
- **没有真实浏览器证据时的 roadmap 记录规则**：只能写"HTTP/DB 验证通过，浏览器视觉验收待完成"，不得写"浏览器回归验收完成"或"浏览器验收通过"。

### Decision 6 — 浏览器验收不得修改真实用户认证数据

**日期**：2026-06-25

- 自动化浏览器验收不得修改真实用户密码。
- 如需登录，应使用既有测试账号，或让用户手动登录后复用 session（如 Playwright 的 `storageState`）。
- 如果必须创建测试账号，必须先征得用户确认，并明确账号、密码、清理方式。
- 不得把真实用户密码改成公开临时密码（如 `test123456`）。
- 验收报告必须区分视觉证据、Network 证据、DB 证据，不能用 DB 推断代替视觉截图。

---

## 八、后续更新规则

以后每次新增、调整、延期、验收大计划项目，都必须同步修改本文件，并在 commit message 中使用 `docs` 或 `chore` 前缀，例如：

```
docs: update fsrs roadmap
```

**禁止**：以聊天记录作为唯一计划来源。所有路线变更必须落盘到本文档。
