# LinguaCafe 当前工作台 / Codex 交接临时文档

> **最后更新**：2026-07-03 (Codex-MorphologyMatrix-ImportRegression-1)
> **文档入口**：先读 `docs/DOCUMENTATION_INDEX.md`，再读本文。
> **旧交接文档**：`docs/CODEX_HANDOFF.md`（2026-06-23）和 `docs/handovers/2026-06-24-c12-c-handoff.md` — 这些是历史交接文档。Codex 新任务应以本文为准。
> **历史索引**：`docs/HISTORY_INDEX.md` 记录旧 status / next task / FSRS phase 文档，避免上下文污染。

---

## 1. 当前阶段一句话

架构收口阶段已结束（总体架构收口 100%）。AI 示意卡 V1-V4 已实现。近期已新增多例句池、复习页题面例句轮换、答案后补充例句不重复、多来源溯源 carousel、Finished reading 确认弹窗、词形原型绑定（surface 保留 + lemma 优先搜索 + 用户修正后生效）、已学词义候选面板、熟词僻义前置结构（不调 AI）、例句池性能优化（消除 N+1 + 批量预加载）。本轮补齐形态变化测试矩阵、项目可控文章 fixture 导入回归、known-sense/FSRS 只读护栏、前端源码级 UI guard。下一步仍应由网页端总设计师选择，不自动进入 AI 真实推荐、AI 释义生成或完整闭环，也不自动进入阅读中刷卡。

## 2. 最近已完成任务

