# LinguaCafe FSRS / Sense Review Roadmap

> **最后更新**：2026-06-26
> **上一轮已验收基线 commit**：`83880cc`

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
| C.15-a | 管理页详情抽屉第一版，前端-only 展示当前行已有字段，不新增 API |
| C.16-a | 管理页当前筛选结果 JSON 导出，复用筛选/排序条件，不分页导出，5000 条上限 |
| C.17-a | 管理页详情抽屉显示最近 20 条 ReviewLog，只读展示 rating/source/FSRS 前后状态 |
| C.15-b | 详情抽屉显示 aliases_zh / collocations，data/export item 字段同步补齐，只读展示 |
| C.20-a | 管理页 JSON 导出字段选择，fields[] 白名单导出，不改变筛选/排序/分页逻辑 |
| C.21-scout | Anki 导出格式侦察 — 旧 AnkiConnect 接口为 legacy word-card 模式，不适合 sense-only；推荐 C.21-a 做 Anki TSV 文件导出 |
| C.22-scout | CSV 导出侦察 — 复用 JSON/TSV 导出基础，确认 CSV 有价值但不紧急，推荐 C.22-a 冻结实现 |
| C.22-a-lite | CSV 导出实现 — 新增 `/review-cards/manage/export-csv`，复用 buildManageQuery/buildItems/EXPORT_FIELDS/EXPORT_LIMIT，fputcsv + BOM + formula injection 防护 |
| C.23-scout | 详情抽屉 ReviewLog 可读性优化侦察 — 确认 rating/state/source 可中文化，FSRS 数值可本地化，建议 C.23-a 冻结实现 |
| C.24-scout | 管理页真实用户批量操作风险复查 — 识别 4 类 Medium 风险（bulk 无确认弹窗/无事务/无上限），推荐 C.24-a 安全加固 |
| C.24-a-lite | 管理页批量操作安全加固 — bulkEnabled/bulkDestroy 使用 DB::transaction、bulkArchive/bulkRestore 增加确认弹窗、reset 弹窗增加不可恢复提示、新增 reset 隔离与 missing ID 测试 |
| D.1-scout | FSRS 设置页 Anki 对标侦察 — 分析当前 FSRS 设置页已有/占位/缺失能力，对标 Anki FSRS 6 大功能，建立独立拆解文档 fsrs-settings-anki-gap-plan.md，在大计划纳入 D 系列 |
| D.2-a | FSRS 设置页信息架构重排 — 重排为复习目标/当前FSRS状态/高级工具三区，修正卡片重置过时文案，新增前往管理页按钮 |
| D.2-b | Desired Retention 帮助说明增强 — 每个 retention 值增加一句话解释，90% 标注"推荐默认值"，低保持率保留可选 |
| D.2-c | 复习负担预估第一版 — 在复习目标区增加小估算框，使用当前 stats 数据做粗略每日复习量预估，提示负担变化 |
| CODEX-FSRS-1 | FSRS 设置页 Anki-like 增强 — 修正 latest commit hash，落地简单模式/高级工具雏形，新增自动优化参数按钮、optimization-status 与 optimize preflight 接口；第一版只检查资格，不真实计算或保存参数 |
| FSRS-D3-scout | FSRS 参数优化可行性侦察 — 确认 fsrs-rs-php 已安装 `compute_parameters()` API；ReviewLog 数据完整可构造训练集；无需新增依赖或 migration；输出独立计划文档 fsrs-parameter-optimization-plan.md；未实现真实优化 |
| FSRS-D3-a | FSRS 参数优化后端最小实现 — computeFsrsOptimizationPreview() 构建训练集调用 compute_parameters() 返回优化预览；SettingsController 接入；17 个新测试（含隔离/过滤/无副作用验证）；不保存参数，不重排卡片 |
| FSRS-D3-c | FSRS 参数优化前端预览 — AdminReviewSettings.vue 增加预览卡片：展示 review_count/card_count/parameter_count、参数摘要（变化数量/最大幅度）、可展开参数明细表格、安全提示（不保存/不重排）；记录不足保持原有提示 |
| FSRS-D3-b | FSRS 参数优化确认保存 — applyFsrsOptimizedParameters() 服务端重新计算并保存 4 个 settings（parameters/source/optimized_at/previous）；Vue confirm 按钮 + loading + 成功提示；24 个测试（+7 新增）；不重排卡片 |
| FSRS-D2-d | FSRS 参数来源与版本说明 — getFsrsOptimizationStatus() 重写读取真实 settings（default/optimized/unknown 三态）；Vue 前端根据状态展示对应文案 + 优化时间 + 参数数量；4 个新测试（28 total）；不涉及参数保存/重排 |
| FSRS-D2-d-fix | D.2-d 修复 — json_encoded settings 读取修复 + v-chip "正在优化参数" + 29 测试 |
| FSRS-D3-d | 调度器参数集成 — FsrsSchedulingService.getActiveFsrsParameters() 从 settings 读取优化参数传入 FSRS() 构造；9 单元测试；不重排卡片，不改历史 ReviewLog |
| RightClickPanel-2-scout | 自动创建复习卡 + 最相关词义默认展开可行性侦察 — 结论：`+ 添加为新释义` 已自动创建 sense review card（无需额外开发）；最相关词义默认展开尚未实现，推荐方案见 right-click-panel-word-sense-plan.md；词典结果无高度限制需加 scroll |
| RightClickPanel-3-a | 最相关词义默认展开 + 词典结果高度限制 — WordSensesList 默认只展开第一个含 confirmed sense 的词性组；VocabularySearchBox 词典结果加 max-height scroll；按钮增加 title 提示保存后会加入词义复习；纯前端，不改后端 |
| RightClickPanel-1 | 右侧点词面板 WordSense UI 功能改造 — 右侧点词面板完成分区整理；词典结果新增“添加为新释义”入口；WordSense 区域改为更清晰的展开/折叠管理卡片与空分组添加入口；不改 FSRS 调度，不改 sense-only 语义 |
| D.4-c-a | 正式重排 confirm preflight API — 后端路由 + Controller + Service confirmPreflight() + preview_hash 校验 + computePreviewData() 复用 + 安全阈值（MAX_NEWLY_DUE_TODAY=200, MAX_TOTAL_CHANGED=2000）+ write_enabled=false 硬编码 |
| D.4-c-b | 正式重排 ReviewCard 写入逻辑 — confirmAndApply() + risk_confirm 支持 + preview_hash 参数顺序修复 |
| D.4-c-d | ✅ 已完成 — 前端确认按钮 + 二次确认弹窗 + 风险确认流程 |
| D.4-c-d-fix | ✅ 已完成 — 成功提示保留 + 409 过期预览强制重新预览 |
| D.4-c-e | ✅ 已完成 — 真实数据联调与浏览器 smoke 总验收 |
| D.4-d-scout | ✅ 已完成 — 重排撤销机制侦察，推荐快照表方案 C，分三步开发 |
| D.4-d-a | ✅ 已完成 — 快照表 schema + 创建快照记录（reschedule_snapshots + reschedule_snapshot_items） |
| D.4-d-b | ✅ 已完成 — 撤销后端 API（POST /settings/fsrs/reschedule-undo + undo 逻辑 + 16 测试） |
| D.4-d-b-fix | ✅ 已完成 — 补全事务内二次校验 + 修复过期提示语义 |
| D.4-d-c | ✅ 已完成 — 前端撤销按钮 + 确认弹窗（AdminReviewSettings.vue +141 行） |
| D.4-d-c-fix | ✅ 已完成 — 修复撤销成功提示被 v-if 隐藏 + 修正"确认后不可撤销"旧文案 + 写入网状协作报告门禁规则 |
| D.4-d-e | ✅ 已完成 — 真实浏览器 smoke + 全链路验收（含最小文案收口） |
| D.4-final-review | ✅ 已完成 — FSRS D.4 全阶段最终 Review — 可阶段性收口 |
| UI-Anki-Scout-1 | ✅ 已完成 — 全界面 Anki 对标侦察，评估 7 个页面的差距与优先级 |
| UI-Anki-Review-Scout | ✅ 已完成 — 刷卡页学习节奏重构侦察，推荐混合型体验，分 5 Phase 实现 |
| UI-Review-a | ✅ 已完成 — SenseReview 信息层级整理（stats 精简、More 菜单、FSRS 折叠） |
| UI-Review-b | ✅ 已完成 — SenseReview 显示答案流程（先问题 → 显示答案 → 再评分） |
| UI-Review-c | ✅ 已完成 — SenseReview 键盘快捷键（Space 显示答案，1/2/3/4 评分） |
| UI-Review-e | ✅ 已完成 — 真实浏览器 smoke 验收记录（代码审查通过，浏览器 Incomplete） |

