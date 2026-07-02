# LinguaCafe 总控大计划

> **最后更新**：2026-07-03 (Codex-MorphologyMatrix-ImportRegression-1)
> **Anti-Mud 规则**：参见 `docs/plans/vibe-coding-collaboration-rules.md` 第 10 节
> **性质**：本文件是 LinguaCafe 项目的总控计划，汇总所有任务线、已完成工作、未完成任务和产品规则。
> **文档入口**：新任务先读 `docs/DOCUMENTATION_INDEX.md` 和 `docs/plans/current-working-handoff.md`；历史文档见 `docs/HISTORY_INDEX.md`。

---

## 1. 文档目的

1. 本文档是 LinguaCafe 项目的**总控计划**，统一管理所有任务方向。
2. `linguacafe-fsrs-roadmap.md` 是历史 FSRS / Sense Review 专项路线图，保留用于追溯，不再作为当前入口。
3. `ai-reading-assist-plan.md` 是 AI 阅读辅助专项计划。
4. `ai-reading-assist-schema-experiment.md` 是 AI schema 设计与实验记录。
5. `fsrs-anki-management-optimization-plan.md` 是 FSRS Anki 管理优化专项。
6. 本文档用于防止旧计划被遗忘：插队任务**不等于**取消旧任务。
7. 新增任务不自动删除旧任务，除非网页端 GPT 明确同意并更新相关文档。
8. `current-working-handoff.md` 是短期工作台；本文是长期总账本。短期候选和已完成任务可以互相引用，但不要把旧 handoff / 旧 NEXT_TASK 重新升格为当前入口。

---

## 2. 当前核心产品方向

1. **sense-only 复习系统**：WordSense 是实际复习对象，ReviewCard `target_type = sense` 是主线，`target_type = word` 是 legacy。
2. **EncounteredWord** 负责：阅读页颜色、熟悉度总览、词形出现记录。
3. **阅读即学习**：但不能把"看过"简单等同于"复习过"。
4. **FSRS 熟悉度**：阅读页绿色深浅由 `ReviewCard.stability + due_at + fsrs_state` 计算（10 档 10%-100%）。
5. **AI 阅读辅助**：手动复制提示词 + 粘贴 AI 返回内容 → 解析预览（不做自动 API 调用）。
6. **多例句绑定**：一张释义卡绑定多个来源句，复习时平均轮换展示。

---

## 3. 已完成主线

### 3.1 FSRS / Anki 管理

| 阶段 | 说明 |
|------|------|
| FSRS-D3-b 到 FSRS-D4-final-review | FSRS 参数优化全流程（优化保存、诊断面板、重排风险、撤销恢复、真实验收） |
| FSRS-Anki-Mgmt-1 到 Mgmt-7 | 恢复默认参数、诊断面板、重排风险面板、Retention 模拟、每日上限侦察/设置/队列接入 |
| Mgmt-7-follow-up | 修复 `/reviews/senses` 绕过每日上限 + SettingsService is_queue_enforced 修复 |
| Mgmt-7-b | 每日新学累计计数精确化（计划中） |

### 3.2 FSRS 阅读页颜色

| 阶段 | 说明 |
|------|------|
| Reader-FSRS-Highlight-1 | 阅读页绿色高亮改为 FSRS 熟悉度驱动（7 档） |
| Reader-Visual-Semantics-1 | 扩展为 10 档（10%-100%）、AI 预览紫色搜索高亮、计划保全规则 |
| Reader-UI-9 | 查词栏 FSRS 熟悉度文字 + 小进度条 |

### 3.3 AI 阅读辅助

| 阶段 | 说明 |
|------|------|
| AI-Reading-Assist-1 | Schema 设计 + 提示词模板 + DeepSeek Flash/Pro 对比实验材料 |
| AI-Reading-Assist-2 | 复制整章英文 + AI 提示词 + 粘贴解析预览（方案 B 总览+详情页） |
| AI-Reading-Assist-2-follow-up | 方案 B 详情页导航 |
| AI-Reading-Assist-2-search | 详情页搜索框 + 紫色命中高亮 |
| AI-Reading-Assist-3 | 确认并保存 AI 解析结果（不创建 WordSense/ReviewCard/ReviewLog） |
| AI-Reading-Assist-4 | AI 译文按句显示/隐藏开关（从已保存数据读取，不创建学习数据） |
| AI-Reading-Assist-4-a | AI 译文双字体区分：移除"AI 译文："前缀，.lc-ai-sentence-translation class，中文衬线字体 |
| AI-Reading-Assist-4-b | 句子对齐修复：prompt 句子列表 + preview/confirm 校验 + 幼圆字体 |
| AI-Reading-Assist-5 | AI 生词/词组建议进入查词侧栏（lookup 接口 + AI 建议区域 + 预填表单） |
| Reader-Lookup-UX-2 | 查词栏添加释义流程重构（统一"添加新释义"面板，隐藏空分组，紧凑显示） |
| Reader-Layout-1 | 阅读页布局扩展（大屏查词栏 520px + 来源句默认收起） |
| Reader-Layout-1-b | 修复全屏误弹窗 + 响应式 breakpoint + 动态宽度同步 |
| Reader-Toolbar-UI-1 | 恢复工具栏 + 添加释义高级选项折叠 + 词典结果紧凑化 |
| Reader-Toolbar-UI-1-b | 工具栏左移避让查词栏 + AI 建议 chip 可见性 + 左词右义词典布局 |
| Codex-Reader-Workspace-UI-1 | 阅读页工作区全宽优化、查词栏宽度统一、词典结果三列布局、AI 建议命中自动展开；已确认不实现 lemma/origin follow-up |
| AddSenseForm-Extract-1 | 从 WordSensesList.vue 提取添加释义表单为独立 AddSenseForm.vue 组件；Anti-Mud 小步重构，不改变保存逻辑 |
| AiSuggestionPanel-Extract-1 | 从 VocabularySideBox.vue 提取 AI 建议面板为独立 AiSuggestionPanel.vue 组件；Anti-Mud 小步重构，不改变 AI lookup 和保存逻辑 |
| TextBlockGroup-Smoke-Guard-1 | 建立阅读页零依赖浏览器 smoke guard（文档 + 脚本），保护 7 个 P0 + 3 个 P1 行为；不改任何业务代码，不新增前端测试依赖 |
| Architecture-Gate-Baseline-1A | 只读核验（skills 安装核验 + 安全扫描 + AGENTS.md 审查）；不修改任何文件 |
| Architecture-Gate-Baseline-1B | 创建 ADR-0001、同步架构闸门规则到 vibe-coding-collaboration-rules.md、修正 smoke guard 文档/脚本；提交 AGENTS.md 与 .opencode/skills/ 为一次性授权例外 |
| ReaderWorkspaceSizing-Convergence-1 | 收敛阅读页宽度断点重复规则为纯函数 helper（`ReaderWorkspaceSizingService.js`）。低风险代码收敛，不改业务逻辑，smoke guard 27/27 PASS |
| Validation-Rules-MCP-Visual-1 | 写入 MCP 视觉验证优先规则。协作规则更新，不改业务代码，用于约束后续阅读页 / UI 交互任务验收 |
| TRAE-Smoke-Guard-ChapterId-1 | 给 text reader smoke guard 增加 `--chapter-id` 参数（默认 5），避免通过修改数据库归属来跑 smoke。测试工具改造，不属于 FSRS 功能本体，不改业务代码，不进入 AI-6 / Lemma-Origin / 架构收敛主任务。起因：Lab-4 因数据库污染被拒绝 |
| Docs-EmployeeOrderRule-1 | 补充三员工提示词顺序规则。后续每轮使用三员工时，网页端 GPT 必须明确第 1 棒、第 2 棒、第 3 棒。顺序可按任务调整，但必须说明原因。不属于 FSRS 功能本体，属于协作流程规则。 |
| Docs-EmployeePromptFormatRule-1 | 补充三员工提示词格式规则：每个员工提示词必须按"发给谁 / 模型 / 档位 / 顺序 / 依赖关系"的固定格式书写，并按执行顺序排列。不属于 FSRS 功能本体，属于协作流程规则。 |
| Docs-PromptSingleBlockRule-1 | 补充提示词单文本框规则（已修正为 Docs-PromptBlockScopeFix-1）。不属于 FSRS 功能本体，属于协作流程规则。 |
| Docs-PromptBlockScopeFix-1 | 修正员工提示词文本框范围规则："发给谁 / 模型 / 档位 / 发的时候有什么要求 / 顺序 / 依赖关系"写在文本框外；每个员工都有单独文本框；文本框内部只放该员工执行正文。不属于 FSRS 功能本体，属于协作流程规则。 |

### 3.4 右击面板 / Review UI

| 阶段 | 说明 |
|------|------|
| RightClickPanel-1, -2-scout, -3-a | 右击点词面板 WordSense 功能改造 + 自动创建卡侦察 + 最相关词义展开 |
| UI-Review-a 到 UI-Review-e | SenseReview 信息层级整理、显示答案流程、键盘快捷键、真实 smoke |
| UI-Anki-Scout-1, UI-Anki-Review-Scout | 全界面 Anki 对标侦察、刷卡页学习节奏重构侦察 |

### 3.5 查词侧栏 / 阅读页 UI

| 阶段 | 说明 |
|------|------|
| Reader-UI-1-a | 第一轮瘦身：隐藏旧 SRS 1-7 熟练度按钮，"旧词条释义（兼容）"→"选择释义"，词典结果默认收起 |
| Reader-UI-6-a | "删除词条"语义收口 → "回归为新词" + 确认弹窗如实告知行为 |
| Reader-UI-6-b | 后端实现：删除 sense ReviewLog + legacy word ReviewLog，删除（禁用→改为删除）legacy word ReviewCard |

### 3.6 架构收敛 — ReviewCardManageController 职责分离