| 任务 | 一句话说明 |
|------|-----------|
| FsrsReschedulePreviewService-ContractScouting-1 | 只读侦查 FSRS 重排预览/确认/应用链路，输出 18 个风险点 |
| FsrsReschedulePreviewService-GapContractTests-1 | 补 5 个 preview + 5 个 confirmPreflight 缺口契约测试 |
| FsrsRescheduleGapContractTests-ScopeFix-1 | 收口越界修改的测试文件 |
| TextBlockService-CreateNewEncounteredWordsContractTests-1 | 12 个 characterization tests 锁定 encountered_words 创建行为 |
| EncounteredWordCreationService-Extract-1 | 从 TextBlockService 提取 encountered_words 写入到独立 Service |
| WordSenseService-DestroyRestore-RiskAudit-1 | 审计 WordSense 删除/归档/恢复链路，输出 14 个风险点 + 总设计师复判 |
| DesignerWorkflow-CodeBuddyRiskRoleAndPlanRefresh-1 | 修正 CodeBuddy 风险角色规则 + 大计划修正 |
| WordSenseService-DestroyRestoreContractTests-1 | 15 个 contract tests 锁定归档/删除/恢复语义 |
| ReviewCardDeleteSnackbar-HistoryPreservedCopy-1 | 补管理页删除成功 snackbar/fallback 文案，MCP Chrome 验收 |
| Codex-ArchitectureOptimizationLoop-1 | Codex 增量架构总审计；新增 5 个 TextBlock phrase/index characterization tests；更新 master plan / hotspot audit / 当前工作台 |
| Codex-ArchitectureFinalGoalMode-1 | Codex 面向 sense-only 最终架构目标做增量审计；补 2 个 FSRS confirmAndApply 拒绝写入 contract tests；更新 master plan / hotspot audit / 当前工作台 |
| OpenCode-ArchitectureTargetMode-Batch1 | 综合推进：SenseReview 到期卡真实显示并评分（MCP Chrome）、TextBlock fallback 只读侦查、ReviewCardManage logs payload 只读侦查。阶段性完成，仍有缺口：pending occurrence 写入未跑通、current-working-handoff 上轮漏更新、TextBlock fallback 缺测试、logs payload 缺 contract tests。 |
| Codex-SpecToHarnessHardeningTargetMode-1 | 将两个软规则转成测试护栏：新增 TextBlock fallback tokenizer 单元测试；补 ReviewCardManage logs payload 精确字段/日期格式与同卡 user/language 过滤 contract tests；不改业务代码。 |
| Codex-SenseReviewRealWorkflowHardeningTargetMode-1 | 将 SenseReview 真实页面 smoke 转为可复验 harness：新增 `smoke:sense-review-data` 命令（只接受已有用户、不创建账号、不接收密码、marker 可识别、不清库）；新增命令 feature test 锁定 marker 形状；新增 smoke playbook。MCP Chrome 真实验收覆盖评分/More/查看原文/确认/改绑/拒绝/忽略/新建。不改 Vue/FSRS/WordSense 语义/ReviewLog/DB schema/AI study card。 |
| Codex-FinalArchitectureClosureTargetMode-1 | 最终架构收口。新增三份冻结文档：`final-architecture-closure-report.md`（收口报告，总体架构收口 81%→100%）、`ai-study-card-v1-frozen-plan.md`（AI 示意卡第一版路线冻结，AI 示意卡规划 25%→55%）、`frontend-review-entry-unification-plan.md`（前端复习入口统一路线冻结，前端入口整理 50%→65%）。MCP Chrome 只读复核阅读页/查词侧栏/AI 阅读辅助按钮/复习入口/词义确认入口/复习卡管理入口。不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema。 |
| Codex-AIStudyCardV1-And-ReviewEntryUnification-1 | AI 示意卡第一版最小实现 + 前端复习入口统一第一轮。新增 `ai_study_card_pending_items` pending 表、Model/Service/Controller/POST route、侧栏「待 AI 解释」按钮、幂等与隔离 tests；首页「开始复习」和导航「复习」进入 `/reviews/senses`，旧 `/senses/review`、`/review-cards/manage`、`/review/false/-1/-1` 保留。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| GLM-AIStudyCardV2-GenerationLoop-1 | AI 示意卡 V2 生成闭环第一阶段。新增 `GET /ai-study-card/pending-items`（支持 chapter_id 过滤）、`POST /ai-study-card/pending-items/{id}/dismiss`、`POST /ai-study-card/pending-items/{id}/restore`；改造 `createOrGetPending` 支持 dismissed 恢复；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释列表面板、取消按钮、生成前预览弹窗雏形。新增 16 个 V2 feature tests（23 tests / 105 assertions 全绿）。MCP Chrome 真实页面验收 24 项全通过。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| GLM-AIStudyCardV3-SafePreviewPackage-1 | AI 示意卡 V3 安全生成包。扩展 `GET /ai-study-card/pending-items` 支持 `status=pending\|dismissed\|all` 过滤；新增 `POST /ai-study-card/pending-items/preview-package` 后端安全包接口（schema_version=ai-study-card-preview-package-v1，含 selected_items/generation_rules/safety_flags）；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增待解释/已取消视图切换、已取消项恢复按钮、真实预览弹窗（用户已选词列表/来源句子/章节位置/勾选取消/全不选禁用生成/AI 推荐词占位/安全说明/生成规则）、「准备生成」按钮触发后端安全包、JSON 展示与复制按钮。新增 14 个 V3 feature tests（37 tests / 184 assertions 全绿）。MCP Chrome 真实页面验收 28 项全通过。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| GLM-AIRecommendationConfirmationLoop-V4-1 | AI 示意卡 V4 AI 推荐词确认闭环。新增 `POST /ai-study-card/pending-items/final-candidates-package` 后端接口（schema_version=ai-study-card-final-candidates-v1，含 user_selected_items/ai_recommended_selected_items/ai_recommended_unselected_items/dedupe_summary/generation_rules 5条/safety_flags 6条；三重隔离 + 后端二次去重 + 空结果 422 + 数量上限保护）；在 `VocabularySideBox.vue` / `VocabularyBox.vue` 新增 V4 完整 UI（粘贴 AI 推荐词 JSON 文本框 + 解析/清空按钮 + 解析错误提示 + 解析摘要 + AI 推荐词列表默认 unchecked + 全选/全不选 + 用户已选词/AI 推荐词视觉分区 + 「生成最终候选包」按钮 + JSON 展示与复制按钮）。新增 18 个 V4 feature tests（56 tests / 294 assertions 全绿）。MCP Chrome 真实页面验收 33 项全通过。不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不改 FSRS/删除归档恢复。 |
| Codex-LegacyEntry-FinishedReading-ExampleGuard-1 | 旧版入口清理执行 + Finished reading 安全护栏 + 阅读例句路线冻结。普通查词组件不再展示“旧词条释义 / 旧版释义 / 旧版示意 / legacy word review”入口文案；后端 legacy 兼容层、`ReviewCard::TARGET_WORD`、legacy route/service/tests 保留。新增 `FinishedReadingSafetyTest` 锁定 Finished reading 只把当前用户/当前语言 `stage=2` 黄词设为 known，且不改绿词 stage、WordSense、ReviewCard、ReviewLog、FSRS；发现并修复自动 known 分支缺少 `language` 过滤。新增 `LegacyEntryUiGuardTest` 防止旧入口文案回到普通查词组件。新增 `reading-inline-review-and-example-pool-plan.md`，只冻结阅读中刷卡/多例句轮换路线，不实现。 |
| Trae-ExamplePool-ReviewRotation-SourceCarousel-1 | 多例句池 + 复习页题面例句轮换 + 答案后补充例句不重复 + 多来源溯源 carousel + Finished reading 确认弹窗。新增 `WordSenseExamplePoolService`（复用 `WordSenseOccurrence` + card example fallback，不新增 migration，不调用 AI），`SenseReviewCardSerializerService` payload 新增 `example_candidates` / `example_candidates_count` / `supplementary_example`，稳定 seed 轮换（review_card_id + fsrs_reps + day-of-year，crc32）；`SenseSourceContextService` 新增 `sourceContextList` 方法 + 新路由 `GET /senses/{id}/source-context-list`，`SenseExampleDialog.vue` 支持来源 1/N 切换；`TextReader.vue` 「完成阅读」按钮新增确认弹窗（说明影响 + 取消/确认）。新增 18 个 feature tests（WordSenseExamplePoolTest 12 + SenseSourceContextMultiSourceTest 6，全绿）。MCP Chrome 真实页面验收：登录 → /reviews/senses 题面正常 → 查看答案 → 单例句无重复补充 → 单来源无切换按钮（符合规则） → 阅读页完成阅读确认弹窗 → 取消不执行 → console/network 正常。**阅读中刷卡仍未实现；AI 不生成例句。** |
| Trae-LemmaKnownSenseBridge-1 | 词形原型绑定 + 已学词义候选 + 熟词僻义前置结构 + 例句池性能优化。新增 `WordSenseKnownSenseService`（只读：`listConfirmedSensesForLemma` + `knownSenseLookupPayload`，批量预加载 occurrence count，不写 ReviewLog/WordSense/ReviewCard/FSRS，不调 AI）；新增端点 `GET /senses/known-sense-lookup?lemma=...&language=...`；`WordSensesList.vue` 新增「已学词义候选」面板（confirmed WordSense 列表，含 sense_zh/sense_en/pos/FSRS chip/复习次数）和「熟词僻义」前置 info alert（明确标注「未调用 AI 判断」）；`WordSenseExamplePoolService::exampleCandidates()` 消除 N+1（批量 whereIn 预加载 Chapter）；`SenseSourceContextService::sourceContextList()` 消除循环内 findChapterById（批量 whereIn 预加载 + limit 12 + PHP unique+take(3) 保持跨数据库兼容）。surface/lemma 绑定：阅读页点词显示 surface（如 geese）+ lemma（如 goose）+ [修改] 入口；搜索/添加新释义优先使用 lemma（搜索框 value=lemma）；用户通过 VocabularySideBox saveLemma 修正 lemma 后，后续添加新释义使用修正后的 lemma；不无条件绑定 published→publish。新增 13 个 feature tests（WordSenseKnownSenseBridgeTest）。MCP Chrome 真实页面验收：登录 → 阅读页点击 geese → 显示 geese→goose→[修改] → 添加新释义搜索框 value=goose → 创建 confirmed sense 后重新点击 geese → 「已学词义候选」面板显示 + 「熟词僻义」提示显示 → /senses/known-sense-lookup 端点 200 → 复习页显示答案 + 例句正常 → 查看原文多来源溯源正常 → console 仅预期 WebSocket 降级 → network 全 200。**阅读中刷卡评分仍未实现；AI 不生成例句；不写 ReviewLog；不改 FSRS；熟词僻义仅前置结构，AI 判断仍未实现。** |
| Codex-MorphologyMatrix-ImportRegression-1 | 形态变化测试矩阵 + 文章 fixture 导入回归 + lemma bridge 覆盖增强。新增 `MorphologyMatrixImportRegressionTest`：覆盖规则复数（ways/technologies）、不规则复数（mice/children）、第三人称（studies/watches）、过去式（ran/went）、过去分词（written/published）、进行时（running/studying）、比较级/最高级（better/worse）、词性歧义（used/broken）；项目可控文章 fixture 的 `processed_text` 保留 surface/lemma/pos；known-sense lookup 只按 lemma 返回 confirmed WordSense，保持 user/language/status 隔离，`read_only=true`，不写 ReviewLog，不创建 ReviewCard，不改 FSRS；`published/running/used/broken` 等歧义词不自动绑定、不自动刷卡。新增 `MorphologyMatrixUiGuardTest` 锁定 `WordSensesList.vue` 仍显示 surface+lemma、add-sense payload 仍用 `lemma: effectiveLemma` + `surface_form: surfaceWord`、熟词僻义文案仍标注未调用 AI、旧入口仍隐藏。**阅读中刷卡评分仍未实现；AI 判断熟词僻义仍未实现；不写 ReviewLog；不改 FSRS；AI 不生成例句。** |