---

## 四、当前最新状态

**Latest commit**：`1f64e3a`（UI-Review-c 基线，UI-Review-e 验收记录新增 docs）

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

### C.15 — 复习卡管理页详情抽屉

**状态**：C.15-scout 侦察完成（C.12 阶段已间接侦察）；**C.15-a 第一版已完成**；**C.15-b 已完成**。

**已完成**：
- C.15-a：管理页详情抽屉第一版，前端-only。
  - 在操作列增加"详情"按钮（外显，位于编辑与归档/恢复之间）。
  - 点击打开 `v-navigation-drawer`（right/temporary/fixed，width=420px）。
  - 抽屉展示 5 个分区：基本信息、释义信息、溯源信息、FSRS 信息、缺失状态。
  - 所有数据来自 `/review-cards/manage/data` 已返回字段，**不新增后端 API**。
  - "查看原文"按钮复用现有 `viewSource()` 和 `SenseExampleDialog`。
  - 空值统一显示 `—`，boolean 显示 `是/否`。
  - 抽屉内不提供编辑、归档、恢复、立即到期、重置、删除操作。
  - 不影响表格筛选、排序、分页、列显示设置、紧凑模式。
  - 不影响现有编辑状态和 source dialog。
- C.15-b：详情抽屉显示 aliases_zh / collocations。
  - `buildItems()` 返回的 item 新增 `aliases_zh` 和 `collocations` 字段，与 `serializeCard()` 格式一致。
  - 详情抽屉"释义信息"分区新增"近义译法"和"搭配"两行，使用 v-chip 展示数组内容。
  - 空数组显示 `—`，前端容错 `null` 值。
  - `export()` 因复用 `buildItems()`，JSON 导出自然包含这两个字段。
  - 不新增 API。
  - 不改 routes。
  - 不做编辑功能。
  - 不新增 Route。
  - 不新增接口。
  - 不查 ReviewLog。

**实现文件**：`ReviewCardManageController.php`、`ReviewCardManage.vue`、`ReviewCardManageTest.php`。

---

### C.16 — 当前筛选结果导出

**状态**：C.16-scout 侦察完成（C.16-a 已实现第一版）；**C.16-a 已完成**。

**已完成**：
- C.16-a：当前筛选结果 JSON 导出。
  - 新增路由 `GET /review-cards/manage/export` → `ReviewCardManageController::export()`。
  - 复用 `buildManageQuery()` 安全约束（user/language/target_type=sense/confirmed sense）。
  - 复用 `applyFilters()` / `applyAdvancedFilters()` / `applySort()` 筛选排序逻辑。
  - 不分页 — 导出全部匹配结果（上限 5000 条，超限返回 422）。
  - JSON 结构：`exported_at` / `language` / `filters` / `count` / `items`。
  - 前端"导出"按钮（toolbar 列设置旁），`responseType: 'blob'` 下载 JSON 文件。
  - 文件名：`review-cards-export-YYYYMMDD-HHMMSS.json`。
  - 17 个测试覆盖：元数据、安全隔离、筛选复用、搜索、高级筛选、排序、不分页、auth 检查。

**不导出**：
- ReviewLog 历史。
- Legacy word card (`target_type=word`)。
- Rejected WordSense。
- Source full context（仅在导出中包含 source_chapter_title 和 source_kind）。

**第一版限制**：
- 只做 JSON，不做 CSV。
- 不做 Anki 包。
- 不分页 — 上限 5000。

**实现文件**：`routes/web.php`, `ReviewCardManageController.php`, `ReviewCardManage.vue`, `ReviewCardManageTest.php`。

---

### C.17 — 管理页详情抽屉显示最近复习记录

**状态**：C.17-scout 已完成（C.14 / C.15-a 已铺垫侦察）；**C.17-a 第一版已完成**。

**已完成**：
- C.17-a：管理页详情抽屉显示最近 20 条 ReviewLog。
  - 新增路由 `GET /review-cards/manage/{reviewCard}/logs` → `ReviewCardManageController::logs()`。
  - 安全策略：先调用 `findManageableSenseCard()` 确保卡片属于当前用户/语言/target_type=sense/sense=confirmed，再按 `user_id` + `language_id` + `review_card_id` 查询 ReviewLog。
  - `orderBy reviewed_at desc`，`limit 20`。
  - 返回字段：`id` / `rating` / `source` / `reviewed_at` / `previous_state` / `new_state` / `previous_due_at` / `new_due_at` / `previous_stability` / `new_stability` / `previous_difficulty` / `new_difficulty`。
  - 前端在详情抽屉中新增"最近复习记录"分区（位于 FSRS 信息之后、缺失状态之前）。
  - 展示 rating 彩色 chip（again=red, hard=orange, good=green, easy=blue, reset=grey）、source、reviewed_at、状态变化（previous_state→new_state）、stability/difficulty 变化、due_at 变化。
  - Loading/error/empty 三态处理。
  - 8 个测试覆盖：正常返回、限 20 条、拒绝其他用户/语言/legacy word/rejected sense、不混入其他卡片日志、空日志。

**第一版限制**：
- 只读展示，不提供编辑/删除/重置操作。
- 只显示当前 live/manageable sense card 的日志。
- 不展示 orphan logs（已删除 ReviewCard 的孤立日志）。
- 不做图表。
- 不做分页。
- 不导出 ReviewLog。
- 不删除 ReviewLog。
- 最多 20 条。

**实现文件**：`routes/web.php`, `ReviewCardManageController.php`, `ReviewCardManage.vue`, `ReviewCardManageTest.php`。

---

### C.20 — 管理页 JSON 导出字段选择

**状态**：C.20-a 已完成。

