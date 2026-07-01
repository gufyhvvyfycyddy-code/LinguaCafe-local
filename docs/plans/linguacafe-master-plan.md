# LinguaCafe 总控大计划

> **最后更新**：2026-07-02 (Codex-ArchitectureOptimizationLoop-1)
> **Anti-Mud 规则**：参见 `docs/plans/vibe-coding-collaboration-rules.md` 第 10 节
> **性质**：本文件是 LinguaCafe 项目的总控计划，汇总所有任务线、已完成工作、未完成任务和产品规则。

---

## 1. 文档目的

1. 本文档是 LinguaCafe 项目的**总控计划**，统一管理所有任务方向。
2. `linguacafe-fsrs-roadmap.md` 是 FSRS / Sense Review 专项路线图。
3. `ai-reading-assist-plan.md` 是 AI 阅读辅助专项计划。
4. `ai-reading-assist-schema-experiment.md` 是 AI schema 设计与实验记录。
5. `fsrs-anki-management-optimization-plan.md` 是 FSRS Anki 管理优化专项。
6. 本文档用于防止旧计划被遗忘：插队任务**不等于**取消旧任务。
7. 新增任务不自动删除旧任务，除非网页端 GPT 明确同意并更新相关文档。

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
| Codex-ArchitectureOptimizationLoop-1 | Codex 基于最新 master 做架构总审计增量，并选择第一轮低风险实现：新增 `TextBlockPhraseIndexingTest` 锁定 TextBlockService 剩余 phrase/index 行为（exact match、跨 NEWLINE、缺词不命中、phraseIndexes 映射、用户/语言隔离）。不改业务代码，不改 TextBlockService / ReaderDataService / Vue / Controller / DB schema，不完成全部架构优化。下一轮候选保留为 FSRS confirmAndApply safe write tests、SenseReview 完整写入 smoke、TextBlock tokenizer fallback 只读侦查。 |

---

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
| Reader-UI-2 | "旧词条释义"改为"选择释义" | ✅ 已完成（Reader-UI-1-a 中完成） |
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