| 阶段 | 说明 |
|------|------|
| ReviewCardManage-ServiceBoundary-1 | ✅ 已完成 — 管理页 Controller 已分离查询、导出、行序列化三类职责：`ReviewCardManageQueryService`、`ReviewCardExportService`、`ReviewCardManageItemSerializerService`。后续危险写操作必须先只读侦察，不直接抽离。 |
| ReviewCardManage-MutationService-Extract-1A | ✅ 已完成 — 抽取单卡归档/恢复与立即到期服务。新增 `ReviewCardManageMutationService`。未处理 update/reset/destroy/bulk。 |
| ReviewCardManage-BulkArchiveRestoreCopy-1 | ✅ 已完成 — 补充批量归档/恢复弹窗说明。只改前端文案，不改业务逻辑。 |
| ReviewCardManage-MutationService-Extract-1B | ✅ 已完成 — 抽取单卡 WordSense 文本编辑服务。update() 的 EDITABLE_FIELDS 白名单、normalizeArray、WordSense 保存逻辑迁入 ReviewCardManageMutationService。未处理 reset/destroy/bulk。 |
| ReviewCardManage-MutationService-Extract-1B-FormatFix | ✅ 已完成 — 修复 1B 引入的缩进漂移。 |
| ReviewCardManage-BulkDeleteList-1 | ✅ 已完成 — 批量彻底删除弹窗显示待删除 lemma / 中文释义列表。不显示 review_card_id / surface_form。不要求输入确认词。保留弹窗确认和"确定删除"按钮。不改后端删除逻辑。 |
| Docs-WorkflowFollowupRules-1 | 已写入阶段推进规则（不默认停下来询问）、提示词时机规则（等待报告时不提前写 OpenCode 提示词）、小任务合并与复杂任务搭载规则、CodeBuddy/WorkBuddy 结论复核规则、本地登录与管理员测试账号规则（具体账号密码不进入 GitHub 文档/代码/测试/日志/报告）。不属于 FSRS 功能本体，属于协作流程与本地验收规则。 |
| Docs-OpenCodeCodeBuddyPairing-1 | 已写入 OpenCode 不允许单独出现、只要有 OpenCode 就必须同时安排 CodeBuddy、CodeBuddy 可后置复核 OpenCode 输出也可并行做无关侦查、CodeBuddy/WorkBuddy 可以单独出现。不属于 FSRS 功能本体，属于协作流程规则。 |
| Docs-AgentSkillRequirement-1 | 已补充智能体提示词必须显式写 skill 的规则（vibe-coding-collaboration-rules.md §14.6）。**已顺手修正 WorkBuddy 不使用 CodeBuddy/OpenCode skills，改为使用内置专家。** 不属于 FSRS 功能本体，属于协作流程规则。 |
| ReviewCardManage-MutationService-Complex-1 | 完成 bulkEnabled 抽取到 MutationService（bulkSetEnabled 方法）；补充 bulkEnabled/bulkDestroy skipped > 0 用户反馈；补充 dueNow 成功 snackbar；统一 reset 文案为"重置复习进度"。不改 destroy/bulkDestroy 核心删除语义，不改 reset 核心 FSRS 语义。 |
| ReviewCardManage-Phase20Stabilize-1 | 收口稳定化：清理 Phase20 smoke 数据（25 张 `smoke_p20_*` 卡已删除）；修正 WorkBuddy 规则冲突（§14.6 "多名专家"仅限 OpenCode/CodeBuddy，WorkBuddy 以 §18 单专家为准）；补 8 个 reset characterization tests（覆盖 fsrs_state/fsrs_due_at/stability/difficulty/reps/lapses/last_reviewed_at/enabled/ReviewLog/WordSense 保留/ReviewCard 保留）；确认已有 destroy characterization tests 覆盖完整；文档化下一步风险顺序（destroy 单卡安全强化 > reset 边界优化）。 |
| RepoArchitectureHotspotAudit-1 | 全仓库架构热点审计：基于最新 master 审查后端 Service（TextBlockService 1239 行最重）、Controller、前端 Vue 组件（TextBlockGroup.vue 2182 行最重）、tokenizer/import/reader 链路；排序 5 个候选任务；最推荐下一阶段为 "TextBlockService → 提取 ReaderDataService"（只读查询拆分，比继续 reset/destroy 更安全）。不改业务代码，不进入功能开发。新增 `docs/plans/repo-architecture-hotspot-audit.md`。 |
| VocabularyService-QuerySearchContractTests-1 | 为 VocabularyService 搜索/分页/过滤补 15 个 contract tests：覆盖 searchVocabulary 返回结构、text 搜索（word+reading）、stage 过滤、translation not empty、only words/only phrases/words+phrases union、4 种排序方向、分页（30 条/页）、exportToCsv 复用搜索。不改 VocabularyService，不改查询语义。 |
| TextBlockGroup-SmokeTests-1 | 建立阅读页 TextBlockGroup smoke 测试基线。采用 MCP Chrome 真实验收（项目无 Playwright 基础设施）：通过 `isolatedContext` 登录 → 打开 `/chapters/read/7` → 确认 token 文本渲染 → 点击 "geese" → 确认 vocab 侧栏打开显示 lemma "goose" + 词典结果 → 确认无 console error → 确认不创建 WordSense/ReviewCard/ReviewLog。不改阅读页业务逻辑，不改 TextBlockGroup.vue。参与测试的章节数据为用户已有数据，未创建新数据。 |
| DictionaryImportService-CharacterizationTests-Fix-1 | 收口测试清理边界：tearDown 不再删除 `storage/app/temp` 下所有文件，只删除本测试类登记的文件和表（通过 `$createdTempFiles`/`$createdTempTables`）。补 `test_import_csv_rejects_existing_table_name` 确认表已存在的抛出异常。14+256 测试全绿。不改业务逻辑，不改导入流程。 |
| DictionaryImportService-CharacterizationTests-1 | 为 DictionaryImportService 新增 13 个 characterization tests：覆盖 CEDICT/HanDeDict/dict.cc/wiktionary 文件识别（含 unsupported txt/tsv 回退）；CSV sample testing（成功/错误路径）；自定义 CSV 导入 validation（非法表名/过长名称/表已存在）；自定义 CSV 导入成功路径（建表/写行/创建 Dictionary 记录/清理）。不改业务逻辑，不改导入流程。256+13 测试全绿。 |
| RepoArchitectureRoadmapRefresh-PostStabilization-1 | 基于最新 master（`a1e67e8`）重新刷新架构热点 roadmap：更新 §2 已完成架构优化（新增 12 项已完成任务）；清理 §7 候选排序中 5 个已完成/重复的任务条目；重新输出 7 个候选排序（DictionaryImport 测试 > TextBlockGroup smoke > VocabularyService 测试 > SenseReview smoke > FSRS scouting > EncounteredWord 提取 > WordSenseService 审计）；新的最推荐下一阶段为 DictionaryImportService characterization tests（低风险、高独立度、为导入链路铺路）。不改业务代码，不改测试，不改页面。 |
| AutoNextStage-DesignerWorkflowRule-1 | 用户要求网页端总流程设计师任何时候都不要停在结论处。已写入 `vibe-coding-collaboration-rules.md` §21。以后每次 Accept / Refuse / 阶段性 Accept / Incomplete 后，网页端 GPT 必须自动进入下一阶段判断，并给出下一轮提示词。该规则不授权 OpenCode / CodeBuddy / WorkBuddy 自动进入下一任务。OpenCode 报告仍必须写"是否进入下一任务：否"。后续 OpenCode 提示词仍必须包含 `必用辅助 skill：oh-my-opencode-slim`。 |
| OhMyOpenCodeSlim-RequiredSkillRule-1 | 用户要求每次 OpenCode 任务必须使用 `oh-my-opencode-slim` skill。已写入 `vibe-coding-collaboration-rules.md` §20。以后 OpenCode 提示词必须包含 `必用辅助 skill：oh-my-opencode-slim`。默认不改 oh-my-opencode-slim 配置；如需修改，必须明确授权并报告影响。不改业务代码，不改 OpenCode 配置。 |
| McpChromeReliability-FinalDocAndContractFix-1 | 补齐复杂度 100 协作规则（§19），说明复杂度 100 不放开边界、必须后置 CodeBuddy、涉及页面必须后置 WorkBuddy。将 `source_display_status` 和 `source_display_label` 加入 ReviewCardManage required fields 契约测试。不改业务逻辑，不改 UI。MCP Chrome playbook 和页面验收已在上一轮完成。 |
| McpChromeReliability-And-ReviewCardSourceE2E-1 | 建立可靠 MCP Chrome 登录验收流程：诊断出 `navigate_page` 丢失 Cookie 根因为跨 browser context 和缺少 `isolatedContext` 参数；验证成功路径为 `new_page(isolatedContext="xxx")` → 表单登录 → 点击链接导航；文档化为 `docs/plans/mcp-chrome-local-smoke-playbook.md`；更新协作规则 §14.10 明确同 context 登录规则和复杂度 100 规则。**MCP Chrome 真实页面验收 ReviewCardManage 完全通过**——3 张测试卡的列显示/详情抽屉/缺溯源筛选均确认：real_chapter 显示章节名、card_example_only 显示"保存例句（未定位原章节）"、missing 显示"缺溯源"。256+29 测试全绿。 |
| ReviewCardManage-SourceDisplayConsistency-Fix-1 | 修复详情抽屉 UI 回归（标题误写"FSRS 信息"、丢失"缺释义"行、重复两个"缺溯源"行、与溯源信息区重复）。修复后"缺失状态"区块含缺释义/缺例句/溯源状态三类，无重复。新增 5 个后端测试（serializeCard 含 source_display 字段、real_chapter/card_example_only/missing 三类断言、list data 也含）。256 测试全绿 + 前端构建成功。MCP Chrome 真实页面验收通过。 |
| ReviewCardManage-SourceDisplayConsistency-1 | 修复管理页外层溯源显示与查看原文结果不一致：新增 `source_display_status`（real_chapter/card_example_only/missing）和 `source_display_label` 字段（向后兼容）。管理页列显示优先使用 display label，区分"已定位原文"/"保存例句（未定位原章节）"/"缺溯源"。详情抽屉缺溯源字段改为三级语义。查看原文后若发生 recovered/fuzzy 写回，自动刷新列表。251+29 测试全绿，前端构建成功。 |
| SenseSourceContext-Contract-1 | 锁定 SenseSourceContext 查询/渲染边界：文档化 7 种 source_kind（chapter/chapter_recovered/chapter_title/chapter_fuzzy/chapter_fuzzy_title/card_example/unavailable）及完整 fallback 顺序；记录输出 JSON 稳定字段；记录写回边界（chapter_recovered/chapter_fuzzy 写回，card_example/unavailable 只读）；新增 3 个 characterization tests（writeback 确认、card_example 不写回、unavailable 结构稳定）。不改 Service、不改 Vue、不改 API shape。新增 `docs/plans/sense-source-context-contract.md`。 |
| TokenizerHealth-Contract-Fix-1 | 收口 tokenizer health 任务边界：移除 tokenizeText 中未验收的 ENGLISH_IRREGULAR_OVERRIDES 真实分词 override（保留在 health 诊断中作为预期对比）；language_health 改为轻量检测（spacy.util.get_installed_models），不再加载 26 个 spaCy 模型；english_lemma_health 单次加载 English 模型 + 单次 import lemminflect，消除 10 倍重复加载；清理 PHP doctor 临时代码（$hasFutureDue placeholder）。6 个测试全绿。 |
| TokenizerHealth-Contract-1 | 增强 tokenizer health 诊断：health_check 新增 version/languages/english/checks 字段，每条语言返回 available/status/model/error（不崩溃）；English lemma 10 样例检查（geese→goose、better→good 等）；PHP doctor 兼容新旧 health JSON，显示语言概览。新增 tests/Unit/TokenizerDoctorTest.php（6 个测试覆盖旧 JSON 兼容、新 JSON 解析、lemma 失败检测、语言不可用）。不改分词主协议、不改导入链路、不改 tokenize 输出。 |
| Docs-LocalLoginMcpRule-1 | 补充 MCP Chrome 本地测试账号规则（§14.10 明确提示词必须提供账号密码、OpenCode 登录失败流程、最终报告说明要求）。记录 ReaderDataService 提取已通过 WorkBuddy 网页端体验师 MCP Chrome 真实页面验收（阅读页文本渲染、点击词弹出 vocab-box、word→lemma 方向保持、词典查询正常、无新增 JS 错误。FSRS 熟悉度在 popup 模式不显示进度条——属于当前模式差异，不视为回归）。 |
| TextBlockService-ReaderDataService-Extract-1 | 正式提取 ReaderDataService：新增 `app/Services/ReaderDataService.php`，迁移 `collectUniqueWords`/`prepareTextForReader`/`loadFsrsFamiliarityLookup`/`indexPhrases` 到新 Service。TextBlockService 保持所有 public 方法和属性（words/uniqueWords/phrases）不变，作为兼容门面。ChapterService/VocabularyService 无需修改。26 个测试全部通过，前端构建成功。不改 Vue/Controller/routes/tokenizer。 |
| TextBlockService-ReaderDataContract-1 | TextBlockService Reader Data 提取前契约锁定：读取完整调用链（ChapterService::getReaderData / VocabularyService::getExampleSentenceReaderData），标记只读/写入边界。新增 `docs/plans/textblock-reader-data-contract.md`（含输出结构、FSRS familiarity 契约、前端兼容契约、下一轮提取边界）。补 9 个 characterization tests（reader data 顶层字段/words 字段/uniqueWords 字段/不打 ReviewCard/不修改 chapter/已归档卡行为/legacy word card 隔离/getReaderData 结构/prepareTextForReader 只读确认）。不改业务代码，不改 TextBlockService。 |
| ReviewCardManage-BulkDestroyPhase20-1 | 完成 bulkDestroy 编排层抽取到 MutationService（bulkDestroy 方法）；提取共享 private helper `findManageableSenseCardForMutation` 在 bulkSetEnabled 和 bulkDestroy 间去重；新增 6 个自动测试覆盖抽取后 response shape/ReviewCard 硬删/WordSense rejected/ReviewLog 保留/其他用户隔离/bulkSetEnabled 回归；MCP Chrome 验收 25 张真实卡片的批量删除弹窗；写入 WorkBuddy 单专家规则（每轮只能使用一个专家、选择规则、提示词格式、最终报告要求）；保留 25 张 `smoke_p20_*` 测试卡给 WorkBuddy 后置验收。不改 destroy 单卡核心删除语义，不改 WordSenseService::removeSenseFromReviewSystem。 |
| ReviewCardManage-BulkEnabledContract-1 | 已锁定 bulkEnabled 抽取到 MutationService 前的输入/输出/权限/事务/测试契约。只做文档，不改业务代码，不进入 service extraction。下一轮若 CodeBuddy 复核通过，可进入 bulkEnabled 最小实现。 |
| ReviewCardManage-MutationBoundary-Scout-1 | 已新增 ReviewCard 管理页危险写操作边界侦查文档（docs/plans/review-card-manage-mutation-boundary-scout.md）。只读侦查 update/enabled/dueNow/reset/destroy/bulkEnabled/bulkDestroy 等写操作，不改业务代码，不进入 service extraction。顺手修正 WorkBuddy 不使用 CodeBuddy/OpenCode skills 的协作规则，并记录纯小文档规则修正不得孤立开任务。 |
| Workflow-ContinuePromptRule-And-LemmaDisplayClick-1 | 已补充"验收后必须继续给下一阶段提示词"规则（vibe-coding-collaboration-rules.md §16.1）。已补充 geese → goose 真实点击验收记录。不改业务代码，不进入新功能开发。 |
| Lemma-Origin-DisplayUI-1 | ✅ 已完成 — 查词栏/浮动弹窗显示 surface → lemma 箭头格式。改 VocabularySideBox.vue 和 VocabularyBox.vue。geese → goose / better → good / best → good 均通过 MCP Chrome 验证。不改后端/WordSense/ReviewCard/FSRS。 |
| Docs-LemmaDisplayScopeFix-1 | ✅ 已完成 — 修正 DisplayUI 架构文档中 VocabularyBox.vue 范围矛盾（"第一轮只做"含改 VocabularyBox.vue，"不改"清单又含不改 VocabularyBox.vue）。已移除"不改"清单中的 VocabularyBox.vue，重新编号。 |
| Lemma-Origin-DisplayArchitecture-1 | 原词 + 原形显示功能架构核验。用户产品方向：阅读页/查词处显示原词+原形，文案格式为 geese → goose。本轮只做架构核验，不实现 UI。已核验阅读页/查词栏/lookup 数据来源。已用 MCP Chrome 查看当前真实界面。不改 WordSense / ReviewCard / FSRS。不改 tokenizer。属于 lemma/origin 用户体验功能的架构先行阶段。 |
| Lemma-Origin-IrregularOverride-1 | 最小修复英语不规则 lemma override。已修复 geese / better / best，通过 doctor human/JSON 验收，已通过 MCP Chrome 真实浏览器打开 /tokenizer/health 验收。已顺手补充 To-do list 执行规则和 MCP Chrome 真实测试规则。不改 WordSense / ReviewCard / FSRS。不属于 FSRS 功能本体，属于 lemma/origin 质量保护。 |
| Lemma-Origin-DoctorIrregular-1 | 已让 php artisan tokenizer:doctor 读取 /tokenizer/health 的 english_irregular，在 human/JSON 输出中展示 cases。任一 case 失败时 doctor 返回失败。不改 tokenizer 实际行为。不属于 FSRS 功能本体，属于 lemma/origin 架构保护与质量治理。 |
| Lemma-Origin-HealthIrregular-1 | 已扩展 /tokenizer/health 的 English lemma 健康检查，覆盖 10 个不规则词形（ran/running/mice/geese/better/best/went/children/studies/was）。不改 tokenizer 实际行为。已顺手补充任何新功能必须先做架构的规则。不属于 FSRS 功能本体，属于 lemma/origin 架构保护与质量治理。 |
| Lemma-Origin-Architecture-1 | 已新增 docs/plans/lemma-origin-architecture.md。只做架构核验，不实现新功能。已核验 English tokenizer / lemminflect fallback / 导入链路 / 风险边界。已写入第一批 lemma 验收样例。已顺手补充 CodeBuddy 只给事实不直接给建议的协作规则。已顺手补充 OpenCode 小任务打包规模规则（10 个清晰子项）。不进入 lemma 实现。不属于 FSRS 功能本体，属于架构先行与质量治理。 |
| Docs-WorkflowBatchingAndDualScout-1 | 已写入 OpenCode 不能只做孤立小文档补丁、小文档任务必须合并多个小任务或搭载大任务、大任务搭载小任务时应搭载多个同类低风险小任务而非一个零散补丁、OpenCode 和 CodeBuddy 可同轮做代码侦查/风险分析/漏洞分析并扮演不同岗位角色。不属于 FSRS 功能本体，属于协作流程与任务调度规则。 |
| Docs-ArchitectureParallelWorkflow-1 | 已写入 OpenCode + CodeBuddy 并行工作流、CodeBuddy 每轮必须指定 skill 的规则、WorkBuddy 作为产品经理/QA 员工的定位、视频架构思想（先定边界再实现、控制复杂度扩散、避免状态分叉/隐式行为/跨文件耦合）、批量彻底删除的产品方向（显示待删除列表、不要求输入确认词、必须弹窗确认）。不属于 FSRS 功能本体，属于架构治理和协作流程规则。不进入 ReviewCardManage 代码实现。 |
| SenseReviewMapping-SmokeTests-1 | MCP Chrome `isolatedContext` 真实验收感复习页面空状态。覆盖 `/senses/review`（词义确认页：标题"词义确认"、筛选区、批量操作区、空状态"没有匹配的词义记录"、统计标签）和 `/reviews/senses`（词义复习页：标题"词义复习"、统计区、空状态"当前没有到期词义卡"）。未创建 WordSense/ReviewCard/ReviewLog，未改变 ReviewCard FSRS 状态，未改变 WordSenseOccurrence 状态，未执行评分/确认/拒绝/忽略/改绑/新建保存/归档/重置/删除。当前仅为空状态 smoke 基线。有卡片/有 pending occurrence 的完整写入路径以后单独开任务。 |
| FsrsReschedulePreviewService-ContractScouting-1 | 只读侦查 FsrsReschedulePreviewService 全链路（preview/confirmPreflight/confirmAndApply）。覆盖 780 行 Service 代码 + 4 个模型 + Controller + 现有 49 个测试。已输出 18 个风险点（6 高/7 中/5 低）并记录为 §7.3。已输出下一轮 contract tests 计划（A.preview 只读测试 9 项 + B.confirmPreflight 测试 7 项）。confirmAndApply 写入测试标记为高风险暂缓单独任务。不改业务代码，不执行重排，不写 ReviewCard/ReviewLog/snapshot。 |
| FsrsReschedulePreviewService-GapContractTests-1 | 在已有 51 个 FSRS 重排测试基础上补缺口 contract tests。新增 5 个 preview 测试：empty candidate 仍返回 preview_hash、stability 变化使 hash 变化、difficulty 变化使 hash 变化、其他语言隔离、空状态 risk_assessment。新增 5 个 confirmPreflight 测试：apply=false high risk 返回 risk_level + 不写 snapshot、apply=false blocked、preflight 成功不创建 snapshot、apply=false + risk_confirm 仍走 preflight 路径不写、stale hash 不写 ReviewCard/snapshot。不改 FSRS 业务逻辑，不执行真实重排，不新增 confirmAndApply 成功写入测试。58 测试全绿。 |
| FsrsRescheduleGapContractTests-ScopeFix-1 | 收口上一轮 gap contract tests 的测试文件边界。从 `FsrsRescheduleSnapshotTest.php` 移除越界新增的两个测试（`test_preflight_does_not_create_snapshot` 和 `test_stale_hash_does_not_create_snapshot`），这两个测试的语义已分别由 `FsrsRescheduleConfirmTest` 中的等价测试覆盖。总测试语义不减少，65 测试全绿。不改 FSRS 业务逻辑，不执行真实重排，不新增 confirmAndApply 成功写入测试。 |
| TextBlockService-CreateNewEncounteredWordsContractTests-1 | 为 `TextBlockService::createNewEncounteredWords()` 补 12 个 characterization tests。覆盖新词创建（word/lemma/base_word/study_base/stage/translation）、已存在词去重、user/language 隔离、UserStudyBaseRule 覆盖、VocabularyTokenFilter 跳过、words_to_skip 过滤、CJK 语言 base_word 清空（lemma==word 时）、English 保留 base_word（如 series）、uniqueWords/processedWords 当前关系、lowercasing、255 长度边界（DB 异常）、batch insert。不改 TextBlockService，不改 tokenizer，不改 import 流程，不提取 Service。12 测试全绿。 |
| EncounteredWordCreationService-Extract-1 | 从 `TextBlockService` 提取 `createNewEncounteredWords()` 写入逻辑到独立 `EncounteredWordCreationService`。原方法保持 public facade，委托给新 Service。行为由 12 个 characterization tests + 1 个直接调用测试锁定（13 全绿）。不改 tokenizer，不改 import 主流程语义，不改 DB schema，不改 Vue。 |
| WordSenseService-DestroyRestore-RiskAudit-1 | 只读审计 WordSenseService 的删除/归档/恢复链路（rejectSense/archiveSense/removeSenseFromReviewSystem/restoreEncounteredWordIfNoActiveSenses）。覆盖 413 行 Service 逻辑 + 2 个 Controller + 5 个模型。已输出 14 个风险点并记录为 §7.4。CodeBuddy 原分级（4 高/5 中/5 低）经总设计师反驳核验后调整为：真正优先测试的仅 3 项（archiveSense 与 removeSenseFromReviewSystem 的 occurrence unlink 不一致、permanent delete ReviewLog 保留设计需锁定、bulkDestroy 批量删无确认）；rejectSense 无调用方降级为疑似遗留方法；deleteReviewLogs=true 无前端入口降级为未来入口风险；restore 按 encountered_word_id 不按 lemma 是安全设计不采纳为风险；archive 不 restore 是归档语义不采纳为 bug。已输出下一轮 contract tests 计划。不改业务代码，不执行删除/归档/恢复，不写数据库。 |
| DesignerWorkflow-CodeBuddyRiskRoleAndPlanRefresh-1 | 根据总设计师对 CodeBuddy 报告的反驳式评估，修正 CodeBuddy 角色定位写入协作规则 §4.x。CodeBuddy 以后更侧重风险线索和 bug 可能性；事实核查和最终采纳由网页端总设计师负责。不把 AI 交接成本/文档冗余自动当成高风险。真正升权的是数据删除、写入、跨用户/语言、不可逆操作和用户误触。同步修正 master plan 中 WordSense 删除审计的风险描述和下一推荐任务范围。 |
| WordSenseService-DestroyRestoreContractTests-1 | 按总设计师复判后的范围补 15 个 contract tests 锁定 WordSense 删除/归档/恢复语义。覆盖 archiveSense 禁用卡/不改 occurrence、removeSenseFromReviewSystem(false) 禁用+解绑 occurrence、permanent delete 删卡+解绑+restore Learning→New、deleteReviewLogs=true 三重过滤、deleteReviewLogs=false 默认保留、另一个 confirmed sense 阻止 restore、Known/Ignored/New 不恢复、encountered_word_id restore 安全设计、legacy word card 不受影响、rejectSense 当前行为、route 权限隔离。不改业务逻辑，不做 UI，不执行真实数据删除。15 测试全绿。 |
| ReviewCardDeleteSnackbar-HistoryPreservedCopy-1 | 根据 WorkBuddy 产品决策，补管理页删除成功 snackbar / fallback 文案。单张删除 fallback 改为"该释义不会再出现在阅读页，复习历史已保留"；批量删除 fallback 改为"已彻底删除词义复习卡，复习历史已保留"；SenseReview 删除 fallback 改为"已彻底删除词义复习卡，复习历史已保留"。后端返回文案已有"复习历史已保留"，MCP Chrome 真实验收确认。不改删除逻辑，不改后端，不改数据。 |
| CodexHandoff-DocsAndWorkingPlanRefresh-1 | 根据 AI 工程化字幕内容整理 Codex 交接前文档治理。新增 `current-working-handoff.md` 临时工作台，记录当前阶段、最近完成任务、产品决策、候选方向、Codex 交接原则。在协作规则中新增 §4.y（AI 上下文/文档分层/可执行验收）。明确文档不是硬约束，关键边界必须进入测试/smoke/harness；明确 Codex 虽可接更复杂任务，但仍需禁止范围、验收命令和总设计师核验。记录上一轮 CodeBuddy 已确认本任务此前未真正 push。不改业务代码。 |
| CodexWorkspaceArtifactCleanup-1 | 清理 Codex-ArchitectureOptimizationLoop-1 后遗留的本地 `data/` 和 `CODEX_SESSION_DIAGNOSIS.txt` artifact（Codex/session 工具产物）。添加最小 `.gitignore` 规则 `/data/` 和 `/CODEX_SESSION_DIAGNOSIS.txt` 防止后续 agent 误提交。不改业务代码，不改测试语义，不继续架构优化。 |
| CodexWorkspaceArtifactCleanup-Followup-1 | 修正 master plan 头部日期为 followup 任务名；核查 `.codex/` 本地 artifact 并加入 `.gitignore`；更新 current-working-handoff 记录收口状态。不改业务代码，不改测试，不继续架构优化。 |
| Codex-ArchitectureOptimizationLoop-1 | Codex 基于最新 master 做架构总审计增量，并选择第一轮低风险实现：新增 `TextBlockPhraseIndexingTest` 锁定 TextBlockService 剩余 phrase/index 行为（exact match、跨 NEWLINE、缺词不命中、phraseIndexes 映射、用户/语言隔离）。不改业务代码，不改 TextBlockService / ReaderDataService / Vue / Controller / DB schema，不完成全部架构优化。下一轮候选保留为 FSRS confirmAndApply safe write tests、SenseReview 完整写入 smoke、TextBlock tokenizer fallback 只读侦查。 |
| Codex-ArchitectureFinalGoalMode-1 | Codex 面向 sense-only 最终架构目标做第二轮增量审计，并选择 FSRS confirmAndApply 拒绝写入路径作为本轮低风险 P1 安全护栏。新增 2 个 contract tests：`apply=true` 高风险未 `risk_confirm` 时不写 ReviewCard、不建 reschedule snapshot、不写 ReviewLog；blocked 超量时即使传 `risk_confirm` 也不写。只改测试和计划文档，不改 `FsrsReschedulePreviewService`、FSRS 算法、ReviewCard/ReviewLog/WordSense 业务语义，不执行真实重排。`php artisan test --filter=FsrsReschedule` 为 98 tests / 479 assertions 全绿。下一轮候选保留为 SenseReview 有数据页面 smoke、TextBlock tokenizer/fallback 只读侦查、ReviewCardManage logs payload serializer 边界。 |
| OpenCode-ArchitectureTargetMode-Batch1 | 综合推进：SenseReview 到期卡真实验收（MCP Chrome 显示 + Good 评分 + ReviewLog 创建）；TextBlock fallback 只读侦查（fallbackEnglishTokenize 仍可被调用但无测试覆盖）；ReviewCardManage logs payload 只读侦查（20 条最近日志、user/language/card 三重过滤，字段已盘点，contract tests 待补）。阶段性完成，仍有缺口：pending occurrence 写入未跑通、TextBlock fallback 缺测试、logs payload 缺 contract tests。 |
| AIStudyCardGenerationWorkflow-Plan | 产品目标冻结：AI 译文 ≠ AI 示意卡；用户选词优先（点击单词/拖动词组→手动添加释义→直接生成可复习卡）；用户可标记"待 AI 解释"词；AI 推荐词必须排除用户已选词；弹窗确认机制中 AI 推荐词默认不选，提供全选按钮；只有被用户确认后才生成示意卡。前端主入口不再展示"词义确认/词义复习"，统一为"复习"。当前不实现。后续实现前必须先做架构侦查，不改 DB schema，不删除现有 SenseMappingReview/SenseReview 能力，不删除旧 word card 兼容层。 |
| OpenCode-AiStudyCardWorkflowPlan-And-Batch1DocFix-1 | 修正 Batch1 文档收口：current-working-handoff 补充 Batch1 阶段性完成状态、未完成缺口、产品决策（复习入口统一/AI 译文≠AI 示意卡/用户选词优先/AI 推荐词不重复/默认不选）。修正 master plan 和 hotspot audit 中"字段已锁定"的夸大表述。记录 AI 示意卡生成流程产品目标，冻结实现前不得直接改 DB schema、不得删除现有能力。不改业务代码，不改测试。 |
| Codex-ProjectDocsGovernanceTargetMode-1 | 基于 Vibe Coding / spec / harness 字幕原则和 CodeBuddy 文档盘点报告治理项目文档体系。新增 `docs/DOCUMENTATION_INDEX.md`、`docs/HISTORY_INDEX.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`、`docs/plans/spec-to-harness-candidates.md`；给旧 `CURRENT_STATUS` / `NEXT_TASK` / `FSRS_PHASE*` / 旧 handoff / 旧 FSRS roadmap 加历史降权标记；收敛 current-working-handoff、hotspot audit、协作规则中的入口职责。不改业务代码，不改测试，不把 AI 示意卡写成已实现。下一轮仍由总设计师选择，不自动执行。 |
| Codex-SpecToHarnessHardeningTargetMode-1 | 将文档中的两个关键软规则转为可执行测试护栏：新增 `tests/Unit/TextBlockFallbackTokenizerTest.php`，锁定 fallbackEnglishTokenize 的保守 lemma、irregular table、安全标记、数字/标点、空文本异常；补 `tests/Feature/ReviewCardManageTest.php` 的 logs payload 精确字段/ISO 日期格式与同一 review_card_id 下 user/language 过滤。只改测试和计划文档，不改 TextBlockService、ReviewCardManageController、tokenizer/import/FSRS/WordSense/ReviewLog 语义。 |
| Codex-SenseReviewRealWorkflowHardeningTargetMode-1 | 将 SenseReview 真实页面 smoke 转为可复验 harness：新增 `smoke:sense-review-data` Artisan 命令（只接受已有本地用户邮箱，不创建账号，不接收密码，不写凭据，marker 可识别，不清库）；新增 `tests/Feature/SenseReviewSmokeDataCommandTest.php` 锁定 marker 数据形状；新增 `docs/plans/sense-review-real-workflow-smoke-playbook.md` 作为可复验页面 smoke 指南。MCP Chrome 真实页面验收覆盖 `/reviews/senses` 评分/More 菜单/查看原文 fallback 和 `/senses/review` 确认/改绑/拒绝/忽略/新建。不改 Vue、FSRS、WordSense 删除/归档/恢复、ReviewLog、DB schema、AI study card。 |
| OpenCode-DesignerProgressAndScoutingRules-1 | 写入总设计师提示词前进度说明规则（§22）与三方架构侦查规则（§23）。新增 current-working-handoff §7 当前主线进度。更新 DOCUMENTATION_INDEX 引用。不改业务代码，不改测试。 |
| OpenCode-AIStudyCardArchitectureScouting-And-ProgressRuleFix-1 | 复合任务：(A) 修正进度规则为固定五条主线，删除"文档治理"固定线，写入"零进度任务不得单独派发"规则；(B) 完成 AI 示意卡架构侦查，新增 `docs/plans/ai-study-card-architecture-scout.md`，覆盖 8 大现有能力地图、未来理想流程、12 个代码接入点、5 个不能改的危险区、第一轮最小目标建议（阅读页标记"待AI解释"）。AI 示意卡规划从 10% → 25%，总体架构收口从 79% → 81%。MCP Chrome 只读观察阅读页、复习入口。不改业务代码，不改测试，不改 Vue。 |
| Codex-FinalArchitectureClosureTargetMode-1 | 最终架构收口。新增三份冻结文档：`final-architecture-closure-report.md`（收口报告，五条主线最终状态、旧系统地基检查、已有硬护栏、不阻塞收口的未完成事项、下一阶段三步路线）、`ai-study-card-v1-frozen-plan.md`（AI 示意卡第一版路线冻结，含目标/用户流程/数据边界/前端边界/后端边界/禁止范围/验收/进度）、`frontend-review-entry-unification-plan.md`（前端复习入口统一路线冻结，含现状/命名规则/未来统一方式/第一轮最小改法/禁止一次性删除旧页面/MCP Chrome 验收/WorkBuddy 复验）。MCP Chrome 只读复核阅读页、查词侧栏、AI 阅读辅助按钮、复习入口、词义确认入口、复习卡管理入口。关键发现：导航仍暴露"单词复习/词义确认"内部名称；首页"开始复习"指向 legacy word review 而非 `/reviews/senses`；"待 AI 解释"按钮尚不存在。五条主线进度更新：总体架构收口 81%→100%（不代表全项目完成）、复习主线稳定 86%→91%、页面真实验收 90%→91%、AI 示意卡规划 25%→55%、前端入口整理 50%→65%。不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema、FSRS 语义、删除/归档/恢复语义、ReviewLog 保留语义、legacy word card 兼容层、SenseReview、SenseMappingReview。 |
| Codex-AIStudyCardV1-And-ReviewEntryUnification-1 | AI 示意卡第一版实现 + 前端复习入口统一第一轮。新增 pending 表/API/Service/Controller 和阅读页查词区「待 AI 解释」按钮（覆盖 `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue`）；重复点击幂等；后端 tests 覆盖鉴权、用户/语言隔离、反向 contract（不写 WordSense/ReviewCard/ReviewLog/EncounteredWord）。首页「开始复习」和导航「复习」进入 `/reviews/senses`，旧 `/senses/review`、`/review-cards/manage`、`/review/false/-1/-1` 保留。进度更新：总体架构收口 100%、复习主线稳定 94%、页面真实验收 96%、AI 示意卡规划 90%、前端入口整理 92%。不调用 AI、不生成释义/复习卡、不改 FSRS、不改删除/归档/恢复、不删除 SenseReview/SenseMappingReview/legacy word 兼容。 |
| GLM-AIStudyCardV2-GenerationLoop-1 | AI 示意卡 V2 生成闭环第一阶段。新增 `GET /ai-study-card/pending-items`（支持 chapter_id 过滤）、`POST dismiss`、`POST restore`；改造 `createOrGetPending` 支持 dismissed 恢复（恢复而非新建，避免重复行）；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释列表面板、取消按钮、「生成 AI 示意卡」按钮、预览弹窗雏形（显示用户已选词 chips + AI 推荐词占位 + 安全说明 + 规则预览 + disabled 确认按钮）。新增 16 个 V2 feature tests（23 tests / 105 assertions 全绿）。MCP Chrome 真实页面验收 24 项全通过。新增 `docs/plans/ai-study-card-v2-generation-loop-plan.md`。进度更新：复习主线稳定 96%、页面真实验收 100%、AI 示意卡规划 100%、前端入口整理 98%；新增子阶段「AI 示意卡生成闭环」70%。**70% 是子阶段进度，非五条主线虚假上调。** 不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复、不删除 SenseReview/SenseMappingReview/legacy word 兼容。 |
| GLM-AIStudyCardV3-SafePreviewPackage-1 | AI 示意卡 V3 安全生成包。扩展 `GET /ai-study-card/pending-items` 支持 `status=pending\|dismissed\|all` 过滤；新增 `POST /ai-study-card/pending-items/preview-package` 后端安全包接口（schema_version=ai-study-card-preview-package-v1，含 selected_items/generation_rules/safety_flags 4 条 no_ai_called/no_review_card_created/no_word_sense_created/no_fsrs_changed）；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释/已取消视图切换、已取消项恢复按钮、真实预览弹窗（用户已选词列表 + 来源句子 + 章节位置 + 数量 + 状态 + 安全说明 + 勾选取消 + 全不选禁用「准备生成」+ AI 推荐词占位区域 + 未来生成规则说明）、「准备生成」按钮触发后端安全包、JSON 展示与复制按钮（成功/失败 toast）。新增 14 个 V3 feature tests（37 tests / 184 assertions 全绿）。MCP Chrome 真实页面验收 28 项全通过。新增 `docs/plans/ai-study-card-v3-safe-preview-package-plan.md`。进度更新：前端入口整理 98%→100%；子阶段「AI 示意卡生成闭环」70%→95%；新增子阶段「AI 生成安全契约」0%→55%。**80% 是子阶段进度提升，非固定五条主线虚假上涨。** 不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复、不删除 SenseReview/SenseMappingReview/legacy word 兼容。 |
| OpenCode-ProductPrinciples-And-LegacyCleanupPlan-1 | 写入 8 条产品核心原则（产品定位、旧版入口、Finished reading、AI 例句、熟词僻义、阅读中刷卡、多例句轮换、词形原型绑定）。新增 `docs/plans/product-principles-and-legacy-cleanup-plan.md`。只读侦查确认 Finished reading 合规（仅处理 EncounteredWord stage=2），记录旧版入口（旧词条释义显示）和 legacy target_type=word 兼容层状态。计划冲突检查未发现重大冲突。不可用 MCP Chrome（MySQL 未运行），基于代码侦查完成。不改业务代码、Vue、Controller、Service、routes、migration。 |
| Codex-LegacyEntry-FinishedReading-ExampleGuard-1 | 旧版入口清理执行 + Finished reading 安全护栏 + 阅读例句路线冻结。普通查词组件隐藏旧版释义入口文案，新增 `LegacyEntryUiGuardTest` 防回归；Finished reading 新增 `FinishedReadingSafetyTest`，锁定当前用户/当前语言 yellow `stage=2` → known `stage=0`，green 学习词 stage 不变，不写 WordSense/ReviewCard/ReviewLog，不改 FSRS；修复 `ChapterService::finishChapter()` 自动 known 分支缺少 language 过滤。新增 `docs/plans/reading-inline-review-and-example-pool-plan.md`，冻结阅读中刷卡和多例句轮换路线但不实现。不删除 `target_type=word`，不删除 legacy route/service/tests，不改 FSRS/ReviewCard/WordSense 删除归档恢复语义。 |
| Trae-ExamplePool-ReviewRotation-SourceCarousel-1 | 多例句池 + 复习页题面例句轮换 + 答案后补充例句不重复 + 多来源溯源 carousel + Finished reading 确认弹窗。新增 `WordSenseExamplePoolService`（复用 `WordSenseOccurrence` + card example fallback，不新增 migration，不调 AI），`SenseReviewCardSerializerService` payload 新增 `example_candidates` / `example_candidates_count` / `supplementary_example`，稳定 seed 轮换（review_card_id + fsrs_reps + day-of-year，crc32）；`SenseSourceContextService` 新增 `sourceContextList` + 新路由 `GET /senses/{id}/source-context-list`；`SenseExampleDialog.vue` 支持来源 1/N 切换；`SenseReview.vue` 答案侧新增补充例句区块 + 防御性去重；`TextReader.vue` 「完成阅读」按钮新增确认弹窗。新增 18 个 feature tests（WordSenseExamplePoolTest 12 + SenseSourceContextMultiSourceTest 6，全绿）。MCP Chrome 真实页面验收：登录 → /reviews/senses 题面正常 → 查看答案 → 单例句无重复补充 → 单来源无切换按钮 → 阅读页完成阅读确认弹窗 → 取消不执行 → console/network 正常。新增子阶段：多例句池 0%→60%、复习页题面轮换 0%→50%、多来源溯源 0%→30%、Finished reading 误触保护 0%→20%，合计 160% 子阶段进度（非固定五条主线虚假上涨）。**阅读中刷卡仍未实现；AI 不生成例句；不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS、不新增 migration、不删除 legacy 兼容层。** |
| Trae-LemmaKnownSenseBridge-1 | 词形原型绑定 + 已学词义候选 + 熟词僻义前置结构 + 例句池性能优化。新增只读服务 `WordSenseKnownSenseService`（`listConfirmedSensesForLemma` 仅返回当前用户/语言/lemma 的 confirmed WordSense，排除 rejected/ai_suggested；批量 `whereIn` + `groupBy` 预加载 occurrence count 消除 N+1；`knownSenseLookupPayload` 返回 `{ lemma, has_confirmed_senses, confirmed_senses, known_sense_new_meaning_hint, read_only }`）；新增端点 `GET /senses/known-sense-lookup`；`WordSensesList.vue` 新增「已学词义候选」面板 + 「熟词僻义」info alert（明确标注「未调用 AI 判断」）；surface/lemma 绑定：点词显示 surface（如 geese）+ lemma（如 goose）+ [修改]，搜索/添加新释义优先用 lemma，用户修正 lemma 后生效，不无条件绑定 published→publish；性能优化：`WordSenseExamplePoolService::exampleCandidates()` 批量 `whereIn` 预加载 Chapter 消除 N+1，`SenseSourceContextService::sourceContextList()` 批量 `whereIn` 预加载 + `limit(12)` + PHP `unique+take(3)` 保持跨数据库兼容（SQLite 不支持 GROUP BY + orderByRaw 组合）。新增 13 个 feature tests（WordSenseKnownSenseBridgeTest，全绿）。MCP Chrome 真实页面验收：登录 → 阅读页点击 geese → 显示 geese→goose→[修改] → 搜索框 value=goose → 创建 confirmed sense 后重新点击 → 「已学词义候选」面板 + 「熟词僻义」提示 → /senses/known-sense-lookup 200 → 复习页显示答案 + 例句正常 → 查看原文多来源正常 → console 仅预期 WebSocket 降级 → network 全 200。新增子阶段：词形原型绑定 10%→60%、熟词僻义识别 15%→65%、阅读中刷卡前置匹配 0%→40%、多例句池性能与来源查询优化 0%→20%，合计 160% 子阶段进度（非固定五条主线虚假上涨）。**阅读中刷卡评分仍未实现；AI 不生成例句；不写 ReviewLog；不改 FSRS；熟词僻义仅前置结构，AI 判断仍未实现；不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不新增 migration、不删除 legacy 兼容层。** |
| Codex-MorphologyMatrix-ImportRegression-1 | 形态变化测试矩阵、真实文章 fixture 导入回归与 lemma 候选强化。新增 `tests/Feature/MorphologyMatrixImportRegressionTest.php`：覆盖 8 类形态变化，每类至少 2 个不同词；项目可控文章 fixture 的 `processed_text` 保留 surface / lemma / pos；known-sense lookup 对矩阵 lemma 返回当前用户/当前语言/confirmed WordSense，排除 rejected / ai_suggested / 其他用户 / 其他语言，payload `read_only=true`；`published` / `running` / `used` / `broken` 等词性歧义只显示 tokenizer/lemma 结果，不自动绑定、不写 ReviewLog、不创建 ReviewCard、不改 FSRS、不自动刷卡。新增 `tests/Feature/MorphologyMatrixUiGuardTest.php`：锁定 `WordSensesList.vue` surface+lemma UI、add-sense payload 优先 `effectiveLemma` 且保留 `surfaceWord`、熟词僻义提示仍写明未调用 AI、旧入口文案仍隐藏。新增子阶段进度合计至少 600%，但这是形态矩阵/导入回归/绑定前置/页面覆盖/文档治理等子阶段提升，不是固定五条主线虚假上涨。**阅读中刷卡评分仍未实现；AI 判断熟词僻义仍未实现；AI 不生成例句；不写 ReviewLog；不改 FSRS。** |