## 3. 当前未最终关闭的事项

本节只放真实未完成事项。已完成任务详情进入 `docs/plans/linguacafe-master-plan.md`，历史材料进入 `docs/HISTORY_INDEX.md`。

- **架构收口阶段已结束**（Codex-FinalArchitectureClosureTargetMode-1）：总体架构收口 100% 不代表全项目完成，只代表旧系统地基已检查、sense-only 复习主线边界清楚、AI 示意卡第一版可进入开发设计。详见 `docs/plans/final-architecture-closure-report.md`。
- **AI 示意卡 V3 安全生成包已实现**：详见 `docs/plans/ai-study-card-v3-safe-preview-package-plan.md`。已取消项恢复按钮、真实预览内容、安全生成包已落地。
- **AI 示意卡 V4 AI 推荐词确认闭环已实现**：详见 `docs/plans/ai-recommendation-confirmation-loop-plan.md`。AI 推荐词粘贴导入、去重、默认不选、用户确认、最终候选包已落地。AI 真实推荐（自动调 AI）、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。
- **前端复习入口统一第一轮已实现**：详见 `docs/plans/frontend-review-entry-unification-plan.md`。首页"开始复习"和导航"复习"指向 `/reviews/senses`；`/senses/review`、`/review-cards/manage`、legacy `/review/false/-1/-1` 保留。
- **Codex-ProjectDocsGovernanceTargetMode-1**：
  - 本轮只做文档治理，不改业务代码和测试；
  - 新增入口索引、历史索引、ADR-0002、spec→harness 候选清单；
  - 旧 `CURRENT_STATUS` / `NEXT_TASK` / `FSRS_PHASE*` / 旧 handoff 已降权为历史参考；
  - 完成后仍由网页端总设计师选择下一任务，不自动进入下一阶段。
- **AIStudyCardGenerationWorkflow**：
  - V1 pending marker 已实现，V2 列表/取消/预览雏形已实现，V3 已取消视图/恢复按钮/真实预览/安全生成包已实现，V4 AI 推荐词粘贴导入/去重/默认不选/用户确认/最终候选包已实现；
  - AI 真实推荐（自动调 AI）、AI 释义生成、AI 示意卡生成闭环仍未实现；
  - 后续任何生成 / 推荐 / 复习卡联动前必须先过 Architecture Gate 与 ADR，不删除现有 SenseMappingReview / SenseReview 能力，不删除 legacy word card 兼容层。
- **旧版入口与 Finished reading 护栏**：
  - 普通查词 UI 的旧版释义入口已完成最小隐藏，兼容字段和后端 legacy 兼容层未删除；
  - Finished reading 自动 known 分支新增测试护栏，并修复当前语言过滤缺口；
  - 本地 MySQL 未启动时 PHP Feature tests 会被数据库连接阻塞，不能用 SQLite 可靠替代（旧迁移含 MySQL collation）。

## 4. 当前产品决策

| 决策 | 内容 |
|------|------|
| 归档语义 | 归档 = 暂停复习卡，不是删除 |
| archiveSense occurrence 引用 | 不清空 review_card_id 和 auto_fsrs_allowed 是当前接受的行为（与 removeSenseFromReviewSystem(false) 不一致但已做产品取舍） |
| 永久删除 ReviewLog | 默认保留复习历史，当前不提供"同时删除复习历史"选项 |
| EncounteredWord restore | 使用 encountered_word_id 匹配是安全设计，不是 bug |
| 归档 restore | 归档不恢复 EncounteredWord 是正确语义（归档 ≠ 删除） |
| ReviewLog 保留 | 不是 bug，是有意设计 |
| 删除提示文案 | 必须提示"复习历史已保留" |
| rejectSense() | 遗留方法，低优先级，无调用方 |
| 复习主入口统一 | 前端不展示"词义确认/词义复习"内部概念，主复习入口统一叫"复习" |
| AI 译文 ≠ AI 示意卡 | AI 译文只服务阅读理解，不自动生成复习卡 |
| 用户选词优先 | 阅读页点击单词或拖动选择词组，手动添加释义后直接生成可复习示意卡 |
| 待 AI 解释词 | 用户可以把不会解释的词标记为"待 AI 解释" |
| AI 推荐词不重复 | AI 推荐词必须排除用户已选择的词 |
| AI 推荐词默认不选 | "AI 生成示意卡"弹窗中 AI 推荐词默认全不选，提供"全选"按钮 |
| 确认后才生成 | 只有被用户确认的 AI 推荐词才进入示意卡生成 |
| legacy word card | 只作兼容，不作为新功能和日常复习主线 |
| Finished reading | 保留章节完成能力；自动 known 只处理当前用户/当前语言黄色新词，不写 WordSense/ReviewCard/ReviewLog/FSRS，不等同复习完成 |
| 阅读中刷卡 / 多例句轮换 | 路线已冻结为 WordSense-only 与真实来源例句池；当前未实现 |

