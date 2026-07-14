# LinguaCafe 总控大计划

> **最后更新**：2026-07-14 (Task 2000-20 `GLM-CustomStudy-Phase3B-Pure-Transition-Policy-And-Contract-Harness-2000-20` — **Custom Study 1A Phase 3B 复合型主线任务**。本轮完成：(A) Phase 3A 架构契约和文档冲突收口 — ADR-0016 + implementation plan 不再写 `TokenService (signs/verifies/rotates token)` / `rotate(answer)` / 静态 `Crypt::encryptString()`；payload 补齐 `completed_ids` + `skipped_ineligible_ids`；`mode` 明确为四种 Criteria mode（`today_forgotten` / `overdue` / `source_chapter` / `leech_attention`），`preview-only` 是功能级性质不是 payload `mode` 值；新增可执行文档 guard `tests/js/CustomStudySessionArchitectureDocsGuard.test.mjs`（40 项，先 RED 9 项失败后 GREEN 全绿）；Phase 3A 标记为 Accepted。(B) State 显式不可变复制边界 — `CustomStudySessionState::withProgress(currentCardId, readyQueue, delayedRepeatQueue, completedIds, skippedIneligibleIds): self` 保留 identity 字段、自动重算 `completed_count`/`total_count`、自动 `step + 1`、拒绝 `step === PHP_INT_MAX` 溢出；新增 `waitUntil(): ?int` + `isCompleted(): bool` 派生查询；Token 常量 `VERSION` / `MAX_CANDIDATE_COUNT` 改为引用 `CustomStudySessionState`（单一来源）。(C) 纯函数 `CustomStudyPreviewPolicy` — `applyRating(state, rating, now): self` + `resume(state, now): self`，只接受四个冻结小写 rating（`again`/`hard`/`good`/`easy`）；Again/Hard → `delayed_repeat_queue`，Good/Easy → `completed_ids`；ready 优先于 delayed，delayed tie 稳定；不调用 `toArray()`/`fromArray()`，只通过 `withProgress()` 创建新 State；不访问 DB/Auth/Request/Crypt/ReviewLog/FSRS/lifecycle/AI。(D) 可执行架构 guard — `tests/js/CustomStudySessionArchitectureDocsGuard.test.mjs` 同时覆盖 ADR 和 implementation plan。(E) TDD（RED → GREEN → REFACTOR）+ 完整回归 + FACT 自审 + 3 commits。**Previous task (2000-19)**: Phase 3A `CustomStudySessionState` + `CustomStudySessionTokenService` + `CustomStudySessionStateException` + 2 unit tests；V1 payload 新增 `completed_ids` + `skipped_ineligible_ids`；五状态 union + 互斥不变量；章节选择器未来契约登记。**Custom Study 1A 当前状态**：Phase 1 ✅ Accepted；Phase 2A ✅ Accepted；Phase 2B ✅ Accepted（Task 2000-18）；Phase 3A ✅ Accepted（Task 2000-19 + Task 2000-20 docs closure）；Phase 3B（`CustomStudyPreviewPolicy` + `CustomStudyPreviewPolicyException` + `withProgress()` + `waitUntil()` + `isCompleted()` + Token 常量单一来源 + 2 unit tests + 文档 guard）代码与测试完成，等待网页端验收；Phase 4-7 未开始；**整体功能不可使用**。**Queue Order 状态**：✅ Accepted / 生产验收通过（Task 2000-14）。**硬规则权威位置**：`vibe-coding-collaboration-rules.md` §28 需求放置/复合任务/报告闭环；§19 复杂度规则。本文件不复制全文，仅引用。当前工作流: GLM 单 Agent 闭环, 详见 `vibe-coding-collaboration-rules.md` §1.5; CodeBuddy/WorkBuddy 已停用, 旧规则见 `docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md`)。
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
6. **多例句绑定**：一张释义卡绑定多个来源句，复习时智能选择展示（preferred occurrence 优先 + 线性轮换 fallback；Task 4 已升级为 smart selection）。

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
| GLM-RealMorphologyImportClickCompletion-1 | 真实 tokenizer/importer 覆盖 + 形态矩阵真实页面点击补全。`MorphologyMatrixImportRegressionTest` 升级为真实 tokenizer 测试，使用 `ChapterService::processChapterText` 调用真实 Python spaCy tokenizer，覆盖真实导入链路而非纯 fixture 断言；8/8 形态变化类别（regular/irregular plurals、third-person、past tense、past participle、progressive、comparative/superlative、adjectival ambiguity）通过 Playwright 真实页面点击覆盖（合计 18 次真实点击）；4 个词性歧义词 `published` / `used` / `broken` / `left` 通过真实页面点击覆盖；data-layer fixture 测试重命名为 `MorphologyMatrixLemmaBridgeDataLayerTest` 以体现其数据层 fixture 边界。测试命名治理完成。真实页面点击验收未使用任何 API/axios/fetch 模拟点击，全部为 Playwright 真实浏览器点击。新增子阶段进度合计约 705%，但这是真实 tokenizer 覆盖/真实页面点击/测试命名治理等子阶段提升，不是固定五条主线的虚假上涨。**阅读中刷卡评分仍未实现；AI 判断熟词僻义仍未实现；不写 ReviewLog；不改 FSRS；不调用 AI；不生成 WordSense/ReviewCard/ReviewLog。** |
| GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1 | 桌面端 AIStudyCard V5 架构收敛。把 `VocabularySideBox.vue` 与 `VocabularyBox.vue` 中重复的 V5 生成学习卡逻辑（结果展示模板、确认对话框模板、候选项构造逻辑、POST 请求逻辑）抽成共享结构。新增 `resources/js/services/AiStudyCardGenerateCardsService.js`（3 个纯函数：`buildGenerateCardItems` / `filterConfirmedGenerateCardItems` / `generateAiStudyCards`，通过传入 axios 实例发请求，不调外部 AI，不写 ReviewLog/FSRS/ReviewCard）；新增 `resources/js/components/Text/AiStudyCardGenerateCardsDialog.vue`（共享确认对话框，v-model + items/loading/error props + confirm event，不直接请求后端）；新增 `resources/js/components/Text/AiStudyCardGenerateCardsResult.vue`（共享结果展示，result prop + go-to-sense-reviews/dismiss events，不直接请求后端）。两个父组件精简为只持有 dialog open 状态、result/loading/error、调用共享 helper、传数据、跳转 `/reviews/senses`。新增 `tests/Feature/AiStudyCardV5DesktopArchitectureGuardTest.php`（17 项 / 134 assertions）：锁定共享文件存在性、两父组件引用共享组件、不再包含重复 V5 模板/逻辑指纹、AI reason 不自动填 sense_zh、service 暴露 3 个纯函数、Dialog/Result 只 emit 事件不直接调后端、BottomSheet 不含 V5 流程、无外部 AI provider 字符串、无 ReviewLog/FSRS/legacy word card 创建调用。重写 `tests/Feature/VocabularyBoxV5UiGuardTest.php`（16 项 / 104 assertions）：扫描目标从单一 VocabularyBox.vue 扩展到 4 个文件。MCP Chrome 真实验收：1920x900 宽屏 VocabularySideBox + 900x900 半屏 VocabularyBox 均完整走通 V5，Network 仅 127.0.0.1 本地请求，无外部 AI 调用，Console 仅预期 WebSocket 降级。9 个测试套件全绿（363 tests / 0 failures），`npm run development` 编译成功。**不新增产品能力；不碰后端业务逻辑；不改 TextBlockGroup.vue；不处理 VocabularyBottomSheet.vue（当前产品范围外）；不实现手机端 V5；不写 ReviewLog；不改 FSRS；不新增 migration；不自动调 AI；不创建 legacy word card；不删除 WordSense/ReviewCard/ReviewLog/legacy 兼容层。** |
| GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2 | 桌面端 AIStudyCard V1-V5 工作流收敛为独立 feature island。在上一轮（DesktopArchitectureConvergence）只把 V5 生成学习卡的 Dialog/Result/Service 收敛的基础上，本轮把 V1（pending item 标记）→ V2（待解释列表/取消/恢复）→ V3（preview-package 安全包）→ V4（粘贴 AI 推荐词 JSON / parse / dedupe / final-candidates-package）→ V5（generate-cards 确认生成学习卡）的完整桌面端流程收敛到一个独立 feature island 组件 `AiStudyCardDesktopWorkflow.vue`（约 1019 行，包含 V1-V5 全部模板/data/methods，通过 `computed: mapState('vocabularyBox', {...})` 从 store 获取上下文）。新增 `resources/js/services/AiStudyCardRecommendationParserService.js`（225 行，3 个纯函数 `buildUserSelectedKeys` / `parseAiRecommendations` / `rededupeRecommendations`，不 import Vue/Vuex/DOM，解析失败安全返回 `{ ok: false, error, recommendations: [] }`）+ `resources/js/services/AiStudyCardPendingWorkflowService.js`（195 行，6 个函数 `createPendingItem` / `listPendingItems` / `dismissPendingItem` / `restorePendingItem` / `buildPreviewPackage` / `buildFinalCandidatesPackage` + re-export 3 个 from `AiStudyCardGenerateCardsService.js` 保持 V5 hardening 边界）。两个父组件 `VocabularySideBox.vue` 与 `VocabularyBox.vue` 只负责挂载该组件，不再各自维护完整 AIStudyCard 流程。期间修复 `VocabularySideBox.vue` mojibake 语法错误（上轮 PowerShell 脚本按行号删除代码后遗留的中文注释/字符串 `?/span>`、`?/v-btn>`、`?/v-chip>` 破损标签 + `setAiLookupError(...)` 缺失闭合引号导致 `npm run development` 失败）。新增 `tests/Feature/AiStudyCardDesktopWorkflowArchitectureGuardTest.php`（22 项 / 293 assertions）：锁定 feature island 文件存在性、两父组件挂载该组件、不再包含重复 V1-V5 模板/data 字段（指纹检测：`待 AI 解释`、`<v-dialog v-model="aiPendingListDialog"`、`<v-dialog v-model="aiStudyCardPreviewDialog"`、`aiPendingItems`、`aiPreviewPackage`、`aiFinalCandidatesPackage`、`aiSelectedRecommendationIndices`）、`AiStudyCardRecommendationParserService` 暴露 3 个纯函数、`AiStudyCardPendingWorkflowService` 暴露 6 个函数 + re-export 3 个、解析失败安全失败、AI 推荐词默认 unchecked、AI reason 不自动填 sense_zh、sense_zh 初始化为空、sense_en 允许为空、`VocabularyBottomSheet.vue` 不含 V1-V5 流程、无外部 AI provider 字符串、无 ReviewLog/FSRS/legacy word card 创建调用。MCP Chrome 真实双 viewport 完整 V1-V5 回归验收全通过：A. 1920x900 宽屏 VocabularySideBox（POST /pending-items → list/dismiss/restore → preview-package → 粘贴 AI 推荐词 JSON（agency/observation）→ final-candidates-package → generate-cards → 「已生成 2 张学习卡」→ WordSense #78/79 + ReviewCard #80/81 (target_type=sense) → Network 30 个请求全部 127.0.0.1:8000）；B. 900x900 半屏 VocabularyBox（导航栏折叠为「更多」菜单 → 完整 V1-V5 流程，使用不同 AI 推荐词（progress）→ generate-cards 200 → WordSense #80/81 + ReviewCard #82/83 (target_type=sense) → Network 19 个请求全部本地）。关键安全契约两个 viewport 均确认：AI 推荐词默认 unchecked / AI reason 不自动填 sense_zh / sense_zh 初始化为空 / sense_en 允许为空 / 无外部 AI provider 调用 / 无 ReviewLog 写入 / 无 legacy word card 创建 / 所有 ReviewCard target_type=sense。10 个测试套件全绿（385 tests / 0 failures）：AiStudyCardDesktopWorkflowArchitectureGuardTest 22 (293) / AiStudyCardV5DesktopArchitectureGuardTest 17 (146) / VocabularyBoxV5UiGuardTest 16 (131) / AiStudyCardPendingItemTest 86 (484) / WordSenseTest 134 (555) / ReviewFsrsTest 63 (374) / SenseReview 19 (98) / SenseTokenPayloadTest 16 (45) / TestingDatabaseHealthConfigTest 6 (50) / TestingDatabaseHealthTest 6 (47)。`npm run development` 编译成功。**不新增产品能力；不碰后端业务逻辑；不碰手机端/BottomSheet；不改 TextBlockGroup.vue；不实现手机端/BottomSheet V1-V5；不自动调 AI；不接 AI provider；不读写 .env；不新增 API key；不写 ReviewLog；不改 FSRS；不重排已有卡；不创建 legacy word card；不删除 WordSense/ReviewCard/ReviewLog/legacy 兼容层；不新增 migration；不清库；不 DCP；不 notification script；不处理 .omo/；不提交敏感文件；不把 API 200 当页面验收。** |

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
| FSRS-Anki-Mgmt-8 | 今日临时上限 / 暂停新卡；区分永久设置与 today-only 临时覆盖 | 📋 计划中 |
| FSRS-Anki-Mgmt-9 | 学习参数 Preset：按语言 / 材料组共享 FSRS、每日上限和队列选项；禁止直接照搬 Anki deck 层级 | 📋 计划中 |
| FSRS-Param-Browser-Smoke | FSRS 参数优化真实浏览器验收 | 📋 待验收 |
| Anki-Review-IntervalPreview-1 | 四个评分按钮显示选择后的预计下次复习时间；预览必须复用真实调度服务且只读 | ✅ 已完成 (commit a009d2a + b927ea5, ADR-0008) |
| Anki-Review-Undo-1 | 撤销上一次真实评分：恢复该次评分前的 FSRS 卡片状态并撤销对应 ReviewLog；必须快照化、事务化、仅允许最后一次操作 | ✅ 已完成 (commit 71d1670 + 977f5cb + 4e27751, ADR-0009, 26/26 Playwright验收通过) |
| Anki-Card-State-1 | 区分"明天再看（bury）""暂停复习（suspend）""归档""重置""删除"，避免所有跳过语义都落到 fsrs_enabled | ✅ 已完成 (commit be30614 + Task A, ADR-0010, 4 lifecycle states + 6 actions + single additive migration + fsrs_enabled mirror + optimistic locking + idempotency + audit events + bulk operations + 133 backend tests + 100 frontend guard tests) |
| Anki-Leech-1 | 遗忘卡识别：按 lapses 阈值标记，默认提示用户改写释义卡；是否自动暂停由用户设置决定 | ✅ 已完成 (commit 9a03740 + Task A + 2000-4 fix, ADR-0011, stable/struggling/leech classification from ReviewLog + pure policy + batch query no N+1 + rewrite package provider_called=false + 4 endpoints + 56+17 backend tests + 94 frontend guard tests + leech NOT lifecycle state + no migration; 2000-4 fix: source=sense_review positive filter + describeWithFeedback pure functions eliminating N+1 + real Policy classification for management filters + 5 query-budget tests + 12 filter-consistency tests + MCP Chrome 36 PASS acceptance incl. batch 2+ rewrite packages) |
| Anki-Queue-Order-1 | 新卡 / 学习中 / 到期卡的队列顺序与积压优先级形成明确、可测试的策略 | ✅ Accepted / 生产验收通过（Task 2000-14，网页端最终验收，2026-07-14） (Task 2000-9A docs + Task 2000-9B docs+feat + Task 2000-10A production closure + Task 2000-13 + Task 2000-14 lock fix, ADR-0015 corrected, 四设置四分类三层架构: `ReviewQueueOrderOptions` 值对象 + `ReviewQueueOrderPolicy` 纯函数 + `ReviewQueueOrderService` 单一排序入口; 4 Anki-aligned settings `interday_learning_review_order`/`new_review_order`/`review_sort_order`/`new_sort_order` 全局 user_id=-1; 4 categories intraday/interday/review/new; Mix 确定性均匀交错非随机; stable daily hash `md5(userId\|language\|localDate\|cardId)` 替代 shuffle; FSRS-5 retrievability `R=(1+FACTOR*elapsed/stability)^DECAY`; 两入口 `/reviews`+`/reviews/senses` 统一; `ReviewService::shuffle()` 删除; `Review.vue` `Math.random()` 删除; `/reviews/rate`+`/reviews/senses/{id}/rate` 返回 `next_card`; 三阶段 daily limits; `/settings/fsrs/queue-order` GET/POST admin middleware; `QueueOrderValidationException` 结构化 422; `AdminReviewSettings.vue` 4 下拉框; 59 Unit + 30 Feature + 22 Node guard 测试全绿; 回归 669 passed; npm build 成功; db:doctor healthy. **Task 2000-10A production closure**: 新增 `ReviewStudyTimezoneService` 统一学习时区边界替代分散的 `config('app.timezone')` 读取; `due_random` 本地日期修复 (`fsrs_due_at` 显式转学习时区再取 Y-m-d, 同一本地日期卡视为同天, 跨本地午夜视为不同天, 新增 America/Los_Angeles + UTC + 跨午夜 + DST 测试); retrievability 证据链修复 (固定 fixture 交叉验证 + NaN/Infinity 保护 + null/0/负数 stability fallback); `Review.vue` 真正消费 `/reviews/rate` 返回的 `next_card` (findIndex+unshift 移动/插入队首, 传 `ignoreDailyLimits`, 更新 `dailyLimitSummary`); 评分请求竞态保护 (`ratingLoading` + `ratingRequestSequence` stale-response guard, 慢响应不覆盖新状态, 快速双击不写双 ReviewLog); `SenseReview.vue` `loadCardsRequestSequence` stale guard + `rate()` 双击保护; `reviewedTodayCount()` 改用统一学习时区; 新增 `ReviewQueueOrderNextCardTest` (9 backend) + `ReviewQueueOrderNextCardGuard.test.mjs` (12 frontend) + `ReviewStudyTimezoneServiceTest` (11 unit) + 扩展 `ReviewQueueOrderServiceTest` (+10 due_random/retrievability 测试) + 扩展 `ReviewQueueOrderFrontendGuard` (8→21) + 扩展 `SenseReviewStackUndoGuard` (35→48). **Task 2000-13**: MCP Chrome 正常评分验收通过 (Legacy + Sense, 2 viewport); 失败分支用可执行 ReviewRatingRecovery.test.mjs 作为行为证据; 新增纯 JS recovery helper ReviewRatingRecovery.js 被 Legacy+Sense Review 真实调用; loadReviews() 返回 Promise; Custom Study 两处旧契约修正. **Task 2000-13 Accept 被撤回 (历史)**: 网页端复核发现 Legacy reload 锁状态缺口. **Task 2000-14 lock fix (最终关闭)**: helper 在 reloadQueue 返回后重新确认锁定 (re-lock after sync reset); 并发调用返回同一 `inFlightPromise` (非 `Promise.resolve()`); reloadQueue sync throw 经 try/catch 安全释放 inFlight + 解锁; Legacy 四个评分按钮绑定 `:disabled="ratingLoading"`; 可执行测试 20 cases/34 assertions; guard test 24 tests. **网页端总流程设计师 2026-07-14 正式 Accept，Queue Order 生产关闭。** 不改 FSRS/ReviewLog/lifecycle/rating/AI/migration) |
| Anki-CustomStudy-1 | 自定义学习会话：今日忘记、逾期、指定来源章节、已标记、遗忘卡；默认不改变正常队列，重排模式需单独确认 | 📋 P2 计划中 |
| Anki-Stats-1 | 统计补齐：未来到期、复习耗时、间隔分布、稳定度、难度、可检索率、评分分布、真实保持率 | 📋 P2 计划中 |
| Anki-Browser-Search-1 | 高级浏览器搜索语法：is:leech、is:suspended、rated:again、prop:lapses | ✅ 已完成 (commit `0f6ef29` docs + `858a3f2` feat, ADR-0012, 纯函数 parser `ReviewCardBrowserSearchParser` + 只读 `ReviewCardBrowserSearchCriteria` + `ReviewCardBrowserSearchQueryApplier` 仅作用于已隔离 query + `InvalidBrowserSearchException` 结构化 422 + `search_meta` 响应字段; V1: is:leech/is:struggling/is:active/is:buried/is:suspended/is:archived (governance/lifecycle 分离, 同类冲突 422, 跨类合法) + rated:again/rated:hard (whereExists 强制 source=sense_review + undone_at IS NULL) + prop:lapses{=,>,>=,<,<=}<非负整数> (直接 WHERE fsrs_lapses); AND 组合, 不支持 OR/NOT/括号/日期/保存搜索/更多 prop/rated:good/rated:easy/新 migration; 普通文本继续 LIKE lemma/surface_form/sense_zh/sense_en/example_sentence_en; 页面 data + JSON/CSV/Anki TSV 导出共用同一 parser + QueryService; governance 分类每请求最多一次复用缓存 ID 无 N+1; 前端 chips + 帮助 + 具体错误 + 高级 token 自动切全部 + 不复制完整 parser; 41 parser 单元 + 28 feature + 31 前端 guard 测试全绿 + 回归套件全绿; MCP Chrome 28/28 PASS; 不改 FSRS/lifecycle/ReviewLog/ADR-0010/ADR-0011。**Task 2000-6 fix**: `exportAnkiTsv()`/`exportCsv()` return type 改为 `\Symfony\Component\HttpFoundation\Response` 公共父类型, 修复非法语法和导出超限时 JsonResponse 返回导致 PHP TypeError; 6 新增测试 CSV/TSV 422 + 合法导出 200 + 不写 ReviewLog + 不改 FSRS/lifecycle; MCP Chrome 13/13 PASS。**Task 2000-7 refactor (ADR-0013)**: 执行管道收敛 — 每请求只 parse 一次, Controller 调 `parseCriteria()` 后将 Criteria 传入新增 `buildFromCriteria()` 单一执行入口, 旧 `build()` 降级为薄包装; 四入口 (data / export JSON / exportAnkiTsv / exportCsv) 共用同一 Criteria + 同一执行入口; Parser 按 first-occurrence 顺序对 `normalizedTokens` / `ratings` / `propertyConditions` 去重, 同类冲突仍 422; governance 仍由 `resolveGovernanceMatchingIds()` 单次批量 O(1), 不复制 Leech Policy, 不逐卡查 ReviewLog; 不改 V1 结果, 不新增语法; 6 parser 去重 + 16 ADR-0013 pipeline feature 测试 (4 入口 Mockery spy 各 parse 一次 / 合法结果回归 / 422 一致结构 / 重复 token 单 chip / 冲突 token 仍 422 / user 隔离 / language 隔离 / sense-only / rated 排除 reset+undone+非 sense_review / governance O(1) / 不写 ReviewLog / 不改 FSRS / 不改 lifecycle); 回归 649 passed / 0 failed; npm build 成功; db:doctor HEALTHY; MCP Chrome 18/18 PASS) |

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