## 4. 未完成任务总表

### 4.1 计划治理 / 仓库治理

| 编号 | 内容 | 状态 |
|------|------|------|
| Repo-Governance-1 | 确认主开发仓库是 `LinguaCafe-local` 还是 `LinguaCafe-dev-main` | 📋 待确认 |
| Repo-Governance-2 | 如需切换主仓库，制定同步计划 | 📋 待定 |
| Plan-Integrity-1 | 总控计划持续维护 | ✅ 当前阶段 |

### 4.2 AI 阅读辅助

| 编号 | 内容 | 状态 |
|------|------|------|
| AI-Reading-Assist-3 | ✅ 已完成 — 确认保存 AI 解析结果（新增 chapter_ai_reading_assists 表 + confirm 接口 + 前端按钮，不创建 WordSense/ReviewCard/ReviewLog） | ✅ 已完成 |
| AI-Reading-Assist-4 | ✅ 已完成 — AI 译文按句显示/隐藏开关（current 读取接口 + 阅读页工具栏开关 + TextBlockGroup 按句渲染） | ✅ 已完成 |
| AI-Reading-Assist-5 | AI 释义与词组结果进入查词侧栏 | 📋 计划中 |
| AI-Reading-Assist-6 | 词组识别后用户可添加整个词组或单个单词 | 📋 计划中 |
| AI-Reading-Assist-7 | 释义卡多例句绑定浏览 | 📋 计划中 |
| AI-Reading-Assist-8 | 复习卡例句轮换 | 📋 计划中 |
| AI-Reading-Assist-9 | AI API 配置页 | 📋 计划中 |
| AI-Reading-Assist-10 | API 自动分析本章 | 📋 计划中 |
| AI-Reading-Assist-11 | DeepSeek Flash / Pro 真实输出稳定性对比（需要人工在 chat.deepseek.com 测试） | 📋 待实验 |