## 5. 下一候选方向

> 架构收口已结束。下一阶段应进入明确的最小实现任务，不再继续无限侦查。每个候选前必须先过 Architecture Gate 与 ADR（如涉及接口、DB、FSRS、删除/归档/恢复）。

### A. AI 示意卡后续：推荐弹窗 / 生成闭环前置设计

- 第一版 pending marker 已完成：阅读页点词 → 侧栏"待 AI 解释"按钮 → 后端只记录 pending item。
- 下一阶段不应直接生成复习卡；应先设计 AI 推荐弹窗、用户确认、排重、默认不选、失败回滚与测试护栏。
- 继续禁止：自动调 AI、自动生成 WordSense/ReviewCard/ReviewLog、改 FSRS、改删除/归档/恢复、删除 legacy word card 兼容层。

### B. 前端复习入口统一后续收口

- 第一轮已完成：首页"开始复习"与导航"复习"进入 `/reviews/senses`，旧路由全保留。
- 后续只可小步收口：评估 `/senses/review` 是否 alias 到 `/reviews/senses`，以及复习卡管理是否进一步移入高级/设置区域。
- 任何删除页面或下线 legacy word review 的动作必须走 ADR。

### C. TextBlockService-RemainingExtractionScouting-1

- phrase/index 最小 characterization tests 已在 Codex-ArchitectureOptimizationLoop-1 中补充；
- 后续若继续该方向，建议聚焦 tokenizer/fallback 或旧 ReaderDataService fallback 分支的只读侦查；
- 不直接拆，先看剩余职责和测试缺口；
- 适合 Codex 接盘，但必须先给禁止范围和验收命令。

### D. FsrsRescheduleConfirmApply-SafeWriteContractTests-1

- 拒绝写入路径的最小 contract tests 已在 Codex-ArchitectureFinalGoalMode-1 中补充；
- 已锁定 `apply=true` 高风险未二次确认、blocked 超量两类场景均不写 ReviewCard、不建 snapshot、不写 ReviewLog；
- 后续若继续该方向，建议只读侦查 stale candidate / params 变化 / snapshot appliedCount=0 等剩余边界，不直接改 FSRS 语义。

### E. SpecToHarness-Hardening-1

- 参考 `docs/plans/spec-to-harness-candidates.md`；
- 只选择一个软约束转测试 / smoke / harness；
- 不把候选清单当作实现授权。

### F. Codex 大任务候选

由网页端总设计师决定。Codex 任务不应从脏上下文开始，必须先看：
1. `docs/plans/current-working-handoff.md`（本文）
2. `docs/plans/linguacafe-master-plan.md`
3. `docs/plans/vibe-coding-collaboration-rules.md`
4. `docs/plans/repo-architecture-hotspot-audit.md`
5. `docs/plans/final-architecture-closure-report.md`（架构收口结论）
6. 按需读 `docs/DOCUMENTATION_INDEX.md`、`docs/HISTORY_INDEX.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`

## 6. Codex 交接原则

1. Codex 可以接复杂任务，但不能取消边界。
2. Codex 任务可以少一点微观步骤，但必须有目标、禁止范围、验收命令和报告格式。
3. Codex 不应自动修改所有文档。
4. Codex 不应自动进入下一任务。
5. Codex 执行后必须由网页端总设计师核验 GitHub 最新代码。
6. CodeBuddy 只做风险线索复核，不做最终判断。
7. OpenCode / Codex 报告都不能直接作为事实。
8. MCP Chrome 页面任务不能用 API 代替。
9. 如果 Codex 改代码，必须说明：改了哪些文件、跑了哪些测试、哪些验收无法完成。
10. Codex 应先读 current-working-handoff，再读 master plan，再读相关模块文档，不从头扫描所有旧文档。

## 7. 当前主线进度

以下百分比是总设计师用于沟通的产品进度估算，不是精确测试覆盖率。后续会根据 GitHub 最新代码、测试和真实页面验收调整。不得把估算进度当成最终完成承诺。

> 文档与协作规则治理属于阶段性支撑任务，不作为固定五条主线之一；如某轮任务专门处理文档规则，可在本轮说明中临时提及。

| 主线 | 进度 | 说明 |
|------|------|------|
| 总体架构收口 | ≈ 100% | 架构收口阶段已结束（Codex-FinalArchitectureClosureTargetMode-1）。100% 不代表全项目完成，只代表旧系统地基已检查、sense-only 复习主线边界清楚、AI 示意卡第一版可进入开发设计、高风险区清楚、文档入口清楚、下一轮不应继续无限侦查。详见 `docs/plans/final-architecture-closure-report.md`。 |
| 复习主线稳定 | ≈ 96% | WordSense/ReviewCard/FSRS 核心链路已锁定；V3 新增 dismissed 列表/restore/preview-package 反向 contract tests，进一步确认不写 WordSense/ReviewCard/ReviewLog/EncounteredWord；入口统一后日常复习主线指向 `/reviews/senses`。 |
| 页面真实验收 | ≈ 100% | V3 MCP Chrome 真实页面验收 28 项全通过：阅读页点词 → 标记 → 列表 → 取消 → 已取消视图 → 恢复 → 真预览弹窗 → 勾选/取消勾选 → 全不选禁用 → 准备生成 → 安全包 JSON → 复制 → 无 AI 调用 → 无学习数据写入 → 主入口/旧入口正常 → console/network 检查。 |
| AI 示意卡规划 | ≈ 100% | V1 pending marker + V2 列表/取消/预览雏形 + V3 已取消视图/恢复/真实预览/安全生成包已落地，路线完全清晰。未完成：AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用。 |
| 前端入口整理 | ≈ 100% | 第一轮入口统一已完成：主入口文案为「复习」并指向 `/reviews/senses`，首页「开始复习」进入 sense-only 主线，旧路由保留。V3 列表/已取消视图/预览/安全包入口与复习入口完全协调。后续仅剩更细的 alias/高级分组收口（不影响日常使用）。 |