**已完成**：
- C.20-a：管理页 JSON 导出字段选择。
  - 后端 `EXPORT_FIELDS` 白名单常量，`export()` 支持 `fields[]` 参数。
  - 不传 `fields` 时导出默认全部字段。
  - 传入 `fields[]` 时只保留白名单字段。
  - 全部无效字段返回 422（含 `allowed_fields`）。
  - 顶层 metadata 新增 `fields` 数组。
  - 前端导出按钮改为 v-menu，标题"选择导出字段"。
  - 菜单内 24 个字段 checkbox，中文 label。
  - 全选 / 恢复默认 / 导出 JSON 三个按钮。
  - 未选字段时前端阻止导出并 snackbar 提示。
  - 不影响表格列设置、详情抽屉、筛选、分页。
  - 不做 CSV。
  - 不做 Anki。
  - 不做 ReviewLog 导出。
  - 不新增数据库结构。
  - 5 个测试覆盖：默认全部字段、选定字段过滤、过滤无效字段、全无效返回 422、metadata 记录字段。

**实现文件**：`ReviewCardManageController.php`、`ReviewCardManage.vue`、`ReviewCardManageTest.php`。

---

### C.21 — Anki 导出格式侦察与实现

**状态**：已完成（C.21-scout 侦察 + C.21-a 第一版实现）。

**侦察结论**：
1. 当前项目中旧 AnkiConnect 接口（路由 `POST /anki/add-card` → `AnkiController::addCardToAnki()`）为 legacy word-card 模式设计，依赖 word-level 字段（`word`, `reading`, `translation`, `exampleSentence`），不兼容 sense-only 主线。
2. 接口实现文件：`app/Services/AnkiApiService.php`（类 `AnkiApiService`，方法 `addWord()`）→ 通过 AnkiConnect HTTP API 调用 Anki 桌面客户端，不适合无 GUI 环境批量导出。请求校验类：`app/Http/Requests/Anki/AddCardToAnkiRequest.php`（字段：`word` required, `reading`, `translation`, `exampleSentence` nullable string）。Anki Note 模型字段：`word`, `reading`, `translation`, `example_sentence`。
3. 旧接口无 tests，无 WordSense 支持，无 ReviewCard 关联，直接复用风险高。
4. 推荐不改造旧 AnkiConnect，而是新建 **Anki TSV 文件导出**（C.21-a）：生成标准 Anki TSV 文件（字段分列，可导入 Anki Desktop/AnkiDroid），不依赖 AnkiConnect HTTP API，不做 apkg/anki 包，输出文件可用户手动导入 Anki。

**C.21-a 实现**（regression fix 后）：
- 路由 `GET /review-cards/manage/export-anki-tsv` → `ReviewCardManageController::exportAnkiTsv()`
- **只导出当前筛选/排序结果**：复用 `buildManageQuery()`，无 mode 参数，无 `all`/`selected` 模式，无 `card_ids`
- **固定 13 列**：`Front`、`Back`、`Lemma`、`Surface`、`POS`、`SenseZh`、`SenseEn`、`ExampleEn`、`ExampleZh`、`AliasesZh`、`Collocations`、`Source`、`FsrsState`
- `Front` / `Back` 为 HTML 拼接的问答面（含例句、lemma、释义、搭配、来源等），其余 11 列为原始字段
- 导出时对 `\t`、`\r`、`\n` 替换为空格，不添加 UTF-8 BOM
- `Content-Type: text/tab-separated-values; charset=UTF-8`，文件名 `review-cards-anki-YYYYMMDD-HHMMSS.tsv`
- 响应头 `X-Export-Count` 返回导出条数；超限时返回 422 JSON（含 `message`/`total`/`limit`）
- Vue：导出菜单底部只保留一个 "导出 Anki TSV" 按钮，不传 mode/card_ids
- 5 个测试覆盖：固定 13 字段下载、用户/语言/sense-only 隔离、筛选复用、超限 422（skip）、tab/newline 消毒
- 未做：apkg 包、CSV/Excel 格式、自定义字段映射、AnkiConnect 集成、`all`/`selected` 导出模式
- **C.21-a-html-escape-fix**：Front / Back 中来自用户文本的内容已 HTML escape（`<` → `&lt;` 等），仅保留固定 `<strong>` / `<br>` 标签，避免 Anki HTML 面板渲染用户输入 HTML。

**决策**：不改造旧 AnkiConnect，不做 AnkiConnect 集成。旧 AnkiConnect 接口保留不动。TSV 导出端点独立、简单、只支持当前筛选结果。

---

### C.22-scout：CSV 导出侦察

#### 当前已有导出能力

| 能力 | 路由 | Controller 方法 | 行号 |
|------|------|----------------|------|
| JSON 导出 | `GET /review-cards/manage/export` | `ReviewCardManageController::export()` | routes L206, controller L126 |
| Anki TSV 导出 | `GET /review-cards/manage/export-anki-tsv` | `ReviewCardManageController::exportAnkiTsv()` | routes L207, controller L203 |

**共享基础**：
- `buildManageQuery()` (controller L636)：强制 user_id + language_id + TARGET_SENSE + confirmed WordSense；支持 q 搜索、8 种标准 filter、4 种 advanced filter、白名单排序
- `buildItems()` (controller L758)：批量预取 occurrence chapter + chapter name，映射 25 个字段
- `EXPORT_LIMIT = 5000` (controller L58)
- `EXPORT_FIELDS`：25 个白名单字段（review_card_id, word_sense_id, lemma, surface_form, pos, sense_zh, sense_en, example_sentence_en, example_sentence_zh, aliases_zh, collocations, source_chapter_id, source_chapter_title, source_kind, fsrs_state, fsrs_due_at, fsrs_stability, fsrs_difficulty, fsrs_reps, fsrs_lapses, fsrs_last_reviewed_at, fsrs_enabled, missing_definition, missing_example, missing_source）

**差异**：
- JSON 导出：支持 `fields[]` 参数字段选择、返回 JSON（含 metadata）、无 HTML 转义、Content-Type: application/json
- Anki TSV 导出：固定 13 列（Front/Back/Aux）、Front/Back 含 HTML（`htmlEscape` + `tsvEscape`）、Content-Type: text/tab-separated-values

**前端**：
- Vue 组件 `ReviewCardManage.vue`：导出菜单含 24 字段复选框 + "导出 JSON" + "导出 Anki TSV" 按钮
- `exportCurrentFilter()`：构造 params（q, filter, sort_by, sort_dir, fsrs_states, due_range, reps_min, lapses_min, fields）
- `exportAnkiTsv()`：同上但不含 fields

**测试**：
- JSON 导出测试：20 个（隔离 4 个、过滤/搜索/排序 5 个、字段选择 4 个、超限 1 个、认证 1 个、其他 5 个）
- Anki TSV 导出测试：6 个（固定字段、隔离、过滤、超限 skipped、tab/newline 转义、HTML 转义）
- 无 CSV 导出测试

#### CSV 导出必要性分析

| 对比维度 | CSV | JSON | Anki TSV |
|----------|-----|------|----------|
| 用途 | 表格分析 / Excel / WPS / Google Sheets / Numbers | 程序化处理 / API | Anki 导入复习 |
| 用户价值 | ✅ 高：用户可用 Excel/WPS 打开、筛选、排序、做数据分析 | 中等：需编程解析 | 特定：仅 Anki 用户 |
| 格式复杂度 | 中等：RFC 4180 引号转义 | 简单 | 简单：TSV 只需 tab/newline 转义 |
| 字段灵活性 | 可支持 fields[] 选择 | ✅ 已支持 | 13 列固定 |
| 中文兼容 | 需 UTF-8 BOM（Excel 要求） | 天然兼容 | 无要求 |

**建议**：CSV 有价值，但不紧急。优先级在 C.22-a（如果冻结）中可排为中等。