### 7.1 LinguaCafe 与 Anki 的对象映射（冻结）

| LinguaCafe | Anki 参考概念 | 产品边界 |
|------------|---------------|----------|
| WordSense | Note / knowledge item | 保存一个具体词义及解释，是内容对象，不直接承担调度 |
| Sense ReviewCard | Card | 一个词义对应的可调度复习对象；日常主线只使用 target_type=sense |
| WordSenseOccurrence / 来源例句 | Evidence / context | 为词义提供阅读来源与题面证据，不独立调度 |
| ReviewLog | revlog | 每次真实评分的不可伪造历史来源；报告、浏览、预览不得写入 |
| EncounteredWord | 阅读熟悉度 / 页面状态 | 负责正文颜色和阅读总览，不再承担独立 SRS 等级 |
| 语言 / 书籍 / 章节 / 未来标签 | Deck / collection 的参考维度 | 用于筛选和学习集合；当前不引入通用 deck 树和任意卡片模板 |

### 7.2 必须借鉴的 Anki 产品原则

1. 内容对象和调度卡片分离。
2. 问题面先出现，显示答案后再评分。
3. Again / Hard / Good / Easy 的含义、快捷键和预计间隔保持可解释。
4. Browser / 管理页能够从报告、复习页和搜索结果精确定位同一张卡，并查看完整历史。
5. Bury、Suspend、Reset、Delete 必须是不同语义，不能用一个 enabled 布尔值概括所有行为。
6. 常用筛选可保存；批量操作必须作用于明确选择，不得偷偷扩大到全部筛选结果。
7. 遗忘卡需要可识别、可修订、可暂停，不能只让用户无限重复失败。
8. 统计应服务于决策：未来负担、保持率、耗时、间隔、稳定度、难度、可检索率和评分使用情况。
9. 调度、查询、统计、页面编排保持清晰接口；前端不能复制 FSRS 计算。

### 7.3 明确不照搬的 Anki 能力

1. 当前不开发通用 Note Type / Card Template 编辑器。
2. 当前不让一个 WordSense 自动生成正反两张 sibling cards；sense-only 单卡仍是主线。
3. 当前不照搬任意层级 deck 树。未来如需要分组，优先使用语言、书籍、章节、标签形成“学习集合”。
4. 阅读正文颜色、原文定位、多例句和 AI 示意卡仍是 LinguaCafe 的独有主线，不能为了像 Anki 而削弱。
5. 近 7 天趋势固定为“今天 + 前 6 个自然日”的滚动窗口，不增加自然周切换。

### 7.4 2026-07-10 Anki 对齐审计结论

**已经对齐：** WordSense / ReviewCard 分层、New / Learning / Review / Relearning 状态、显示答案后四档评分、1-4 快捷键、ReviewLog、FSRS desired retention、参数优化、每日上限、管理页、复习历史和基础统计。

**当前 P1 差距：** 报告记录无法精确跳转卡片；评分按钮不显示预计间隔；没有撤销最后一次评分；bury / suspend / archive 语义未分离；旧 stage 1-7 控件仍存在于部分词汇页面。

**当前 P2 差距：** 没有保存筛选、遗忘卡治理、自定义学习会话、选项 Preset、未来到期 / 耗时 / 可检索率 / 真实保持率统计；ReviewCardManage 与 AdminReviewSettings 仍是大组件，后续应按查询、状态操作、详情、导出和设置领域继续拆分。

**下一轮冻结决定：** 今日学习日报的重点词义、进步记录和最近复习记录均可点击，并精确打开对应的 sense ReviewCard 管理详情；近 7 天趋势继续使用滚动 7 天。

### 7.5 Anki 参考优先规则（Task 2000-15 冻结）

> 详细规则正文见 `docs/plans/vibe-coding-collaboration-rules.md` §8.7。本节为引用与定位，不重复全文。

1. **覆盖范围**：所有与 Anki 相关的功能（评分、队列、自定义学习、卡片正反面、字段显示、空字段、快捷键、撤销、学习步骤、复习顺序），在产品判断前必须先核查 Anki 官方手册或官方源码。
2. **决策顺序固定**：(1) Anki 官方设计 → (2) 保留 Anki 核心语义的网页适配 → (3) Anki 没有对应设计时才进行 LinguaCafe 独立产品判断。不允许跳级。
3. **不得把"普通网页产品习惯"直接替代 Anki 已存在的设计**。例如：不得用"一次性展开所有内容"替代 Anki 先 Question 后 Show Answer；不得用"无内容时显示'无'"替代 Anki conditional replacement 的字段非空才渲染。
4. **Anki 没有统一默认视觉样式时**，对应 ADR 或 implementation plan 必须明确写："Anki 没有冻结该视觉样式；以下为 LinguaCafe 项目适配。"
5. **本次卡面契约（Task 2000-15 冻结）的 Anki 依据**：
   - Anki 卡面支持 HTML/CSS 自定义（Anki Manual — Styling & HTML）；
   - Anki conditional replacement 支持字段非空时才渲染标签和内容（Anki Manual — Card Generation / Conditional Replacement）；
   - Anki 正常流程为先显示问题，Show Answer 后显示答案（Anki Manual — Studying / Questions）。
6. **官方来源名称**：Anki Manual — Card Generation / Conditional Replacement；Anki Manual — Styling & HTML；Anki Manual — Studying / Questions。
7. **不得把论坛意见或第三方模板写成 Anki 官方默认设计。** 社区模板 / 论坛讨论 / 博客经验帖只能作为社区经验参考。
8. **与 §7 / §7.1-7.4 的关系**：§7 规定必须先查 Anki 以及"不盲目照搬"；§7.1-7.4 规定对象映射、必须借鉴原则、不照搬能力、对齐审计结论；§7.5（本节）规定决策顺序固定、不得用普通网页产品习惯替代 Anki、以及 Anki 没有冻结视觉样式时必须声明的项目适配条款。

---

## 8. 建议下一步顺序

以下为网页端 GPT 或项目负责人参考的建议顺序，不绑定开发节奏：

| 建议顺序 | 编号 | 内容 | 理由 |
|----------|------|------|------|
| 1 | Anki-Browser-DeepLink-1 | 今日学习日报三类记录精确跳转对应 sense ReviewCard 管理详情 | 用户已明确决定；补齐“统计 → Browser → Card Info”最短闭环，且不改变 FSRS |
| 2 | Anki-Review-IntervalPreview-1 | 四个评分按钮显示预计下次复习时间 | Anki 复习界面的核心可解释性；必须复用真实调度服务，只读预览 |
| 3 | Anki-Review-Undo-1 | 撤销最后一次真实评分 | 降低误触成本，但涉及 ReviewLog 与 FSRS 恢复，必须独立 ADR 和事务快照 |
| 4 | ReviewCardManage-MutationBoundary-Scout-1 | 继续侦察 reset / destroy / bulk 的危险写操作边界 | 为 bury / suspend / archive / reset / delete 语义分离做准备 |
| 5 | Anki-Card-State-1 | 区分明天再看、暂停、归档、重置、删除 | 当前 fsrs_enabled 不能表达临时跳过和永久暂停的差别 |
| 6 | Reader-UI-1-a | 查词侧栏第一轮瘦身与旧 stage 1-7 用户入口清理 | EncounteredWord stage 只保留阅读颜色职责，避免双 SRS 心智模型 |
| 7 | Anki-Leech-1 | 遗忘卡识别与修订提示 | 避免高 lapses 卡无限消耗复习时间 |
| 8 | Anki-Browser-SavedSearch-1 | 管理页保存筛选、状态侧栏和可复用学习集合 | 为自定义学习和批量治理提供基础 |
| 9 | Anki-CustomStudy-1 | 今日忘记 / 逾期 / 指定章节 / 已标记 / 遗忘卡自定义学习 | 补齐正常队列之外的受控学习能力 |
| 10 | Anki-Stats-1 | 未来到期、耗时、可检索率、真实保持率等统计 | 让用户基于长期数据调节负担，而非只看单日正确率 |
| 11 | FSRS-Anki-Mgmt-9 | 学习参数 Preset 与分组策略 | 当前先保持全局设置；在筛选/学习集合稳定后再引入共享预设 |
| 12 | AI-Reading-Assist-4 | AI 译文按句显示/隐藏 | LinguaCafe 阅读主线能力，不因 Anki 对齐而取消 |
| 13 | Lemma-Origin-1 | 原型识别回归修复 | 影响字典查询和释义准确性 |
| 14 | Mgmt-7-c | 自动提升词汇等级改为 FSRS | 清理旧 SRS 遗留逻辑 |

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

## Recent Update: GLM-RealMorphologyImportClickCompletion-1

- Real tokenizer/importer coverage for the morphology matrix is complete. `MorphologyMatrixImportRegressionTest` now drives the real import pipeline via `ChapterService::processChapterText` invoking the real Python spaCy tokenizer, instead of pure fixture assertions on `processed_text`.
- 8/8 morphology categories are covered by real Playwright page clicks (18 clicks total): regular plurals, irregular plurals, third-person singular, past tense, past participle, progressive forms, comparative/superlative forms, and adjectival ambiguity.
- 4 adjectival-ambiguity words (`published` / `used` / `broken` / `left`) are covered by real Playwright page clicks, confirming they only display tokenizer/lemma results without irreversible auto-binding.
- Test naming governance complete: the data-layer fixture test was renamed to `MorphologyMatrixLemmaBridgeDataLayerTest` to reflect its data-layer fixture boundary.
- Page-click verification used real Playwright browser clicks only. No API / axios / fetch was used to simulate page clicks.
- Sub-phase progress uplift is approximately 705%, covering real tokenizer coverage / real page clicks / test naming governance. **This 705% is sub-stage improvement, NOT a fake uplift of the fixed five mainlines.**
- Still NOT implemented: reading inline review scoring (阅读中刷卡评分); AI judgment of rare meanings (AI 判断熟词僻义). No ReviewLog written. No FSRS changes. No AI calls. No WordSense/ReviewCard/ReviewLog generated.
- Did NOT enter the next task automatically.

## Recent Update: GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1

- Desktop AIStudyCard V5 architecture convergence complete. `VocabularySideBox.vue` and `VocabularyBox.vue` previously each maintained a duplicate copy of the V5 generate-cards template + `openGenerateCardsDialog` candidate construction + `confirmGenerateCards` request logic, violating high cohesion / low coupling and risking wide-screen / half-screen behavior drift when V5 rules change.
- Converged into shared structure:
  - New `resources/js/services/AiStudyCardGenerateCardsService.js`: 3 pure functions `buildGenerateCardItems` / `filterConfirmedGenerateCardItems` / `generateAiStudyCards` (POST `/ai-study-card/generate-cards` via injected axios; no external AI calls; no ReviewLog/FSRS/ReviewCard writes).
  - New `resources/js/components/Text/AiStudyCardGenerateCardsDialog.vue`: shared confirmation dialog (v-model + items/loading/error props + confirm event; shows candidate/lemma/source chip/AI reason reference; sense_zh required; sense_en optional; shows "N items, M filled"; does not call backend directly).
  - New `resources/js/components/Text/AiStudyCardGenerateCardsResult.vue`: shared result panel (result prop + go-to-sense-reviews/dismiss events; shows created/skipped/duplicate/failed + sense_id/review_card_id + source_binding_status + safety copy; does not call backend directly).
- Both parent components now only hold dialog open state, result/loading/error, call shared helper, pass data, and redirect to `/reviews/senses`.
- New `tests/Feature/AiStudyCardV5DesktopArchitectureGuardTest.php` (17 tests / 134 assertions): locks shared file existence, both parents reference shared components, no duplicate V5 template/logic fingerprints, AI reason not auto-filled into sense_zh, service exposes 3 pure functions, Dialog/Result only emit events, BottomSheet has no V5 flow, no external AI provider strings, no ReviewLog/FSRS/legacy word card creation calls.
- Rewrote `tests/Feature/VocabularyBoxV5UiGuardTest.php` (16 tests / 104 assertions): scan targets expanded from single VocabularyBox.vue to 4 files.
- MCP Chrome real acceptance: 1920x900 wide-screen VocabularySideBox + 900x900 half-screen VocabularyBox both complete V5 flow (pending AI explanation → list → preview-package → paste AI recommended JSON → final-candidates-package → generate cards → sense_zh required → sense_en empty → AI reason not auto-filled → result display → /reviews/senses). Network only 127.0.0.1 local requests, no external AI calls. Console only expected WebSocket downgrade (pre-existing, non-blocking).
- 9 test suites all green (363 tests / 0 failures). `npm run development` compiled successfully.
- **NOT done this round**: no new product capability; no backend business logic changes; no TextBlockGroup.vue changes; no VocabularyBottomSheet.vue processing (out of current product scope); no mobile V5; no ReviewLog writes; no FSRS changes; no new migration; no automatic AI calls; no legacy word card creation; no WordSense/ReviewCard/ReviewLog deletion; no legacy compatibility layer removal.
- Did NOT enter the next task automatically. Next step is still decided by the web-based overall process designer; does NOT auto-enter V6.

## Recent Update: GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2

- Desktop AIStudyCard V1-V5 workflow feature island convergence complete. Previous round (DesktopArchitectureConvergence) only converged the V5 generate-cards Dialog/Result/Service into shared structure; the V1 (pending item marker) → V2 (pending list / dismiss / restore) → V3 (preview-package) → V4 (paste AI recommended JSON / parse / dedupe / final-candidates-package) full desktop flow was still duplicated across both `VocabularySideBox.vue` and `VocabularyBox.vue`. Any change to V1-V4 rules required touching both components, risking wide-screen / half-screen behavior drift.
- Converged into a feature island component:
  - New `resources/js/components/Text/AiStudyCardDesktopWorkflow.vue` (~1019 lines): contains V1-V5 full template/data/methods. Uses `computed: mapState('vocabularyBox', { _chapterId/_sentenceIndex/_sentenceText/_studyBase/_baseWord })` to read context from the store instead of receiving via props.
  - New `resources/js/services/AiStudyCardRecommendationParserService.js` (225 lines): 3 pure functions `buildUserSelectedKeys(finalCandidatesPackage)` / `parseAiRecommendations(rawJson, userSelectedKeys)` / `rededupeRecommendations(parsed)`. Does NOT import Vue/Vuex/DOM. Parse failure returns `{ ok: false, error, recommendations: [] }`.
  - New `resources/js/services/AiStudyCardPendingWorkflowService.js` (195 lines): 6 functions `createPendingItem` / `listPendingItems` / `dismissPendingItem` / `restorePendingItem` / `buildPreviewPackage` / `buildFinalCandidatesPackage`; re-exports 3 from `AiStudyCardGenerateCardsService.js` (`generateAiStudyCards` / `buildGenerateCardItems` / `filterConfirmedGenerateCardItems`) to keep V5 hardening boundary intact.