### 7.1 子阶段进度

| 子阶段 | 进度 | 说明 |
|--------|------|------|
| AI 示意卡生成闭环 | ≈ 95% | V3 已取消视图/恢复按钮/真实预览/安全生成包已完成（95%）。**这个 95% 是「AI 示意卡生成闭环」子阶段的进度，不是固定五条主线的虚假上调。** AI 真实推荐、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。 |
| AI 生成安全契约 | ≈ 85% | V3 安全生成包（schema_version=ai-study-card-preview-package-v1）+ V4 最终候选包（schema_version=ai-study-card-final-candidates-v1）已完成。V4 safety_flags 6 条：no_ai_called_by_linguacafe / ai_response_pasted_by_user / no_review_card_created / no_word_sense_created / no_fsrs_changed / user_confirmation_required_before_card_generation；generation_rules 5 条。V3 + V4 共 32 个反向 contract tests 覆盖用户/语言/状态隔离 + 去重 + 默认不选 + 空结果 + 数量上限。**85% 是子阶段进度，不是固定五条主线的虚假上调。** API key 安全存储、真实 AI 调用边界、用户确认后生成 WordSense/ReviewCard 仍未实现。 |
| AI 推荐词确认闭环 | ≈ 80% | V4 新增子阶段。粘贴导入、去重、默认不选、用户确认、最终候选包已落地。**80% 是子阶段进度，不是固定五条主线的虚假上调。** AI 真实推荐（自动调用 AI 获取推荐词）仍未实现。 |
| 旧版入口清理执行 | ≈ 80% | 普通查词界面的旧入口文案已隐藏，并加源码 guard；后端兼容层和 legacy route/service/tests 保留。**80% 是子阶段进度，不是固定五条主线的虚假上调。** 更深层删除必须另做依赖审计。 |
| Finished reading 安全护栏 | ≈ 70% | Feature test 覆盖 yellow→known、green/WordSense/ReviewCard/ReviewLog/FSRS/用户/语言隔离，并修复语言过滤缺口。本轮新增「完成阅读」确认弹窗（说明影响 + 取消/确认），不污染真实数据。**70% 是子阶段进度，不是固定五条主线的虚假上调。** |
| 阅读中复习 / 多例句轮换路线 | ≈ 60% | 路线冻结文档明确 WordSense-only、真实来源例句、熟词僻义分区、surface/lemma 绑定原则。已实现多例句池、题面例句轮换、补充例句不重复、多来源溯源、词形原型绑定前置、known-sense 候选、例句池/来源查询性能优化。本轮新增形态变化测试矩阵 0%→100%、文章 fixture 导入回归 0%→100%、词形原型绑定 60%→90%、熟词僻义识别前置 65%→85%、阅读中刷卡前置匹配 40%→60%、多例句池性能与来源查询优化 20%→50%、页面验收覆盖增强、文档与测试矩阵治理 0%→100%。这些是子阶段进度，不是固定五条主线虚假上涨。**阅读中刷卡评分仍未实现。AI 不生成例句。熟词僻义仅前置结构，AI 判断仍未实现。** |

> 如果任务失败或 Incomplete，对应进度不得上调。
> 如果一个任务完成后不会推动任何固定主线进度，就不得作为 OpenCode / Codex / Trae 的单独任务派发；应合并到能推动主线进度的复合任务中。纯小修正只能作为复合任务的附带项。
> "总体架构收口 100%"是进入新功能开发前的架构收口 100%，不是全项目完成。不要把它写成全项目完成。

## 8. 临时文档使用规则

1. 本文是短期工作台，不是永久事实源。
2. 每次完成一个阶段，可以更新本文。
3. 过期事项要移动到 master plan 或删除。
4. 不允许让本文和 master plan 冲突。
5. 如冲突，以 master plan 为准，并修正本文。

## Recent Update: Codex-FinalArchitectureClosureTargetMode-1

- Added three frozen-plan documents:
  - `docs/plans/final-architecture-closure-report.md` — final architecture closure report (Overall architecture closure 81% → 100%).
  - `docs/plans/ai-study-card-v1-frozen-plan.md` — AI study card v1 frozen plan (AI study card planning 25% → 55%).
  - `docs/plans/frontend-review-entry-unification-plan.md` — frontend review entry unification frozen plan (Frontend entry cleanup 50% → 65%).
- MCP Chrome read-only page observation covered: reading page (`/chapters/read/5`), vocabulary side box, AI reading assist button, review entry (homepage "开始复习" + nav "单词复习/词义确认"), sense confirmation entry (`/senses/review`), review card management entry (`/review-cards/manage`).
- Key findings from page observation:
  - "待 AI 解释" button does not exist yet — confirms AI study card v1 entry position.
  - Navigation still exposes internal names "单词复习 / 词义确认".
  - Homepage "开始复习" button points to `/review/false/-1/-1` (legacy word review), not `/reviews/senses`.
  - legacy word card compatibility layer exists as "旧版释义（兼容）" label.
  - Review card management page shows 7 sense cards, no legacy word cards visible.
- Updated progress to: Overall architecture closure 100%, Review mainline 91%, Page real acceptance 91%, AI study card planning 55%, Frontend entry cleanup 65%.
- "Overall architecture closure 100%" means the architecture-closure phase is over and AI study card v1 can enter development design; it does NOT mean the whole project is finished.
- This task did NOT change business code, tests, Vue, Controller, Service, routes, migration, DB schema, FSRS semantics, delete/archive/restore semantics, ReviewLog retention, legacy word card compatibility layer, SenseReview, or SenseMappingReview.

## Recent Update: Codex-AIStudyCardV1-And-ReviewEntryUnification-1