#### CSV 导出风险清单

1. **安全隔离**（低风险）：完全复用 `buildManageQuery()` 的 user_id + language_id + TARGET_SENSE + confirmed 限定，禁止绕过或使用 card_ids
2. **Excel formula injection**（高风险）：字段以 `=` `+` `-` `@` 开头时 Excel 会当作公式执行，必须对所有以这些字符开头的单元格加单引号前缀转义
3. **RFC 4180 转义**（中风险）：含逗号、双引号、换行符的字段需双引号包裹 + 内部双引号加倍转义
4. **UTF-8 BOM**（低风险）：Excel 打开 UTF-8 CSV 中文乱码，需加 BOM（`\xEF\xBB\xBF`），但现有 JSON/TSV 均无 BOM
5. **多行字段**（中风险）：example_sentence_en/zh 可能含换行，CSV 换行是记录分隔符，需转义
6. **数据量**（低风险）：沿用 EXPORT_LIMIT=5000，突破即 422
7. **禁忌范围**：禁止导出 legacy word card、ReviewLog、非 confirmed sense、other user/language

#### C.22-a 推荐冻结方向

1. **路由**：`GET /review-cards/manage/export-csv` → `ReviewCardManageController::exportCsv()`
2. **复用**：完全复用 `buildManageQuery()` + `buildItems()` + `EXPORT_FIELDS` + `EXPORT_LIMIT`
3. **范围**：仅当前筛选/排序结果（与 JSON 导出行为一致）
4. **字段选择**：支持 `fields[]` 参数，沿用 JSON 导出的白名单校验逻辑
5. **CSV header**：英文字段名（与 EXPORT_FIELDS 一致），不输出中文 header
6. **Formula injection 防护**：对所有以 `=` `+` `-` `@` `\t` 开头的单元格加单引号前缀 `'`
7. **RFC 4180 转义**：用 `fputcsv()` 或 league/csv 处理逗号/引号/换行转义
8. **BOM**：加 UTF-8 BOM（仅 CSV 导出，JSON/TSV 保持无 BOM），确保 Excel 中文兼容
9. **Content-Type**：`text/csv; charset=UTF-8`
10. **不导出**：ReviewLog、legacy word card、非 confirmed sense

#### C.22-a 禁止范围

- 不做 all/selected/card_ids 模式参数
- 不做 all/selected 模式
- 不做 card_ids 参数
- 不做导入
- 不做编辑
- 不改 Anki TSV 导出
- 不改 JSON 导出
- 不改 `buildManageQuery()` / `buildItems()` 签名
- 不做数据库 migration
- 不做 ReviewLog 导出
- 不做 legacy word card 导出
- 不新增 Service 层
- 不调用 AnkiConnect
- 不做 Excel 公式执行回测（只做 escape，不做验证是否被 Excel 执行）
- CSV header 不用中文

---

### C.22-a-lite：CSV 导出实现（已完成）

**实现时间**：2026-06-25

**路由**：`GET /review-cards/manage/export-csv` → `ReviewCardManageController::exportCsv()`

**实现要点**：
- 复用 `buildManageQuery()`、`buildItems()`、`EXPORT_FIELDS`（25 字段）、`EXPORT_LIMIT`（5000 条）
- 支持 `fields[]` 参数，复用 JSON export 白名单，默认全字段
- 使用 `fputcsv()`（PHP 内置），UTF-8 BOM
- Excel formula injection 防护：`=` `+` `-` `@` tab CR LF 开头的单元格前加单引号 `'`
- 数组字段 `aliases_zh`/`collocations` 用 `；` 拼接
- `null` → 空字符串
- 超过 5000 条返回 422 JSON
- Vue：在已有导出菜单底部新增"导出 CSV"按钮，使用当前字段选择 `exportFields` 和筛选条件

**测试**（6 个）：
1. `test_export_csv_downloads_selected_fields` — fields[]=lemma&sense_zh&fsrs_state，断言 BOM + header + 数据
2. `test_export_csv_uses_current_user_language_and_sense_only_scope` — 用户/语言/sense 隔离
3. `test_export_csv_respects_current_filters` — q + fsrs_states 筛选
4. `test_export_csv_escapes_rfc4180_values` — 逗号/双引号/换行 → fgetcsv 回读
5. `test_export_csv_prevents_excel_formula_injection` — `=SUM` `+cmd` `-10` `@user` 全部带引号前缀
6. `test_export_csv_rejects_over_limit` — markTestSkipped（过慢，路径复用 export/anki 已验证）

**安全边界**：
- 不做 all/selected/card_ids 模式
- 不改 Anki TSV / JSON 导出
- 不导出 ReviewLog / legacy word card
- 不新增 composer/npm 依赖

---

### 下一阶段候选任务

以下任务为候选，均未冻结实现。C.15、C.16、C.17、C.18、C.20、C.20-a、C.21-scout、C.21-a、C.22-scout、C.22-a-lite、C.23-scout、C.24-scout、C.24-a-lite、D.1-scout、D.2-a、D.2-b、D.2-c、D.2-d 已完成。

| 优先级 | 编号 | 内容 | 类型 | 理由 |
|--------|------|------|------|------|
| 中 | D.4-scout | 重排已有卡片可行性侦察 | ✅ 已完成 | 输出 10 节侦察报告 |
| 中 | D.4-a | 预览已有卡片重排影响 | ✅ 已完成 | 后端只读 preview API |
| 中 | D.4-b | 管理员 FSRS 设置页接入重排预览按钮与结果展示 | ✅ 已完成 | 前端 Vue 接入，已合并审查建议微调 |
| 中 | D.4-b-fix | 收口重排预览 UI 文案、位置、文档状态与浏览器验收 | ✅ 已完成 | 位置调整、按钮文案优化、skipped_count 提示、浏览器 smoke |
| 中 | D.4-c-scout | 正式重排确认机制侦察 | ✅ 已完成 | 推荐方案 A（不写 ReviewLog + preview_hash + 二次确认）|
| 高 | D.4-c-a | confirm preflight API + preview_hash 校验 | ✅ 已完成 | 后端路由 + Controller + Service + 安全阈值 |
| 高 | D.4-c-b | 正式重排 ReviewCard 写入 | ✅ 已完成 | confirmAndApply() + risk_confirm 支持 |
| 高 | D.4-c-d | 前端确认按钮 + 二次确认弹窗 | ✅ 已完成 | 双弹窗 + 风险流程 + 倒计时 |
| 高 | D.4-c-d-fix | 成功提示保留 + 409 过期预览强制刷新 | ✅ 已完成 | preserveSuccess + handleReschedulePreviewExpired |
| 高 | D.4-c-e | 真实数据联调与浏览器 smoke 总验收 | ✅ 已完成 | 7 测试 42 断言全部通过 |
| 高 | D.4-d-scout | 重排撤销机制侦察 | ✅ 已完成 | 推荐快照表方案 C，分 D.4-d-a/b/c 三步开发 |
| 高 | D.4-d-a | 快照表 schema + 创建快照记录 | ✅ 已完成 | reschedule_snapshots + reschedule_snapshot_items + 写入集成 |
| 高 | D.4-d-b | 撤销后端 API | ✅ 已完成 | POST /settings/fsrs/reschedule-undo + undoLatestForUserLanguage + 16 测试 |
| 高 | D.4-d-b-fix | 补全事务内二次校验 + 过期提示语义 | ✅ 已完成 | target_type/fsrs_enabled/undone 事务内校验 + 明确过期消息 |
| 高 | D.4-d-c | 前端撤销按钮 + 确认弹窗 | ✅ 已完成 | AdminReviewSettings.vue +141 行 — 常驻撤销按钮 + 3 秒倒计时弹窗 |
| 高 | D.4-d-c-fix | 修复撤销 UI 成功提示隐藏 + 旧文案冲突 + 报告门禁 | ✅ 已完成 | 成功提示脱离 v-if 控制 + 文案改为"7 天内可撤销" + 网状协作规则写入 |
| 高 | D.4-d-e | 真实浏览器 smoke + 全链路验收 | ✅ 已完成 | 文案收口 + Feature tests 全覆盖（158 tests ✅） |
| 高 | D.4-final-review | D.4 全阶段最终 Review | ✅ 已完成 | 20 目标全部完成，可阶段性收口，详见 [fsrs-d4-final-review.md](./fsrs-d4-final-review.md) |

