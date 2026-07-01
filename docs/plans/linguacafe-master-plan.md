# LinguaCafe 总控大计划

> **最后更新**：2026-06-30 (Docs-ServiceBoundaryWorkflow-1)
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
| Lemma-Origin-DisplayUI-1 | ✅ 已完成 — 查词栏/浮动弹窗显示 surface → lemma 箭头格式。改 VocabularySideBox.vue 和 VocabularyBox.vue。geese → goose / better → good / best → good 均通过 MCP Chrome 验证。不改后端/WordSense/ReviewCard/FSRS。 |
| Docs-LemmaDisplayScopeFix-1 | ✅ 已完成 — 修正 DisplayUI 架构文档中 VocabularyBox.vue 范围矛盾（"第一轮只做"含改 VocabularyBox.vue，"不改"清单又含不改 VocabularyBox.vue）。已移除"不改"清单中的 VocabularyBox.vue，重新编号。 |
| Lemma-Origin-DisplayArchitecture-1 | 原词 + 原形显示功能架构核验。用户产品方向：阅读页/查词处显示原词+原形，文案格式为 geese → goose。本轮只做架构核验，不实现 UI。已核验阅读页/查词栏/lookup 数据来源。已用 MCP Chrome 查看当前真实界面。不改 WordSense / ReviewCard / FSRS。不改 tokenizer。属于 lemma/origin 用户体验功能的架构先行阶段。 |
| Lemma-Origin-IrregularOverride-1 | 最小修复英语不规则 lemma override。已修复 geese / better / best，通过 doctor human/JSON 验收，已通过 MCP Chrome 真实浏览器打开 /tokenizer/health 验收。已顺手补充 To-do list 执行规则和 MCP Chrome 真实测试规则。不改 WordSense / ReviewCard / FSRS。不属于 FSRS 功能本体，属于 lemma/origin 质量保护。 |
| Lemma-Origin-DoctorIrregular-1 | 已让 php artisan tokenizer:doctor 读取 /tokenizer/health 的 english_irregular，在 human/JSON 输出中展示 cases。任一 case 失败时 doctor 返回失败。不改 tokenizer 实际行为。不属于 FSRS 功能本体，属于 lemma/origin 架构保护与质量治理。 |
| Lemma-Origin-HealthIrregular-1 | 已扩展 /tokenizer/health 的 English lemma 健康检查，覆盖 10 个不规则词形（ran/running/mice/geese/better/best/went/children/studies/was）。不改 tokenizer 实际行为。已顺手补充任何新功能必须先做架构的规则。不属于 FSRS 功能本体，属于 lemma/origin 架构保护与质量治理。 |
| Lemma-Origin-Architecture-1 | 已新增 docs/plans/lemma-origin-architecture.md。只做架构核验，不实现新功能。已核验 English tokenizer / lemminflect fallback / 导入链路 / 风险边界。已写入第一批 lemma 验收样例。已顺手补充 CodeBuddy 只给事实不直接给建议的协作规则。已顺手补充 OpenCode 小任务打包规模规则（10 个清晰子项）。不进入 lemma 实现。不属于 FSRS 功能本体，属于架构先行与质量治理。 |
| Docs-WorkflowBatchingAndDualScout-1 | 已写入 OpenCode 不能只做孤立小文档补丁、小文档任务必须合并多个小任务或搭载大任务、大任务搭载小任务时应搭载多个同类低风险小任务而非一个零散补丁、OpenCode 和 CodeBuddy 可同轮做代码侦查/风险分析/漏洞分析并扮演不同岗位角色。不属于 FSRS 功能本体，属于协作流程与任务调度规则。 |
| Docs-ArchitectureParallelWorkflow-1 | 已写入 OpenCode + CodeBuddy 并行工作流、CodeBuddy 每轮必须指定 skill 的规则、WorkBuddy 作为产品经理/QA 员工的定位、视频架构思想（先定边界再实现、控制复杂度扩散、避免状态分叉/隐式行为/跨文件耦合）、批量彻底删除的产品方向（显示待删除列表、不要求输入确认词、必须弹窗确认）。不属于 FSRS 功能本体，属于架构治理和协作流程规则。不进入 ReviewCardManage 代码实现。 |

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