- Implemented AI study card v1 pending marker: new `ai_study_card_pending_items` table, `AiStudyCardPendingItem` model, `AiStudyCardPendingItemService`, `AiStudyCardPendingItemController`, and `POST /ai-study-card/pending-items`.
- Added reading lookup button「待 AI 解释」in `VocabularySideBox.vue` and responsive `VocabularyBox.vue`; it records a pending item only and returns success/existing feedback.
- Added `tests/Feature/AiStudyCardPendingItemTest.php` for auth, user/language isolation, duplicate idempotency, and reverse contracts confirming no WordSense/ReviewCard/ReviewLog/EncounteredWord writes.
- Unified frontend review entry round 1: homepage「开始复习」and nav「复习」point to `/reviews/senses`; `/senses/review`, `/review-cards/manage`, and `/review/false/-1/-1` remain accessible.
- Progress updated to: Overall architecture closure 100%, Review mainline stability 94%, Page real acceptance 96%, AI study card planning 90%, Frontend entry cleanup 92%.
- Still not implemented: AI recommendation modal, AI meaning generation, automatic WordSense/ReviewCard creation, FSRS integration, full AI study card generation loop, or final legacy entry cleanup.

## Recent Update: GLM-AIStudyCardV2-GenerationLoop-1

- AI study card v2 generation loop phase 1 is implemented: pending item list, dismiss/restore, generation preview modal placeholder.
- New backend endpoints: `GET /ai-study-card/pending-items` (with chapter_id filter), `POST /ai-study-card/pending-items/{id}/dismiss`, `POST /ai-study-card/pending-items/{id}/restore`.
- `createOrGetPending()` refactored: if same key exists as dismissed, restore to pending instead of creating duplicate.
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` now include "待 AI 解释列表" button, list panel with cancel, "生成 AI 示意卡" button, and preview modal placeholder with safety notice and rule preview.
- Added 16 new V2 feature tests (23 tests / 105 assertions total, all green). Covers list auth, user/language isolation, chapter filter, dismiss/restore, idempotency, reverse contracts (no WordSense/ReviewCard/ReviewLog writes).
- MCP Chrome real-page acceptance 24/24 passed: login → reading page → click word → mark → list → cancel → re-mark → preview modal → no AI calls → no learning data writes → main/old entry points work → console/network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 98%.
- New sub-phase: AI study card generation loop 70%. **This 70% is the sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal.

## Recent Update: GLM-AIStudyCardV3-SafePreviewPackage-1

- AI study card v3 safe preview package is implemented: dismissed-item restore button, real preview content, safe preview package.
- Backend: extended `GET /ai-study-card/pending-items` to accept `status=pending|dismissed|all`; added `POST /ai-study-card/pending-items/preview-package` that returns a safe JSON package (schema_version=ai-study-card-preview-package-v1) with selected_items, generation_rules, and safety_flags (no_ai_called / no_review_card_created / no_word_sense_created / no_fsrs_changed).
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` upgraded with pending/dismissed view toggle, restore button for dismissed items, real preview modal (user-selected words list, source sentence, chapter position, count, status, safety notice, checkbox per item with "select all none → disable 准备生成", AI-recommended words placeholder area, future generation rules), "准备生成" button triggering backend preview-package endpoint, JSON display, and "复制生成包" button with success/failure toast.
- Added 14 new V3 feature tests (37 tests / 184 assertions total, all green). Covers dismissed list auth/isolation, restore idempotency + no learning data, preview-package auth/user/language/status isolation, empty item_ids, reverse contracts (no WordSense/ReviewCard/ReviewLog/FSRS changes, no pending status change).
- MCP Chrome real-page acceptance 28/28 passed: login → reading page → click word → mark → list → cancel → dismissed view → restore → real preview modal → checkbox toggle → all-uncheck disables button → 准备生成 → safe package JSON → copy → no AI calls → no WordSense/ReviewCard/ReviewLog writes → main/old review entry points work → console/network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 100%.
- Sub-phase progress: AI study card generation loop 70% → 95%; AI generation safety contract 0% → 55%. **These are sub-phase progress, NOT a fake uplift of the five main lines.** AI recommended words, AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal.
- Did NOT enter the next task automatically.

## Recent Update: GLM-AIRecommendationConfirmationLoop-V4-1