**建议下一步**：等待网页端 GPT 根据 GitHub 最新代码和产品方向决定下一阶段。

详细侦察报告见 [fsrs-reschedule-confirm-scout.md](./fsrs-reschedule-confirm-scout.md)。

---

### C.23-scout：详情抽屉 ReviewLog 可读性优化侦察

**侦察日期**：2026-06-25

#### 当前 ReviewLog 展示现状

| 项目 | 详情 |
|------|------|
| **路由** | `GET /review-cards/manage/{reviewCard}/logs`（routes/web.php L209） |
| **Controller 方法** | `ReviewCardManageController::logs()`（controller L417-448） |
| **返回字段** | id, rating, source, reviewed_at, previous_state, new_state, previous_due_at, new_due_at, previous_stability, new_stability, previous_difficulty, new_difficulty |
| **前端显示位置** | 详情抽屉底部"最近复习记录"区块（ReviewCardManage.vue L555-588） |
| **加载/空/错误状态** | 加载中显示"加载复习记录中..."，失败显示错误文字，空列表显示"暂无复习记录。" |
| **分页** | 后端 limit 20 条，无前端分页 |
| **用户/语言/sense 隔离** | 先走 `findManageableSenseCard()` 校验 user_id + language_id + target_type=sense + confirmed |
| **测试覆盖** | 9 个 HTTP 测试：基础数据返回、limit 20、其他 user 拒绝、其他 language 拒绝、legacy word card 拒绝、rejected sense 拒绝、跨 card 日志隔离、空数据 |

#### 当前可读性问题

1. **rating（评分）**：后端返回小写字符串 `again`/`hard`/`good`/`easy`/`reset`，前端用 `logRatingColor()` 映射为彩色 chip。chip 上直接显示 `again`/`hard`/`good`/`easy`/`reset` 英文原文，对中文用户不够直观。虽然有颜色区分（红/橙/绿/蓝/灰），但英文文案本身不够友好。
2. **state（状态）**：`previous_state` 和 `new_state` 显示原始英文值如 `new`/`learning`/`review`/`relearning`/`manual`，无中文映射，不易读。
3. **source（来源）**：显示原始英文值如 `review`/`reschedule`/`import`/`manage_reset`/`manage_archive`，无中文映射，不够直观。
4. **FSRS 数值**：`S`（稳定度）和 `D`（难度）显示为浮点数（如 `1.23`/`5.67`），使用了 `formatFsrsNumber()` 保留 2 位小数，但标签和数值对非技术用户不够友好。
5. **到期时间**：使用 `formatDueAt()` 本地化为 `MM/DD HH:mm` 格式，可读性中等。
6. **复习时间**：使用 `formatDateTime()` 本地化为 `MM/DD HH:mm` 格式，可读性中等。
7. **整体布局**：每条日志以卡片方式展示，视觉层级尚可，但"一行英文 chip → 一行 state 箭头 → 一行 S/D 数值 → 一行到期"的信息密度对新手用户偏高。
8. **不可读的业务字段**：`reviewed_at` 没有相对时间（如"3 天前"），`S`/`D` 数值没有单位说明。

#### C.23-a 推荐方向

1. **只改前端展示层**：不修改 ReviewLog 数据模型、不修改后端 API 字段结构、不修改 FSRS 算法。
2. **rating 中文化 chip**：`again` → `忘记`（红色）、`hard` → `困难`（橙色）、`good` → `良好`（绿色）、`easy` → `简单`（蓝色）、`reset` → `重置`（灰色）。
3. **state 中文化映射**：`new` → `新词`、`learning` → `学习中`、`review` → `复习中`、`relearning` → `重学中`、`manual` → `手动`。
4. **source 中文化映射**：`review` → `复习`、`reschedule` → `重排`、`import` → `导入`、`manage_reset` → `管理页重置`、`manage_archive` → `管理页归档`。
5. **FSRS 数值加单位**：`S: 1.23 → 5.67` 改为 `稳定度: 1.23 → 5.67`，`D: 7.0 → 6.5` 改为 `难度: 7.0 → 6.5`。
6. **相对时间可选**：`reviewed_at` 可增加"xx 分钟/小时/天前"的相对时间替代（可选，非必须）。
7. **空状态和失败提示已存在**：当前已有加载/空/错误三种状态，无需新增。
8. **保持纯前端改动**：不改 API 返回字段、不改测试、不改路由。

#### C.23-a 测试建议

1. **HTTP 测试现有**：9 个 logs 端点 HTTP 测试已覆盖隔离/权限/数据/空状态 → C.23-a 无需新增 HTTP 测试。
2. **浏览器验收**：C.23-a 的改动为中文化映射和文案优化，主要依靠浏览器视觉验收确认文案显示正确。
3. **Vue 单元测试**：如果抽取 `logRatingLabel`、`stateLabel`、`sourceLabel` 等映射函数到独立方法，可加简单的字符串映射单元测试。

#### C.23-a 禁止范围

- 不删除 ReviewLog
- 不改 FSRS 算法/评分逻辑
- 不做导出（CSV/JSON/TSV/Raw）
- 不做批量操作
- 不做数据库 migration
- 不做 ReviewLog 编辑/删除
- 不改 routes/web.php
- 不改 controller 返回字段
- 不改测试覆盖结构
- 不改 CSS 布局（只改文案/chip label）
- 不改 API 响应格式

---

### C.24-scout：管理页真实用户批量操作风险复查

**侦察日期**：2026-06-25

#### 当前批量操作安全现状

管理页现有 4 类高危操作：

| 操作 | 路由 | 确认弹窗 | 用户隔离 | 事务性 |
|------|------|----------|----------|--------|
| 单卡重置 | POST /review-cards/manage/{id}/reset | ✅ resetDialog（含 FSRS 清空说明） | ✅ findManageableSenseCard(user_id+language_id+sense confirmed) | ✅ 单卡操作 |
| 单卡删除 | DELETE /review-cards/manage/{id} | ✅ deleteDialog（标注"不可恢复"） | ✅ findManageableSenseCard | ✅ DB::transaction |
| 批量归档/恢复 | POST /review-cards/manage/bulk-enabled | ❌ 无确认弹窗，直接执行 | ✅ 逐卡 whereHas user_id+language_id+confirmed | ❌ foreach 无事务包裹 |
| 批量删除 | POST /review-cards/manage/bulk-delete | ✅ bulkDeleteDialog（标注"不可恢复"） | ✅ 逐卡 whereHas user_id+language_id+confirmed | ⚠️ 逐卡独立事务，无外层回滚 |