### 4.3 查词侧栏 / 阅读页 UI

| 编号 | 内容 | 状态 |
|------|------|------|
| Reader-UI-1 | 查词侧栏后续轮次瘦身（本轮 Reader-UI-1-a 已完成第一轮） | 📋 暂缓，等后续轮次 |
| Reader-UI-2 | 普通查词界面不展示旧版释义入口 | ✅ 已完成（Codex-LegacyEntry-FinishedReading-ExampleGuard-1 中隐藏；后端兼容层未删） |
| Reader-UI-3 | 词典结果默认收起，词典名称弱化 | ✅ 已完成（Reader-UI-1-a 中完成） |
| Reader-UI-4 | 添加释义简化为"词性 + 中文释义"，高级字段默认隐藏 | 📋 计划中 |
| Reader-UI-5 | 移除或隐藏旧 SRS 1-7 熟练度条 | ✅ 已完成（Reader-UI-1-a 中完成） |
| Reader-UI-6-a | "删除词条"语义收口 → "回归为新词" + 确认弹窗优化 | ✅ 已完成 | 见下方审计报告 |
| Reader-UI-6-b | 回归为新词时删除释义复习卡与复习记录 | ✅ 已完成 | 后端删除 sense ReviewCard+ReviewLog、legacy word ReviewCard+ReviewLog，WordSense 标记 rejected，EncounteredWord 已删除（同硬删除） |
| Reader-UI-7 | hover 自动查词开关 | 📋 计划中 |
| Reader-UI-8 | 隐藏右侧常驻"词汇表 / 阅读设置 / 关于本章 / 操作记录" | 📋 计划中 |
| Reader-UI-9 | 查词栏显示 FSRS 熟悉度百分比 + 小进度条 | ✅ 已完成 |"FSRS 熟悉度：N%"文字 + v-progress-linear 绿色进度条，无 FSRS 数据时显示"尚未复习" |