- Both parent components `VocabularySideBox.vue` and `VocabularyBox.vue` now only mount `AiStudyCardDesktopWorkflow` and no longer maintain the full AIStudyCard flow themselves.
- Also fixed `VocabularySideBox.vue` mojibake syntax errors (leftover `?/span>`, `?/v-btn>`, `?/v-chip>` broken tags + missing closing quote in `setAiLookupError(...)` from previous round's PowerShell line-number-based deletion, which broke `npm run development`).
- New `tests/Feature/AiStudyCardDesktopWorkflowArchitectureGuardTest.php` (22 tests / 293 assertions): locks feature island file existence, both parents mount the component, no duplicate V1-V5 template/data field fingerprints (`待 AI 解释`, `<v-dialog v-model="aiPendingListDialog"`, `<v-dialog v-model="aiStudyCardPreviewDialog"`, `aiPendingItems`, `aiPreviewPackage`, `aiFinalCandidatesPackage`, `aiSelectedRecommendationIndices`), parser service exposes 3 pure functions, workflow service exposes 6 functions + re-exports 3, parse failure safe-fails, AI recommendations default unchecked, AI reason not auto-filled into sense_zh, sense_zh initialized empty, sense_en nullable, `VocabularyBottomSheet.vue` has no V1-V5 flow, no external AI provider strings, no ReviewLog/FSRS/legacy word card creation calls.
- MCP Chrome real acceptance: 1920x900 wide-screen VocabularySideBox + 900x900 half-screen VocabularyBox both complete the full V1-V5 flow (pending item → list/dismiss/restore → preview-package → paste AI recommended JSON → final-candidates-package → generate-cards → sense_zh required → sense_en empty → AI reason not auto-filled → result display → /reviews/senses). Network only 127.0.0.1 local requests, no external AI calls. Console only expected WebSocket downgrade (pre-existing, non-blocking). This round's dual-viewport acceptance created 4 new WordSense + 4 new ReviewCard (all `target_type=sense`) + 0 ReviewLog + 0 legacy word card; existing cards' FSRS was not rescheduled.
- 10 test suites all green (385 tests / 0 failures): AiStudyCardDesktopWorkflowArchitectureGuardTest 22 (293) / AiStudyCardV5DesktopArchitectureGuardTest 17 (146) / VocabularyBoxV5UiGuardTest 16 (131) / AiStudyCardPendingItemTest 86 (484) / WordSenseTest 134 (555) / ReviewFsrsTest 63 (374) / SenseReview 19 (98) / SenseTokenPayloadTest 16 (45) / TestingDatabaseHealthConfigTest 6 (50) / TestingDatabaseHealthTest 6 (47). `npm run development` compiled successfully.
- **NOT done this round**: no new product capability; no backend business logic changes; no TextBlockGroup.vue changes; no VocabularyBottomSheet.vue processing (out of current product scope); no mobile V1-V5; no ReviewLog writes; no FSRS changes; no new migration; no automatic AI calls; no legacy word card creation; no WordSense/ReviewCard/ReviewLog deletion; no legacy compatibility layer removal.
- Did NOT enter the next task automatically. Next step is still decided by the web-based overall process designer; does NOT auto-enter V6.

## Recent Update: GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4

- **Round type**: GM5.2 1000% closed-loop round 4 (deep-module split). 本轮同时完成检测 + 编码，不是只检测报告。基于 GitHub master `03eec56` 继续。
- **本轮目标**：把上轮 feature island 收敛后留下的 `AiStudyCardDesktopWorkflow.vue`（约 1020 行 / 10 类职责）拆成更小、更深、更内聚的桌面端 AIStudyCard workflow 子模块，形成"深模块"结构（container + presentational + service），同时保持所有现有功能不变、SideBox / Box 仍只挂载一个总入口。
- **架构检测事实**（拆分前）：总行数约 1020 / template 约 480 / script 约 540 / data 25 / methods 22 / 3 类 service 调用 / 1 处 clipboard DOM fallback / 10 类职责。
- **拆分实现**：
  - 新增 `resources/js/services/AiStudyCardClipboardService.js`（70 行，封装 navigator.clipboard + textarea fallback，不访问 Vue/Vuex/后端）；
  - 新增 `resources/js/components/Text/AiStudyCardPendingListDialog.vue`（183 行，pending/dismissed 列表，props in / events out）；
  - 新增 `resources/js/components/Text/AiStudyCardRecommendationPanel.vue`（132 行，AI 推荐词粘贴/解析/列表，默认不选）；
  - 新增 `resources/js/components/Text/AiStudyCardPackagePanel.vue`（67 行，preview/final package JSON 展示与复制按钮）；
  - 新增 `resources/js/components/Text/AiStudyCardPreviewDialog.vue`（253 行，V3-V5 预览弹窗，组合上述三者 + GenerateCardsResult）；
  - 重构 `resources/js/components/Text/AiStudyCardDesktopWorkflow.vue` 从 1020 行降至 448 行（满足 ≤450 限制），保留 25 个 data / 22 个 methods / 所有 service 调用 / Vuex 接入 / 跨子组件事件协调；
  - 修改 `tests/Feature/AiStudyCardV5DesktopArchitectureGuardTest.php` 与 `tests/Feature/VocabularyBoxV5UiGuardTest.php` 适配新架构（workflow 直接渲染 `AiStudyCardGenerateCardsDialog` + `AiStudyCardPreviewDialog`，`AiStudyCardGenerateCardsResult` 由 `AiStudyCardPreviewDialog` 负责）；
  - 新增 `tests/Feature/AiStudyCardDesktopWorkflowDeepModuleGuardTest.php`（27 项 / 221 assertions）覆盖文件存在性、container 引用子组件、container 不含 pending list / recommendation panel / package panel 模板指纹、container 不含 clipboard DOM fallback、clipboard service 含 fallback、4 个子组件不调用 axios、子组件不 import Vuex / mapState、parser 默认不选、generate 初始化 sense_zh 为空、dialog 中文释义必填英文解释可选、SideBox / Box 仍挂载 workflow、中文文案完整性测试存在、无外部 AI provider 字符串、无 ReviewLog / FSRS rating / legacy word card 创建调用、VocabularyBottomSheet 不含 V5 workflow、行数 guard ≤ 450。
- **测试结果**：12 个测试套件全绿（417 tests / 2159 assertions）；`npm run development` Compiled Successfully in 5209ms。
- **MCP Chrome 真实页面验收**（GLM 自己执行，未用 API 200 代替）：
  - 宽屏 1920x900：`/chapters/read/6` → 点击 construed → VocabularySideBox 显示 → 中文文案正常无 mojibake → 完整 V1-V5（pending → list → preview package → AI 推荐词默认不选 → 勾选 agency → final candidates → V5 对话框 → 中文释义必填 → 生成 → 结果展示 → /reviews/senses 跳转）。Network 全部本地，无外部 AI 请求；Console 无阻塞错误。新增 WordSense 1 + ReviewCard #84 target_type=sense，无 ReviewLog / legacy word card。
  - 半屏 900x900：完整 V1-V5（construed="半屏解释" + agency="代理机构"）→ "已生成 2 张学习卡"。Network 全部本地，无外部 AI 请求；Console 无阻塞错误。新增 WordSense 2 + ReviewCard #85/#86 均 target_type=sense，无 ReviewLog / legacy word card。
  - 未做 mobile viewport 主流程验收（当前产品无手机端）。
- **安全边界确认**：未读写 `.env`；未清库；未 `migrate:fresh` / `db:wipe`；未 DCP；未运行 notification script；未自动调用 AI；未接入 DeepSeek/OpenAI/任意 AI provider；未新增 API key；未写 ReviewLog；未改 FSRS；未重排已有 ReviewCard；未创建 legacy word ReviewCard；未删除 WordSense/ReviewCard/ReviewLog；未删除 legacy 兼容层；未修改 TextBlockGroup.vue / VocabularyBottomSheet.vue；未实现手机端 / BottomSheet V5；未进入 V6；未回滚 feature island；未重新制造 SideBox / Box 复制；未再次制造中文 mojibake。
- **commit / push**：`refactor: split AI study card desktop workflow modules`（无 `--force`，未提交 untracked 临时文件）。
- **下一步仍由网页端总流程设计师决定**，不自动进入 V6。

## Recent Update: GM52-AIStudyCardPendingLifecycleClosure-1000-5

- **Round type**: GM5.2 1000% closed-loop round 5 (pending item lifecycle closure). 本轮同时完成检测 + 编码，不是只检测报告。基于 GitHub master `282f808` 继续。
- **本轮目标**：收口 AIStudyCard pending item 生命周期。用户完成「生成学习卡」后，原 pending item 应标记为 `processed`，不再出现在 pending 列表中；同时保持所有现有 V1-V5 行为、deep-module split 架构、安全契约不变。
- **5 条生命周期规则冻结**：
  1. `user_selected + created` → pending item 标记为 `processed`
  2. `user_selected + duplicate` → pending item 标记为 `processed`
  3. `user_selected + skipped/failed` → 保持 `pending`
  4. `ai_recommended` → 不修改任何 pending item
  5. `dismissed` item 不应被 processed
- **后端修改**：
  - `app/Models/AiStudyCardPendingItem.php` 新增常量 `STATUS_PROCESSED = 'processed'`；
  - `app/Services/AiStudyCardPendingItemService.php` `listPending` 支持 `processed` 过滤；新增私有方法 `markPendingItemProcessed(int $pendingItemId): bool`（安全、幂等，只 UPDATE WHERE status=pending AND user_id AND language）；新增私有方法 `emptyPendingLifecycleInfo()` 用于 ai_recommended；`generateCardsFromConfirmedCandidates` 中按 confirmed_items payload 的 `source` 字段合并 lifecycle 处理；`skippedResult` 和 `$failed` 数组都包含 `pending_item_lifecycle` 字段（含 `pending_item_id` / `source` / `previous_status` / `new_status` / `marked_processed`）；
  - `app/Http/Controllers/AiStudyCardPendingItemController.php` validator 接受 `processed`：`'status' => ['nullable', 'string', 'in:pending,dismissed,processed,all']`。
- **前端修改**：
  - `resources/js/components/Text/AiStudyCardPendingListDialog.vue` 新增第三个 tab「已处理」+ processed items 列表（只读，无操作按钮，显示「状态：已处理 \| 处理于 {{ updated_at }}」）；新增 `processedItems` prop；
  - `resources/js/components/Text/AiStudyCardDesktopWorkflow.vue` data 新增 `aiPendingProcessedItems: []`；`loadAiPendingDismissedItems()` 同时加载 processed；`confirmGenerateCards` 成功后刷新 pending list；模板绑定 `:processed-items`；行数 444 ≤ 450 限制（与上一轮持平，未恶化）。
- **新增测试** `tests/Feature/AiStudyCardPendingLifecycleTest.php`（15 项测试）：覆盖 R1-R5 全部 5 条规则 + 跨用户隔离 + 跨语言隔离 + 不写 ReviewLog + 不改 FSRS + 不创建 legacy word card + processed 可查询 + 响应字段结构 + 幂等性（其中 R3 failed 路径使用 Mockery 模拟 WordSenseService 抛出异常）。
- **修改测试** `tests/Feature/AiStudyCardPendingItemTest.php` 中 `test_generate_cards_deduplicates_duplicate_candidates`：新 lifecycle 逻辑下，第一次 generate-cards 后 pending item 变 processed，第二次该 item 已不在 `validPendingItems` 中（status=pending 过滤），所以进入 `skipped` 而非 `duplicate`。断言改为 `skipped_count=1` / `duplicate_count=0`。
- **测试结果**：13 个测试套件全绿（554 tests / 0 failures）；`npm run development` Compiled Successfully。
- **MCP Chrome 真实页面双 viewport 验收**（GLM 自己执行，未用 API 200 代替）：
  - 宽屏 1920x900：`/chapters/read/6` 点击 construed → SideBox → V1-V5 完整闭环 → POST `/ai-study-card/generate-cards` 200 → 结果显示「已生成 1 张学习卡」+「已从待解释移至已处理」chip → pending list 自动刷新「待解释 (0) / 已处理 (1)」→ processed 视图显示 construed「状态：已处理 \| 处理于 2026-07-07 00:32」→ `/reviews/senses` 正常显示 sense card。数据库：pending item #5 status=processed, ReviewLog=13（未增加）, TARGET_WORD=6（未增加）, TARGET_SENSE=34（含 #87 新增）。Network 全部 127.0.0.1:8000，Console 仅 WebSocket 降级。
  - 半屏 900x900：点击 entanglement → VocabularyBox → V1-V5 完整闭环 → POST 200 → 「已生成 1 张学习卡」+「已从待解释移至已处理」chip → pending list 自动刷新「待解释 (0) / 已处理 (2)」（construed + entanglement）。数据库：pending item #6 status=processed, ReviewLog=13（未增加）, TARGET_WORD=6（未增加）, TARGET_SENSE=35（含 #88 新增）。Network 全部本地，Console 仅 WebSocket 降级。
  - 未做 mobile viewport 主流程验收（当前产品无手机端）。
- **安全边界确认**：未读写 `.env`；未清库；未 `migrate:fresh` / `db:wipe`；未 DCP；未运行 notification script；未自动调用 AI；未接入 DeepSeek/OpenAI/任意 AI provider；未新增 API key；未写 ReviewLog（验收后总数仍 13）；未改 FSRS（未触及任何 fsrs_* 字段）；未重排已有 ReviewCard；未创建 legacy word ReviewCard（验收后 TARGET_WORD 仍 6）；未删除 WordSense/ReviewCard/ReviewLog；未删除 legacy 兼容层；未修改 TextBlockGroup.vue / VocabularyBottomSheet.vue；未实现手机端 / BottomSheet V5；未进入 V6；未回滚 deep-module split；未重新制造 SideBox / Box 复制；未再次制造中文 mojibake；未新增 migration（仅 UPDATE 现有 status 字段值）。
- **commit / push**：`feat: close AI study card pending lifecycle`（无 `--force`，未提交 untracked 临时文件）。
- **下一步仍由网页端总流程设计师决定**，不自动进入 V6。

## Recent Update: GM52-SenseMultiExampleBindingAndReviewRotation-1000-6

- **Round type**: GM5.2 1000% closed-loop round 6 (Sense 多例句绑定 + 复习例句轮换). 本轮同时完成现状检测 + 编码，不是只检测报告。基于 GitHub master `0321502` 继续。
- **本轮目标**：从"能生成并复习单张 sense card"推进到"一张词义卡可以绑定多个来源例句；复习时可以轮换展示不同例句，避免永远只看同一句"。
- **6 条产品规则冻结**：
  1. 一张词义卡可以绑定多个来源例句（同一 WordSense 可有多条 occurrence）
  2. 不要重复绑定完全相同的来源（`sentence_id` → `chapter_id+text_block_index+sentence_index` → normalized `sentence_text` 三层去重）
  3. 复习时优先显示来源例句（occurrence → ReviewCard 保存例句 → 空状态）
  4. 多例句轮换（第一次显示一条，下一次尽量不同，简单轮换即可）
  5. 记录本次显示的例句（本轮选择**不新增 migration**，用稳定 seed 轮换：`crc32(reviewCardId*31 + fsrsReps*7 + dayOfYear) % total`）
  6. 查看原文/译文要对应当前显示例句（本轮至少带出 `displayed_occurrence_id`；source context 完全跟随留作 P2）
- **现状检测事实**：
  - `word_sense_occurrences` 表已存在（无新增 migration），含 `id`, `word_sense_id`, `chapter_id`, `text_block_index`, `sentence_index`, `sentence_id`, `sentence_en`, `sentence_zh`, `status` 等字段
  - 一个 WordSense 已可拥有多条 occurrence（一对多）
  - AIStudyCard created / duplicate / 手动添加 / AI 建议添加 4 条路径检测前已写 occurrence（本轮未改动绑定逻辑）
  - `/reviews/senses` 卡片例句来源：检测前已由 `SenseReviewCardSerializerService` + `WordSenseExamplePoolService` 构建 candidates 池
  - 查看原文/译文：`SenseSourceContextService::sourceContextList` 仍按 ReviewCard 默认来源取（**P2**：未跟随 `displayed_occurrence_id`）
  - 数据库无 `last_shown_occurrence` / example rotation 字段
  - 检测前无测试覆盖多例句、无测试覆盖复习页例句轮换
- **后端修改**：
  - `app/Services/WordSenseExamplePoolService.php` **未修改**（`exampleCandidates` / `pickQuestionIndex` / `pickSupplementaryIndex` 已在 commit `4432ecd` 存在，本轮直接复用稳定 seed 轮换：`crc32($reviewCardId * 31 + $fsrsReps * 7 + $dayOfYear) % $total`）
  - `app/Services/SenseReviewCardSerializerService.php` `serialize` 新增 3 个 payload 字段：`displayed_occurrence_id`（来自 question example，null 时表示 card fallback 或空） / `occurrence_count`（候选池大小） / `example_source_status`（`occurrence` | `card_fallback` | `empty`）；同时新增 docblock 说明"轮换不持久化 last shown occurrence id"的设计决策
- **前端修改**：
  - `resources/js/components/Senses/SenseReview.vue` 答案侧新增多例句提示 chip：`v-if="currentCard.occurrence_count > 1"` 显示「本词义已有 N 条来源例句」；`x-small` + `outlined` + `color="info"`，不挤占评分按钮
- **新增测试**：
  - `tests/Feature/SenseMultiExampleBindingTest.php`（13 项 / 45 assertions）：覆盖多条 occurrence 绑定 / sentence_id 去重 / chapter+text_block+sentence_index 去重 / sentence_text 弱去重 / AIStudyCard created 绑定 / duplicate 补绑定 / ai_recommended 无来源不绑定 / 跨用户隔离 / 跨语言隔离 / 不创建 legacy word card / 不写额外 ReviewLog / 不改 FSRS / ReviewCard 唯一约束保留
  - `tests/Feature/SenseReviewExampleRotationTest.php`（10 项 / 31 assertions）：覆盖单 occurrence 显示该例句 / 3 occurrence 不永远第一条 / 评分后下一次尽量不同 / `displayed_occurrence_id` 在 payload / `occurrence_count` 在 payload / 无 occurrence fallback 到 card 例句 / 无任何例句空状态 / 轮换不写 ReviewLog / 轮换不改 FSRS / 轮换不影响每日上限
- **测试结果**：13 个测试套件全绿（410 tests / 0 failures）；`npm run development` Compiled Successfully（6403ms，app.js 7.4 MiB）。
- **MCP Chrome 真实页面双 viewport 验收**（GLM 自己执行，未用 API 200 代替）：
  - 宽屏 1920x900：登录 `1816529781@qq.com` → `/reviews/senses` 显示 22 张到期 sense card → 筛选出 3 张多例句卡（`occurrence_count=2`）：`codex_sense_smoke_20260702_b_bind_target` / `codex_sense_smoke_20260702_b_confirm_target` / `codexmatrix` → 多例句提示 chip 正确显示「本词义已有 2 条来源例句」 → 显示答案 → 答案侧完整 → 评分 `good` → 跳到下一张多例句卡，提示 chip 同样正确 → 查看原文/译文对话框打开（sourceCount=1，未跟随 occurrence_count=2 → **P2 记录**） → 轮换验证（临时 PHP 脚本对 card#61 reps 0-10 序列化，产生 2 个不同 `displayed_occurrence_id`，DB 未写入）。Network 全部 127.0.0.1:8000，Console 仅 WebSocket 降级。
  - 半屏 900x900：无横向滚动（`scrollWidth=892=clientWidth=892`）→ 多例句提示 chip 不挤、不挡评分按钮 → 答案侧布局正常 → 查看原文/译文对话框正常打开。Network 全部本地，Console 仅 WebSocket 降级。
  - 未做 mobile viewport 主流程验收（当前产品无手机端）。
- **安全边界确认**：未读写 `.env`；未清库；未 `migrate:fresh` / `db:wipe`；未 DCP；未运行 notification script；未自动调用 AI；未接入 DeepSeek/OpenAI/任意 AI provider；未新增 API key；未写 ReviewLog（轮换验证后 DB `ReviewLog` 总数未变）；未改 FSRS（轮换验证后 `fsrs_reps` / `fsrs_due_at` / `fsrs_stability` / `fsrs_difficulty` / `fsrs_state` / `fsrs_lapses` 未变）；未重排已有 ReviewCard；未创建 legacy word ReviewCard；未删除 WordSense/ReviewCard/ReviewLog；未删除 legacy 兼容层；未修改 TextBlockGroup.vue / VocabularyBottomSheet.vue；未实现手机端 / BottomSheet V5；未进入 V6；未新增 migration（仅 SELECT 现有 occurrence 数据）；未破坏现有 source context fallback（`SenseSourceContextService` 未修改，仅记录 P2）。
- **P2 已知问题**：
  1. `SenseSourceContextService::sourceContextList` 未跟随 `displayed_occurrence_id`（查看原文/译文对话框 sourceCount=1 不跟随 occurrence_count=2）。下一轮需让 source context 跟随 `displayed_occurrence_id`。
  2. More 菜单危险操作未做视觉分隔（沿用 Task 5 状态，本轮未顺手处理）。
  3. 测试数据干扰：`codex_sense_smoke_*` 等 lemma 仍存在（本轮不清理，记录为后续 P2）。
- **commit / push**：`feat: rotate sense review source examples`（无 `--force`，未提交 untracked 临时文件）。
- **下一步仍由网页端总流程设计师决定**，不自动进入 V6。

---

## Recent Update: GM52-SenseSourceContextFollowDisplayedOccurrence-1000-7

**任务**：Sense source context 跟随 displayed occurrence（关闭上一轮 P2）。

**本轮同轮完成现状检测 + 编码**，不进入计划模式。

### 现状检测结果

- `SenseReviewCardSerializerService` 已返回 `displayed_occurrence_id` / `occurrence_count` / `example_source_status`（Task 6 已实现）。
- `SenseReview.vue` 点击「查看原文」未传 `preferred_occurrence_id`。
- `SenseOccurrenceController::sourceContextList` 不读 query 参数。
- `SenseSourceContextService::sourceContextList` 不支持指定 occurrence。
- MCP Chrome 可复现：当前显示例句与查看原文第一条来源不一致。

### 产品规则冻结（6 条）

1. 当前显示例句优先：`displayed_occurrence_id = X` 时，sources[0] 必须是 occurrence X。
2. 必须安全校验 occurrence（user/language/sense/status=bound/有 chapter 或 sentence）。
3. 失败时保留原 fallback 链 + 返回 `preferred_occurrence_status`。
4. sources[0] = preferred，其余去重后追加，不重复展示同一 occurrence。
5. 不新增复杂 UI，只轻量提示（"已定位到当前复习例句" / "未定位到当前例句，已显示其他可用来源"）。
6. More 菜单「彻底删除」加视觉分隔（小改，<20 行）。

### 后端实现

- `SenseSourceContextService::sourceContextList()` 新增 `?int $preferredOccurrenceId = null` 参数 + `resolvePreferredOccurrence()` 私有方法（严格校验 user/language/sense/bound）。
- preferred 有效且有 chapter 时构建 source context 放 sources[0]，标记 `matched`。
- preferred 无效/无 chapter 时标记 `invalid` / `fallback`，走原 fallback 链（chapter → chapter_recovered → chapter_title → chapter_fuzzy → chapter_fuzzy_title → card_example → unavailable）。
- 返回 payload 新增 `preferred_occurrence_status` 字段。
- `SenseOccurrenceController::sourceContextList` 读取 `preferred_occurrence_id` query 参数。

### 前端实现

- `SenseReview.vue` `viewSource()` 新增 `preferred_occurrence_id` query 参数传递（仅当 `displayed_occurrence_id` 存在时）。
- `SenseExampleDialog.vue` 新增 `preferredHint` computed + success alert 展示提示。
- More 菜单「彻底删除」前加 `<v-divider class="my-1" />` 视觉分隔。

### 新增测试

- `tests/Feature/SenseSourceContextDisplayedOccurrenceTest.php`（14 项 / 53 assertions）：覆盖 valid preferred → sources[0] / 不重复 / 跨用户 fallback / 跨语言 fallback / 跨 sense fallback / 非 bound fallback / 无 chapter 不 500 fallback / 无参数旧行为不变 / payload 含 status / 不写 ReviewLog / 不改 FSRS / 不创建 legacy card / 不新增 occurrence / fallback 链。

### 自动测试结果

14 个套件全绿（443 tests / 0 failures）：SenseSourceContextDisplayedOccurrenceTest 14 (53) — 新增 / SenseReviewExampleRotationTest 10 / SenseMultiExampleBindingTest 13 / SenseSourceContext* 全绿 / SenseReview 19 / SenseTokenPayloadTest 16 / ReviewFsrsTest 63 / WordSenseTest 134 / AiStudyCardPendingLifecycleTest 15 / AiStudyCardPendingItemTest 86 / VocabularySideBoxChineseTextIntegrityTest 5 / TestingDatabaseHealthConfigTest 6 / TestingDatabaseHealthTest 6。

`npm run development` 编译成功。

### MCP Chrome 真实验收

- 宽屏 1920x900：sense#61 (occurrence_count=2) → 显示答案 → More 菜单 divider 确认（DOM: HR.v-divider 位于「重置」与「彻底删除」之间）→ 点击「查看原文」→ Network 确认 `GET /senses/61/source-context-list?preferred_occurrence_id=11 [200]` → source dialog 第一条来源 example 文本与当前卡片显示例句一致 → fallback 提示「未定位到当前例句，已显示其他可用来源。」（occ#11 无 chapter_id，正确 fallback）→ PHP 脚本验证 sense#68 (occ#19/20) 返回 matched → Network 全部 127.0.0.1:8000，Console 仅 WebSocket 降级。
- 半屏 900x900：无横向滚动（scrollWidth=900=clientWidth）→ dialog fitsWindow=true（852x411.7 在 900x831 内）→ divider 在 900x900 也存在 → 第一条来源仍对应当前例句 → Network 全部本地，Console 仅 WebSocket 降级。
- 未做 mobile viewport 主流程验收（当前产品无手机端）。

### 数据库事实

- 无新增 ReviewLog（endpoint 只读 occurrence + chapter）。
- 未改 FSRS（endpoint 不触碰 ReviewCard / fsrs_* 字段）。
- 无 legacy word card 创建。
- 无新增 migration（仅 SELECT 现有表）。
- 无新增 WordSenseOccurrence（endpoint 只 SELECT 现有 occurrence）。

### ~~WorkBuddy P2 处理~~（历史记录，WorkBuddy 已停用 2026-07-13）

- More 菜单危险操作已做视觉分隔（divider + 红色保留），改动 <20 行。
- 测试数据干扰只记录不清理（`codex_sense_smoke_*` 等 lemma 仍存在）。

### 安全边界确认

未读写 `.env`；未清库；未 `migrate:fresh` / `db:wipe`；未 DCP；未运行 notification script；未自动调用 AI；未接入 DeepSeek/OpenAI/任意 AI provider；未新增 API key；未写 ReviewLog；未改 FSRS；未重排已有 ReviewCard；未创建 legacy word ReviewCard；未删除 WordSense/ReviewCard/ReviewLog；未删除 legacy 兼容层；未修改 TextBlockGroup.vue / VocabularyBottomSheet.vue；未实现手机端 / BottomSheet V5；未进入 V6；未新增 migration；未破坏现有 source context fallback 链（无 preferred 参数时行为完全不变；preferred 无效时 fallback 到原链）。

### P0 / P1 / P2 / P3

- P0：无。
- P1：无。
- P2（上一轮遗留，本轮已关闭）：
  1. `SenseSourceContextService::sourceContextList` 未跟随 `displayed_occurrence_id` → **已关闭**（本轮实现 preferred_occurrence_id 支持）。
  2. More 菜单危险操作未做视觉分隔 → **已关闭**（本轮加 divider）。
- P3（残留，与上一轮持平）：
  1. `AiStudyCardDesktopWorkflow.vue` 仍 444 行接近 450 阈值。
  2. `AiStudyCardPreviewDialog.vue` prop drilling 仍是 P3 待办。
  3. 手动添加释义/AI 建议添加释义路径不写 occurrence（Task 6 记录的后续任务）。
  4. 测试数据干扰：`codex_sense_smoke_*` 等 lemma 仍存在（不清理）。

### commit / push

`fix: align sense source context with displayed example`（无 `--force`，未提交 untracked 临时文件）。

### 下一步仍由网页端总流程设计师决定

不自动进入 V6。

---

## 2026-07-07 CodeX-SourceContextWriteBoundary-1

**任务**：Source Context read / recover 写入边界收口。

**目标**：把「查看原文」相关入口的读写边界讲清楚，并用测试锁住。用户体验上，打开原文弹窗仍保持原行为；架构上，后续 Agent 不能再把 source context 简单理解成完全只读。

### 核验事实

- `sourceContext()` 的 recovery 分支会通过 `writeBackRecoveredSource()` 写回来源定位字段。
- `sourceContextList()` 的 direct chapter carousel 路径不应写回来源定位字段。
- `sourceContextList()` 在没有 chapter-based sources 时会调用 `sourceContext()` 作为单来源 fallback，因此可能触发 recovery 写回。
- 这个写回只应更新 source location，不应写复习历史、不应创建卡片、不应改 FSRS。

### 本轮改动

- 新增 `tests/Feature/SenseSourceContextWriteBoundaryTest.php`。
- 更新 `docs/plans/sense-source-context-contract.md`，补充 `sourceContextList()` 调用链、preferred 参数、direct carousel 路径、fallback recovery 路径和写入边界。
- 更新 `SenseSourceContextMultiSourceTest` 文件头注释，把“只读”改成分路径说明。

### 新增测试

- `test_source_context_list_recovery_fallback_writes_only_source_location_fields`
  - 验证 `sourceContextList()` fallback recovery 会写回 `WordSense.source_chapter_id / sentence_id`。
  - 同时验证不写 ReviewLog、不创建 ReviewCard、不新增 WordSenseOccurrence。
- `test_direct_chapter_source_context_list_does_not_change_source_location_fields`
  - 验证 direct chapter carousel 路径不会改 `WordSense.source_chapter_id / sentence_id`。

### 自动测试结果

- `php artisan test --filter=SenseSourceContextWriteBoundaryTest --stop-on-failure`
  - 2 passed，16 assertions。
- `php artisan test --filter=SenseSourceContext --stop-on-failure`
  - 58 passed，312 assertions。

### 安全边界确认

未改业务运行逻辑；未改页面；未改 API shape；未写 ReviewLog；未改 FSRS；未创建 legacy word card；未新增 migration；未清库；未读写 `.env`；未 DCP；未运行 notification script；未进入 V6；未自动调用 AI。

---

## 2026-07-07 CodeX-SenseOccurrenceControllerBoundary-1

**任务**：SenseOccurrenceController 多职责第一刀收口。

**开始前代码屎山评分**：7.8 / 10。

### 目标

把 `SenseOccurrenceController` 中最容易扩散的响应字段数组和列表清洗逻辑移出 Controller。Controller 继续负责 HTTP 边界、权限语言检查、请求校验和调用业务 service；payload shape 由独立 serializer service 持有。

### 本轮改动

- 新增 `app/Services/SenseOccurrencePayloadSerializerService.php`。
- `SenseOccurrenceController` 注入 `SenseOccurrencePayloadSerializerService`。
- Controller 底部 `serializeOccurrence()` / `serializeSense()` / `normalizeList()` 保留为薄包装，只委托 serializer，避免本轮大范围替换所有调用点。
- 新增 `tests/Feature/SenseOccurrenceControllerArchitectureGuardTest.php`，防止响应字段数组和 `array_map('trim')` 清洗逻辑重新回到 Controller 私有方法中。

### 不做范围

- 不改 route。
- 不改 API shape。
- 不改 source context。
- 不改 inline confirmation 业务。
- 不改 manual sense 创建/编辑语义。
- 不改 FSRS / ReviewLog / ReviewCard。
- 不新增 migration。

### 测试结果

- `php -l "app/Http/Controllers/SenseOccurrenceController.php"`：通过。
- `php -l "app/Services/SenseOccurrencePayloadSerializerService.php"`：通过。
- `vendor/bin/phpunit "tests/Feature/SenseOccurrenceControllerArchitectureGuardTest.php"`：1 passed，10 assertions。
- `vendor/bin/phpunit tests/Feature/WordSenseTest.php --filter occurrence_list_returns_only_current_user_language`：1 passed，4 assertions。
- `vendor/bin/phpunit tests/Feature/WordSenseTest.php --filter bind_current_sense_can_create_sense_review_card`：1 passed，5 assertions。

### 残留风险

- `SenseOccurrenceController` 仍然偏胖，尚未拆出 `examples()`、inline confirmation 管理入口、bulk action 入口。
- 当前只是 payload shape 第一刀，不是 Controller 完整治理。
- 更宽的 WordSense 全量测试命令被工具层拦截，本轮未声称全量通过。

**完成后代码屎山评分**：7.1 / 10。

---

## 2026-07-07 CodeX-SenseHttpControllerBoundaries-1

**任务**：收尾 SenseOccurrenceController 治理，并建立后续功能落位架构。

**背景**：用户明确要求，后续新增功能必须先说明其所在架构；如果架构不存在，先建立架构，再做功能，避免继续生成“屎山”。

### 已完成的 Controller 收口

- `ba75783 architecture-cleanup`：抽出 `SenseOccurrencePayloadSerializerService`。
- `dec9ff4 refactor: extract sense occurrence examples service`：抽出 examples 查询与 payload。
- `bd68620 refactor: extract inline confirmation controller`：抽出阅读中词义确认入口到 `ReadingInlineSenseConfirmationController`。
- `646b225 refactor: extract source context controller`：抽出来源上下文入口到 `SenseSourceContextController`；产品归类为“复习辅助功能”。
- `9eeb573 refactor: extract sense occurrence action controller`：抽出单条“处理待确认词义”动作到 `SenseOccurrenceActionController`。
- `02cfbc9 refactor: extract sense occurrence bulk action controller`：抽出批量“处理待确认词义”动作到 `SenseOccurrenceBulkActionController`。
- `af4c439 refactor: extract manual word sense controller`：抽出手动词义创建/编辑/归档到 `ManualWordSenseController`。

### 新架构文档

新增 `docs/architecture/sense-http-controller-boundaries.md`，作为 sense / review-adjacent HTTP 功能落位的当前架构契约。后续相关功能必须先查该文件。

文档明确：

- 用户侧 product wording：occurrence action 叫“处理待确认词义”。
- `source context / 查看原文 / 来源上下文` 归类为“复习辅助功能”，不是 reader-only 功能。
- `SenseOccurrenceController` 只保留查询/候选/预览/例句薄包装，不再承接写入型动作。
- 新功能若没有明确 Controller / Service / serializer 归属，必须先建架构，不得直接开发。
- 每次 Controller 迁移必须有 route guard、old-controller forbidden guard、新 Controller method guard、行为测试和必要的 MCP Chrome 验收。

### 同步更新文档

- `docs/DOCUMENTATION_INDEX.md`：把 `docs/architecture/sense-http-controller-boundaries.md` 放入新任务必读入口和 Module contracts 层。
- `docs/plans/current-working-handoff.md`：顶部新增当前架构硬规则。
- `docs/plans/sense-source-context-contract.md`：把 source context Controller 引用更新为 `SenseSourceContextController`，并注明复习辅助功能归类。
- `docs/plans/right-click-panel-word-sense-plan.md`：把手动释义链路更新为 `ManualWordSenseController.storeManualSense()`。
- `docs/plans/ai-study-card-architecture-scout.md`：把手动释义后端入口更新为 `ManualWordSenseController::storeManualSense`。

### 当前 Controller 边界摘要

- `SenseOccurrenceController`：查询/候选/known sense lookup/read-only inline preview/duplicates/examples。
- `ReadingInlineSenseConfirmationController`：阅读中词义确认、管理、撤销、undo。
- `ManualWordSenseController`：手动 WordSense 创建、编辑、归档。
- `SenseSourceContextController`：复习辅助来源上下文。
- `SenseOccurrenceActionController`：单条待确认词义动作。
- `SenseOccurrenceBulkActionController`：批量待确认词义动作。

### 后续功能准入规则

任何 sense / review-adjacent 新功能任务，在进入实现前必须先回答：

1. 属于哪个产品区块？
2. HTTP 入口属于哪个 Controller？
3. 业务逻辑属于哪个 Service？
4. response shape 属于哪个 serializer / payload service？
5. 是否写 ReviewLog / FSRS / WordSense / ReviewCard / AI 相关状态？
6. 哪个测试防止它回流到旧 Controller？
7. 是否需要 MCP Chrome 真实页面验收？

不能回答时，下一步不是功能实现，而是先建立架构。

---

## 2026-07-07 CodeX-AIStudyCardV6Preflight-1

**任务**：查看大计划后进入下一步：AI Study Card V6 前置架构门。

**选择理由**：`current-working-handoff.md` §5 把下一候选方向指向 V6 前置设计。V1-V5 已完成本地闭环，但 V6 涉及真实 AI provider / API key / 自动推荐 / 自动释义，是高风险边界；直接实现会制造新的耦合和安全风险。因此本轮只做 preflight，不接真实 AI。

### 新增文档

- `docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md`
  - 冻结 V6 的真实 AI 边界。
  - 明确 V6 只代表 AI 推荐候选，不代表自动建卡。
  - 明确 provider 调用必须 backend-only、显式用户动作触发、默认 fail closed。
  - 禁止 API key 出现在代码、前端、文档示例、日志、DB、response payload。
  - 禁止 provider 输出绕过用户确认直接创建 `WordSense` / `ReviewCard`。
  - 禁止写 `ReviewLog` 或改 FSRS。

- `docs/plans/ai-study-card-v6-preflight-plan.md`
  - 冻结 V6 分阶段路线：V6-1 request package preview、V6-2 provider adapter stub disabled by default、V6-3 real provider integration、V6-4 UX integration。
  - 定义候选 schema：`ai-study-card-v6-request-package-v1` 与 `ai-study-card-v6-recommendation-package-v1`。
  - 明确 V6 必须回流到现有 V4/V5 用户确认路径，不能新建第二条卡片创建路径。

### 新增 guard 测试

- `tests/Feature/AiStudyCardV6PreflightArchitectureGuardTest.php`
  - 验证 ADR 和 preflight plan 存在。
  - 验证文档明确 V6 是 pre-implementation gate，不是实现。
  - 验证当前 routes 未暴露 V6 provider route。
  - 验证当前 AI Study Card 前后端关键 surface 没有真实 provider URL / API key pattern。
  - 验证文档索引注册 ADR-0004 和 V6 preflight plan。

### 不做范围

- 不接真实 AI provider。
- 不新增 API key。
- 不修改 `.env`。
- 不新增真实 V6 route。
- 不自动推荐。
- 不自动释义。
- 不自动创建 WordSense / ReviewCard。
- 不写 ReviewLog。
- 不改 FSRS。
- 不改 V1-V5 业务逻辑。
- 不改 Vue 产品流程。
- 不新增 migration。

### V6-1 实现结果

已实现 provider-disabled request-package preview：

- 新增 `AiStudyCardV6RecommendationController::requestPackage`。
- 新增 `AiStudyCardV6RequestPackageService::buildRequestPackage`。
- 新增路由 `POST /ai-study-card/v6/recommendations/request-package`。
- 新增桌面 UI 组件 `AiStudyCardV6RequestPackagePanel.vue`，挂载到 `AiStudyCardPreviewDialog.vue`。
- 新增前端 API wrapper `buildV6RequestPackage()`。
- 返回 `schema_version=ai-study-card-v6-request-package-v1`。
- 只打包当前用户、当前语言、pending 状态的 selected items。
- 过滤跨用户、跨语言、dismissed、processed 项。
- 不返回 raw `source_payload`。
- 不调用 provider。
- 不创建 `WordSense` / `ReviewCard`。
- 不写 `ReviewLog`。
- 不改 FSRS。
- 不改变 pending item 状态。

新增 `tests/Feature/AiStudyCardV6RequestPackageTest.php`，覆盖 request package shape、隔离、状态过滤、raw payload 排除、数量上限、V5 route 不变等边界。

新增 `tests/Feature/AiStudyCardV6RequestPackageUiGuardTest.php`，锁定桌面 UI 入口、安全文案、本地 endpoint 调用、主 workflow 不新增 V6 状态/方法、无 provider/API key 材料、无 ReviewLog/FSRS/legacy word card 创建调用。

### V6-2 实现结果

已实现 provider adapter stub，disabled by default：

- 新增 `AiStudyCardV6ProviderInterface`。
- 新增 `AiStudyCardV6DisabledProviderAdapter`。
- 新增 `AiStudyCardV6ProviderDisabledException`。
- 新增 `AiStudyCardV6RecommendationSchemaService`。
- 新增 `AiStudyCardV6RecommendationService`。
- `AppServiceProvider` 默认把 `AiStudyCardV6ProviderInterface` 绑定到 disabled adapter。
- fake provider 只存在于测试匿名类中，不作为生产 provider。
- 没有新增 route。
- 没有新增 UI。
- 没有真实 API key。
- 没有真实外部请求。
- malformed provider output fail-closed。
- provider exception fail-closed。
- disabled provider 在任何 provider result 被信任前直接失败。
- 合法 fake provider output 也只返回 validated recommendation package，仍要求用户确认，不创建学习数据。

新增 `tests/Feature/AiStudyCardV6ProviderAdapterTest.php`，覆盖默认 disabled binding、disabled/failing provider safe failure、malformed schema rejection、valid fake provider output validation、无真实 provider URL/API key 材料、学习表不写入等边界。

### V6-3 provider 配置/安全门实现结果

已实现真实 provider 前的配置/安全门：

- 新增 `config/ai_study_card_v6.php`。
- 新增 `AiStudyCardV6ProviderSecurityPolicyService`。
- 新增 `tests/Feature/AiStudyCardV6ProviderSecurityConfigTest.php`。
- 新增 `docs/plans/ai-study-card-v6-provider-security-plan.md`。
- 默认 `provider.name=disabled`。
- 默认 `external_requests_enabled=false`。
- 默认 `secret_source=not_configured` / `secret_reference=null`。
- 默认 `timeout_seconds=0` / `max_retries=0`，表示真实 provider 尚未配置。
- 默认 quota / malformed output / network failure 都 fail-closed。
- 默认禁止 raw prompt / raw response / source text / secret reference / provider headers 日志。
- 默认 provider 不允许创建 WordSense / ReviewCard / ReviewLog / legacy word card，不允许改 FSRS。
- 默认要求真实 provider 前必须做浏览器 Network smoke。
- 测试确认配置与 policy 文件不包含常见 API key 变量名、`env(...)`、token-like 字符串或 live provider endpoint。

### V6-4 real-provider ADR / implementation plan 实现结果

已完成真实 provider 实现前的计划冻结：

- 新增 `docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md`。
- 新增 `docs/plans/ai-study-card-v6-real-provider-implementation-plan.md`。
- 新增 `docs/testing/ai-study-card-v6-real-provider-network-smoke-playbook.md`。
- 新增 `tests/Feature/AiStudyCardV6RealProviderPlanGuardTest.php`。
- 冻结未来只能先实现一个 backend-only OpenAI-compatible adapter，并且必须先由用户确认 provider / secret storage / timeout / failure behavior。
- 冻结未来 provider route 为 `POST /ai-study-card/v6/recommendations/provider-preview`，但本轮没有实现该 route。
- 明确禁止把 live provider 调用塞入 request-package、preview-package、final-candidates-package、generate-cards 或 inline-preview。
- 明确 UI 阶段必须做真实浏览器 Network 验收，API/curl/代码审查不能替代。
- 明确 V5 card generation remains the only card creation path。

### V6-5 provider-preview backend skeleton 实现结果

已实现 provider-preview 后端骨架，仍保持 disabled/fail-closed：

- 新增 `AiStudyCardV6ProviderPreviewService::preview`。
- `AiStudyCardV6RecommendationController` 新增 `providerPreview()`。
- 新增路由 `POST /ai-study-card/v6/recommendations/provider-preview`。
- 路由要求登录。
- 请求必须包含 `request_package`。
- request package 必须是 `ai-study-card-v6-request-package-v1`。
- 当前默认安全配置下一定返回 503 fail-closed。
- 返回 `security_policy_blocked=true` / `no_provider_called=true`。
- 不调用 live provider。
- 不新增 UI。
- 不写 WordSense / ReviewCard / ReviewLog。
- 不改 FSRS。
- 不创建 legacy word card。
- 不返回 secret-like 信息。

新增 `tests/Feature/AiStudyCardV6ProviderPreviewRouteTest.php`，覆盖 auth、route/controller 绑定、malformed package 422、security preconditions 503 fail-closed、无学习数据写入、disabled response 不暴露 secret/provider 材料、V6 request-package route 不变。

### V6-6 prompt / response contract skeleton 实现结果

已实现 provider prompt / response contract skeleton，仍不接真实 provider：

- 新增 `AiStudyCardV6PromptBuilderService::buildPromptPayload`。
- 新增 `AiStudyCardV6ProviderResponseParserService::parseAndValidate`。
- 新增 `tests/Feature/AiStudyCardV6PromptAndResponseContractTest.php`。
- prompt payload schema：`ai-study-card-v6-provider-prompt-payload-v1`。
- 输入仍是 `ai-study-card-v6-request-package-v1`。
- 目标输出仍是 `ai-study-card-v6-recommendation-package-v1`。
- prompt payload 只包含 selected item 的最小字段。
- 不包含 raw source payload。
- 不包含 full chapter text。
- 长句截断到 500 字符。
- 不包含 provider endpoint / API key / env / HTTP client 调用。
- response parser 拒绝空字符串、坏 JSON、数组 JSON、schema-invalid JSON。
- schema-valid JSON 仍只作为 unchecked recommendation package，要求用户确认。
- 不写 WordSense / ReviewCard / ReviewLog。
- 不改 FSRS。

### V6-7 OpenAI-compatible adapter fake-transport skeleton 实现结果

已实现 OpenAI-compatible adapter skeleton，仍不接真实 provider：

- 新增 `AiStudyCardV6ProviderTransportInterface`。
- 新增 `AiStudyCardV6ProviderTransportException`。
- 新增 `AiStudyCardV6OpenAiCompatibleProviderAdapter`。
- 新增 `tests/Feature/AiStudyCardV6OpenAiCompatibleAdapterTest.php`。
- 默认 `AppServiceProvider` 仍绑定 disabled adapter，不绑定该 adapter。
- adapter 只有在测试中注入 fake transport 才执行。
- adapter 不知道 provider endpoint。
- adapter 不知道 API key。
- adapter 不读取 `.env`。
- adapter 不使用 `Http::` / curl / Guzzle。
- adapter 使用 `AiStudyCardV6PromptBuilderService` 构造 provider-neutral payload。
- adapter 从 OpenAI-compatible `choices.0.message.content` 提取 JSON。
- adapter 使用 `AiStudyCardV6ProviderResponseParserService` 解析和 schema 校验。
- missing choices / invalid JSON / schema-invalid JSON / transport exception 全部 fail-closed。
- 不写 WordSense / ReviewCard / ReviewLog。
- 不改 FSRS。

### V6-8 DeepSeek backend transport 实现结果

已实现 backend live transport，但仍不接 UI：

- `config/ai_study_card_v6.php` 支持用户手动本地环境配置。
- `AppServiceProvider` 默认仍 disabled；只有本地配置允许时才绑定 OpenAI-compatible adapter。
- 新增 `AiStudyCardV6OpenAiCompatibleHttpTransport`。
- provider-preview backend route 在配置启用后可经后端 transport 调用 DeepSeek-compatible chat-completions endpoint。
- 自动测试使用 HTTP fake，不发真实外部请求。
- 错误分类细化为 auth / quota / malformed response / network / missing base URL / missing key / missing model。
- 仍不创建 WordSense / ReviewCard / ReviewLog。
- 仍不改 FSRS。
- 仍不创建 legacy word card。
- V6-10 已新增 live provider UI trigger，见下方更新。

### V6-9 provider output contract + backend smoke 实现结果

已完成真实 provider output contract 修复与 backend-only smoke：

- 修复 `AiStudyCardV6PromptBuilderService`，明确要求 provider 返回顶层 `schema_version` / `recommended_items` / `dropped_items` / `provider_metadata_redacted` / `safety_flags`。
- 明确禁止把推荐数组写成顶层 `recommendations`。
- 保持 provider result 只作为 unchecked recommendation package，不创建学习数据。
- backend-only provider-preview smoke 在本地配置启用后返回 `success=true` / `status=200` / `schema=ai-study-card-v6-recommendation-package-v1` / `recommended_count=1`。
- smoke 前后 `word_senses` / `review_cards` / `review_logs` 计数不变。
- 不读取 `.env`，不打印 API key，不写 WordSense / ReviewCard / ReviewLog，不改 FSRS，不创建 legacy word card。

### V6-10 provider-preview UI trigger 实现结果

已完成显式 UI trigger 与真实浏览器 Network 验收：

- `AiStudyCardV6RequestPackagePanel.vue` 新增明确按钮「调用 V6 AI 推荐（后端预览）」。
- 浏览器只调用本地 `POST /ai-study-card/v6/recommendations/provider-preview`。
- 不在 page load / token click / 打开弹窗 / 生成请求包时自动调用 provider-preview。
- WorkBuddy 网页端体验师真实浏览器验收确认：provider-preview 返回 200，「V6 AI 推荐预览（默认不勾选）」正常显示，外部 provider 域名请求 0 条，无 secret 暴露，数据库计数不变。
- 新增/更新 UI guard，确认 V6 UI 无 provider/key material，不调用外部域名，不写 ReviewLog/FSRS，不创建卡片。

### V6-11 recommendation package → V4 default-unchecked list 实现结果

已实现 V6 推荐结果回流到现有 V4/V5 用户确认路径的最小桥接：

- `AiStudyCardV6RequestPackagePanel.vue` 在 V6 推荐预览出现后提供「导入到 AI 推荐词列表（默认不勾选）」按钮。
- `AiStudyCardPreviewDialog.vue` 只做事件转发，不直接改状态、不调用 axios。
- `AiStudyCardDesktopWorkflow.vue::applyV6Recommendations()` 将 V6 recommendation package 写入现有 `aiRecommendationJsonInput`，复用既有 `parseAiRecommendations()` 与 V4 去重/默认不选规则。
- 导入后 `aiSelectedRecommendationIndices=[]`，不会自动勾选。
- 导入后不会自动生成最终候选包，不会打开生成学习卡对话框，不会创建 WordSense / ReviewCard / ReviewLog，不会改 FSRS。
- `tests/Feature/AiStudyCardV6RequestPackageUiGuardTest.php` 新增护栏，锁定 V6 推荐回流到 V4 列表但默认 unchecked。

### V6-12 provider duplicate filtering hardening 实现结果

WorkBuddy 验收 V6-11 时发现当前真实 provider 返回的 3 个推荐词全部与用户已选 pending item 重复。前端 V4 去重正确工作，导入后有效推荐数量为 0，且数据库不变、无外部浏览器请求、无 secret 暴露。该场景证明重复去重正确，但尚未证明非重复推荐项成功导入。

本轮新增后端双保险：

- `AiStudyCardV6PromptBuilderService` 明确要求 provider 不得把 user-selected `word` / `lemma` / `surface` 再放入 `recommended_items`。
- 如果 provider 丢弃重复项，要求写入 `dropped_items`，`reason=duplicate_with_user_selected_item`。
- `AiStudyCardV6RecommendationService` 新增服务层去重：即使 provider 仍返回重复推荐，也会在 schema validation 后把与 request package `selected_items` 重复的 recommendation 移入 `dropped_items`。
- 重复判断同时比较 `lemma` / `word` / `surface`，大小写归一。
- `provider_metadata_redacted.duplicate_with_user_selected_count` 记录服务层丢弃数量。
- 新增/更新 V6 tests，锁定 duplicate-with-selected 被丢弃、非重复 recommendation 保留、仍 default unchecked、仍不写 WordSense / ReviewCard / ReviewLog、不改 FSRS。
- 自动测试：`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 78 passed / 782 assertions；`npm run development` 编译成功。

### V6-13 empty duplicate result UX hint 实现结果

WorkBuddy 复验确认 V6-12 后 provider 已返回 3 个非重复推荐词（intellectual / interrelationship / revision），导入 V4 列表后共 3 条、默认 0 勾选，数据库不变、外部请求 0、secret 暴露 0。该阶段可 Accept。

本轮新增重复结果空状态提示：

- `AiStudyCardV6RequestPackagePanel.vue` 在 V6 推荐预览上方显示本次推荐摘要：AI 返回的新推荐数、自动丢弃数。
- 当 `recommended_items` 为 0 且 `dropped_items` 大于 0 时，显示提示：`AI 本次没有找到新的可加入候选词，重复项已自动丢弃。你可以换一组待解释词再试。`
- 该提示只是 UI 说明，不触发新请求，不自动勾选，不生成最终候选包，不创建 WordSense / ReviewCard / ReviewLog，不改 FSRS。
- `AiStudyCardV6RequestPackageUiGuardTest` 新增护栏，锁定推荐数/丢弃数摘要与重复项空状态提示。
- 自动测试：`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 78 passed / 787 assertions；`npm run development` 编译成功。

### V6-14 import-to-manual-confirmation guidance 实现结果

WorkBuddy 验收 V6-13 时确认推荐摘要真实可见，且数量与 JSON 一致：`本次 AI 返回 3 个新推荐，自动丢弃 0 个重复或不合格项。` 全部丢弃空状态因 provider 返回非重复项未触发，保留为自然触发场景待后验。

本轮新增 V6 导入后的人工确认路径引导：

- `AiStudyCardRecommendationPanel.vue` 新增 `importNotice` 只读提示位。
- `AiStudyCardPreviewDialog.vue` 将 `aiRecommendationImportNotice` 传入 V4 推荐词列表区域。
- `AiStudyCardDesktopWorkflow.vue::applyV6Recommendations()` 在 V6 recommendation package 导入后显示提示：已从 V6 导入多少条推荐词、默认未勾选、需要手动勾选、再点击「准备生成」和「生成最终候选包」，最终生成学习卡前仍必须填写中文释义。
- 如果 V6 recommendation package 没有可导入的新推荐词，则显示重复项已丢弃、可换一组待解释词再试。
- 该提示不自动勾选、不自动生成最终候选包、不打开生成学习卡对话框，不创建 WordSense / ReviewCard / ReviewLog，不改 FSRS。
- `AiStudyCardV6RequestPackageUiGuardTest` 新增护栏，锁定 V6 导入提示必须引导到 V5 人工确认，且不得把 AI reason 写入 `sense_zh`。
- 自动测试：`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 79 passed / 799 assertions；`php artisan test --filter VocabularyBoxV5UiGuardTest --stop-on-failure` 为 16 passed / 135 assertions；`npm run development` 编译成功。

### V6-15 V5 reason-vs-definition warning 实现结果

WorkBuddy 验收 V6-14 时确认 V5 人工确认路径引导完整通过：V6 推荐导入后能打开 V5 人工确认对话框，AI reason 仅显示为“推荐理由（参考说明，不是释义）”，中文释义字段为空且必填，未点击最终确认时数据库不变。

本轮新增 V5 对话框 reason-vs-definition 强提示：

- `AiStudyCardGenerateCardsDialog.vue` 在存在 AI 推荐项时显示 warning：`AI 推荐理由只解释为什么推荐这个词，不等于中文释义。请自己判断词义后填写“中文释义（必填）”，不要直接把推荐理由当作释义。`
- 每个 AI 推荐项的 reason 下方显示二次提示：`请根据上下文填写中文释义；推荐理由不会自动保存，也不会替你完成释义。`
- 仍不自动填 `sense_zh`，不自动确认，不创建 WordSense / ReviewCard / ReviewLog，不改 FSRS。
- `VocabularyBoxV5UiGuardTest` 增加护栏，锁定强提示存在，并继续确认 `sense_zh` 不会从 reason 自动填入。
- 自动测试：`php artisan test --filter VocabularyBoxV5UiGuardTest --stop-on-failure` 为 16 passed / 138 assertions；`npm run development` 编译成功。

### V6-16 V5 generation counts before confirm 实现结果

WorkBuddy 验收 V6-15 时确认 V5 对话框顶部 warning 和每个 AI 推荐项下方的 reason-vs-definition 提示真实可见，且中文释义字段仍为空且必填、AI reason 没有自动写入、未点击最终确认时不创建学习数据。该阶段 Accept。

本轮继续围绕“未填写中文释义时的确认行为是否足够清楚”做产品收口：

- `AiStudyCardGenerateCardsDialog.vue` 新增三个 computed：`filledCount`（非空 `sense_zh` 项数）、`skippedCount`（空 `sense_zh` 项数）、`canConfirm`（至少 1 项已填才为 true）。
- 对话框底部显示明确计数：`共 X 项，将生成 Y 张，将跳过 Z 项`，Y/Z 由 computed 实时计算。
- 0 项已填时确认按钮 `:disabled="!canConfirm"`，按钮文案变为 `请至少填写 1 个中文释义`，并显示 warning alert：`还没有填写任何中文释义，无法生成学习卡。请至少填写 1 个中文释义后再确认。`
- 至少 1 项已填时按钮文案变为 `确认生成 Y 张学习卡`，反映实际将生成的卡片数量。
- 仍不自动填 `sense_zh`，不自动确认，不创建 WordSense / ReviewCard / ReviewLog，不改 FSRS，不创建 legacy word card。
- 前端 `AiStudyCardGenerateCardsService::filterConfirmedGenerateCardItems()` 早已按非空 `sense_zh` 过滤，UI 计数与后端实际生成行为一致。
- `VocabularyBoxV5UiGuardTest` 新增 2 条护栏：锁定 `将生成` / `将跳过` / `filledCount` / `skippedCount` / `canConfirm` 存在，锁定 0 已填时按钮 disabled 与引导文案存在。
- 自动测试：`php artisan test --filter VocabularyBoxV5UiGuardTest --stop-on-failure` 为 18 passed / 148 assertions；`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 79 passed / 799 assertions；`npm run development` 编译成功。
- MCP Chrome 真实页面验收：6 项候选（4 用户已选 + 2 V6 推荐）打开 V5 对话框时显示 `共 6 项，将生成 0 张，将跳过 6 项`，按钮显示 `请至少填写 1 个中文释义`，warning alert 可见；手动填写 1 项中文释义后实时变为 `共 6 项，将生成 1 张，将跳过 5 项`，按钮变为 `确认生成 1 张学习卡`，warning alert 消失；未点击最终确认，数据库 `word_sense` / `review_card` / `review_log` 计数前后不变；Network 全程仅 127.0.0.1:8000，无外部 provider 域名，无 `POST /ai-study-card/generate-cards`。

### V6-17 V5 per-candidate generate/skip status 实现结果

WorkBuddy 验收 V6-16 时确认 V5 对话框底部已显示明确计数（共 X 项，将生成 Y 张，将跳过 Z 项），0 已填时按钮禁用并引导。该阶段 Accept。

本轮继续提升 V5 对话框内“将生成 / 将跳过”的逐项可见性：

- `AiStudyCardGenerateCardsDialog.vue` 新增 `isFilled(item)` 方法，判断 `item.sense_zh` 是否非空（与 `filledCount` computed 和后端 `filterConfirmedGenerateCardItems()` 一致）。
- 每个候选项标题区右侧新增状态 chip：已填中文释义时显示 `将生成`（success 色 outlined），未填时显示 `将跳过`（warning 色 outlined）。
- 状态 chip 与底部计数实时同步：填写中文释义后该项 chip 从「将跳过」变为「将生成」，底部「将生成 Y 张」+1；删除中文释义后 chip 回到「将跳过」，底部计数回退。
- 仍不自动填 `sense_zh`，不自动确认，不创建 WordSense / ReviewCard / ReviewLog，不改 FSRS，不创建 legacy word card。
- `VocabularyBoxV5UiGuardTest` 新增 1 条护栏（test 19）：锁定 `isFilled(item)` 方法存在、状态 chip 文案 `将生成` / `将跳过` 存在、chip 颜色绑定 `isFilled(item) ? 'success' : 'warning'` 存在、chip 文案绑定 `isFilled(item) ? '将生成' : '将跳过'` 存在。
- 自动测试：`php artisan test --filter VocabularyBoxV5UiGuardTest --stop-on-failure` 为 19 passed / 155 assertions；`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 79 passed / 799 assertions；`npm run development` 编译成功。
- MCP Chrome 真实页面验收：7 项候选（5 用户已选 + 2 AI 推荐 mediation/phenomenology）打开 V5 对话框时每项右侧均显示 `将跳过` chip，底部显示 `共 7 项，将生成 0 张，将跳过 7 项`，按钮显示 `请至少填写 1 个中文释义` 且 disabled，warning alert 可见；手动填写 mediation 中文释义 `调解；斡旋` 后该项 chip 实时变为 `将生成`（success 色），底部变为 `共 7 项，将生成 1 张，将跳过 6 项`，按钮变为 `确认生成 1 张学习卡` 且 enabled，warning alert 消失；删除 mediation 中文释义后该项 chip 回到 `将跳过`（warning 色），底部回到 `共 7 项，将生成 0 张，将跳过 7 项`，按钮回到 `请至少填写 1 个中文释义` 且 disabled，warning alert 重新出现；AI reason 仅显示为「推荐理由（参考说明，不是释义）」，未自动写入 sense_zh；未点击最终确认，数据库 word_sense / review_card / review_log 计数前后不变（41/41/15）；Network 全程仅 127.0.0.1:8000，无外部 provider 域名，无 POST /ai-study-card/generate-cards。

### V6-18 V5 result candidate overview 实现结果

V6-17 收口了 V5 对话框内的逐项状态。本轮收口 V5 最终确认后结果页的「候选项总览」可见性，解决用户困惑：前端 `filterConfirmedGenerateCardItems()` 只发送已填项给后端，所以后端 `summary.skipped_count` 不包含「未填写释义」的项；用户在对话框看到 7 项、只填 1 项，但结果页显示「创建 1 / 跳过 0」会造成误解。

本轮纯前端收口，零后端改动、零 DB schema 改动：

- `AiStudyCardDesktopWorkflow.vue` 的 `confirmGenerateCards()` 在 `.then(data => ...)` 时捕获对话框层面的候选总数（`totalCandidates` = `aiGenerateCardsItems.length`）和已填数（`confirmedItems.length`），附加 `data.candidate_overview = { total, filled, skipped_unfilled }` 到结果 payload。
- `AiStudyCardGenerateCardsResult.vue` 在 success/error alert 之后、四类计数 chip 之前新增「候选项总览」区块：`共 N 项 · 已填写 X 项 · 未填写 Y 项`，配 `已填写 → 已提交生成`（success chip）和 `未填写 → 未提交、未生成、未删除`（warning chip）；当 `skipped_unfilled > 0` 时额外显示 `未填写的 Y 项不会生成学习卡，也不会被删除，可稍后再次确认`。
- 仍不自动填 `sense_zh`，不自动确认，不改 FSRS，不创建 ReviewLog，不创建 legacy word card。
- `VocabularyBoxV5UiGuardTest` 新增 1 条护栏（test 20）：锁定 workflow 中 `totalCandidates` 捕获、`data.candidate_overview = {` 赋值、`total/filled/skipped_unfilled` 三字段，以及 result 组件中 `result.candidate_overview` 渲染、`候选项总览` 文案、`已填写 → 已提交生成` / `未填写 → 未提交、未生成、未删除` 文案、`candidate_overview: { total, filled, skipped_unfilled }` docblock 契约。
- 自动测试：`php artisan test --filter VocabularyBoxV5UiGuardTest --stop-on-failure` 为 20 passed / 166 assertions；`php artisan test --filter AiStudyCardV6 --stop-on-failure` 为 79 passed / 799 assertions；`php artisan test --filter AiStudyCardV5DesktopArchitectureGuardTest --stop-on-failure` 为 17 passed / 149 assertions；`npm run development` 编译成功（5.42s）。
- MCP Chrome 真实页面验收（含本轮特殊允许的「点击最终确认」）：7 项候选（5 用户已选 + 2 AI 推荐 mediation/phenomenology），手动填写 mediation 中文释义 `调解；斡旋` 后点击 `确认生成 1 张学习卡`；结果页显示 `候选项总览：共 7 项 · 已填写 1 项 · 未填写 6 项`，`已填写 → 已提交生成` / `未填写 → 未提交、未生成、未删除` chip，`未填写的 6 项不会生成学习卡，也不会被删除，可稍后再次确认`，后端计数 `创建 1 / 跳过 0 / 重复 0 / 失败 0`，新建学习卡 `mediation → 释义 #87 / 复习卡 #89`，`进入 /reviews/senses 复习` 入口可见，`这不是 AI 自动调用` 安全文案可见；数据库 word_senses 41→42 (+1)、review_cards 41→42 (+1)、review_logs 15→15 (+0)，符合预期；Network 45 个请求全部命中 127.0.0.1:8000，包含 `POST /ai-study-card/generate-cards` (reqid=163)，无任何外部 provider 域名。

### 后续允许的下一小步

V5 对话框逐项「将生成 / 将跳过」状态已收口，V5 最终确认后的结果页也已显示候选项总览。下一步可考虑的方向（仍需网页端总流程设计师确认）：

1. V5 对话框内对“将跳过”的项给出更明显的视觉差异（如轻微灰化整项背景），让用户扫一眼就能区分生成区与跳过区。
2. V6 provider-preview 在本地 SSL 证书修复后做真实 provider 调用端到端验收（当前仅 backend-only smoke 通过）。
3. 其他与 AI Study Card 无关的产品线候选，参见 `current-working-handoff.md`。

### V6-19 V5 → /reviews/senses → sense card FSRS rating 闭环验收 实现结果

V6-18 收口了 V5 结果页的候选项总览。本轮验证并锁定了「V5 生成学习卡 → 进入 /reviews/senses → 对新生成 sense card 完成一次受控 FSRS 评分」的完整学习闭环，确认新生成 sense card 可立即进入复习队列，一次评分只产生预期的 ReviewLog / FSRS / card 状态变化，不影响其它卡，不创建 legacy word card。

本轮零后端改动、零 DB schema 改动、零 UI 文案改动，仅新增 1 条测试护栏：

- 现有架构已满足「立即复习」产品需求：`ReviewCardService::ensureSenseCard()` 创建新卡时 `fsrs_state='new'`、`fsrs_due_at=Carbon::now()`、`fsrs_enabled=true`，而 `SenseReviewService::dueSenseReviewCardQuery()` 的过滤条件是 `fsrs_due_at <= now() && fsrs_enabled=true && word_senses.status=confirmed`，所以新生成 sense card 一定立即出现在 `/reviews/senses` 队列。
- `ReviewCardService::recordReview()` 使用 `DB::transaction + lockForUpdate`，只更新目标 card 的 FSRS 字段（state / due_at / stability / difficulty / reps / lapses / last_reviewed_at），只创建 1 条 `source='sense_review'` 的 ReviewLog，不触碰其它 card。
- `WordSenseTest` 新增 1 条 closed-loop 测试 `test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects`：模拟 V5 生成结果（新建 confirmed WordSense + sense ReviewCard），预置另一张 future-dated sense card 和一张 legacy word card 作为「不应被影响」基线；GET `/reviews/senses?ignoreDailyLimits=1` 断言目标卡出现在队列；POST `/reviews/senses/{id}/rate` rating=good；断言 `word_senses` / `review_cards` / sense card 数 / legacy word card 数都不变，`review_logs` +1，目标卡 FSRS 字段前进，其它卡 `fsrs_reps` 不变，新 ReviewLog 的 `review_card_id` / `rating` / `source='sense_review'` 正确。
- 自动测试：`VocabularyBoxV5UiGuardTest` 20 passed / 166 assertions；`AiStudyCardV6` 79 passed / 799 assertions；`SenseReview` 29 passed / 129 assertions；`ReviewFsrsTest` 63 passed / 374 assertions；`WordSense` 197 passed / 820 assertions（含新增 1 条）；`npm run development` 编译成功（5.54s）。
- MCP Chrome 真实页面验收（路径 A，复用上一轮 mediation card）：打开 `/reviews/senses`（已登录），通过浏览器 `fetch('/reviews/senses?ignoreDailyLimits=1')` 确认 mediation card（review_card_id=89, word_sense_id=87, lemma=mediation, sense_zh=调解；斡旋）在 22 张到期卡队列末尾；评分前 DB：word_senses=42, review_cards=42（36 sense + 6 word legacy）, review_logs=15, card #89 fsrs_state=new/fsrs_reps=0/fsrs_stability=null；通过浏览器 `fetch('/reviews/senses/89/rate', { method:'POST', body: JSON.stringify({rating:'good'}) })` 做受控评分，响应 200，`reviewed_card` state=new→review、reps=0→1、stability=null→3.173、difficulty=null→5.282、due_at=2026-07-08→2026-07-11；评分后 DB：word_senses=42, review_cards=42, review_logs=16（+1，新 log id=17, card_id=89, rating=good, source=sense_review），legacy word card 数不变，60 秒窗口内只有 card #89 被更新、只新建 1 条 ReviewLog；页面 reload 后 UI 显示「到期数量 22→21」「今日已复习 0→1」，mediation card 已移出队列；Network 全部命中 127.0.0.1:8000，无任何外部 provider 域名。

---

## 2026-07-09 GLM-AIStudyCardFullLoopRegressionHarness-1

**任务**：把 AI Study Card V6 → V4 → V5 → `/reviews/senses` → FSRS rating 主链路从「只靠聊天报告验收」沉淀成可重复运行的回归防护体系，让后续 GLM / OpenCode / WorkBuddy 等任何 agent 改 AI 学习卡时都能用它判断主链路有没有被改坏。

**选择理由**：V6-19 已完成主链路闭环（commit `4bcb637`），但所有验收证据分散在聊天记录里，没有可重复执行的 harness / playbook / 自动测试组合。后续 agent 改 AI 学习卡时只能凭印象判断有没有破坏主链路，无法防止 regression。本轮只做 harness / playbook / 测试体系建设，不改业务代码、不改 DB schema、不改 UI 文案。

### 交付物

1. **新增 guard 测试**：`tests/Feature/AiStudyCardFullLoopGuardTest.php`（3 tests / 84 assertions）
   - `test_full_loop_v6_to_sense_rating_locks_main_chain_safety_contract`：P2 全链路索引 guard，在单个测试中走完 V6 request-package → V6 provider-preview（fail-closed）→ V4 final-candidates-package → V5 generate-cards → `/reviews/senses` 队列 → sense card `rate`，断言 12 条安全契约（V6 不写学习数据 / V5 只对填了 `sense_zh` 的项生成 / V5 不写 ReviewLog / V5 不创建 legacy word card / V5 结果含 `/reviews/senses` 入口 / 新卡立即可复习 / 评分只 +1 ReviewLog / 只更新目标卡 / 不创建新 WordSense / 不创建新 ReviewCard / 不创建 legacy word card / ReviewLog source=sense_review）。
   - `test_v5_generation_safety_contract_index_documented_in_one_place`：V5 生成阶段安全契约索引（5 条断言），把分散在 `AiStudyCardPendingItemTest` 中的 V5 安全契约集中到一处可读位置。
   - `test_sense_rating_safety_contract_index_documented_in_one_place`：P3 sense rating 安全契约索引，分散 `WordSenseTest::test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects` 的单点失败风险。
2. **新增 playbook**：`docs/testing/ai-study-card-full-loop-regression-playbook.md`（12 节）
   - §1 Purpose / §2 Pre-flight / §3 测试命令矩阵（9 条命令 + 期望计数）/ §4 MCP Chrome 真实验收 playbook（轻量 7 步 + 完整 20 步）/ §5 数据库验收矩阵（每阶段表 delta）/ §6 网络验收（禁止 provider 域名）/ §7 Refuse 条件（12 条安全契约 + 额外触发器）/ §8 Accept/Refuse/Incomplete 判断 / §9 允许修改文件边界 / §10 停止条件 / §11 文件→测试映射 / §12 Change log。
3. **更新 3 份主文档**：本文件 + `current-working-handoff.md` + `DOCUMENTATION_INDEX.md`。

### 自动测试

- `AiStudyCardFullLoopGuardTest`: 3 passed (84 assertions)
- 组合运行 `AiStudyCardFullLoopGuardTest|VocabularyBoxV5UiGuardTest|AiStudyCardV6|SenseReview|ReviewFsrsTest|WordSense`: 391 passed, 1 skipped (2372 assertions)
- `npm run development`: webpack compiled successfully (14.51s, exit code 0)

### MCP Chrome 轻量验收

- `/reviews/senses` 可访问、UI 正常渲染、V5 结果页 `/reviews/senses` 入口仍存在、前端构建未破坏。
- 本轮只改测试和文档，未改 UI / 业务代码，因此按用户授权做轻量验收，不重复完整生成/评分流程。

### 安全边界确认

- 未读取 / 打印 / 修改 / 提交 `.env`。
- 未输出 secret / API key。
- 未创建 WordSense / ReviewCard / ReviewLog（本轮不执行完整生成/评分）。
- 未修改 FSRS。
- 未创建 legacy word card。
- 未运行 migrate:fresh / db:wipe / 清库。
- 未运行 notification script。
- 未 DCP。
- 未把后端 smoke 当真实页面验收。
- 未伪造 MCP Chrome 验收结果。

### 明确未做

- 不新增产品能力；不碰后端业务逻辑；不碰 V5 对话框 / 结果页 UI；不改 SenseReview.vue；不改 SenseReviewController / SenseReviewService / ReviewCardService / SenseReviewQueryService；不新增 migration；不清库；不 DCP；不 notification script；不处理 .omo/；不提交敏感文件；不把 API 200 当页面验收。

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。候选方向参见本文件第 4 节。

- Did NOT enter the next task automatically.

---

## 2026-07-09 GLM-SenseReviewExampleRotationMemory-1

**任务**：让一个 WordSense 拥有多个例句时，SenseReview 能根据学习历史智能选择例句。用户第一次复习看到例句 A，下一次复习自动显示另一个例句 B，帮助建立词义迁移。

**选择理由**：GM52-SenseMultiExampleBindingAndReviewRotation-1000-6 已实现多例句绑定 + seed-based 轮换，但 `crc32(seed) % total` 哈希取模不满足"第一次 A、第二次 B、第三次 C"的序列化需求，也不满足"复习失败时切换不同例句"。本轮将轮换策略从哈希改为线性序列。

### 交付物

1. **`app/Services/WordSenseExamplePoolService.php`** — `pickQuestionIndex` 从 `crc32(seed) % total` 改为 `(reviewCardId + fsrsReps + fsrsLapses) % total` 线性序列；`pickSupplementaryIndex` 加 `fsrsLapses` 参数。
2. **`app/Services/SenseReviewCardSerializerService.php`** — `serialize` 传入 `$card->fsrs_lapses`；更新注释反映新策略。
3. **`tests/Feature/SenseReviewExampleRotationTest.php`** — 新增 4 个测试覆盖线性序列轮换、循环、lapses 切换、单例句稳定。
4. **`docs/testing/sense-review-example-rotation-playbook.md`** — 新增 12 节 playbook。

### 自动测试

- `SenseReviewExampleRotationTest`: 14 passed (39 assertions)
- 组合运行: 364 passed, 1 skipped (1688 assertions)
- `npm run development`: webpack compiled successfully (5.04s)

### MCP Chrome 真实页面验收

- card 62 评分前（reps=1）：index=(62+1+0)%2=1 → card_fallback 例句
- 评分 `good` 后（reps=2）：index=(62+2+0)%2=0 → occurrence_id=11 例句
- 例句已切换，`example_source_status` 从 `card_fallback` 变为 `occurrence`
- DB：review_logs 16→17（+1），其他不变；Network 全部 127.0.0.1:8000

### 安全边界确认

- 未读取 / 修改 / 提交 `.env`；未输出 secret；未修改 FSRS 算法；未修改 WordSense/ReviewCard 数据模型边界；serialize 保持只读；未创建 legacy word card；未清库；未 DCP；未 notification script。

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-09 GLM-SenseReviewUnderstandingAid-1000-7

**任务**：增强 SenseReview 的理解层。用户复习一个 sense 时，虽然能看到例句和中文释义，但缺少"为什么这个例句代表这个词义"的理解辅助。本轮在答案面增加折叠"理解这个词义"区块，展示 sense-level 的 explanation / meaning_boundary / context_hint / usage_keywords 四个理解辅助字段，帮助用户形成词义边界。

**选择理由**：SenseReview 例句轮换系统（GM52-SenseMultiExampleBindingAndReviewRotation-1000-6 + SenseReviewExampleRotationMemory-1）已完成，用户能看到不同例句和中文释义，但缺少词义边界理解辅助。本轮增加 sense-level 的理解辅助字段，不修改 FSRS / ReviewCard / ReviewLog / 例句轮换 / source context，纯增强展示层。

### 交付物

1. **`database/migrations/2026_07_09_000001_add_understanding_aid_to_word_senses_table.php`** — 新增 `word_senses.understanding_aid` JSON nullable 列，可回滚。
2. **`app/Models/WordSense.php`** — `understanding_aid` 加入 `$fillable` 和 `casts()`（array）。
3. **`app/Services/SenseReviewCardSerializerService.php`** — 新增 `normalizeUnderstandingAid()` 私有方法，serialize 透传规范化后的 `understanding_aid` 结构（null-safe，部分数据自动补默认值）。
4. **`resources/js/components/Senses/SenseReview.vue`** — 答案面左栏增加折叠"理解这个词义"区块；`understandingAid` / `hasUnderstandingAid` computed；`understandingAidOpen` data 字段（卡片切换时重置为 false）。
5. **`tests/Feature/SenseReviewUnderstandingAidTest.php`** — 新增 6 个测试（21 assertions）覆盖序列化、空值安全、FSRS 安全、ReviewLog 安全、sense-level（非 occurrence-level）、部分数据。
6. **`docs/testing/sense-review-understanding-helper-playbook.md`** — 新增 12 节 playbook。

### 自动测试

- `SenseReviewUnderstandingAidTest`: 6 passed (21 assertions)
- 组合运行: 1 skipped, 379 passed (1755 assertions)
- `npm run development`: webpack compiled successfully (5.36s)

### MCP Chrome 真实页面验收

- card 63 (lemma: published)：默认折叠（understandingAidOpen=false），展开显示全部 4 个子字段（explanation / meaning_boundary / context_hint / usage_keywords）
- 评分按钮（忘了/勉强记得/记得/很熟）全部 enabled
- More 菜单按钮 enabled
- viewSource() 成功打开原文对话框，显示"已定位到当前复习例句"
- Network：4 个请求全部 127.0.0.1:8000（无 external provider）
- DB：ReviewLog count 1→1（不变），FSRS 全字段不变

### 安全边界确认

- 未读取 / 修改 / 提交 `.env`；未输出 secret；未修改 FSRS 算法/interval/stability/difficulty/rating 逻辑；未修改 ReviewCard target_type；未修改 ReviewLog 记录逻辑（仍只记录用户评分）；未创建 legacy word card；未自动调用外部 AI；未自动生成/合并 WordSense；未自动修改 sense_zh；未清库；未 migrate:fresh；未 db:wipe；未 DCP；未 notification script。
- understanding_aid 是 sense-level（非 occurrence-level），例句轮换时保持不变。
- serialize 保持只读；展开/折叠是纯前端 `understandingAidOpen` 布尔状态，零网络请求。

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-09 GLM-SenseReviewContextualUnderstanding-1000-10

**任务**：SenseReview 智能复习上下文层（Task 4）。把 Task 3 sense-level 的 `understanding_aid` 升级为「sense-level + occurrence-level merged」，让理解辅助跟随当前显示例句，并新增智能例句选择（preferred occurrence 优先 + 线性轮换 fallback）。新增 `related_collocations` 字段（类似使用）。

**选择理由**：Task 3 已建立 sense-level 理解辅助，但当复习卡绑定多个 occurrence 时，sense-level 的 context_hint / 判断依据 / 类似使用无法精确描述"为什么这个例句代表这个词义"。本轮通过复用 `WordSenseOccurrence.evidence` JSON 列（无新 migration）让 occurrence-level 的 context_hint / judgment_basis / related_collocations 在序列化时 override sense-level 对应字段，让理解辅助真正跟随当前显示例句。同时新增 `pickQuestionIndexWithContext()` 实现 preferred_occurrence_id 优先匹配，关闭 source context 切换例句后理解辅助不跟随的体验缺口。

### 交付物

1. **`app/Services/SenseReviewCardSerializerService.php`** — `serialize()` 新增 `array $options = []` 参数；新增 `mergeOccurrenceEvidence()` 私有方法（内存合并，不写库）；`normalizeUnderstandingAid()` 新增 `related_collocations` 字段（默认 []）；透传 `preferred_occurrence_id` 到 pool service。
2. **`app/Services/WordSenseExamplePoolService.php`** — 新增 `pickQuestionIndexWithContext()` 方法（preferred_occurrence_id 优先匹配 → 线性轮换 fallback `(reviewCardId + fsrsReps + fsrsLapses) % total`）。
3. **`resources/js/components/Senses/SenseReview.vue`** — 新增「类似使用」outlined chips 块渲染 `related_collocations`；「常见搭配关键词」标签改为「判断依据」；`hasUnderstandingAid` computed 增加 `related_collocations` 检查。
4. **`tests/Feature/SenseReviewContextualUnderstandingTest.php`** — 新增 8 个测试（23 assertions）覆盖 preferred_occurrence_id 优先匹配 / 无 preferred 时线性轮换 fallback / occurrence evidence override sense-level / explanation 与 meaning_boundary 不被 override / source context 完整 occurrence 优先 / occurrence 不存在 fallback example_sentence / 切换例句不写 ReviewLog / 切换例句不改 FSRS 字段。
5. **`docs/testing/sense-review-understanding-helper-playbook.md`** — 重写为 sense-level + occurrence-level merged playbook（12 节扩展为 13 条 Refuse 条件 + 新增 occurrence-level 数据源说明 + `mergeOccurrenceEvidence` 与 `pickQuestionIndexWithContext` 描述 + 8/23 测试矩阵）。

### 复用已有数据无新 migration

`WordSenseOccurrence.evidence` JSON 列 pre-existing。本轮仅复用其中 `context_hint` / `judgment_basis` / `related_collocations` 三个 key。其他 evidence 内的 key（如 raw_payload siblings）不受影响。

### 自动测试

- `SenseReviewContextualUnderstandingTest`: 8 passed (23 assertions)
- `SenseReviewUnderstandingAidTest`: 6 passed (21 assertions)
- `ReviewFsrsTest`: 63 passed (374 assertions)
- `FsrsSchedulingServiceTest`: 9 passed (46 assertions)
- SenseReview 全量组合: 1 skipped, 244 passed (1001 assertions)
- `npm run development`: webpack compiled successfully (5.42s, 7.37 MiB app.js)

### MCP Chrome 真实页面验收

- 登录 `1816529781@qq.com` → `/reviews/senses`，复习 5 张卡（published / codexmatrix / technology / city / window）
- card 63 (lemma: published)：理解辅助块展开显示 explanation / meaning_boundary / context_hint / judgment_basis
- 查看原文对话框显示「已定位到当前复习例句」（`preferred_occurrence_id=16` 生效）
- 切换例句：理解辅助跟随当前 occurrence
- 评分按钮（忘了/勉强记得/记得/很熟）全部 enabled
- More 菜单按钮 enabled；快捷键 1/2/3/4 可用
- Network：25 个请求全部 `127.0.0.1:8000`（无 api.deepseek.com / api.openai.com / api.anthropic.com / generativelanguage.googleapis.com / api.x.ai）
- DB：ReviewLog 22 条全部 `source='sense_review'`（展开/折叠/切例句/查看原文均零写入）；ReviewCard 42 / WordSense 42 计数稳定

### 安全边界确认

- 未读取 / 修改 / 提交 `.env`；未输出 secret；未修改 FSRS 算法/interval/stability/difficulty/rating/scheduling 逻辑；未修改 ReviewCard `target_type`；未修改 ReviewLog 记录逻辑（仍只记录用户评分）；未创建 legacy word card；未自动调用外部 AI；未自动创建/合并/修改 WordSense；未自动修改 sense_zh；未清库；未 migrate:fresh；未 db:wipe；未 DCP；未 notification script。
- 复用 `WordSenseOccurrence.evidence` 列无新 migration；occurrence-level override 只在序列化时内存合并，不写数据库；`explanation` 与 `meaning_boundary` 始终 sense-level 不被 occurrence override。
- serialize 保持只读；展开/折叠是纯前端 `understandingAidOpen` 布尔状态；切换例句通过 `preferred_occurrence_id` query 参数传给 source-context-list endpoint，不写 ReviewLog。

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-09 GLM-SenseReviewLearningFeedback-1

**任务**：在 SenseReviewCardSerializer payload 新增 `learning_feedback` 只读聚合，帮助用户了解自己的遗忘模式。包含 total_reviews / again / hard / good / easy 计数 + recent_reviews 最近 5 条 + forgetting_pattern 子结构（total_forget / forget_rate / last_forget_date / trend）。

**选择理由**：用户复习时缺少「这张卡历史上忘了多少次、最近趋势如何」的学习反馈。trend 基于最近 6 条非 reset ReviewLog 的前后半 'again' 计数比较（improving / declining / stable / insufficient），纯事实计算无 AI 推测。

### 交付物

1. **`app/Services/SenseReviewCardSerializerService.php`** — 新增 `buildLearningFeedback()` + `computeForgettingTrend()` 私有方法 + `nonResetSenseReviewLogQuery()` helper + `RATING_LABELS` 常量。
2. **`resources/js/components/Senses/SenseReview.vue`** — 新增「学习状态」「遗忘情况」两个折叠块 + `learningFeedback` / `forgettingPattern` computed + 空状态文案。
3. **`tests/Feature/SenseReviewLearningFeedbackTest.php`** — 16 项测试 / 72 assertions。

### 自动测试

- `SenseReviewLearningFeedbackTest`: 16 passed (72 assertions)
- `npm run development`: compiled successfully

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（serialize 只读）；不创建 legacy word card；不自动调 AI；不新增 migration；reset 日志排除；多用户隔离通过 review_card_id scoping。

### commit

`cda203a feat: add sense review learning feedback with forgetting pattern`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-09 GLM-SenseReviewSessionSummary-1

**任务**：用户完成若干张词义卡复习后，可以主动结束本次复习，并看到只属于当前页面会话的总结。总结不是长期统计，不写数据库，不跨刷新保存。

**选择理由**：用户缺少「本次复习了多少张、忘了多少张、需要重点注意哪些词义」的即时反馈。会话总结只统计当前页面打开之后实际完成的评分，不混入历史评分/reset 日志/其它用户/其它会话。

### 交付物

1. **`resources/js/components/Senses/SenseReviewSessionTracker.js`** — 纯函数 helper（createSession / recordRating / getStats / getNeedsAttention / clearSession），requestId 去重防双击，immutable updates。
2. **`resources/js/components/Senses/SenseReviewSessionSummary.vue`** — 展示组件（评分分布 + 重点词义 + 继续复习/结束并离开按钮）。
3. **`resources/js/components/Senses/SenseReview.vue`** — 集成 session tracker + summary 组件 + 「结束本次复习」按钮 + 队列清空自动总结 + summary 状态下禁用快捷键。
4. **`tests/js/SenseReviewSessionTracker.test.mjs`** — 12 项 Node 内置 assert 测试。

### needsAttention 规则

纯事实规则，不使用 AI 推测：
- 本次评分为 again；
- 本次评分为 hard；
- 或评分后 `forgetting_pattern.trend = declining`。

### 自动测试

- Node tests: 12 passed
- `npm run development`: compiled successfully

### 安全边界确认

- 不新增 DB 写入；不写 ReviewLog（除用户评分产生的正常 ReviewLog）；不改 FSRS；不创建 legacy word card；不自动调 AI；不新增 migration；不跨刷新保存（无 sessionStorage/localStorage/DB 持久化）；summary 状态下 Space/1/2/3/4 不操作卡片。

### commit

`9b1a0bd feat: add sense review session summary`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 GLM-SenseReviewModularization-1

**任务**：SenseReview 模块职责拆分与学习反馈解耦。把 `SenseReview.vue` 从 1153 行降至 771 行，拆出 5 个子组件 + 1 个后端 Service。`SenseReviewCardSerializerService.php` 从 396 行降至 266 行，不再直接查询 ReviewLog，委托新 Service。

**选择理由**：`SenseReview.vue` 已膨胀到 1153 行，同时负责过多页面和业务状态。serializer 同时负责例句、语境、理解辅助、学习反馈和 ReviewLog 聚合。继续往大组件中追加会话总结会进一步恶化维护成本。

### 架构侦察结论

- `SenseReview.vue` 1153 行，同时负责：队列加载、卡索引、评分 API、弹窗协调、会话状态、学习反馈展示、评分按钮、理解辅助、编辑弹窗、会话总结。
- `SenseReviewCardSerializerService.php` 396 行，直接查询 ReviewLog 并维护评分中文标签。
- serializer 的 `buildLearningFeedback()` 方法内联了 ReviewLog 聚合逻辑，与序列化职责混合。
- per-card ReviewLog 查询存在 N+1 风险（P1，本轮不强制优化）。

### 交付物

1. **`app/Services/SenseReviewLearningFeedbackService.php`**（181 行）— 学习反馈聚合唯一事实来源（ReviewLog 聚合/total/again/hard/good/easy/recent_reviews/forgetting_pattern/trend 计算/用户和卡片隔离），READ-ONLY。
2. **`app/Services/SenseReviewCardSerializerService.php`**（396→266 行）— 委托新 Service，不再直接查询 ReviewLog。
3. **`resources/js/components/Senses/SenseReviewLearningFeedbackPanel.vue`**（226 行）— 学习状态+遗忘情况只读展示。
4. **`resources/js/components/Senses/SenseReviewRatingControls.vue`**（63 行）— 4 评分按钮+快捷键提示，emit rating 事件。
5. **`resources/js/components/Senses/SenseReviewSessionSummary.vue`**（144 行）— 本次复习总结展示。
6. **`resources/js/components/Senses/SenseReviewUnderstandingAid.vue`**（103 行）— 理解辅助折叠块。
7. **`resources/js/components/Senses/SenseReviewEditDialog.vue`**（207 行）— 编辑弹窗+表单+save API。
8. **`resources/js/components/Senses/SenseReview.vue`**（1153→771 行）— 容器回归页面容器职责。
9. **`tests/Feature/SenseReviewLearningFeedbackServiceTest.php`** — 13 项 Service 测试 / 41 assertions。
10. **`tests/Feature/SenseReviewSerializerContractTest.php`** — 5 项 serializer contract 测试 / 67 assertions。
11. **`docs/architecture/sense-review-module-boundaries.md`** — 新增架构说明。

### 拆分前后行数对照

| 文件 | 拆分前 | 拆分后 | 变化 |
|------|--------|--------|------|
| `SenseReview.vue` | 1153 | 771 | −382 (−33%) |
| `SenseReviewCardSerializerService.php` | 396 | 266 | −130 (−33%) |

### props / events 契约

| 组件 | Props | Emits |
|------|-------|-------|
| LearningFeedbackPanel | learningFeedback, fsrsStability | — |
| RatingControls | disabled | rating(again\|hard\|good\|easy) |
| SessionSummary | stats, needsAttention | continue-review, exit |
| UnderstandingAid | aid | — |
| EditDialog | value (v-model), card | input, saved |

### 是否改变公开 API / payload 语义

- 否。不改变公开 API、不改变 payload 结构、不改变 Controller 路由、不改变 ReviewLog/FSRS 语义。

### N+1 风险

- per-card ReviewLog 查询仍存在（P1）。本轮只做职责解耦，不强制批量优化。记录为下一轮 P1 架构候选。详见 `docs/architecture/sense-review-module-boundaries.md` §5。

### 自动测试

- `SenseReviewLearningFeedbackServiceTest`: 13 passed (41 assertions)
- `SenseReviewSerializerContractTest`: 5 passed (67 assertions)
- `SenseReviewLearningFeedbackTest`: 16 passed (72 assertions)
- `SenseReviewContextualUnderstandingTest`: 8 passed (23 assertions)
- `SenseReviewExampleRotationTest`: 14 passed (39 assertions)
- `SenseReviewDailyLimitsTest`: 17 passed (81 assertions)
- `ReviewFsrsTest`: 63 passed (374 assertions)
- Node tests: 12 passed
- `npm run development`: compiled successfully

### MCP Chrome 真实页面验收（14 项架构回归）

1. 新会话不显示伪造总结 ✓
2. 显示答案正常 ✓
3. learning_feedback 结构正确 ✓
4. understanding_aid 结构正确 ✓
5. 评分按钮正常 ✓
6. 会话总结自动显示 ✓
7. needsAttention 列出 again+hard 卡 ✓
8. More 菜单 5 项 ✓
9. 编辑弹窗 7 字段预填 ✓
10. 查看原文对话框 ✓
11. summary 状态下快捷键禁用 ✓
12. Console 仅预存在消息 ✓
13. Network 仅 127.0.0.1 ✓
14. 桌面双列布局 ✓

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（除用户评分）；不创建 legacy word card；不自动调 AI；不新增 migration；不修改公开路由；不修改评分 API 语义；不读取/修改/提交 `.env`；不 DCP；不 notification script。

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 GLM-SenseReviewTodaySummary-1

**任务**：新增「今日复习总结」。当前已有「本次复习总结」（page-load scoped，刷新重置），但用户在一天内可能多次打开页面、多个会话分别评分。今日总结必须把同一个自然日内多次页面会话产生的真实词义卡评分合并展示。

**选择理由**：用户上午复习 5 张，下午再复习 8 张，晚上查看今日总结应显示 13 次，而不是只显示最后一次页面会话。本次总结和今日总结必须明确区分，不能混为一谈。

### 交付物

1. **`app/Services/SenseReviewTodaySummaryService.php`**（~200 行）— 只读跨会话日汇总 Service。复用 `SenseReviewQueryService::nonResetSenseReviewLogQuery()` 保证 reset 排除规则、sense-only 过滤、用户/语言隔离与 `ReviewStatsService` 和 `SenseReviewLearningFeedbackService` 完全一致。
2. **`app/Http/Controllers/SenseReviewController.php`** — 新增 `todaySummary()` 薄方法：读 user + language，委托 Service，返回 JSON。
3. **`routes/web.php`** — 新增 `GET /reviews/senses/today-summary`（只读，需登录）。
4. **`resources/js/components/Senses/SenseReviewTodaySummary.vue`**（~210 行）— 纯展示组件，emit `close`，无 API 调用，无 ReviewLog 写入，无 FSRS 修改，无卡片队列变更。
5. **`resources/js/components/Senses/SenseReview.vue`** — 集成入口按钮（统计区 + 本次总结页 + 队列清空页）+ dialog + `openTodaySummary()`/`closeTodaySummary()`。
6. **`tests/Feature/SenseReviewTodaySummaryTest.php`** — 18 项后端测试 / 64 assertions。
7. **`tests/js/SenseReviewTodaySummaryGuard.test.mjs`** — 10 项 Node guard 测试。

### 今日时间边界与 timezone 规则

1. 项目无用户时区配置；`config/app.php` line 68 `'timezone' => env('APP_TIMEZONE', 'UTC')`；`.env` 无 `APP_TIMEZONE` → 默认 UTC。
2. 今日边界：`Carbon::today($timezone)`（00:00:00）到 `Carbon::tomorrow($timezone)`（exclusive）。
3. 后端返回 `timezone` / `day` / `day_start` / `day_end`，前端不自行猜测日期边界。
4. 不新增用户时区设置，不修改 `.env`。

### ReviewLog 纳入规则

- 当前登录用户；
- 当前语言；
- `ReviewCard.target_type = sense`（通过 join review_cards + word_senses 过滤）；
- rating ∈ (again/hard/good/easy)；
- reviewed_at 落在今日时间范围。
- 排除：rating=reset、source=reset、legacy word card、其它用户、其它语言。

### 今日总结内容

1. 今日评分总次数；
2. 今日复习过的不同 WordSense 数量；
3. 四种评分分布（忘了/勉强记得/记得/很熟）；
4. 今日遗忘率（again/total，0 次时显示无记录，不显示 0%）；
5. 今日重点词义（max 10，按 sense 聚合）；
6. 今日最近复习记录（max 10，newest first）。

### 重点词义聚合规则（纯事实）

- 今日出现 again；
- 或今日出现 hard；
- 或同一 sense 今日被评分多次；
- 或今日最后一次评分仍为 again/hard。
- 同一 sense 只显示一项聚合结果。排序：again desc → hard desc → total desc。

### 本次复习 vs 今日复习

| 维度 | 本次复习总结 | 今日复习总结 |
|------|-------------|-------------|
| 数据源 | `SenseReviewSessionTracker.js`（纯前端） | 后端 ReviewLog via `GET /reviews/senses/today-summary` |
| 范围 | 当前页面会话 | 跨会话，今日所有真实评分 |
| 刷新 | 重置 | 持久（ReviewLog 是事实来源） |
| 写入 | 不写 ReviewLog/FSRS | 只读，不写 ReviewLog/FSRS |

### 自动测试

- `SenseReviewTodaySummaryTest`: 18 passed (64 assertions)
- Node guard `SenseReviewTodaySummaryGuard.test.mjs`: 10 passed
- `npm run development`: compiled successfully

### MCP Chrome 真实页面验收（两个页面会话累计）

- 会话一：评 again 1 张 → 今日总结累计 35（34 baseline + 1）。
- 刷新页面后会话二：评 good + hard 2 张 → 今日总结累计 37（34 + 3）。
- 本次总结只显示会话二的 2 张；今日总结显示 37。
- 同一 sense 今日重复评分时按 sense 聚合，不重复堆多行。
- 关闭今日总结后可继续复习。
- Console 无新增阻塞错误；Network 仅 localhost/127.0.0.1。

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（除用户评分）；不创建 legacy word card；不自动调 AI；不新增 migration；不读取/修改/提交 `.env`；不 DCP；不 notification script；不修改公开 payload 语义。

### commit

`feat: add daily sense review summary`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 GLM-SenseReviewBatchFeedback-1

**任务**：消除 SenseReview 学习反馈的 ReviewLog N+1 查询。当 `/reviews/senses` 返回 N 张卡时，原 `buildForCard()` 为每张卡单独查询 ReviewLog（约 7 条查询/卡），共 ~7N 条查询。本轮新增批量路径，将整个卡片队列的 ReviewLog 查询降至常数级。

**选择理由**：这是上一轮模块拆分记录的 P1 架构债务。复习队列增大时 N+1 会显著拖慢首屏加载。

### 交付物

1. **`app/Services/SenseReviewLearningFeedbackService.php`**（181→~280 行）— 新增 `buildForCards(array $reviewCardIds): array` 批量方法。一次查询加载所有目标卡片的非 reset ReviewLog，在内存中按 `review_card_id` 聚合。`buildForCard()` 委托 `buildForCards([$id])`，保证单一事实来源（共享 `buildFeedbackFromLogs()` 私有方法）。
2. **`app/Services/SenseReviewCardSerializerService.php`**（266→~310 行）— 新增 `serializeMany(Collection $cards): array`，内部调用 `buildForCards()` 一次，把预计算 feedback map 传给每个 `serialize()`。`serialize()` 新增可选 `learning_feedback` option 以跳过单卡查询。
3. **`app/Http/Controllers/SenseReviewController.php`** — `index()` 改用 `serializeMany()`；`rate()` 保持 `serialize()`（单卡路径，2 条查询）。
4. **`tests/Feature/SenseReviewBatchFeedbackTest.php`** — 22 项测试 / 58 assertions，包括查询数量锁定测试。

### 查询数量优化前后对比

| 场景 | 优化前 | 优化后 |
|------|--------|--------|
| 1 张卡 | ~7 条 review_logs 查询 | 1 条 |
| 5 张卡 | ~35 条 | 1 条 |
| 20 张卡 | ~140 条 | 1 条 |
| 查询数量与卡片数量关系 | 线性增长 | 常数（1） |

### 向后兼容

- `buildForCard(int $reviewCardId)` 保留，委托 `buildForCards([$id])`。
- `serialize(ReviewCard $card, array $options = [])` 签名兼容，`$options` 新增可选 `learning_feedback` key。
- 公开 HTTP payload 结构和语义 100% 不变（`SenseReviewSerializerContractTest` 5 项全绿）。
- `buildForCard()` 与 `buildForCards()` 产出的 payload 完全一致（`test_single_and_batch_produce_identical_payload`）。

### 自动测试

- `SenseReviewBatchFeedbackTest`: 22 passed (58 assertions)
- `SenseReviewLearningFeedbackServiceTest`: 13 passed (41 assertions)
- `SenseReviewSerializerContractTest`: 5 passed (67 assertions)
- `SenseReviewLearningFeedbackTest`: 16 passed (72 assertions)
- `SenseReviewContextualUnderstandingTest`: 8 passed (23 assertions)
- `SenseReviewExampleRotationTest`: 14 passed (39 assertions)
- `SenseReviewDailyLimitsTest`: 17 passed (81 assertions)
- `ReviewFsrsTest`: 63 passed (374 assertions)
- `SenseReviewTodaySummaryTest`: 18 passed (64 assertions)
- Node tests: 22 passed (12 SessionTracker + 10 TodaySummaryGuard)
- `npm run development`: compiled successfully

### MCP Chrome 架构回归

- 页面正常加载（serializeMany 批量路径）✓
- 今日复习总结正常打开和关闭 ✓
- Console 仅预存在 WebSocket 消息 ✓
- Network 仅 localhost/127.0.0.1 ✓
- today-summary 接口 GET 200 ✓

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（除用户评分）；不创建 legacy word card；不自动调 AI；不新增 migration；不读取/修改/提交 `.env`；不 DCP；不 notification script；不修改公开 payload 语义；不改变评分流程；不修改 due_at 计算。

### commit

`perf: batch sense review learning feedback queries`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 GLM-SenseReview-UnifiedLearningReport-AndRatingPresentation-1000-2

**任务**：架构收敛 + 前端产品开发 + 小型后端展示契约调整。两步交付：Task B（架构收敛）+ Task A（统一学习报告入口）。

**选择理由**：SenseReview 页面此前散落四个独立报表按钮 + Session Summary 一个额外入口，文案/状态分散；评分 hard 标签前后端不一致（后端"勉强"，前端"勉强记得"/"困难"混用）。本轮把报表元数据/评分展示契约集中到两个纯配置文件，统一 ReportCenter 为 v-model=boolean + 内部状态契约，并把页面入口收敛为单一"学习报告"按钮 + 报告首页选择。

### Task B 交付物（架构收敛）

1. **`resources/js/components/Senses/SenseReviewReportCatalog.js`**（新增，纯配置）— 前端四个报表元数据的唯一来源（key/title/description/icon/color/endpoint/component/payloadProp/maxWidth/loadingText）。不调 API、不访问 Vuex、不写状态。
2. **`resources/js/components/Senses/SenseReviewRatingPresentation.js`**（新增，纯配置）— 前端四个评分（again/hard/good/easy）的 label/color/hotkey/score 唯一来源。hard → 勉强记得，score 2。提供 `labelForRating()`/`colorForRating()`/`hotkeyHintText()`。
3. **`app/Services/SenseReviewRatingContract.php`**（修改）— `hard` 标签 `勉强` → `勉强记得`。score 仍为 2，rating value 仍为 `hard`，allowed ratings 顺序不变。无 DB/Auth/config/FSRS。
4. **`resources/js/components/Senses/SenseReviewReportCenter.vue`**（重写）— 契约从 `v-model=activeReport(string)` 改为 `v-model=open(boolean)`。内部拥有 `selectedReportKey/loading/error/payload/requestSequence`。首页 `selectedReportKey=null` 不发 GET；选中报告后才请求；返回列表归零；关闭彻底清理。requestSequence 防止异步响应串台；同一报告 loading 中不重复请求。
5. **`resources/js/components/Senses/SenseReviewRatingControls.vue`**（重写）— 改为消费 `RATING_PRESENTATION` 循环渲染按钮 + `hotkeyHintText()` 提示。
6. **`tests/js/SenseReviewRatingPresentationGuard.test.mjs`**（新增，12 项）— 跨前后端契约 guard：PHP/JS rating 顺序、英文值、中文标签、score、hard 标签=勉强记得、hard score=2、前端无孤立"勉强"文案、Catalog 4 个报告顺序。
7. **现有 guard 测试同步更新**：ReportCenterGuard（14 项）、SevenDayTrendGuard、TodaySummaryGuard、DailyReportGuard、ThirtyDayCalendarGuard 均改为校验 `reportCenterOpen` + Catalog endpoint。

### Task A 交付物（统一学习报告入口）

1. **`resources/js/components/Senses/SenseReview.vue`**（精简）— 删除四个独立报表按钮 + Session Summary 额外入口；仅保留单一"学习报告"按钮 `@click="reportCenterOpen = true"`。
2. **ReportCenter 首页 UI**（已随 Task B 落地）— 2×2 网格四个报告选择卡，含标题/说明/图标，首页零 GET 请求。
3. **报告内部导航** — 保留关闭按钮 + 新增"返回报告列表"；返回不关闭弹窗；不允许多 dialog；不使用 `$refs`。
4. **30 天日历强度语义保留** — 主颜色继续由 `day.total_reviews / maxDayTotal` 决定，不依赖 forget_rate/stability_rate。
5. **全文案统一** — 评分按钮、快捷键提示、本次复习总结、今日复习总结、今日学习日报、近 7 天趋势、近 30 天日历、日期详情、学习反馈、后端 rating label 全部统一 hard → "勉强记得"。

### 自动测试

- SenseReviewRatingContractTest: 9 passed (30 assertions)
- SenseReviewReportPeriodServiceTest: 12 passed (42 assertions)
- SenseReviewDailySeriesBuilderTest: 13 passed (62 assertions)
- SenseReviewSevenDayTrendTest: 32 passed (171 assertions)
- SenseReviewThirtyDayCalendarTest: 32 passed (521 assertions)
- SenseReviewTodaySummaryTest: 18 passed (64 assertions)
- SenseReviewDailyReportTest: 26 passed (84 assertions)
- SenseReview (整套): 284 passed (1487 assertions)
- ReviewFsrsTest: 63 passed (374 assertions)
- 6 个 JS guard 测试: 74 passed（RatingPresentation 12 + ReportCenter 14 + ThirtyDayCalendar 14 + TodaySummary 10 + DailyReport 12 + SevenDayTrend 12）
- `npm run development`: compiled successfully
- `php artisan db:doctor`: All checks passed — database is healthy

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（除用户评分）；不创建 legacy word card；不自动调 AI；不新增 migration；不读取/修改/提交 `.env`；不 DCP；不 notification script；不改 rating API 值；不改 due/stability/difficulty/state；不改阅读页；不改 AIStudyCard；不改 tokenizer；不改导入导出；不改 VocabularyBottomSheet。

### commit

- Commit 1: `refactor: centralize sense review report and rating presentation contracts`
- Commit 2: `feat: add unified sense review learning report hub`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 GLM-SenseReview-DailyReportConsolidation-AndMergedProduct-1000-3

**任务**：合并两个今日报告（"今日复习总结" TodaySummary + "今日学习日报" DailyReport）的架构与产品。Task B 优化后端架构（新增纯计算层、收敛 Product Service、删除重复 endpoint、迁移测试）；Task A 开发合并后的前端产品（Catalog 缩减为 3 项、DailyReport 五区域、删除 TodaySummary Vue、每次打开回到首页）。

**选择理由**：上一轮（1000-2）已统一 ReportCatalog / RatingPresentation / ReportCenter v-model 契约，但仍存在两个今日报告（TodaySummary + DailyReport），它们共享 focus_senses / progress_senses / recent_reviews 算法但各自维护一份，违反高内聚低耦合硬原则。用户决定合并为一个"今日学习日报"。

### 交付物

#### Task B（架构优化）

1. **`docs/adr/ADR-0006-sense-review-daily-report-consolidation.md`** — ADR 记录合并原因、重复点、唯一 endpoint、唯一 Product Service、删除策略、测试迁移、查询预算、回滚方式。
2. **`app/Services/SenseReviewDailyInsightBuilder.php`**（新增）— 纯计算层，`build(Collection $logs): array` 返回 `focus_senses` / `progress_senses` / `recent_reviews`。零 DB 查询。统一原 TodaySummary + DailyReport 的 focus/progress/recent 算法。
3. **`tests/Feature/SenseReviewDailyInsightBuilderTest.php`**（新增）— 17 项测试（49 assertions）覆盖纯计算零 DB 查询、focus 规则/排序/limit/last_reviewed_at、progress 两种转换/无效排除、recent 顺序/limit/rating label、空集合、输入顺序不变语义。
4. **`app/Services/SenseReviewDailyReportService.php`**（修改）— 收敛为唯一今日报告 Product Service，五段式 payload（overview / quality / focus_senses / progress_senses / recent_reviews）。使用 `PeriodService::rollingDays(1, tz)` + `AnalyticsQueryService` + `MetricsService` + `InsightBuilder` + `RatingContract`。
5. **`app/Http/Controllers/SenseReviewController.php`**（修改）— 删除 `SenseReviewTodaySummaryService` 依赖注入和 `todaySummary()` 方法。
6. **`routes/web.php`**（修改）— 删除 `Route::get('/reviews/senses/today-summary', ...)`。
7. **`app/Services/SenseReviewTodaySummaryService.php`**（删除）。
8. **`tests/Feature/SenseReviewTodaySummaryTest.php`**（删除）— 覆盖一对一迁移到 `SenseReviewDailyReportTest` + `SenseReviewDailyInsightBuilderTest`。
9. **`tests/Feature/SenseReviewDailyReportTest.php`**（修改）— 32 项测试（106 assertions），包含迁移的 TodaySummary 覆盖（用户隔离/语言隔离/reset 排除/legacy 排除/日期边界/recent_reviews/focus 规则/no ReviewLog write/no FSRS change/rating label/空状态）+ 查询预算测试（phase flag 模式）。

#### Task A（产品开发）

10. **`resources/js/components/Senses/SenseReviewReportCatalog.js`**（修改）— 从 4 项缩减为 3 项（daily-report / seven-day-trend / thirty-day-calendar），删除 today-summary 条目，更新日报描述。
11. **`resources/js/components/Senses/SenseReviewReportCenter.vue`**（修改）— 删除 SenseReviewTodaySummary import / registration / COMPONENT_MAP entry。
12. **`resources/js/components/Senses/SenseReviewDailyReport.vue`**（修改）— 新增第五区域"最近复习记录"（`report.recent_reviews` v-list：lemma / sense_zh / rating_label chip / reviewed_at time），新增 `formatTime(iso)` 方法。
13. **`resources/js/components/Senses/SenseReviewTodaySummary.vue`**（删除）。
14. **`tests/js/SenseReviewTodaySummaryGuard.test.mjs`**（删除）— 覆盖迁移到 `SenseReviewDailyReportGuard.test.mjs` + `SenseReviewReportCenterGuard.test.mjs`。
15. **`tests/js/SenseReviewReportCenterGuard.test.mjs`**（修改）— 17 项测试，新增 reopen-returns-to-home 测试 + Catalog 3 项断言。
16. **`tests/js/SenseReviewDailyReportGuard.test.mjs`**（修改）— 12 项测试，5 区域断言 + 旧标题排除。
17. **`tests/js/SenseReviewRatingPresentationGuard.test.mjs`**（修改）— 3 项报告断言 + today-summary 排除。
18. **`tests/js/SenseReviewSevenDayTrendGuard.test.mjs`**（修改）— 3 项报告断言 + today-summary 排除。
19. **`tests/js/SenseReviewThirtyDayCalendarGuard.test.mjs`**（修改）— 3 项报告断言 + today-summary 排除。
20. **`docs/architecture/sense-review-module-boundaries.md`**（修改）— 五→六层架构、TodaySummaryService/endpoint/Vue 标记 DELETED、DailyInsightBuilder 章节、Catalog 四→三项、Props/Events 表更新。

### 自动测试

- SenseReviewDailyInsightBuilderTest: 17 passed (49 assertions)
- SenseReviewDailyReportTest: 32 passed (106 assertions)
- SenseReviewSevenDayTrendTest: 32 passed (171 assertions)
- SenseReviewThirtyDayCalendarTest: 32 passed (521 assertions)
- SenseReviewReportPeriodServiceTest: 12 passed (42 assertions)
- SenseReviewReportMetricsServiceTest: 21 passed
- SenseReviewAnalyticsQueryServiceTest: 18 passed
- ReviewFsrsTest: 63 passed (374 assertions)
- FsrsSchedulingServiceTest: 9 passed (46 assertions)
- WordSense: 197 passed
- SenseReview (整套): 289 passed
- 5 个 JS guard 测试: 69 passed（DailyReport 12 + ReportCenter 17 + RatingPresentation 12 + SevenDayTrend 12 + ThirtyDayCalendar 16）
- `npm run development`: compiled successfully
- `php artisan db:doctor`: All checks passed — database is healthy
- 全量测试: 1791 passed, 14 failed (pre-existing, unrelated), 14 skipped

### 查询预算

- 空日报：1 次 ReviewLog period query
- 有记录日报：最多 2 次 ReviewLog query（period + sensesReviewedBefore）
- DailyInsightBuilder：0 DB query
- MetricsService：0 DB query
- recent_reviews：不新增查询（additive 字段，来自同一批 ReviewLog）
- 1 / 10 / 50 个词义时查询数量保持常数

### 安全边界确认

- 不改 FSRS；不写 ReviewLog（除用户评分）；不创建 legacy word card；不自动调 AI；不新增 migration；不读取/修改/提交 `.env`；不 DCP；不 notification script；不改 rating API 值；不改 due/stability/difficulty/state；不改阅读页；不改 AIStudyCard；不改 tokenizer；不改导入导出；不改 VocabularyBottomSheet。

### commit

- Commit 1: `refactor: consolidate sense review daily report architecture`
- Commit 2: `feat: merge sense review daily reports`

### 下一步仍由网页端总流程设计师决定

不自动进入下一任务。

- Did NOT enter the next task automatically.

---

## 2026-07-10 Anki-Product-Architecture-Roadmap-Audit-1

**任务性质：** 网页端总流程设计师基于 Anki 官方手册、Anki 官方仓库、LinguaCafe 最新 master 和当前总计划完成产品 / UI / 架构对齐审计，只修改路线图，不改业务代码。

### 审计结论

1. LinguaCafe 已拥有 Anki 主干能力的基础：内容对象与调度对象分离、四档评分、FSRS、ReviewLog、每日上限、管理页和统计。
2. 当前最大差距不在调度算法，而在“复习 → 报告 → 精确管理 → 修订卡片”的闭环。
3. 下一轮优先实施 `Anki-Browser-DeepLink-1`：今日学习日报中的重点词义、进步记录、最近复习均可精确打开对应 sense ReviewCard 管理详情。
4. 近 7 天趋势继续保持滚动 7 天，不引入自然周切换。
5. 后续顺序冻结为：预计间隔 → 撤销评分 → 卡片状态语义分离 → 遗忘卡治理 → 保存筛选 / 自定义学习 → 长期统计 → Preset。
6. 明确不开发通用 Note Type / Card Template 和任意 deck 树；WordSense + 单张 sense ReviewCard 继续作为产品主线。

### 已写入总计划的新增项

- Anki-Review-IntervalPreview-1
- Anki-Review-Undo-1
- Anki-Card-State-1
- Anki-Leech-1
- Anki-Queue-Order-1
- Anki-CustomStudy-1
- Anki-Stats-1
- Anki-Browser-DeepLink-1
- Anki-Browser-SavedSearch-1
- Anki-Browser-Search-1
- LinguaCafe / Anki 对象映射、必须借鉴原则和明确不照搬范围

### 下一步

后续顺序（不自动进入，逐项由网页端总流程设计师批准）：

1. **Browser Search fix** — `Anki-Browser-Search-1-Fix-2000-6` ✅ 已完成（export 422 return type 修复）
2. **Search architecture convergence** — `ReviewCard-Browser-Search-Pipeline-Convergence-2000-7` ✅ 已完成（ADR-0013 执行管道收敛：每请求只 parse 一次, `buildFromCriteria()` 单一执行入口, 旧 `build()` 薄包装, 四入口共用 Criteria, Parser first-occurrence 去重, governance O(1), 不改 V1 结果, 不新增语法; 6 parser 去重 + 16 pipeline feature 测试, 回归 649 passed / 0 failed, MCP Chrome 18/18 PASS）
3. **Card Info** — `Anki-Card-Info-Architecture-And-Development-2000-8` ✅ 已完成（ADR-0014 read model 收敛: 打开详情抽屉只发一个 canonical `GET /review-cards/manage/{reviewCard}/detail` 请求, 不再额外请求 `/logs` `/lifecycle-events` `/leech`; 新增 `app/Services/ReviewCardInfoQueryService.php` 只读编排服务 `build()` 复用 `serializeCard()` 顶层字段 + ReviewLog (card+user+language, reviewed_at DESC, limit 20, 保留所有 source 与 undone 行) + ReviewCardStateEvent (card+user, created_at DESC, limit 20) + `SenseReviewLeechQueryService::describeForCard()` (不复制 Policy, 不调 AI); response additive 增加 `card_info: { review_logs: {items, limit:20}, lifecycle_events: {items, limit:20}, leech }`, 旧 30 顶层字段不变, 旧三端点保留; 前端 `ReviewCardManage.vue` 改造: `openDetail`/`loadDeepLinkDetail` 都走 canonical endpoint, `loadCardInfo()` 单请求 + `detailRequestSeq` 序号守卫防 stale response, `closeDetail()` bump 序号清理, `v-tabs` 三标签 (概览/历史/诊断), 历史标注「最近 N 条」, undone 保留展示, 删除 `loadDetailLeech` dead code, 抽屉级 loading/error/empty; 28 backend tests + 21 frontend guard tests, 回归 669 passed / 0 failed, npm build 成功, db:doctor HEALTHY, MCP Chrome 29/29 PASS; 不改 Browser Search V1 语法, 不改 FSRS/lifecycle/ReviewLog schema, 不新增 migration, 不调 AI, 不在 Card Info 内新增编辑/暂停/归档/重置/删除按钮）
4. **Queue Order** — ✅ Accepted / 生产验收通过（Task 2000-14，网页端最终验收，2026-07-14） (Task `Anki-Queue-Order-Architecture-Research-2000-9A` docs-only + Task `Anki-Queue-Order-Architecture-Optimization-And-Development-2000-9B` docs+feat + Task `GLM-QueueOrder-Production-Closure-And-Custom-Study-Architecture-2000-10A` production closure + Task `GLM-QueueOrder-Executable-Error-Recovery-Tests-And-CustomStudy-Contract-Cleanup-2000-13` recovery helper + Task `GLM-QueueOrder-Final-Accept-And-SenseStudyCard-Presentation-Contract-2000-15` final accept: ADR-0015 corrected 四设置四分类三层架构; `ReviewQueueOrderOptions` 值对象 + `ReviewQueueOrderPolicy` 纯函数 + `ReviewQueueOrderService` 单一排序入口; 4 Anki-aligned settings 全局 user_id=-1; 4 categories intraday/interday/review/new; Mix 确定性均匀交错非随机; stable daily hash 替代 shuffle; FSRS-5 retrievability; 两入口 `/reviews`+`/reviews/senses` 统一; `ReviewService::shuffle()` 删除; `Review.vue` `Math.random()` 删除; `/reviews/rate`+`/reviews/senses/{id}/rate` 返回 `next_card`; 三阶段 daily limits; `/settings/fsrs/queue-order` GET/POST admin middleware; `AdminReviewSettings.vue` 4 下拉框; 59 Unit + 30 Feature + 22 Node guard 测试全绿; 回归 669 passed; npm build 成功; db:doctor healthy. **2000-10A 本轮**: 新增 `ReviewStudyTimezoneService` 统一学习时区; `due_random` 本地日期修复; retrievability 证据链修复 (固定 fixture 交叉验证); `Review.vue` 真正消费 `next_card` + `ignoreDailyLimits` + `ratingLoading`/`ratingRequestSequence` stale guard; `SenseReview.vue` `loadCardsRequestSequence` stale guard; `reviewedTodayCount()` 改用统一学习时区; 新增 `ReviewQueueOrderNextCardTest` (9) + `ReviewQueueOrderNextCardGuard.test.mjs` (12) + `ReviewStudyTimezoneServiceTest` (11) + 扩展 `ReviewQueueOrderServiceTest` (+10) + 扩展 `ReviewQueueOrderFrontendGuard` (8→21) + 扩展 `SenseReviewStackUndoGuard` (35→48); MCP Chrome 正常评分证据通过 (Legacy + Sense, 2 viewport); **Task 2000-13**: 失败分支用可执行 ReviewRatingRecovery.test.mjs 作为行为证据; 新增纯 JS recovery helper ReviewRatingRecovery.js 被 Legacy+Sense Review 真实调用. **Task 2000-13 Accept 被撤回 (历史)**: 网页端复核发现 Legacy reload 锁状态缺口. **Task 2000-14 lock fix (最终关闭)**: helper 在 reloadQueue 返回后重新确认锁定 (re-lock after sync reset); 并发调用返回同一 `inFlightPromise`; reloadQueue sync throw 经 try/catch 安全释放 inFlight + 解锁; Legacy 四个评分按钮绑定 `:disabled="ratingLoading"`; 可执行测试 20 cases/34 assertions; guard test 24 tests. **网页端总流程设计师 2026-07-14 正式 Accept，Queue Order 生产关闭。** 不改 FSRS/ReviewLog/lifecycle/rating/AI/migration）
5. **Custom Study 1A** — 自定义学习会话第一版（架构契约已冻结：ADR-0016 + `custom-study-1a-implementation-plan.md`；**Phase 1 ✅ Accepted；Phase 2A ✅ Accepted；Phase 2B ✅ Accepted（Task 2000-18：`EloquentChapterLocator` + `SourceChapterQuery` + `LeechAttentionQuery` + `CustomStudyQueryService`，14+27+27+18=86 tests）；Phase 3A（`CustomStudySessionState` 不可变值对象 + `CustomStudySessionTokenService` 加解密+验证 + `CustomStudySessionStateException` + 2 unit tests）代码与测试完成（Task 2000-19），等待网页端总流程设计师验收**；Phase 3B / Phase 4-7 未开始，需网页端总流程设计师单独授权才能进入。授权后建议开发顺序：Phase 1 值对象/校验器（已实现） → Phase 2 查询服务（2A+2B 已实现） → Phase 3A Session State + Token Service（已实现） → Phase 3B（未定义） → Phase 4 会话编排策略（PreviewPolicy + SessionService） → Phase 5 前端（包含 **共享卡面抽取阶段**：`Task CS-11.5: Shared card presentation component SenseStudyCard.vue`，落位 ADR-0016 §20，含阅读式 token 句子展示 + 空答案字段隐藏契约 + AI 译文卡面需求登记 + 章节选择器只显示存在候选卡的章节契约 ADR-0016 §21） → Phase 6 路由集成 → Phase 7 端到端 MCP Chrome 验收。SenseStudyCard 抽取阶段必须复用 `SenseSentencePreview.vue`，禁止 v-html，禁止 tokenizer 重调用，禁止新增 API/migration。**整体功能尚不可使用**。）
6. **Saved Search** — 保存搜索
7. **today-only limits** — 今日限制
8. **Study Overview** — 学习概览

不提前把 Custom Study、Saved Search、today-only limits、Study Overview 或其他后续任务标记完成。Card Info (Task 2000-8) 已完成。Queue Order (Task 2000-9A 架构研究 + Task 2000-9B 架构修正+开发 + Task 2000-10A 生产收口 + Task 2000-13 recovery helper + Task 2000-14 lock fix 最终关闭) ✅ Accepted / 生产验收通过（Task 2000-14，网页端最终验收，2026-07-14）。Custom Study 1A 架构契约已冻结（ADR-0016 + implementation plan）；Phase 1 ✅ Accepted；Phase 2A ✅ Accepted；Phase 2B ✅ Accepted（Task 2000-18：`EloquentChapterLocator` + `SourceChapterQuery` + `LeechAttentionQuery` + `CustomStudyQueryService`，86 tests）；Phase 3A 代码与测试完成（Task 2000-19：`CustomStudySessionState` + `CustomStudySessionTokenService` + `CustomStudySessionStateException` + 2 unit tests），等待网页端验收；Phase 3B / Phase 4-7 未开始，需网页端总流程设计师单独授权才能进入。

Anki-Review-Undo-1 已完成（commit 71d1670 Task B + Task A commit, ADR-0009）。最终架构：`ReviewCardFsrsSnapshotService` 纯快照（8 FSRS 字段 capture/restore/matches/fingerprint/validate）→ `ReviewCardService::recordReview()` 事务化评分（DB::transaction + lockForUpdate + before/after snapshot + session_id）→ `SenseReviewUndoPolicy` 纯策略（10 blocked reasons）→ `SenseReviewSessionActionService` 只读 timeline → `SenseReviewUndoService` 事务撤销（幂等 + 冲突 + 栈式）→ 前端 sessionStorage UUID + snackbar + 操作历史抽屉 + Ctrl/Cmd+Z 统一 `requestUndo()`。ReviewLog 不删除，只标记 undone；产品统计通过 `scopeNotUndone` 排除 undone，审计查询保留 undone。

Anki-Review-IntervalPreview-1 已完成（commit a009d2a + b927ea5, ADR-0008）。最终架构：FsrsSchedulingService::previewAllRatings() 纯投影 → SenseReviewIntervalPreviewService 访问校验 → GET /reviews/senses/{reviewCard}/interval-preview 只读端点 → SenseReview.vue 请求编排 → SenseReviewRatingControls.vue 纯展示。Preview 与真实评分共用同一 schedule() 核心方法。