#### 并行侦察结果

**杨戬（代码侦察）**：
- 所有单卡操作（reset/destroy/enabled/due-now/logs）均经 `findManageableSenseCard()` 检查 `user_id + language_id + target_type=sense + status=confirmed`，不匹配则 abort(404)。
- 批量操作（bulk-enabled/bulk-delete）对每个 ID 独立查询检查 `user_id + language_id + target_type + whereHas sense confirmed`，不匹配则 skip。
- **确认弹窗缺口**：批量归档/恢复无确认弹窗，单卡恢复（toggleEnabled）和单卡归档（archive）也无确认弹窗。
- **输入上限缺口**：bulk-enabled 和 bulk-delete 均未限制 ids[] 数组大小（前端受分页 100 条限制，但 API 层无上限）。
- **事务缺口**：bulk-enabled 无 DB::transaction 包裹，bulk-delete 逐卡独立事务（Service 内部有事务）但外层循环无整体回滚。

**黄飞虎（测试结构侦察）**：
- Bulk enabled: 7 个测试（归档/恢复/skip 其他用户/skip 其他语言/skip legacy word card/空数组拒绝/保留 WordSense）
- Bulk delete: 8 个测试（多卡删除/sense reject/保留日志/skip 其他用户/skip 其他语言/skip legacy word card/空数组拒绝/排除复习队列）
- Single delete: 16 个测试（覆盖面最全，含隔离/WordSense reject/保留 Occurrence/保留日志/不影响同 lemma 其他 sense/恢复 word stage）
- Single reset: 仅 1 个测试（仅检查 fsrs_last_reviewed_at 变 null，无隔离性测试）
- **测试缺口**：不存在 ID 处理、ids[] 超上限行为、reset 用户/语言隔离均无测试覆盖

**姜子牙（风险审计）**：
- ### 风险分级
- **Medium（4 项）**：批量归档/恢复无确认弹窗；ids[] 无上限列表；bulk 操作无事务；高风险操作缺少二次确认（如输入 DELETE 确认）
- **Low（4 项）**：用户隔离已到位；撤销沟通基本到位（删除标注"不可恢复"，重置只说"清空 FSRS"未明确标注不可恢复）；UI 选择受分页限制（max 100）；错误反馈返回 affected/skipped 数量但未指明具体失败 ID

**申公豹（历史偏离复盘）**：
- C.21-a 的 all/selected/card_ids 偏离模式在批量操作中已不存在（当前所有操作仅接受 ids[] 逐卡处理）
- 安全隔离（user_id + language_id + sense confirmed）在 4 个操作中均已到位
- **禁止 C.24-a 引入**：all/selected/card_ids 模式、改变 API 响应格式、新增路由/Service/migration
- **推荐 C.24-a 包含**：bulk archive 增加确认弹窗（纯前端 wrap 色 dialog）、bulk-enabled 外层加 DB::transaction
- **不推荐**：type-to-confirm、后端 max_selection 上限、X-Operation-Count header

#### C.24-a 推荐方向

1. **后端**：
   - bulkEnabled() 外层包裹 `DB::transaction()` 确保批量归档/恢复的原子性
   - bulkDestroy() 外层包裹 `DB::transaction()` 确保批量删除的原子性
   - 保持现有 `ids[]` 逐卡校验模式，不改所有单卡操作逻辑

2. **前端**：
   - 为 bulkArchive 和 bulkRestore 增加 warning 色确认弹窗（参考 bulkDeleteDialog 样式）
   - 重置弹窗增加"此操作不可恢复"标注（当前只说"清空 FSRS 记忆状态"）

3. **测试**：
   - 补充 bulk-enabled 和 bulk-delete 在 `DB::transaction` 包裹后的回归测试
   - 补充 reset 的用户/语言隔离测试（模拟其他用户/其他语言返回 404）
   - 补充 bulk 操作传入不存在 ID 的 skipped 行为测试

4. **禁用范围**：
   - 不做 all/selected/card_ids 模式
   - 不新增路由
   - 不新增 migration/model/Service
   - 不改变 API 响应格式
   - 不改 export（JSON/TSV/CSV）
   - 不改 FSRS 算法/ReviewLog
   - 不改认证/授权模型

#### 测试建议

1. 现有 7 个 bulk enabled 测试 + 8 个 bulk delete 测试在包裹事务后应保持通过。
2. 补充 3 个新测试：reset 用户隔离、reset 语言隔离、bulk 操作传入不存在 ID。
3. 浏览器验收只需确认新增的确认弹窗正确显示文案和颜色。

#### C.24-a 禁止范围

- 不新增 route
- 不新增 Controller 方法
- 不新增 migration
- 不新增 model
- 不新增 Service
- 不新增 composer/npm 依赖
- 不改变 API 返回字段名
- 不改变路由 URL/HTTP method
- 不做 all/card_ids/selected 模式
- 不做 type-to-confirm
- 不改 export（JSON/TSV/CSV/Anki）
- 不改 FSRS
- 不改 ReviewLog
- 不改 auth/authz

---

### C.24-a-lite：管理页批量操作安全加固（已完成）

**完成日期**：2026-06-25

**实现内容**：

1. **后端事务包裹**：
   - `bulkEnabled()` 外层包裹 `DB::transaction()`，确保批量归档/恢复的原子性
   - `bulkDestroy()` 外层包裹 `DB::transaction()`，确保批量删除的原子性
   - 保持逐卡 `user_id` + `language_id` + `target_type=sense` + `confirmed WordSense` 校验
   - API 返回结构不变（`affected/skipped`，`deleted/skipped`）

2. **前端确认弹窗**：
   - `bulkArchive` 和 `bulkRestore` 触发前弹出 warning 色确认弹窗
   - 弹窗文案明确：即将操作 N 张复习卡、只影响选中的 sense review cards、是否继续
   - `reset` 弹窗增加 `"此操作不可恢复。重置后 FSRS 记忆状态将被清除。"` 标注

3. **测试**（4 个新增）：
   - `test_reset_rejects_other_user_card`：other user 的 sense card → reset → 404
   - `test_reset_rejects_other_language_card`：other language 的 sense card → reset → 404
   - `test_bulk_enabled_skips_missing_ids`：1 真实 card + 1 不存在 ID → affected=1, skipped=1
   - `test_bulk_destroy_skips_missing_ids`：1 真实 card + 1 不存在 ID → deleted=1, skipped=1

4. **未修改**：
   - 未新增 route / Controller 方法 / migration / model / Service / 依赖
   - 未改变 API 响应字段名
   - 未改 export（JSON/TSV/CSV/Anki）
   - 未改 FSRS / ReviewLog / auth
   - 未做 all/selected/card_ids 模式

---

### D 系列：FSRS 设置页完善 / Anki-like 配置体验

**状态**：D.1-scout 已完成；D.2-a/D.2-b/D.2-c 已完成；D.2-d 为未冻结候选。

**方向说明**：

D 系列的目标是把 LinguaCafe 的 FSRS 设置页从"状态说明页"升级为"决策辅助页"。这不是改 FSRS 算法，不是让用户手动编辑稳定度/难度，而是在已有的 FSRS 调度引擎之上，给用户一个能理解、能决策、能放心使用的设置界面。