### 4.4 FSRS / Anki 管理

| 编号 | 内容 | 状态 |
|------|------|------|
| Mgmt-7-b | 每日新学累计计数精确化（当前只限制队列显示数量，未严格统计今日已学新卡累计） | 📋 计划中 |
| Mgmt-7-c | 自动提升词汇等级改为 FSRS 复习记录（审计确认当前仍使用旧 SRS EncounteredWord.setStage） | 📋 待开发 |
| FSRS-Anki-Mgmt-8 | 今日临时上限 / 暂停新卡 | 📋 计划中 |
| FSRS-Anki-Mgmt-9 | Preset / 分组参数长期评估 | 📋 计划中 |
| FSRS-Param-Browser-Smoke | FSRS 参数优化真实浏览器验收 | 📋 待验收 |

### 4.5 释义卡 / 多例句 / 复习体验

| 编号 | 内容 | 状态 |
|------|------|------|
| Sense-Example-Link-1 | 一张释义卡绑定多个来源例句 | 📋 计划中 |
| Sense-Example-Link-2 | 查词时先选定释义卡，再展示该卡其他例句 | 📋 计划中 |
| Sense-Example-Link-3 | 复习卡例句轮换 | 📋 计划中 |
| Sense-Example-Link-4 | 避免连续两天显示同一句 | 📋 计划中 |
| Sense-Example-Link-5 | 尽量平均展示所有来源例句 | 📋 计划中 |