- AI study card v4 AI recommendation confirmation loop is implemented: paste AI recommendation JSON, dedupe, default unchecked, user confirmation, final candidates package.
- Backend: added `POST /ai-study-card/pending-items/final-candidates-package` that returns a safe JSON package (schema_version=ai-study-card-final-candidates-v1) with user_selected_items / ai_recommended_selected_items / ai_recommended_unselected_items / dedupe_summary / generation_rules (5 rules) / safety_flags (6 flags: no_ai_called_by_linguacafe / ai_response_pasted_by_user / no_review_card_created / no_word_sense_created / no_fsrs_changed / user_confirmation_required_before_card_generation). Backend enforces user/language/status triple isolation, secondary dedupe (AI vs user-selected, AI internal, unselected vs selected), empty-result 422, post-query empty 422, and size limits (max 100 user-selected, max 200 AI recommendations).
- Frontend: `VocabularySideBox.vue` and `VocabularyBox.vue` upgraded with V4 paste-AI-recommendation-JSON textarea, parse/clear buttons, parse error message, parse summary (original/valid/dropped-missing-word/dropped-duplicate-with-user/dropped-ai-internal-duplicate), AI recommendation list with checkbox per item default unchecked, select-all/select-none for AI recommendations, visual separation between user-selected words and AI recommendations (v-divider), "生成最终候选包" button in v-card-actions, final candidates package JSON display, and "复制最终候选包" button with success/failure toast.
- Added 18 new V4 feature tests (56 tests / 294 assertions total, all green). Covers auth, user/language/status isolation, AI dedupe vs user-selected, AI internal dedupe, default-unchecked reflected in data structure, empty selected + empty AI returns 422, only user-selected without AI allowed, invalid AI does not crash, no WordSense/ReviewCard/ReviewLog creation, no pending status change, no FSRS field changes (fsrs_state/fsrs_due_at/fsrs_stability/fsrs_difficulty/fsrs_reps/fsrs_lapses/fsrs_last_reviewed_at/fsrs_enabled), safety_flags correct, unselected AI deduped against selected AI, max items limit, source_preview_package preserved.
- MCP Chrome real-page acceptance 33/33 passed: login → reading page → click word → mark → list → preview modal → safe package → paste valid JSON (agency + mediation) → parse summary 2/2/0 → default 0 checked → paste duplicate JSON (substantive + agency + Agency) → parse summary 3/1/1/1 → paste malformed JSON → "JSON 格式错误" error → no crash → select one AI recommendation → select all → select none → re-select agency → 生成最终候选包 → final package JSON with schema_version=ai-study-card-final-candidates-v1, user_selected_items(3), ai_recommended_selected_items(1: agency), ai_recommended_unselected_items(1: mediation), dedupe_summary, generation_rules(5), safety_flags(6) → 复制最终候选包 → "已复制到剪贴板" → no external AI network requests (only local POST /final-candidates-package 200 OK) → no WordSense/ReviewCard/ReviewLog writes → /reviews/senses main entry works → /review-cards/manage old entry works → console/network clean.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 100%.
- Sub-phase progress: AI study card generation loop 95% (unchanged); AI generation safety contract 55% → 85%; AI recommendation confirmation loop 0% → 80% (new sub-phase). **These are sub-phase progress, NOT a fake uplift of the five main lines.** AI real recommendation (auto AI call), AI meaning generation, WordSense/ReviewCard generation loop, and real AI calls are still not implemented.
- Regression: ReviewFsrsTest 61/364, FsrsSchedulingServiceTest 9/46, WordSense (DestroyRestore+Test) 149/595 all green. npm run development compiled successfully.
- No AI calls, no API key saved, no WordSense/ReviewCard/ReviewLog created, no FSRS changes, no delete/archive/restore changes, no SenseReview/SenseMappingReview/legacy word card removal.
- Did NOT enter the next task automatically.

## Recent Update: Trae-ExamplePool-ReviewRotation-SourceCarousel-1

- Multi-example pool + review-page question example rotation + answer-side supplementary example non-duplication + multi-source carousel + Finished reading confirmation dialog are implemented.
- New backend service `WordSenseExamplePoolService`: collects real-source example candidates from `WordSenseOccurrence` (status=BOUND, sentence_en non-empty) + card example fallback. Dedupe by chapter + sentence text; sentence-only dedupe for card fallback against any occurrence. Read-only: no ReviewLog, no WordSense, no ReviewCard, no FSRS writes. No AI calls.
- `SenseReviewCardSerializerService` payload extended with `example_candidates`, `example_candidates_count`, `supplementary_example`. Question example index chosen by stable seed (review_card_id * 31 + fsrs_reps * 7 + day-of-year, crc32 hash). Supplementary index chosen by independent seed (review_card_id * 17 + fsrs_reps * 13 + day-of-year + 1009), guaranteed different from question index. Supplementary is null when only one candidate exists.
- `SenseSourceContextService::sourceContextList()` returns `{ sense_id, sources: [...], count }` with up to 3 distinct chapter sources. Same-chapter duplicates collapse. Falls back to single-element source list when no chapter sources exist. New route `GET /senses/{id}/source-context-list`.
- `SenseExampleDialog.vue` rewritten to support multi-source carousel: 来源 1/N chip, prev/next buttons, scrollTargetIntoView on source change. Single-source cards do not show switching buttons (rule compliance). `context` computed preserved as legacy alias.
- `SenseReview.vue` answer side adds supplementary-example block with defensive dedup check (supplementary_example.sentence_en === currentCard.example_sentence_en → null). `viewSource()` now calls `/source-context-list` endpoint.
- `TextReader.vue` 「完成阅读」 button now opens confirmation dialog first. Dialog explains: yellow (new) words marked known; green (learning) words do not enter review; existing WordSense review cards and review history are not affected. Cancel does not execute; confirm runs the original `finish()` method. Backend semantics unchanged.
- Added 18 new feature tests (WordSenseExamplePoolTest 12 + SenseSourceContextMultiSourceTest 6, all green): multiple occurrences produce multiple candidates; duplicate sentences collapsed; card example fallback; card fallback dedupe against occurrences; question rotation not always first; question rotation stable for same seed; question rotation changes with reps; supplementary different from question; supplementary null for single candidate; pool does not write ReviewLog/WordSense/ReviewCard; serializer payload includes candidates + supplementary; serializer payload supplementary null for single candidate; source-context-list shape; multiple distinct chapters produce multiple sources; same-chapter duplicates collapse; no-chapter fallback to single source list; read-only; cross-user isolation.
- Regression: FinishedReadingSafetyTest 2/27, LegacyEntryUiGuardTest 1/12, ReviewFsrsTest 61/364, FsrsSchedulingServiceTest 9/46, WordSense (DestroyRestore+ExamplePool+Test) 161/680, SenseSourceContextTest 29/186 all green. npm run development compiled successfully.
- MCP Chrome real-page acceptance: login (1816529781@qq.com) → /reviews/senses question example displayed → 显示答案 → single-example card shows no duplicate supplementary (rule compliance) → 更多 → 查看原文 → single-source dialog shows no switching buttons (rule compliance) → /chapters/read/5 → 「完成阅读」 button → confirmation dialog with correct text ("确认完成阅读？" + yellow/green/history explanation + 取消/确认完成) → click 取消 → dialog closes, page does NOT enter "阅读完成" state → console only WebSocket (Pusher local log fallback, expected per AGENTS.md) → network all 200.
- Five-line progress: Overall architecture closure 100%, Review mainline stability 96%, Page real acceptance 100%, AI study card planning 100%, Frontend entry cleanup 100%.
- New sub-phase progress: multi-example pool 0% → 60%; review-page question rotation 0% → 50%; multi-source carousel 0% → 30%; Finished reading misclick protection 0% → 20%. Total sub-phase uplift: 160%. **This 160% is four sub-phase progress values, NOT a fake uplift of the five main lines.**
- **Reading inline review (阅读中刷卡) is still NOT implemented. AI does NOT generate examples. No AI calls. No WordSense/ReviewCard/ReviewLog created. No FSRS changes. No delete/archive/restore changes. No SenseReview/SenseMappingReview/legacy word card removal. No new migration.**
- Did NOT enter the next task automatically.