**对标基线**：Anki 的 FSRS 设置界面，包含 Desired Retention 滑块 + Help Me Decide 模拟器 + 参数优化入口 + 健康检查 + 重排控制 + 参数版本信息。

**原则**：
1. 第一阶段（D.2）先做产品信息架构和 UI，占位功能可以不接算法。
2. 第二阶段（D.3）才考虑参数优化，必须先侦察 fsrs-rs-php 能力。
3. 第三阶段（D.4）才考虑重排已有卡片，必须先侦察影响量级。
4. 所有会大幅改变到期时间的操作必须强提醒。
5. 第一目标是"让用户知道自己改 retention 会带来什么后果"。

**当前能力概览**（详见 `fsrs-settings-anki-gap-plan.md`）：

| 分类 | 能力 |
|------|------|
| A. 已有 | Desired Retention 下拉选择 + 保存、FSRS 说明、FSRS 统计总览（总词义卡/启用中/归档/到期/状态分布/熟练度）、旧 SRS 折叠设置 |
| B. 占位 | 参数来源（固定文案）、参数编辑（暂未开放）、卡片重置（后续开放 — 实际管理页已有，文案过时）、参数优化（后续开放） |
| C. 缺失 | Help Me Decide / retention 负担预估、参数优化入口+健康检查+预览、重排已有卡片+影响量提示、模拟未来复习负担、参数版本/来源/优化时间真实展示 |

#### D.1-scout（✅ 已完成，2026-06-25）

**内容**：FSRS 设置页 Anki 对标侦察 + 独立拆解文档建立。

**产出**：
- 完整分析了 `AdminReviewSettings.vue`（407 行）的当前能力（3 类：已有/占位/缺失）。
- 对标 Anki FSRS 6 大功能，整理差异表。
- 建立独立拆解文档 `docs/plans/fsrs-settings-anki-gap-plan.md`，包含 6 个章节和详细 D.2-D.4 小步骤拆解。
- 在大计划中纳入 D 系列阶段。

**实现文件**：`docs/plans/fsrs-settings-anki-gap-plan.md`（新增）、`docs/plans/linguacafe-fsrs-roadmap.md`（更新）。

#### D.2-a：FSRS 设置页信息架构重排（✅ 已完成，2026-06-25）

- 重排卡片布局：复习目标区（Desired Retention + 保存）/ 当前状态区（统计总览）/ 高级工具区（参数来源/编辑/重置/优化）
- 更新过时文案：卡片重置不再是"后续开放"，已改为"已在复习卡管理页开放" + 前往管理页按钮
- 参数编辑改为"不开放手动编辑"并说明原因；参数优化保留为"暂未开放"并说明后续条件
- 删除页面底部"FSRS 参数优化和卡片重置功能会在后续版本加入"过时文案
- 不做算法、优化、预估；只做信息架构和文案

**D.2-a-fix 补充**（2026-06-25）：
- 高级工具改为默认折叠，标题旁提示"参数优化、手动参数、卡片重置等低频操作，需要时再打开。"
- 高级工具内部顺序调整为：自动优化参数 → 参数来源 → 手动编辑参数 → 卡片重置
- "自动优化参数"说明后续根据复习记录自动优化；"手动编辑参数"改为"暂未开放，后续单独评估"
- "前往复习卡管理页"按钮移到高级工具内容区左下角

#### D.2-b：Desired Retention 帮助说明增强（✅ 已完成，2026-06-25）

- 每个 retention 值增加一句中文解释，包括 70%/75%/80%/85%/90%/92%/95%/97%
- 90% 使用 v-chip 标注为"推荐默认值"
- 低保持率保留可选，文案说明"更容易忘"
- 纯前端 computed 计算，无后端改动

#### D.2-c：复习负担预估第一版（✅ 已完成，2026-06-25）

- 在"复习目标"区增加小型估算框，使用当前 stats（enabled/due/reviewed_today）做粗略每日复习量预估
- 显示范围（±25%），90% 提示"比较平衡的默认选择"，低保持率提示"更容易忘"，高保持率提示"复习更密"
- 底部说明"粗略预估，不会重排已有卡片"
- 纯前端，不改后端，不画图表

#### CODEX-FSRS-1：FSRS 设置页 Anki-like 功能增强（✅ 已完成，2026-06-25）

- 修正 roadmap 顶部与"当前最新状态"里的 latest commit：D.2-c 后基线为 `9dad39d`。
- 在 FSRS 设置页明确形成"简单模式 / 高级工具"雏形：复习目标与当前 FSRS 状态默认显示，高级工具默认折叠。
- 在高级工具第一项"自动优化参数"中新增按钮：`根据我的复习记录优化`。
- 新增 `GET /settings/fsrs/optimization-status`：返回 `review_count`、`min_required=300`、`can_optimize` 和口语化提示。
- 新增 `POST /settings/fsrs/optimize`：第一版仅做 preflight，不真实优化参数，不写入 FSRS 参数，不重排已有卡片。
- `review_count` 只统计当前用户、当前语言、confirmed `target_type=sense` ReviewCard 下的真实 ReviewLog，并排除 `source=reset`、legacy word card、其他用户、其他语言和无法关联到 confirmed WordSense 的记录。
- 参数来源轻量展示为"当前使用默认参数 / 还没有优化过"，不新增 migration，不把 D.2-d 的真实参数来源与版本字段标记为完成。
- 后续边界：D.2-d 负责参数来源与版本说明；D.3 负责真实参数优化计算与保存；D.4 负责重排已有卡片。手动编辑参数仍是未来高风险功能，未标记完成。

#### D.2-d：参数来源与版本说明（✅ 已完成，2026-06-26）

- 后端 `resolveFsrsParameterSource()` 读取真实 settings（user_id=-1），返回 6 字段：parameters_source / source_label / last_optimized_at / parameters_count / has_optimized_parameters / warning
- 返回 default（未优化）/ optimized（source=optimized）/ custom（未知来源）/ unknown（格式异常）四种状态
- 前端根据状态展示对应文案：默认参数 → "当前使用默认参数 / 还没有保存过优化参数 / 参数数量：19 个"；已优化 → "当前使用已优化参数 / 最近优化时间 / 参数数量：21 个 / 只影响新评分，不重排已有卡片"
- 4 个新测试（29 total，170 assertions）：默认、已优化、JSON 异常、自定义来源、confirm-then-status
- 不新增 migration，不保存参数，不重排卡片
- **fix**: resolveFsrsParameterSource() 增加 `decodeSettingValue()` 正确 json_decode `fsrs_parameters_source` 和 `fsrs_parameters_optimized_at`（upsertGlobalSetting 存储时使用 json_encode）；前端 optimized 状态改为绿色 v-chip"正在优化参数"

#### D.3-scout：参数优化可行性侦察（✅ 已完成，2026-06-25）

- 研究 fsrs-rs-php 的 `compute_parameters()` 方法 — **已确认可用**
- 确认需要的 ReviewLog 数量阈值 — **维持 300**
- 输出独立计划文档 `fsrs-parameter-optimization-plan.md`
- 未实现真实优化

#### D.3-a：参数优化后端最小实现（✅ 已完成，2026-06-25）