### 4.6 Lemma / 原型识别

| 编号 | 内容 | 状态 |
|------|------|------|
| Lemma-Origin-1 | 英文原型识别回归侦察与修复 | 📋 计划中 |
| Lemma-Origin-2 | 添加释义时允许选择当前词形、系统原型、手动原型 | 📋 计划中 |
| Lemma-Origin-3 | 手动原型只作用于本篇当前绑定，不全局改 lemma | 📋 计划中 |

### 4.7 Source Context / 原文定位

| 编号 | 内容 | 状态 |
|------|------|------|
| Source-Context-Verify-1 | 复查"查看原文/译文"是否仍大量 fallback 到 card_example | 📋 待侦察 |
| Source-Context-Fuzzy-1 | 如仍 fallback，恢复模糊定位原文任务 | 📋 待侦察 |
| Source-Context-Diagnostics-1 | 原文定位失败诊断日志 | 📋 待侦察 |

### 4.8 Sense Review 真实验收

| 编号 | 内容 | 状态 |
|------|------|------|
| SenseReview-Smoke-1 | 真实刷 5-10 张 sense cards | 📋 待验收 |
| SenseReview-Smoke-2 | 测试 Space / 1 / 2 / 3 / 4 快捷键 | 📋 待验收 |
| SenseReview-Smoke-3 | 查看答案面复杂度 | 📋 待验收 |
| SenseReview-Smoke-4 | More 菜单体验 | 📋 待验收 |
| SenseReview-Smoke-5 | 确认每日上限和超额复习入口真实可用 | 📋 待验收 |