## Recent Update: Trae-LemmaKnownSenseBridge-1

- Lemma/surface binding + known-sense candidates + known-sense-new-meaning front-only structure + example-pool N+1 optimization are implemented.
- New backend service `WordSenseKnownSenseService` (read-only): `listConfirmedSensesForLemma(userId, language, lemma)` returns only confirmed WordSense of the current user/language/lemma (excludes rejected/AI-suggested/pending); batch-loads `WordSenseOccurrence` counts via `whereIn` + `groupBy` to avoid N+1; `knownSenseLookupPayload()` returns `{ lemma, has_confirmed_senses, confirmed_senses, known_sense_new_meaning_hint, read_only }`. No ReviewLog/WordSense/ReviewCard/FSRS writes. No AI calls.
- New endpoint `GET /senses/known-sense-lookup?lemma=...&language=...` on `SenseOccurrenceController::knownSenseLookup`. Enforces user/language isolation via `Auth::user()->selected_language` and 403 on language mismatch. 422 on empty lemma.
- `WordSensesList.vue` adds 「已学词义候选」 panel (v-if="knownSenses.length > 0"): success-color header with mdi-bookmark-check icon, count chip, lemma label, per-sense row with 已学 chip + pos chip + FSRS chip + 已复习 N 次 + sense_zh + sense_en. Adds 「熟词僻义」 info alert with explicit "未调用 AI 判断" notice. `fetchKnownSenseLookup` uses stale-guard pattern (latestKnownSenseLookupKey) to avoid race conditions. `effectiveLemma` (studyBase → baseWord → lemma → surface → word) and `language` watcher both trigger `fetchKnownSenseLookup` and `fetchSenses`.
- Lemma/surface binding: reading-page click on a word shows surface (e.g. `geese`) + lemma (e.g. `goose`) + [修改] entry; search/add-sense flow prefers lemma (search box `value=lemma`); user can correct lemma via `VocabularySideBox::saveLemma` → `commit setStudyBase` → `POST /vocabulary/word/update`; later add-sense uses corrected lemma; no unconditional binding of `published`→`publish`. Test `test_add_new_sense_uses_corrected_lemma_after_user_edit` confirms lemma and surface_form stored independently.
- Performance: `WordSenseExamplePoolService::exampleCandidates()` eliminates N+1 Chapter query by batch `whereIn` preloading chapter names. `SenseSourceContextService::sourceContextList()` eliminates per-occurrence `findChapterById` by batch `whereIn` preloading + `limit(12)` + PHP `unique('chapter_id')->take(3)` (cross-database compatible: SQLite GROUP BY + orderByRaw combination fails, so PHP-layer dedupe retained). Returns identical fields and fallback behavior.
- Added 13 new feature tests in `tests/Feature/WordSenseKnownSenseBridgeTest.php`: returns confirmed senses for lemma; isolates by user; isolates by language; excludes rejected; excludes ai_suggested; does not write ReviewLog/WordSense/ReviewCard; returns empty for unknown lemma; normalizes lemma lowercase; returns FSRS fields (has_review_card/fsrs_state/fsrs_reps/fsrs_due_at/fsrs_enabled); returns occurrence_count; knownSenseLookupPayload returns correct shape + read_only flag; add new sense uses corrected lemma after user edit; payload separates known-sense candidates from add-new-sense area.
- Regression: WordSenseExamplePoolTest 12/85, SenseSourceContextMultiSourceTest 6/33, FinishedReadingSafetyTest 2/27, LegacyEntryUiGuardTest 1/12, ReviewFsrsTest 61/364, FsrsSchedulingServiceTest 9/46, WordSense (DestroyRestore+ExamplePool+Test+KnownSenseBridge) 174/718 all green. npm run development compiled successfully (4951ms).
- MCP Chrome real-page acceptance: login (1816529781@qq.com) with isolatedContext → /chapters/read/7 → click `geese` → 单词基础信息 shows `geese → goose [修改]` → 添加新释义 search box value=`goose` (lemma preferred) → 添加为新释义 → 保存新释义 → 取消选择 → re-click `geese` → 「已学词义候选」 panel shows 1 confirmed sense (鹅, 雌鹅, 鹅肉, 弯把熨斗, noun, FSRS chip, 已复习 0 次) + 「熟词僻义」 info alert shows "这个词你学过一些意思，但这里可能是新意思。" + "未调用 AI 判断" → /reviews/senses 显示答案 + 例句正常 → 更多 → 查看原文 多来源溯源 dialog 正常 → console only WebSocket (Pusher local log fallback, expected per AGENTS.md) → network all 200 (including GET /senses/known-sense-lookup?lemma=goose&language=english 200).
- Five-line progress: Overall architecture closure 100% (unchanged), Review mainline stability 96% (unchanged), Page real acceptance 100% (unchanged), AI study card planning 100% (unchanged), Frontend entry cleanup 100% (unchanged). **The five main lines are NOT inflated.**
- New sub-phase progress: lemma-surface binding 10% → 60%; known-sense-new-meaning recognition 15% → 65%; reading-inline-review front-end matching 0% → 40%; multi-example-pool performance & source-query optimization 0% → 20%. Total sub-phase uplift: 160%. **This 160% is four sub-phase progress values, NOT a fake uplift of the five main lines.**
- **Reading inline review scoring (阅读中刷卡评分) is still NOT implemented. No ReviewLog written. No FSRS changes. No real AI calls. AI does NOT generate examples. Known-sense-new-meaning is only a front-end structure; AI judgment for "is this a known-sense-new-meaning case?" is still NOT implemented. No WordSense/ReviewCard created by the new endpoint. No delete/archive/restore changes. No SenseReview/SenseMappingReview/legacy word card removal. No new migration.**
- Did NOT enter the next task automatically.