- `SettingsService::computeFsrsOptimizationPreview()` — 构建训练集 → 调用 `compute_parameters()` → 返回 current/optimized 对比
- `SettingsService::buildFsrsOptimizationTrainSet()` — 查询 review_logs，过滤 sense+confirmed+not-reset per user/language，映射 rating，计算 delta_t
- `SettingsService::countOptimizableCards()` — 统计符合条件的卡片数
- `SettingsController::optimizeFsrsParameters()` — 返回优化预览 JSON（不保存参数）
- 17 个测试覆盖：预飞、隔离（word/reset/user/language/unconfirmed）、无副作用确认、rating 映射
- 不保存参数，不重排卡片

#### D.3-d：调度器参数集成（✅ 已完成，2026-06-26）

- `FsrsSchedulingService::getActiveFsrsParameters()` — 从 Setting 表读取 `fsrs_parameters`（user_id=-1），验证 JSON/数组/数量/数值范围，失败则降级到 `get_default_parameters()`
- `extensionItemState()` line 100: `get_default_parameters()` → `$this->getActiveFsrsParameters()`
- 9 个单元测试：默认未保存 / 有效参数 / JSON 损坏 / 数量异常 / 非数值 / 超范围
- 不重排卡片，不改历史 ReviewLog，不改 desiredRetention
- 98 全量回归测试通过（9 + 60 + 29）

#### D.4-scout：重排已有卡片可行性侦察（✅ 已完成）

- 研究 fsrs-rs-php 的 reschedule 方法 — 确认无 reschedule API，需用 next_states() + good 评分模拟
- 研究 Anki "Reschedule Cards on Change" 设计 — 参考其 toggle+save 模式但改进为 preview→confirm 流程
- 输出 FSRS-D4-scout 报告（10 章节：Git 状态、Anki 对标、代码事实、重排范围、预览设计、确认设计、风险审计、阶段拆分、建议、合规）
- 推荐拆分为 D.4-a（预览后端只读）/ D.4-b（预览前端）/ D.4-c（正式重排）/ D.4-d（浏览器验收）

#### D.4-a：预览已有卡片重排影响（🔄 开发中）

- `POST /settings/fsrs/reschedule-preview` 只读接口
- 只处理 english + sense card + review 状态 + confirmed WordSense + 有 FSRS 记忆状态的卡片
- 用 `fsrs-rs-php next_states()` 模拟 Good 评分计算新间隔
- 返回 summary（总数、变化方向、幅度、峰值）和 samples（最多 20 条，按变化幅度排序）
- 不写数据库、不写 ReviewLog、不修改 ReviewCard
- 服务端依赖：`FsrsSchedulingService.getActiveFsrsParameters()`（已公开）
- 当 fsrs-rs-php 不可用时返回 `preview_available=false` 和清楚提示
- 暂不做 fuzz / easy days / load balancer

#### D.4-c-a：confirm preflight API + preview_hash 校验（✅ 已完成）

- POST /settings/fsrs/reschedule-confirm 路由 + Controller + Service 签名
- preview_hash 校验（服务端重新计算并比对，不含 timestamp）
- 安全阈值检查（MAX_NEWLY_DUE_TODAY=200, MAX_TOTAL_CHANGED=2000）
- write_enabled=false 硬编码，只做只读校验

#### D.4-c-b：正式重排 ReviewCard 写入逻辑（✅ 已完成）

- confirmAndApply() 完整写入流程（DB::transaction + chunkById + lockForUpdate）
- risk_confirm 支持：newly_due_today > 200 时允许二次确认后继续
- preview_hash 参数顺序修复（ensureSortedArray() 保证 hash 一致性）
- 不写 ReviewLog，不影响 optimizer
- 成功消息："已重排 X 张卡片，其中 Y 张今天到期"

#### D.4-c-d：前端确认按钮 + 二次确认弹窗（✅ 已完成）

- AdminReviewSettings.vue 预览卡片内底部"确认重排"按钮（v-btn color="warning" outlined）
- 点击后弹出 v-dialog 二次确认弹窗（3 秒倒计时 + 统计表）
- newly_due_today > 200 时允许通过二次确认后继续（不硬拒绝）
- total_changed > 2000 时仍拒绝执行
- 高风险时弹出第二风险对话框（红色警告 + 3 秒倒计时）
- 成功提示："已重排 X 张卡片，其中 Y 张今天到期"
- 撤销机制延后至 D.4-d，不在 D.4-c-d 实现
- 不写 ReviewLog，不影响 optimizer

#### D.4-c-d-fix：修复成功提示保留与 409 过期预览强制刷新（✅ 已完成）

- 修复 1：`previewFsrsRescheduleImpact()` 增加 `preserveSuccess` 参数；成功后自动 re-preview 不再清除成功提示（调用 `{ preserveSuccess: true }`）
- 修复 2：新增 `handleReschedulePreviewExpired()` 方法；所有 409 分支统一调用该方法，不再写入后端返回的新 `preview_hash` 到旧 preview
- 409 后清空 `fsrsReschedulePreview = null`，关闭弹窗，停止倒计时，显示错误消息
- 确认按钮增加 `!fsrsReschedulePreview` 条件：409 清空 preview 后按钮自动禁用
- 仍需手动点击"看看重排后卡片到期日会怎么变"重新预览

#### D.4-c-e：真实数据联调与浏览器 smoke 总验收（✅ 已完成）

- 创建 3 张 eligible sense card、1 张 word card、1 张 disabled card、1 张 ai_suggested card、1 张 rejected card、1 张 other user card
- 7 个 PHPUnit 自动化验收测试（42 断言）：preview 仅显示 eligible 卡、preflight 通过、apply 成功改变 eligible 卡、ineligible 卡不变、ReviewLog 不写入、409 正确返回、重排后可重新预览
- 浏览器 smoke（Vuetify SPA 登录限制，API 替代验证确认所有端点正常工作）
- db:doctor、tokenizer:doctor 均通过

**FSRS D.4 已阶段性收口**。详见 [fsrs-d4-final-review.md](./fsrs-d4-final-review.md)。
**新增**：[UI-Anki-Scout-1](./ui-anki-comparison-scout.md) — 全界面 Anki 对标侦察完成。

**当前产品判断**：
- D.4 完成后，不应直接进入 D.5 或 FSRS 设置页继续折叠。
- 应转向**学习闭环体验**优化。
- 刷卡页（`/reviews/senses`）是最高频页面，认知负载高于 Anki 同行。
- **UI-Anki-Review-Scout** 已完成 — 推荐混合型体验，分 5 Phase 实现。
- `/review` legacy 页面已评估，不应删除，但标记为 legacy 不积极开发。

**UI-Review-a、UI-Review-b、UI-Review-c、UI-Review-e 已完成**：SenseReview 完整改造 + 验收记录。
- 信息层级整理 ✅
- 显示答案流程 ✅
- 键盘快捷键 ✅
- 验收记录（代码审查通过，浏览器 Incomplete）✅

**建议下一步**：人工补验 — 手动刷 5-10 张卡确认体验后，再决定是否进入 UI-Review-d（间隔预估）或转向其他任务。

#### D 系列禁止范围

- 不手动编辑单卡 stability/difficulty
- 不删除 ReviewLog
- 不绕过 sense-only
- 不改 ReviewCard target_type 语义
- 不改 CSV / Anki TSV / JSON 导出
- 不改复习评分按钮语义
- 不做 AnkiConnect 同步
- 不做多 deck / preset 系统（除非后续单独侦察）
- 不把 D 系列标记为已完成（除 D.1-scout 外）

---

## 六、延后项目

以下项目暂不做：

| 项目 | 原因 |
|------|------|
| 批量重置 | 非当前优先级 |
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