### 4.9 ReviewCardManage 安全服务边界

| 编号 | 内容 | 状态 |
|------|------|------|
| ReviewCardManage-MutationBoundary-Scout-1 | 只读侦察管理页危险写操作边界：编辑、归档/恢复、立即到期、重置、彻底删除、批量操作、权限校验、ReviewLog 保留语义 | 📋 下一步建议 |
| ReviewCardManage-MutationService-Extract-1A | ✅ 已完成 — 抽取单卡归档/恢复与立即到期服务（enabled + dueNow）。新增 ReviewCardManageMutationService。未处理 update/reset/destroy/bulk。 |
| ReviewCardManage-MutationService-Extract-1B | 待定 — 抽取 update（WordSense 文本编辑，含 normalizeArray）。需独立 Phase。 | 📋 待决定 |
| ReviewCardManage-MutationService-Extract-1C | 待定 — reset / destroy / bulk 操作（涉及 ReviewCardService、WordSenseService、事务、EncounteredWord 语义）。需 scout 明确方案后才能处理。 | 📋 待侦察后决定 |

### 4.10 Dev / 运行环境

| 编号 | 内容 | 状态 |
|------|------|------|
| DevMain-Run-1 | 启动脚本验证 | 📋 待验证 |
| DevMain-Run-2 | 旧电脑运行验证 | 📋 待验证 |
| DevMain-Run-3 | 如切换到 dev-main，验证最新功能同步和运行 | 📋 待定 |

---

## 5. 颜色语义规则

1. **黄色/橙色**：阅读正文中的新词 / 未处理词（stage = 2）。
2. **绿色**：已进入学习系统 / 已有释义卡（stage < 0）。
3. **绿色深浅**：FSRS 熟悉度，10% 到 100%（`fsrs_familiarity_percent`）。
4. **紫色**：AI 预览搜索命中（仅在 TextReaderAiAssist 详情页生效）。
5. 不允许把黄色、绿色、紫色混用。
6. AI 搜索命中不能用黄/绿。
7. **查词栏也要显示 FSRS 熟悉度百分比**（`Reader-UI-9`）。

---

## 6. 计划保全规则

1. 已写入 roadmap / master plan 的任务不得静默删除。
2. 临时插入任务不等于取消原计划。
3. "本轮不做"不等于"以后不做"。
4. 暂缓任务必须写为 planned / follow-up / postponed。
5. 如果因为技术限制不能做，必须写明原因和替代方案。
6. 网页端 GPT 负责产品优先级判断。
7. 本地 Agent 不得擅自改变产品优先级。
8. 每次更新 roadmap 时检查是否误删旧计划。

## 7. Anki 参考规则

本文档适用于网页端 GPT 和本地 OpenCode Agent：

1. **必须先查 Anki**：涉及以下主题时，在提出产品问题或生成开发提示词前，必须先查看 Anki 官方手册 / 代码仓库 / 功能讨论：

   - SRS / FSRS
   - 复习卡 / 复习记录 / review history / revlog
   - 删除 / 重置 / Forget / Reset
   - Card Info / Browser / Browse
   - 统计图
   - 学习队列 / answer buttons
   - deck options / preset
2. **Anki 是参考基准，不是圣经**：
   - LinguaCafe 是阅读学习工具，不是 Anki 克隆。
   - 如果 Anki 做法与 LinguaCafe 已明确的 sense-only 规则或用户明确产品决定冲突，以 LinguaCafe 总控计划和用户决定为准。
   - 偏离 Anki 时必须在报告 / 提示词中说明原因。
3. **本地 Agent 的 Anki 参考报告要求**：
   - 查看了哪些 Anki 资料。
   - 借鉴了什么。
   - 哪些地方没有照搬。
4. **禁止凭想象回答 "Anki 大概怎么做"**。

---

## 8. 建议下一步顺序

以下为网页端 GPT 或项目负责人参考的建议顺序，不绑定开发节奏：

| 建议顺序 | 编号 | 内容 | 理由 |
|----------|------|------|------|
| 1 | ReviewCardManage-MutationBoundary-Scout-1 | 只读侦察管理页危险写操作服务边界 | 用户明确希望把危险操作做成更安全的服务边界；先 scout，不直接改删除/reset/bulk |
| 2 | Reader-UI-9 | ✅ 已完成 — 查词栏显示 FSRS 熟悉度百分比 + 小进度条 | 用户明确要求，改动小，价值高 |
| 3 | Reader-UI-1-a | 查词侧栏第一轮瘦身 | 隐藏旧 SRS 1-7 按钮，"旧词条释义"→"选择释义"，词典默认收起 |
| 4 | Reader-UI-6-a | "删除词条"→"回归为新词"语义收口 | 用户明确要求，改动小，价值高 |
| 5 | AI-Reading-Assist-3 | ✅ 已完成 — 确认保存 AI 解析结果（不创建 WordSense/ReviewCard/ReviewLog） | 新增 chapter_ai_reading_assists 表 + confirm 接口 + 前端按钮 |
| 6 | AI-Reading-Assist-4 | AI 译文按句显示/隐藏 | 核心 AI 辅助阅读功能 |
| 6 | Sense-Example-Link-1 | 释义卡多例句绑定 | 提升释义卡质量 |
| 7 | Lemma-Origin-1 | 原型识别回归修复 | 影响字典查询和释义准确性 |
| 8 | Mgmt-7-c | 自动提升词汇等级改为 FSRS | 清理旧 SRS 遗留逻辑 |

## Recent Update: Codex-FinalArchitectureClosureTargetMode-1

- Architecture-closure phase is finalized. Three frozen-plan documents added:
  - `docs/plans/final-architecture-closure-report.md` — closure report with one-line conclusion, five-line final status, old-system foundation check, existing hard guardrails, non-blocking unfinished items, and a three-step next-stage roadmap.
  - `docs/plans/ai-study-card-v1-frozen-plan.md` — AI study card v1 frozen plan: only records "pending AI explanation" items from the reading page; no AI calls, no review card creation, no FSRS / ReviewCard / delete-archive-restore / legacy word card changes, no DB schema change this round.
  - `docs/plans/frontend-review-entry-unification-plan.md` — frontend review entry unification frozen plan: future main entry unified as "复习", `/reviews/senses` is the main line, `/senses/review` becomes alias, `/review-cards/manage` moves to "advanced", legacy word review stays as compatibility layer not exposed in nav.
- MCP Chrome read-only observation confirmed current entry state: nav still exposes "单词复习 / 词义确认", homepage "开始复习" still points to legacy `/review/false/-1/-1`, "待 AI 解释" button does not yet exist.
- Five-line progress updated: Overall architecture closure 81% → 100% (not full project completion), Review mainline 86% → 91%, Page real acceptance 90% → 91%, AI study card planning 25% → 55%, Frontend entry cleanup 50% → 65%.
- This task did NOT change business code, tests, Vue, Controller, Service, routes, migration, DB schema, FSRS semantics, delete/archive/restore semantics, ReviewLog retention, legacy word card compatibility layer, SenseReview, or SenseMappingReview.
- Next stage should NOT continue infinite scouting. The recommended next minimum implementation is AI study card v1 (pending item only), followed by frontend review entry unification round 1, followed by AI recommendation popup and card generation loop.

## Recent Update: Codex-AIStudyCardV1-And-ReviewEntryUnification-1

- AI study card v1 pending marker is implemented: reading-page word click exposes「待 AI 解释」and stores a pending row only.
- Frontend review entry unification round 1 is implemented: homepage and nav daily review entry now target `/reviews/senses`; legacy routes remain available.
- Contract coverage added for pending item auth, user/language isolation, idempotent duplicate clicks, and no writes to learning/review tables.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 94%, Page real acceptance 96%, AI study card planning 90%, Frontend entry cleanup 92%.
- Not implemented: AI recommendation modal, AI meaning generation, automatic WordSense/ReviewCard generation, FSRS integration, full card-generation loop, or final cleanup/removal of old routes.

## Recent Update: GLM-AIStudyCardV2-GenerationLoop-1

- AI study card v2 generation loop phase 1 is implemented: pending item list (GET with chapter_id filter), dismiss/restore endpoints, and generation preview modal placeholder.
- `createOrGetPending()` refactored: if same key exists as dismissed, restore to pending instead of creating duplicate row (idempotent design leveraging V1 unique constraint that includes status field).
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` now include "待 AI 解释列表" button, list panel with word/sentence/status/time/cancel, "生成 AI 示意卡" button, and preview modal with safety notice, user-selected word chips, AI recommendation placeholder, rule preview, and disabled confirm button.
- Added 16 new V2 feature tests (23 tests / 105 assertions total, all green). Covers list auth, user/language isolation, chapter filter, 404 for other users' chapters, only-pending filter, dismiss/restore, idempotency, reverse contracts (no WordSense/ReviewCard/ReviewLog writes), existing sense/card state preservation.
- MCP Chrome real-page acceptance 24/24 passed: login → reading page → click word → mark → list → cancel → re-mark (restore) → preview modal → no AI calls → no learning data writes (WordSense=19, ReviewCard=16, ReviewLog=2 unchanged) → main/old entry points work → console clean (only pre-existing WebSocket降级) → network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 98%.
- New sub-phase: AI study card generation loop 70%. **This 70% is the sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- New document: `docs/plans/ai-study-card-v2-generation-loop-plan.md`.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal, no new migration.

## Recent Update: GLM-AIStudyCardV3-SafePreviewPackage-1

- AI study card v3 safe preview package is implemented: dismissed-item restore button (P2 fix), real preview content (P3 fix), and safe preview package (new sub-phase).
- Backend: extended `GET /ai-study-card/pending-items` to accept `status=pending|dismissed|all` filter; added `POST /ai-study-card/pending-items/preview-package` returning a safe JSON package with `schema_version=ai-study-card-preview-package-v1`, `selected_items` (item_id/chapter_id/text_block_index/sentence_index/word/normalized_word/surface/lemma/sentence_text/status/created_at), `generation_rules` (4 rules: no_auto_review_card / ai_recommended_default_unchecked / ai_recommended_exclude_user_selected / user_confirmation_required_before_generation), `safety_flags` (4 flags: no_ai_called / no_review_card_created / no_word_sense_created / no_fsrs_changed), `schema_version`, `created_at`.
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` upgraded with pending/dismissed view toggle (v-btn-toggle), restore button for dismissed items, real preview modal (user-selected words list with checkboxes, source sentence, chapter position, count, status, safety notice, "全不选禁用准备生成" rule, AI-recommended words placeholder area with "下一阶段由 AI 推荐 / AI 推荐词默认不选 / AI 推荐词不会和你已选的词重复" notices, future generation rules explanation), "准备生成" button triggering backend preview-package endpoint, JSON display in read-only textarea, "复制生成包" button with success/failure toast.
- Added 14 new V3 feature tests (37 tests / 184 assertions total, all green). Covers dismissed list auth/isolation/language filter, restore idempotency + no learning data, preview-package auth/user/language/status isolation, empty item_ids, max 100 items cap, reverse contracts (no WordSense/ReviewCard/ReviewLog/FSRS changes, no pending status change, no learning data created).
- MCP Chrome real-page acceptance 28/28 passed: login → reading page → click word → mark → list → cancel → dismissed view → restore → real preview modal → checkbox toggle → all-uncheck disables "准备生成" → re-check re-enables → 准备生成 → safe package JSON displayed with schema_version/selected_items/generation_rules/safety_flags → copy → "已复制到剪贴板" toast → no AI calls (only 127.0.0.1 requests) → no WordSense/ReviewCard/ReviewLog writes (WordSense=19, ReviewCard=16, ReviewLog=2 unchanged) → main review entry `/reviews/senses` works → old entry `/review-cards/manage` works → console clean (only pre-existing WebSocket降级) → network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 98% → 100%.
- Sub-phase progress: AI study card generation loop 70% → 95%; AI generation safety contract 0% → 55%. **The 80% uplift (25 + 55) is sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- New document: `docs/plans/ai-study-card-v3-safe-preview-package-plan.md`.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal, no new migration.
- Did NOT enter the next task automatically.

## Recent Update: GLM-AIRecommendationConfirmationLoop-V4-1

- AI study card v4 AI recommendation confirmation loop is implemented: paste AI recommendation JSON, dedupe, default unchecked, user confirmation, final candidates package.
- Backend: added `POST /ai-study-card/pending-items/final-candidates-package` returning a safe JSON package with `schema_version=ai-study-card-final-candidates-v1`, `user_selected_items` (with source=user_selected), `ai_recommended_selected_items` (with source=ai_recommended), `ai_recommended_unselected_items`, `dedupe_summary` (original_count/valid_count/dropped_missing_word/dropped_duplicate_with_user/dropped_ai_internal_duplicate), `generation_rules` (5 rules: no_auto_review_card / ai_recommended_default_unchecked / ai_recommended_exclude_user_selected / user_confirmation_required_before_generation / user_confirmation_required_before_card_generation), `safety_flags` (6 flags: no_ai_called_by_linguacafe / ai_response_pasted_by_user / no_review_card_created / no_word_sense_created / no_fsrs_changed / user_confirmation_required_before_card_generation). Backend enforces user/language/status triple isolation, secondary dedupe (AI vs user-selected, AI internal, unselected vs selected), empty-result 422, post-query empty 422, and size limits (max 100 user-selected, max 200 AI recommendations). Input JSON schema_version=ai-study-card-recommendations-v1 with recommended_items array; field tolerance for missing word/lemma/surface/confidence/reason/sentence_text.
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` upgraded with V4 paste-AI-recommendation-JSON textarea, "解析推荐词" / "清空推荐词" buttons, parse error message (JSON format / recommended_items not array / no valid items), parse summary (original/valid/dropped-missing-word/dropped-duplicate-with-user/dropped-ai-internal-duplicate), AI recommendation list with checkbox per item default unchecked, "全选推荐词" / "全不选推荐词" buttons, visual separation between user-selected words and AI recommendations (v-divider + section titles), "生成最终候选包" button in v-card-actions, final candidates package JSON display, and "复制最终候选包" button with success/failure toast. User-selected item toggles call redeedupeAiRecommendationsAfterUserSelectionChange to keep dedupe in sync.
- Added 18 new V4 feature tests (AiStudyCardPendingItemTest 56 tests / 294 assertions total, all green). Covers: auth, user/language/status isolation, AI dedupe vs user-selected, AI internal dedupe, default-unchecked reflected in data structure (unselected_ai_recommendations field), empty selected + empty AI returns 422, post-query empty returns 422, only user-selected without AI allowed, invalid AI does not crash, missing word dropped, no WordSense/ReviewCard/ReviewLog creation, no pending status change, no FSRS field changes (fsrs_state/fsrs_due_at/fsrs_stability/fsrs_difficulty/fsrs_reps/fsrs_lapses/fsrs_last_reviewed_at/fsrs_enabled), safety_flags correct (6 flags all true), unselected AI deduped against selected AI, max items limit (100 / 200), source_preview_package preserved.
- MCP Chrome real-page acceptance 33/33 passed: login → reading page → click word "substantive" → mark → list (3 items) → preview modal → safe package → paste valid JSON (agency + mediation) → parse summary 2/2/0/0/0 → default 0 checked → paste duplicate JSON (substantive + agency + Agency) → parse summary 3/1/1/1/1 (substantive excluded as duplicate with user-selected, Agency excluded as internal duplicate) → paste malformed JSON → "JSON 格式错误：Unexpected end of JSON input" → no crash → "清空推荐词" → re-paste valid JSON + parse → select agency → select all → select none → re-select agency → 生成最终候选包 → final package JSON with schema_version=ai-study-card-final-candidates-v1, user_selected_items(3), ai_recommended_selected_items(1: agency), ai_recommended_unselected_items(1: mediation), dedupe_summary(2/2/0/0/0), generation_rules(5 all true), safety_flags(6 all true) → 复制最终候选包 → "已复制到剪贴板" → no external AI network requests (only local POST /final-candidates-package 200 OK, reqid=48) → no WordSense/ReviewCard/ReviewLog writes → /reviews/senses main entry works → /review-cards/manage old entry works → console clean (only pre-existing WebSocket降级) → network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 100%.
- Sub-phase progress: AI study card generation loop 95% (unchanged); AI generation safety contract 55% → 85%; AI recommendation confirmation loop 0% → 80% (new sub-phase). **The 110% uplift (30 + 80) is sub-phase progress, NOT a fake uplift of the five main lines.** AI real recommendation (auto AI call), AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- Regression: ReviewFsrsTest 61/364, FsrsSchedulingServiceTest 9/46, WordSense (DestroyRestore+Test) 149/595 all green. npm run development compiled successfully.
- New document: `docs/plans/ai-recommendation-confirmation-loop-plan.md`.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal, no new migration.
- Did NOT enter the next task automatically.
