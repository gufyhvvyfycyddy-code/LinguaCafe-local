# LinguaCafe 全仓库架构热点审计

> **审计日期**：2026-07-01（最近任务刷新 2026-07-03 GLM-RealMorphologyImportClickCompletion-1）
> **基准 commit**：`7f3d4b6`
> **审计方式**：只读侦查，不改代码，不进入功能开发。

> **2026-07-03 Trae 任务新增热点**：
> - `WordSenseKnownSenseService`（新增）：只读服务，`listConfirmedSensesForLemma` + `knownSenseLookupPayload`，批量 `whereIn` + `groupBy` 预加载 occurrence count 消除 N+1。不写 ReviewLog/WordSense/ReviewCard/FSRS。无 migration。低风险。
> - `SenseOccurrenceController::knownSenseLookup`（新增方法）：`GET /senses/known-sense-lookup`，鉴权 + 用户/语言隔离 + 422 空 lemma。无新依赖。
> - `WordSensesList.vue`（修改）：新增「已学词义候选」面板 + 「熟词僻义」info alert + `fetchKnownSenseLookup` stale-guard。`effectiveLemma` (studyBase → baseWord → lemma → surface → word) 同时触发 `fetchSenses` 和 `fetchKnownSenseLookup`。需要后续关注面板与已保存释义区的视觉重叠（同一 sense 可能同时出现在两个区域）。
> - `WordSenseExamplePoolService::exampleCandidates()`（修改）：消除 N+1 Chapter 查询，改为批量 `whereIn` 预加载 chapter names。返回字段不变。
> - `SenseSourceContextService::sourceContextList()`（修改）：消除循环内 `findChapterById`，改为批量 `whereIn` 预加载 Chapter + `limit(12)` + PHP `unique('chapter_id')->take(3)`。跨数据库兼容（SQLite 不支持 GROUP BY + orderByRaw 组合，故保留 PHP 层 dedupe）。返回字段和 fallback 不变。
> - `routes/web.php`（修改）：新增 `Route::get('/senses/known-sense-lookup', ...)`。无路由冲突。
> - 测试覆盖：13 个 feature tests（WordSenseKnownSenseBridgeTest）+ 完整回归测试套件全绿（WordSense 174/718, ReviewFsrsTest 61/364, FsrsSchedulingServiceTest 9/46, WordSenseExamplePoolTest 12/85, SenseSourceContextMultiSourceTest 6/33, FinishedReadingSafetyTest 2/27, LegacyEntryUiGuardTest 1/12）。npm run development 编译成功。

> **2026-07-03 Codex 任务新增测试矩阵热点**：
> - `MorphologyMatrixImportRegressionTest`（新增）：项目可控文章 fixture + 8 类形态变化矩阵。覆盖 surface/lemma/pos 保留、known-sense lookup 只读、user/language/status 隔离、ambiguous forms 不自动绑定、不写 ReviewLog/ReviewCard/FSRS。
> - `MorphologyMatrixUiGuardTest`（新增）：源码级守护 `WordSensesList.vue` 仍显示 surface+lemma、add-sense payload 仍用 `effectiveLemma` + `surfaceWord`、熟词僻义提示仍标注未调用 AI、旧入口仍隐藏。
> - 架构影响：不新增 route/service/controller，不改 Vue 行为，不改 FSRS/ReviewLog/ReviewCard/WordSense 删除归档恢复语义；本轮主要把已实现前置结构转为更宽的 harness。

> **2026-07-03 GLM-RealMorphologyImportClickCompletion-1 测试覆盖增量**：
> - `MorphologyMatrixImportRegressionTest`（升级为真实 tokenizer/importer 回归测试）：从纯 fixture 断言升级为通过 `ChapterService::processChapterText` 调用真实 Python spaCy tokenizer 的真实导入链路回归，覆盖真实分词/lemma/POS 而非静态 processed_text。8/8 形态变化类别通过 Playwright 真实页面点击覆盖（18 次真实点击），4 个词性歧义词 `published` / `used` / `broken` / `left` 通过真实页面点击覆盖。
> - `MorphologyMatrixLemmaBridgeDataLayerTest`（重命名）：原 data-layer fixture 测试重命名以体现其数据层 fixture 边界，与真实 tokenizer/importer 回归测试职责区分。测试命名治理完成。
> - 真实页面点击验收未使用 API/axios/fetch 模拟点击，全部为 Playwright 真实浏览器点击。
> - 架构影响：不新增 route/service/controller，不改 Vue 行为，不改 FSRS/ReviewLog/ReviewCard/WordSense 删除归档恢复语义。阅读中刷卡评分与 AI 判断熟词僻义仍未实现；不写 ReviewLog、不改 FSRS、不调用 AI。

> **2026-07-02 Trae 任务新增热点**：
> - `WordSenseExamplePoolService`（新增）：只读，复用 `WordSenseOccurrence` + card example fallback，不写 ReviewLog/WordSense/ReviewCard/FSRS。无 migration。低风险。
> - `SenseReviewCardSerializerService`（修改）：payload 新增 `example_candidates` / `example_candidates_count` / `supplementary_example`。需要后续关注 payload 大小增长（最多 10 个 occurrence 候选 + 1 个 card fallback）。
> - `SenseSourceContextService::sourceContextList()`（新增方法）：复用现有 `sourceContext` fallback 链，最多返回 3 个 distinct chapter sources。无新依赖。
> - `SenseExampleDialog.vue`（重写）：从单 context 改为 sources carousel。保留了 `context` computed 作为 legacy alias，避免破坏外部调用。
> - `TextReader.vue`（修改）：新增 `finishConfirmDialog` data + `openFinishConfirmDialog` 方法 + v-dialog。不改后端语义。
> - 测试覆盖：18 个 feature tests（WordSenseExamplePoolTest 12 + SenseSourceContextMultiSourceTest 6）+ 完整回归测试套件全绿。

---

## 1. 审计目标

本轮全仓库架构审计的目标：
- 找出当前 LinguaCafe 仓库中比 ReviewCardManage 更值得优先处理的架构风险点。
- 给下一批安全重构任务排序，确保每轮可独立 Phase、可真实验收。
- 不重复已经完成的架构优化（bulkEnabled/bulkDestroy 已抽到 MutationService）。
- 不动高风险核心语义（FSRS 算法、WordSenseService 删除、reset 核心事务）。

---

## 2. 已完成的架构优化（Roadmap 刷新后）

> **审计日期**：2026-07-01（第二次刷新）
> **基准 commit**：`a1e67e8`

| 优化项 | 状态 | 说明 |
|--------|------|------|
| ReviewCardManage bulkEnabled 抽取 | ✅ 已完成 | `bulkSetEnabled()` 在 MutationService + 共享 helper |
| ReviewCardManage bulkDestroy 抽取 | ✅ 已完成 | `bulkDestroy()` 在 MutationService + 共享 helper |
| reset 完整 characterization tests | ✅ 已完成 | 8 个测试覆盖全部 FSRS 字段变化 + ReviewLog + 只读保护 |
| destroy 单卡 characterization tests | ✅ 确认已有 | 现有测试覆盖删除/拒绝/日志保留/关联清除/条件恢复 |
| bulkDestroy 抽取 + 共享 helper | ✅ 已完成 | `bulkDestroy()` 在 MutationService，`findManageableSenseCardForMutation()` 共享 |
| TextBlockService ReaderDataService 提取 | ✅ 已完成 | 新增 `ReaderDataService`，TextBlockService 保持门面接口不变 |
| Tokenizer health 诊断增强 | ✅ 已完成 | health_check 新增 version/languages/english/checks，轻量检测，单次加载 |
| SenseSourceContext 查询/渲染分离 | ✅ 已完成 | 新增 `SenseSourceContextResolverService`，SourceService 保持 public 门面 |
| ReviewCardManage 来源列一致性修复 | ✅ 已完成 | 新增 `source_display_status`/`source_display_label`，三级语义（real_chapter/card_example_only/missing）|
| MCP Chrome 登录验收可靠流程 | ✅ 已完成 | 诊断 isolatedContext 根因，建立 playbook，协作规则 §14.10 |
| 协作规则体系 | ✅ 已完成 | WorkBuddy 单专家、复杂度 100、oh-my-opencode-slim 必用、设计师自动推进 |
| destroy 单卡核心语义 | ❌ 不建议改 | 核心在 `WordSenseService::removeSenseFromReviewSystem()` |
| TextBlockService phrase/index characterization tests | ✅ 已完成 | `TextBlockPhraseIndexingTest` 锁定 phrase 命中、跨 NEWLINE 命中、缺词不命中、phraseIndexes 排序映射、用户/语言隔离；不改业务代码 |
| FSRS confirmAndApply 拒绝写入安全测试 | ✅ 已完成 | `FsrsRescheduleConfirmTest` 新增 2 个 tests，锁定 `apply=true` 高风险未二次确认 / blocked 超量时不写 ReviewCard、不建 snapshot、不写 ReviewLog；不改业务代码 |
| 文档入口治理 / 历史降权 | ✅ 已完成 | 新增 `DOCUMENTATION_INDEX` / `HISTORY_INDEX` / ADR-0002 / spec→harness 候选清单；旧 `NEXT_TASK`、`CURRENT_STATUS`、`FSRS_PHASE*`、旧 handoff 已标记为历史参考；不改业务代码 |
| Spec-to-harness 第一轮硬化 | ✅ 已完成 | `TextBlockFallbackTokenizerTest` 锁定 fallback tokenizer 保守语义；`ReviewCardManageTest` 补 logs payload 精确字段/日期格式和同卡 user/language 过滤；不改业务代码 |

---

## 2.1 Codex-ArchitectureOptimizationLoop-1 增量审计（2026-07-02）

> **基准 commit**：`095a3dd docs: prepare Codex handoff working plan`
> **性质**：Codex 架构总审计 + 第一轮低风险测试护栏。不是完成全部架构优化。

### P0：数据安全或用户状态风险

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| 本轮未发现新的 P0 | — | — | — | 否 | 现有高危写入集中在 WordSense / ReviewCard / FSRS / import，已有专项测试或需要单独授权；本轮不扩大写入语义 |

### P1：高收益、低风险、测试可覆盖的架构优化

| 问题 | 文件 | 用户影响 | 为什么是架构问题 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|---|
| TextBlockService 剩余 phrase/index 逻辑缺少直接 characterization tests | `app/Services/TextBlockService.php`、`app/Services/ReaderDataService.php` | 短语高亮、短语查询、阅读页 phraseIndexes 若回归，会直接影响阅读页交互 | phrase 标注仍由 TextBlockService 内存算法负责，ReaderDataService 再把 `phrase_ids` 映射为前端 `phraseIndexes`；这是 reader 数据契约的一部分 | 新增 `tests/Feature/TextBlockPhraseIndexingTest.php`，5 tests / 18 assertions | 是 | 只补测试，不改业务逻辑；覆盖现有文档候选的 "TextBlockService 剩余 phrase/index/read data 逻辑侦查 + characterization tests" 中最安全部分 |
| TextBlockService 仍保留 ReaderDataService fallback 分支导致代码重复 | `app/Services/TextBlockService.php` | 暂无直接用户影响，但后续维护者可能误改旧分支 | 构造函数总是创建 `ReaderDataService`，旧内联 `prepareTextForReader` / `indexPhrases` 分支成为兼容残留，增加理解成本 | ReaderDataService 相关测试 + 本轮 phrase/index 测试 | 否 | 删除旧分支会触碰 reader 数据核心路径，需单独只读确认调用方是否允许去掉兼容分支 |
| ReviewCardManageController 仍持有 `logs()` payload 和单卡 find helper | `app/Http/Controllers/ReviewCardManageController.php` | 日志抽屉字段若漂移会影响管理页查看复习历史 | 查询/导出/序列化/批量写入已下沉，`logs()` 仍是 controller 内 payload 组装 | ReviewCardManageTest 已覆盖 logs / 权限 / 过滤 | 否 | 收益中等但不是本轮最小风险；可后续提取 `ReviewCardManageLogService` 或纳入 serializer |

### P2：可做但不急的结构清理

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| SenseOccurrenceController 同时做表单 validation、序列化、service 编排 | `app/Http/Controllers/SenseOccurrenceController.php` | 若字段漂移会影响 `/senses/review`、手动释义、来源例句 | WordSense / SenseReview 相关 Feature tests + 空状态 MCP smoke | 否 | 需要接口/序列化边界设计，可能触及 Vue 页面契约 |
| TextBlockGroup.vue 仍是 reader 前端状态大组件 | `resources/js/components/Text/TextBlockGroup.vue` | 阅读页点词、hover、短语、词典、学习侧栏都受影响 | 已有 MCP Chrome smoke 基线，但没有组件级测试 | 否 | 高价值但必须页面验收，且不适合在本轮后端测试任务中拆组件 |
| VocabularyService 查询/导入/词汇处理职责仍宽 | `app/Services/VocabularyService.php` | 搜索、CSV 导出、导入后处理可能互相影响 | VocabularySearchTest / DictionaryImportTest | 否 | 已补查询测试；真正拆服务需单独边界设计 |

### 暂缓：风险大、需要产品决策或页面验收成本高

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| FSRS confirmAndApply safe write contract tests / 写入路径调整 | `app/Services/FsrsReschedulePreviewService.php` | 可能改变大量 ReviewCard 到期时间和 FSRS 参数 | FsrsReschedulePreviewTest / FsrsRescheduleConfirmTest | 否 | 写入路径风险高，需单独任务；本轮不执行真实重排 |
| WordSense 删除 / 归档 / 恢复语义调整 | `app/Services/WordSenseService.php`、相关 controllers | 可能影响 ReviewCard、ReviewLog、Occurrence、EncounteredWord | WordSenseDestroyRestoreTest / ReviewCardManageTest | 否 | 产品语义已做取舍，本轮不改删除/归档/恢复 |
| SenseReview 有卡片 / pending occurrence 的完整页面写入 smoke | `resources/js/components/Senses/SenseReview.vue`、`resources/js/components/Senses/SenseMappingReview.vue` | 直接影响复习评分、确认、拒绝、忽略、改绑、新建 | 后端 Feature tests + 空状态 MCP smoke | 否 | 必须 MCP Chrome 真实页面验收，且需要测试账号/数据准备 |

### 本轮实际落地

- 新增 `tests/Feature/TextBlockPhraseIndexingTest.php`。
- 锁定 `TextBlockService::updatePhraseIds()` 的 exact match、跨 `NEWLINE` match、缺词 early return。
- 锁定 `ReaderDataService` 路径下 `TextBlockService::indexPhrases()` 的 phrase id → phraseIndexes 映射和用户/语言隔离。
- 不改 `TextBlockService.php`、`ReaderDataService.php`、Vue、Controller、数据库结构、权限、FSRS、WordSense 删除语义。
- 下一轮建议优先在以下三者中选一项：`FsrsRescheduleConfirmApply-SafeWriteContractTests-1`、`SenseReview-FullWriteSmoke-1`、`TextBlockService-TokenizerFallbackScouting-1`。

## 2.2 Codex-ArchitectureFinalGoalMode-1 增量审计（2026-07-02）

> **基准 commit**：`daa2e6f docs: finalize Codex artifact cleanup`
> **性质**：面向 sense-only 最终架构目标的第二轮增量优化。不是完成全部架构优化。

### P0：数据安全或用户状态风险

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| 本轮未发现新的 P0 | — | — | — | 否 | 当前高危写入仍集中在 FSRS apply、WordSense 删除/归档、import；本轮只补拒绝写入路径测试，不改变任何写入语义 |

### P1：高收益、低风险、测试可覆盖的架构优化

| 问题 | 文件 | 用户影响 | 为什么是架构问题 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|---|
| FSRS confirmAndApply 高风险拒绝路径缺少明确“不写”断言 | `app/Services/FsrsReschedulePreviewService.php`、`tests/Feature/FsrsRescheduleConfirmTest.php` | 若未来改动误让高风险未二次确认或 blocked 超量路径写入，会批量改动 sense review card 到期时间，影响日常复习队列 | confirmAndApply 是 sense-only 复习系统里最大批量写入口之一；拒绝路径必须和成功写入路径一样有 contract tests | 新增 `test_confirm_apply_true_high_risk_without_risk_confirm_does_not_write` 和 `test_confirm_apply_true_blocked_risk_does_not_write`，锁定 ReviewCard 字段、ReviewLog count、RescheduleSnapshot count | 是 | 只加强测试，不改 Service；直接推进“FSRS 只服务真实 sense card，legacy word card 不回主线”的安全边界 |
| TextBlockService 旧 ReaderDataService fallback 分支仍待确认是否可删除 | `app/Services/TextBlockService.php` | 暂无直接用户影响，但继续保留会增加 reader 数据链路理解成本 | ReaderDataService 已抽出，旧分支可能成为未来 agent 误改点 | ReaderDataService tests + TextBlockPhraseIndexingTest | 否 | 需要单独只读确认调用路径；删除兼容分支可能触及阅读页核心契约 |
| ReviewCardManageController `logs()` payload 仍在 Controller 内 | `app/Http/Controllers/ReviewCardManageController.php` | 管理页复习历史抽屉字段若漂移会影响用户追溯 ReviewLog | 查询/导出/行序列化已抽 Service，logs payload 仍是未收敛边界 | ReviewCardManageTest logs 相关断言 | 否 | 与本轮 FSRS 写入测试无关；建议后续单独抽 serializer/service 或先补 payload contract |

### P2：可做但不急的结构清理

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| SenseReview 有卡片 / pending occurrence 页面流程仍缺完整 MCP Chrome 写入 smoke | `resources/js/components/Senses/SenseReview.vue`、`resources/js/components/Senses/SenseMappingReview.vue` | 评分、确认、拒绝、忽略、改绑、新建是 sense-only 用户主流程 | 后端 Feature tests + 空状态 smoke | 否 | 需要真实页面账号/数据准备；本轮未改 Vue，不做 API 代替页面验收 |
| VocabularyService 查询/导入/导出职责仍宽 | `app/Services/VocabularyService.php` | 搜索、CSV 导出、导入后处理可能互相影响 | VocabularySearchTest / DictionaryImport tests | 否 | 需要单独拆分计划；不是本轮最高风险写入 |

### 暂缓：风险大、需要产品决策或页面验收成本高

| 问题 | 文件 | 用户影响 | 测试护栏 | 本轮处理 | 原因 |
|---|---|---|---|---|---|
| FSRS confirmAndApply 写入语义调整、stale candidate 事务 hook、appliedCount=0 snapshot 语义 | `app/Services/FsrsReschedulePreviewService.php` | 可能改变大量 ReviewCard 到期时间或撤销语义 | FsrsReschedule 系列 98 tests | 否 | 会改变或重新定义写入语义，需要单独侦查和产品确认；本轮只锁定拒绝路径不写 |
| WordSense 删除 / 归档 / 恢复语义调整 | `app/Services/WordSenseService.php`、相关 controllers | 可能影响 ReviewCard、ReviewLog、Occurrence、EncounteredWord | WordSenseDestroyRestoreTest / ReviewCardManageTest | 否 | 产品语义已做取舍，本轮不改删除/归档/恢复 |
| TextBlockGroup.vue 组件拆分 | `resources/js/components/Text/TextBlockGroup.vue` | 阅读页点词、hover、查词侧栏均受影响 | TextBlockGroup smoke baseline | 否 | 高价值但需要页面验收；不适合与 FSRS 后端测试同轮混做 |

### 本轮实际落地

- 修改 `tests/Feature/FsrsRescheduleConfirmTest.php`，新增 2 个 confirmAndApply 拒绝写入 contract tests。
- 高风险未 `risk_confirm`：断言 HTTP 422、`risk_level=high`、`write_enabled=false`，并确认 ReviewCard 字段、ReviewLog、RescheduleSnapshot 均未变化。
- blocked 超量：断言 HTTP 422、`risk_level=blocked`、`requires_risk_confirm=false`，即使传 `risk_confirm=true` 也不写 ReviewCard、不建 snapshot、不写 ReviewLog。
- 不改 `FsrsReschedulePreviewService.php`、`FsrsSchedulingService.php`、`ReviewCardService.php`、Vue、Controller、数据库结构、FSRS 参数算法、ReviewLog 保留语义。
- 下一轮建议优先在以下三者中选一项：`SenseReview-FullWriteSmoke-1`、`TextBlockService-TokenizerFallbackScouting-1`、`ReviewCardManage-LogsPayloadBoundary-1`。

## 2.3 Codex-ProjectDocsGovernanceTargetMode-1 文档治理审计（2026-07-02）

> **基准 commit**：`aea1a6a docs: plan AI study card workflow`
> **性质**：只做文档治理，不做代码架构重构。

### 上下文风险：旧文档污染

| 风险 | 文件 | 影响 | 本轮处理 | 后续要求 |
|---|---|---|---|---|
| 旧 next/current/final 文档被 agent 当作当前入口 | `docs/NEXT_TASK.md`、`docs/CURRENT_STATUS.md`、`docs/FSRS_FINAL_STATUS.md` | 可能让 Codex 从旧任务开始执行，绕过当前 handoff 和 master plan | 顶部加历史标记，新增 `docs/HISTORY_INDEX.md` | 新任务必须先读 `docs/DOCUMENTATION_INDEX.md` |
| 旧 FSRS phase 文档分散且名字像状态源 | `docs/FSRS_PHASE*.md`、`docs/FSRS_NEXT_STEPS.md`、`docs/FSRS_USER_GUIDE.md` | 可能把旧 FSRS 分阶段计划误读为当前 sense-only 主线 | 顶部加历史标记，历史索引归类 | 当前 FSRS/sense-only 以 master plan、handoff、ADR-0002 为准 |
| 旧 handoff 名称吸引 Codex 优先读取 | `docs/CODEX_HANDOFF.md`、`docs/handovers/**` | Codex 可能从旧交接文档恢复过期上下文 | 顶部加历史标记，current-working-handoff 明确当前入口 | 不从旧 handoff 直接开始任务 |
| 软规则缺硬验证 | 多个计划/协作规则 | 文档写了“不要破坏”，但未来仍可能被忽略 | 新增 `docs/plans/spec-to-harness-candidates.md` | 每轮只选一个候选转 tests / smoke / harness |

### 后续候选保留

| 候选 | 状态 | 为什么仍保留 |
|---|---|---|
| TextBlock fallback tests | 已实现 | 最小测试缺口已由 Codex-SpecToHarnessHardeningTargetMode-1 关闭；后续只在要拆 tokenizer 服务时再扩展 |
| ReviewCardManage logs contract tests | 已实现 | 最小 payload/filter contract tests 已由 Codex-SpecToHarnessHardeningTargetMode-1 关闭；后续只在要抽 serializer/service 时再扩展 |
| SenseReview occurrence / FullMenu MCP Chrome smoke | 未实现 | 有数据页面写入流程仍需要真实浏览器验收 |
| AIStudyCard pending marker scaffolding | 已完成 | 第一版 pending marker 已有独立表/API/tests；后续推荐弹窗与生成闭环仍需单独架构侦查，不能直接写死 DB/API |

### 本轮成果边界

- 本轮降低的是 AI 上下文误读风险，不是完成全部架构优化。
- 不改变 `TextBlockService`、`ReviewCardManageController`、`SenseReview`、FSRS、WordSense 或 AI study card 代码。
- 不把 AI 示意卡写成已实现；只把已拍板边界抽为 ADR，后续实现仍需独立任务和页面验收。

## 2.4 Codex-SpecToHarnessHardeningTargetMode-1 增量审计（2026-07-02）

> **性质**：把已记录的软规则转为第一轮低风险测试护栏；不做业务实现，不做架构重构。

### 本轮选择

| 软规则 | 文件 | 本轮硬化 | 边界 |
|---|---|---|---|
| TextBlock fallback tokenizer 不应静默漂移 | `tests/Unit/TextBlockFallbackTokenizerTest.php` | 新增 3 个 tests：保守 lemma/irregular table、安全标记+数字+标点、空文本异常 | 不改 `TextBlockService`，不改 tokenizer/import/ReaderDataService 语义 |
| ReviewCardManage logs payload 稳定 | `tests/Feature/ReviewCardManageTest.php` | 新增 2 个 tests：精确字段顺序+ISO 日期格式、同一 review_card_id 下仍按 user/language 过滤 | 不改 `ReviewCardManageController`，不改 response 结构 |

### 本轮暂缓

| 候选 | 暂缓原因 |
|---|---|
| SenseReview FullMenu / occurrence 写入 smoke | 必须用 MCP Chrome、真实页面和 marker test data；应作为独立页面 smoke 任务 |
| Legacy word card mainline guard | `ReviewFsrsTest` 已有 sense-only queue / word-card exclusion 覆盖；暂不重复 |
| ReviewLog preservation | `ReviewCardManageTest` 和 `WordSenseDestroyRestoreTest` 已覆盖默认保留语义；暂不重复 |
| AI study card future harness | 当前只有 ADR/产品边界，没有实现/schema/API；实现前只能做架构侦查 |

## 2.5 Codex-FinalArchitectureClosureTargetMode-1 架构收口结论（2026-07-02）

> **基准 commit**：本轮新 commit `docs: finalize architecture closure plan`
> **性质**：架构收口阶段总收束。新增三份冻结文档，不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema。

### 收口结论

| 维度 | 结论 |
|---|---|
| 旧系统地基 | WordSense / ReviewCard / ReviewLog / FSRS / EncounteredWord / WordSenseOccurrence / TextBlock 各自职责已检查；不可乱改区已写入 `final-architecture-closure-report.md` §3 |
| sense-only 复习主线边界 | 已清楚：`target_type=sense` 是主线，`target_type=word` 是 legacy 兼容层，不删除、不回日常主线 |
| 已有硬护栏 | FSRS 拒绝写入 contract tests、TextBlock phrase/index 测试、TextBlock fallback 测试、ReviewCardManage logs payload contract tests、WordSense 删除/归档/恢复 contract tests、SenseReview smoke harness（marker data + 命令测试 + playbook + MCP Chrome 真实页面验收）、文档入口和历史降权规则 |
| 高风险区 | TextBlockGroup.vue / VocabularySideBox.vue / WordSensesList.vue / reader 页面状态流 / Vuex/store / WordSense / ReviewCard / FSRS / AI lookup / sense-only review / import-export / source context / review scheduling —— 改动前必须先过 Architecture Gate |
| 文档入口 | `DOCUMENTATION_INDEX.md` 为入口；旧 `NEXT_TASK` / `CURRENT_STATUS` / `FSRS_PHASE*` / 旧 handoff 已降权为历史参考 |
| 架构阻塞 | 不存在必须先解决的架构阻塞；可以进入 AI 示意卡第一版开发设计 |

### 五条主线最终进度

| 主线 | 上轮 | 本轮 | 说明 |
|---|---|---|---|
| 总体架构收口 | 81% | 100% | 架构收口阶段结束；100% 不代表全项目完成 |
| 复习主线稳定 | 86% | 91% | SenseReview smoke harness + FSRS 拒绝写入 contract tests 已补 |
| 页面真实验收 | 90% | 91% | MCP Chrome 只读复核 6 个入口 |
| AI 示意卡规划 | 25% | 55% | 架构侦查完成 + 第一版路线冻结 |
| 前端入口整理 | 50% | 65% | 统一方向冻结（不实现） |

### 下一阶段路线（三步）

1. **AI 示意卡最小可用版**：阅读页点词 → "待 AI 解释"按钮 → 后端只记录 pending item。详见 `docs/plans/ai-study-card-v1-frozen-plan.md`。
2. **前端复习入口统一**：首页"开始复习"指向 `/reviews/senses`、导航栏合并为"复习"、"复习卡管理"归组到"高级"。详见 `docs/plans/frontend-review-entry-unification-plan.md`。
3. **AI 推荐弹窗和卡片生成闭环**：未来路线，不在本轮冻结。

### 本轮落地

- 新增 `docs/plans/final-architecture-closure-report.md`、`docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/plans/frontend-review-entry-unification-plan.md`。
- 更新 `current-working-handoff.md`、`linguacafe-master-plan.md`、`repo-architecture-hotspot-audit.md`（本文）、`spec-to-harness-candidates.md`、`DOCUMENTATION_INDEX.md`。
- MCP Chrome 只读复核阅读页、查词侧栏、AI 阅读辅助按钮、复习入口、词义确认入口、复习卡管理入口。
- 不改业务代码、测试、Vue、Controller、Service、routes、migration、DB schema、FSRS 语义、删除/归档/恢复语义、ReviewLog 保留语义、legacy word card 兼容层、SenseReview、SenseMappingReview。

## 2.6 Codex-AIStudyCardV1-And-ReviewEntryUnification-1 增量审计（2026-07-02）

> **性质**：第一版最小实现 + 前端入口统一第一轮。实现前已按 Architecture Gate 复核责任边界，实际改动控制在 pending item 独立边界、阅读侧栏按钮和入口跳转。

### 本轮落地

- 新增独立 pending marker 后端边界：`AiStudyCardPendingItem`、`AiStudyCardPendingItemService`、`AiStudyCardPendingItemController`、`ai_study_card_pending_items`。
- 新增 `POST /ai-study-card/pending-items`，只记录用户在阅读页选择的单词待解释意图。
- `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 增加「待 AI 解释」按钮；不改 `TextBlockGroup.vue` 主选择流程。
- 首页「开始复习」与导航「复习」进入 `/reviews/senses`；旧 `/senses/review`、`/review-cards/manage`、legacy `/review/false/-1/-1` 保留。

### 边界与测试护栏

| 风险 | 本轮处理 |
|---|---|
| pending item 污染 WordSense / ReviewCard / ReviewLog / EncounteredWord | `AiStudyCardPendingItemTest` 反向 contract 覆盖不写这些表 |
| 用户/语言/章节越权 | Service 内按当前用户 `selected_language` + chapter ownership 查询，feature tests 覆盖 |
| 重复点击无限新增 | pending unique key + service 幂等查询，feature test 覆盖 |
| 复习主线入口仍走 legacy | 首页与导航改为 `/reviews/senses`，MCP Chrome 验收 |
| 误删旧兼容入口 | 路由未删除，MCP Chrome 验收旧入口仍可打开 |

### 本轮未做

- 不调用真实 AI，不保存 AI key，不新增 AI 配置。
- 不生成 AI 释义，不生成 WordSense/ReviewCard/ReviewLog。
- 不改 FSRS、ReviewCard reset/delete/archive/restore、WordSense 删除/归档/恢复。
- 不删除 SenseReview、SenseMappingReview、legacy word review 兼容层。

### 五条主线进度

| 主线 | 本轮后 |
|---|---|
| 总体架构收口 | 100% |
| 复习主线稳定 | 94% |
| 页面真实验收 | 96% |
| AI 示意卡规划 | 90% |
| 前端入口整理 | 92% |

## 2.7 GLM-AIStudyCardV2-GenerationLoop-1 增量审计（2026-07-02）

> **性质**：AI 示意卡 V2 生成闭环第一阶段。实现前已按 Architecture Gate 复核责任边界，实际改动控制在 pending item 列表/取消/恢复后端边界、阅读侧栏列表/预览前端组件。

### 本轮落地

- 新增 `GET /ai-study-card/pending-items`（支持 `chapter_id` 过滤，只返回当前用户/当前语言/pending 状态）。
- 新增 `POST /ai-study-card/pending-items/{id}/dismiss`（pending → dismissed，不物理删除，幂等）。
- 新增 `POST /ai-study-card/pending-items/{id}/restore`（dismissed → pending，检查 unique 冲突）。
- 改造 `AiStudyCardPendingItemService::createOrGetPending()`：若同 key 存在 dismissed 项，恢复为 pending 而非新建。
- `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 新增「待 AI 解释列表」按钮、列表面板（含取消按钮）、「生成 AI 示意卡」按钮、预览弹窗雏形（安全说明 + 用户已选词 chips + AI 推荐词占位 + 规则预览 + disabled 确认按钮）。
- 新增 `docs/plans/ai-study-card-v2-generation-loop-plan.md`。

### 边界与测试护栏

| 风险 | 本轮处理 |
|---|---|
| pending item 列表泄露其他用户/语言数据 | Service 内按当前用户 `selected_language` + chapter ownership 查询，feature tests 覆盖用户隔离/语言隔离/章节归属 404 |
| dismiss/restore 污染 WordSense/ReviewCard/ReviewLog | 反向 contract tests 覆盖 dismiss/restore 不写这些表 |
| dismiss 后重复标记产生重复行 | `createOrGetPending()` 检测 dismissed 行并恢复，feature tests 覆盖 store 路径和 restore 路径 |
| restore 与已有 pending 行 unique 冲突 | Service 内检查是否已有 pending 行，feature test 覆盖 |
| 预览弹窗误触发 AI 调用 | 预览纯前端展示，MCP Chrome 验收确认无 AI 网络请求 |
| 预览弹窗误生成复习卡 | MCP Chrome 验收确认 WordSense=19/ReviewCard=16/ReviewLog=2 未变 |

### 本轮未做

- 不调用真实 AI，不保存 AI key，不新增 AI 配置。
- 不生成 AI 释义，不生成 WordSense/ReviewCard/ReviewLog。
- 不改 FSRS、ReviewCard reset/delete/archive/restore、WordSense 删除/归档/恢复。
- 不删除 SenseReview、SenseMappingReview、legacy word review 兼容层。
- 不新增 migration（复用 V1 `ai_study_card_pending_items` 表）。

### 五条主线进度

| 主线 | V1 后 | V2 后 |
|---|---|---|
| 总体架构收口 | 100% | 100% |
| 复习主线稳定 | 94% | 96% |
| 页面真实验收 | 96% | 100% |
| AI 示意卡规划 | 90% | 100% |
| 前端入口整理 | 92% | 98% |

### 子阶段进度

| 子阶段 | V2 后 | 说明 |
|---|---|---|
| AI 示意卡生成闭环 | 70% | 列表/取消/预览雏形已完成。**70% 是子阶段进度，非五条主线虚假上调。** AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。 |

## 2.8 GLM-AIStudyCardV3-SafePreviewPackage-1 增量审计（2026-07-02）

> **性质**：AI 示意卡 V3 安全生成包。实现前已按 Architecture Gate 复核，本轮在 V2 pending item 列表/取消/恢复雏形边界上做最小扩展：补 dismissed 视图与恢复按钮、把预览弹窗从纯占位升级为真实预览、新增安全生成包接口。改动仍集中在 pending item Service/Controller/routes、阅读侧栏 VocabularySideBox/VocabularyBox、相关 feature tests、计划文档。

### 本轮落地

- 扩展 `GET /ai-study-card/pending-items` 支持 `status=pending|dismissed|all` 过滤（默认仍为 `pending`，向后兼容）。
- 新增 `POST /ai-study-card/pending-items/preview-package` 后端接口：返回 `schema_version=ai-study-card-preview-package-v1` 的安全 JSON 包，含 `selected_items` / `generation_rules`（4 条） / `safety_flags`（4 条 no_ai_called / no_review_card_created / no_word_sense_created / no_fsrs_changed） / `created_at`。
- 路由放置在 `{id}` 通配路由之前，避免 `preview-package` 被误匹配为 id。
- `AiStudyCardPendingItemService::buildPreviewPackage()`：用户/语言/状态三重隔离；最多 100 项；空 `item_ids` 返回 422；不调用 AI；不生成 WordSense/ReviewCard/ReviewLog；不触发 FSRS；不改变 pending item 状态。
- `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 升级：v-btn-toggle 切换「待解释/已取消」视图、已取消项显示「恢复」按钮调用现有 restore 接口、真实预览弹窗（用户已选词列表 + 来源句子 + 章节位置 + 数量 + 状态 + 安全说明 + 每项 checkbox + AI 推荐词占位区域 + 未来生成规则说明）、「准备生成」按钮触发后端 preview-package 接口、JSON 只读 textarea 展示、「复制生成包」按钮 + 成功/失败 toast。
- 前端 checkbox 状态只在前端 state 中（不写 DB）：全不选时禁用「准备生成」按钮。
- 新增 `docs/plans/ai-study-card-v3-safe-preview-package-plan.md`。

### 边界与测试护栏

| 风险 | 本轮处理 |
|---|---|
| dismissed 列表泄露其他用户/语言数据 | Service 内按当前用户 `selected_language` 过滤；feature tests 覆盖用户隔离、语言隔离、`status=all` 时仍只返回当前用户/当前语言 |
| restore 接口被滥用恢复他人 item | restore 已在 V2 落地用户隔离；V3 feature tests 复核 A 用户不能 restore B 用户 item、restore 不生成学习数据 |
| preview-package 泄露其他用户/语言/状态 item | Service 内三重过滤：user_id / language_id / status=pending；feature tests 覆盖用户/语言/状态隔离，dismissed item 不能进入生成包 |
| preview-package 改变 pending item 状态 | Service 不修改 pending item；feature test 覆盖 preview-package 后 status 不变 |
| preview-package 误调用 AI | Service 内不调用任何 AI 服务；MCP Chrome 验收确认 Network 只有 127.0.0.1 请求 |
| preview-package 误生成 WordSense/ReviewCard/ReviewLog | Service 不写这些表；feature tests 反向 contract 覆盖 count 不变；MCP Chrome 验收确认 WordSense=19/ReviewCard=16/ReviewLog=2 未变 |
| preview-package 误触发 FSRS | Service 不调用 FSRS；feature tests 覆盖 ReviewCard 字段（含 due_at / stability / difficulty / fsrs_state）不变 |
| 空 `item_ids` 越过校验 | Controller `required|array|min:1`；Service 二次检查 empty 返回 422；feature test 覆盖 |
| 超 100 项 DoS | Service 内 `count > 100` 返回 422；feature test 覆盖 |
| 前端预览弹窗误触发 AI 调用 | 前端纯展示，只通过「准备生成」按钮调用本地 preview-package 接口；MCP Chrome 验收确认 |
| 前端「准备生成」全不选时仍可点击 | 前端 `:disabled="selectedItemIds.length === 0"` 绑定；MCP Chrome 验收确认全不选时按钮 disabled |
| 复制生成包失败静默 | 前端 `navigator.clipboard.writeText` Promise reject 时显示「复制失败」toast；MCP Chrome 验收确认成功提示 |
| 用户已选词与未来 AI 推荐词重复 | `generation_rules.ai_recommended_exclude_user_selected=true` 在生成包 schema 中固定；feature test 覆盖 schema 形状 |
| AI 推荐词默认全选 | `generation_rules.ai_recommended_default_unchecked=true` 在生成包 schema 中固定；前端 AI 推荐词区域为占位且明确写「默认不选」；feature test 覆盖 schema 形状 |

### 本轮未做

- 不调用真实 AI，不保存 AI key，不新增 AI 配置页。
- 不生成 AI 释义，不生成 WordSense/ReviewCard/ReviewLog。
- 不改 FSRS、ReviewCard reset/delete/archive/restore、WordSense 删除/归档/恢复。
- 不删除 SenseReview、SenseMappingReview、legacy word review 兼容层。
- 不新增 migration（复用 V1 `ai_study_card_pending_items` 表）。
- 不在 DB 中保存前端 checkbox 勾选状态（本轮纯前端 state）。
- 不在 DB 中保存生成包（生成包是临时 JSON，复制即走）。

### 五条主线进度

| 主线 | V2 后 | V3 后 |
|---|---|---|
| 总体架构收口 | 100% | 100% |
| 复习主线稳定 | 96% | 96% |
| 页面真实验收 | 100% | 100% |
| AI 示意卡规划 | 100% | 100% |
| 前端入口整理 | 98% | 100% |

### 子阶段进度

| 子阶段 | V2 后 | V3 后 | 说明 |
|---|---|---|---|
| AI 示意卡生成闭环 | 70% | 95% | dismissed 视图/恢复按钮/真实预览/安全生成包已完成。**95% 是子阶段进度，非五条主线虚假上调。** AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。 |
| AI 生成安全契约 | — | 55% | 新增子阶段。schema_version=ai-study-card-preview-package-v1 + 4 条 generation_rules + 4 条 safety_flags + 14 个 V3 feature tests 覆盖用户/语言/状态隔离与反向 contract。AI 真实调用、AI 推荐词、用户确认生成等阶段仍未实现。 |

> **本轮合计提升 80%（25 + 55）是子阶段进度提升，不是固定五条主线的虚假上涨。** 固定五条主线仅在「前端入口整理」从 98% 提到 100%，其余保持。AI 推荐词仍未实现，AI 释义生成仍未实现，WordSense/ReviewCard 生成闭环仍未实现，外部 AI 调用仍未实现。

## 2.9 GLM-AIRecommendationConfirmationLoop-V4-1 增量审计（2026-07-02）

> **性质**：AI 示意卡 V4 AI 推荐词确认闭环。实现前已按 Architecture Gate 复核，本轮在 V3 安全生成包边界上做最小扩展：补 AI 推荐词粘贴导入、解析容错、前端 + 后端双重去重、AI 推荐词默认不选、用户勾选确认、最终候选包接口。改动仍集中在 pending item Service/Controller/routes、阅读侧栏 VocabularySideBox/VocabularyBox、相关 feature tests、计划文档。

### 本轮落地

- 新增 `POST /ai-study-card/pending-items/final-candidates-package` 后端接口：返回 `schema_version=ai-study-card-final-candidates-v1` 的安全 JSON 包，含 `user_selected_items` / `ai_recommended_selected_items` / `ai_recommended_unselected_items` / `dedupe_summary` / `generation_rules`（5 条，新增 `user_confirmation_required_before_card_generation`） / `safety_flags`（6 条，新增 `ai_response_pasted_by_user` 与 `user_confirmation_required_before_card_generation`） / `created_at` / `source_preview_package_schema_version`。
- 路由放置在 `{id}` 通配路由之前，避免 `final-candidates-package` 被误匹配为 id。
- `AiStudyCardPendingItemService::buildFinalCandidatesPackage()`：
  - 用户/语言/状态三重隔离：只打包当前用户、当前语言、pending 状态的 item。
  - 后端二次去重：AI 推荐词与用户已选词去重、AI 推荐词内部去重、unselected_ai_recommendations 与 selected_ai_recommendations 之间去重。
  - 空结果保护：`selected_item_ids` 与 `selected_ai_recommendations` 都为空 → 返回 422；查询后用户已选词被全部过滤掉且 AI 推荐词也为空 → 返回 422。
  - 数量上限：最多 100 个用户已选词；最多 200 个 AI 推荐词（selected + unselected）。
  - 字段容错：缺 `word` 丢弃；缺 `lemma` 用 `word` 代替；缺 `surface` 用 `word` 代替；缺 `confidence` 为 null；缺 `reason` 为「无说明」；缺 `sentence_text` 为 null。
  - 不调用 AI；不生成 WordSense/ReviewCard/ReviewLog；不触发 FSRS；不改变 pending item 状态；不保存 AI 推荐词或勾选状态到 DB。
- `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 升级 V4 完整 UI：
  - 「粘贴 AI 推荐词 JSON」文本框（v-textarea multi-line）+ 「解析推荐词」「清空推荐词」按钮。
  - 解析错误提示（JSON 格式错误 / recommended_items 不是数组 / 全部无效）。
  - 解析摘要（original_count / valid_count / dropped_missing_word / dropped_duplicate_with_user / dropped_ai_internal_duplicate）。
  - AI 推荐词列表带 checkbox（默认 unchecked）+ 「全选推荐词」「全不选推荐词」按钮。
  - 用户已选词区域与 AI 推荐词区域使用 v-divider 视觉分区，不混成一组。
  - 「生成最终候选包」按钮（v-card-actions 中，与 V3「准备生成」并列）。
  - 最终候选包 JSON 展示区 + 「复制最终候选包」按钮 + 成功/失败 toast。
- 前端 `parseAiRecommendations()` 实现：JSON.parse 容错 + recommended_items 数组校验 + 用户已选词 lemma/word 集合预构建 + AI 推荐词 lemma 优先去重 + 缺 word 丢弃 + 默认 unchecked（aiSelectedRecommendationIndices = []）。
- 前端 `redeedupeAiRecommendationsAfterUserSelectionChange()` 实现：用户勾选/取消用户已选词后，自动重新过滤 AI 推荐词，避免出现重复词显示。
- 新增 `docs/plans/ai-recommendation-confirmation-loop-plan.md`。

### 边界与测试护栏

| 风险 | 本轮处理 |
|---|---|
| final-candidates-package 泄露其他用户/语言/状态 item | Service 内三重过滤：user_id / language_id / status=pending；feature tests 覆盖用户隔离、语言隔离、状态隔离（dismissed 不能进入） |
| final-candidates-package 改变 pending item 状态 | Service 不修改 pending item；feature test 覆盖 status 不变 |
| final-candidates-package 误调用 AI | Service 内不调用任何 AI 服务；MCP Chrome 验收确认 Network 仅本地请求 |
| final-candidates-package 误生成 WordSense/ReviewCard/ReviewLog | Service 不写这些表；feature tests 反向 contract 覆盖 count 不变 |
| final-candidates-package 误触发 FSRS | Service 不调用 FSRS；feature test 覆盖 ReviewCard 8 个 FSRS 字段（fsrs_state/fsrs_due_at/fsrs_stability/fsrs_difficulty/fsrs_reps/fsrs_lapses/fsrs_last_reviewed_at/fsrs_enabled）不变 |
| AI 推荐词与用户已选词重复 | 前端 parseAiRecommendations 先去重 + 后端二次去重；feature test 覆盖 |
| AI 推荐词内部重复 | 前端 seenKeys 去重 + 后端二次去重；feature test 覆盖 |
| AI 推荐词默认全选 | 前端 aiSelectedRecommendationIndices = []；后端 generation_rules.ai_recommended_default_unchecked=true；feature test 覆盖 unselected_ai_recommendations 字段 |
| 空 selected_item_ids + 空 selected_ai_recommendations 越过校验 | Controller nullable array + Service empty 检查返回 422；feature test 覆盖 |
| 查询后空结果（item_ids 非空但被三重隔离过滤掉）越过校验 | Service 在 userSelectedItems 映射后再次检查 empty(userSelectedItems) && empty(cleanSelectedAi) 返回 422；feature tests 覆盖用户隔离、语言隔离、状态隔离三类场景 |
| 超 100 用户已选词 / 超 200 AI 推荐词 DoS | Service 内 count 检查返回 422；feature test 覆盖 |
| AI 推荐词 unselected 与 selected 之间重复 | 后端二次去重时把 selected 的 key 加入 seenAiKeys，再过滤 unselected；feature test 覆盖 |
| 前端粘贴 malformed JSON 致页面崩溃 | 前端 try/catch JSON.parse + 显示「JSON 格式错误：...」+ 不崩溃；MCP Chrome 验收确认 |
| 前端「生成最终候选包」按钮在无任何勾选时仍可点击 | 前端 `:disabled` 绑定 selectedItemIds.length === 0 && aiSelectedRecommendationIndices.length === 0；MCP Chrome 验收确认 |
| 复制最终候选包失败静默 | 前端 `navigator.clipboard.writeText` Promise reject 时显示「复制失败」toast；MCP Chrome 验收确认成功提示 |
| 用户已选词被 AI 推荐词覆盖 | 前端 V3/V4 用户已选词区域保留 V3 勾选状态；AI 推荐词在独立分区；MCP Chrome 验收确认 |
| 重复词显示两次 | 前端 parseAiRecommendations 去重；redeedupeAiRecommendationsAfterUserSelectionChange 在用户取消勾选时重新过滤；MCP Chrome 验收确认 |

### 本轮未做

- 不调用真实 AI，不保存 AI key，不新增 AI 配置页。
- 不生成 AI 释义，不生成 WordSense/ReviewCard/ReviewLog。
- 不改 FSRS、ReviewCard reset/delete/archive/restore、WordSense 删除/归档/恢复。
- 不删除 SenseReview、SenseMappingReview、legacy word review 兼容层。
- 不新增 migration（复用 V1 `ai_study_card_pending_items` 表）。
- 不在 DB 中保存 AI 推荐词或前端 checkbox 勾选状态（本轮纯前端 state + 后端响应）。
- 不在 DB 中保存最终候选包（最终候选包是临时 JSON，复制即走）。
- 不把最终候选包发送给外部 AI。

### 五条主线进度

| 主线 | V3 后 | V4 后 |
|---|---|---|
| 总体架构收口 | 100% | 100% |
| 复习主线稳定 | 96% | 96% |
| 页面真实验收 | 100% | 100% |
| AI 示意卡规划 | 100% | 100% |
| 前端入口整理 | 100% | 100% |

### 子阶段进度

| 子阶段 | V3 后 | V4 后 | 说明 |
|---|---|---|---|
| AI 示意卡生成闭环 | 95% | 95% | 保持（V4 不改变 AI 示意卡生成闭环子阶段进度） |
| AI 生成安全契约 | 55% | 85% | V4 新增最终候选包 schema（`ai-study-card-final-candidates-v1`）+ 6 条 safety_flags + 5 条 generation_rules + 后端二次去重 + 18 个反向 contract tests。**85% 是子阶段进度，非五条主线虚假上调。** API key 安全存储、真实 AI 调用边界仍未实现。 |
| AI 推荐词确认闭环 | — | 80% | 新增子阶段。粘贴导入、去重、默认不选、用户确认、最终候选包已落地。**80% 是子阶段进度，非五条主线虚假上调。** AI 真实推荐（自动调用 AI 获取推荐词）仍未实现。 |

> **本轮合计提升 110%（30 + 80）是子阶段进度提升，不是固定五条主线的虚假上涨。** 固定五条主线全部保持不变。AI 真实推荐仍未实现，AI 释义生成仍未实现，WordSense/ReviewCard 生成闭环仍未实现，外部 AI 调用仍未实现。

## 2.10 Codex-LegacyEntry-FinishedReading-ExampleGuard-1 增量审计（2026-07-02）

> **性质**：产品原则落地的第一轮低风险执行。改动边界控制在普通查词 UI 旧入口隐藏、Finished reading 自动 known 分支安全过滤、测试护栏、路线冻结文档。不删除后端 legacy 兼容层，不删除 `target_type=word`，不改 FSRS / ReviewCard / WordSense 删除归档恢复语义。

### 本轮落地

- `WordSensesList.vue` 不再在普通查词区域显示 `legacyTranslation` 的旧词条释义 caption。
- `VocabularySideBox.vue` 和响应式 `VocabularyBox.vue` 隐藏旧版释义折叠编辑入口；兼容数据字段和现有传参保留。
- `ChapterService::finishChapter()` 的 `autoMoveWordsToKnown` 分支新增当前 `language` 过滤，避免构造 payload 改到同用户其他语言词。
- 新增 `tests/Feature/FinishedReadingSafetyTest.php`，锁定 Finished reading 只将当前用户/当前语言 yellow `stage=2` 设为 known；green 学习词 stage、WordSense、ReviewCard、ReviewLog、FSRS 字段、其他用户、其他语言均保持。
- 新增 `tests/Feature/LegacyEntryUiGuardTest.php`，防止 `旧词条释义` / `旧版释义` / `旧版示意` / `legacy word review` 文案回到普通查词组件。
- 新增 `docs/plans/reading-inline-review-and-example-pool-plan.md`，冻结阅读中刷卡、多例句轮换、熟词僻义衔接、surface/lemma 绑定路线；本轮不实现。

### 边界与测试护栏

| 风险 | 本轮处理 |
|---|---|
| 普通用户继续看到旧入口 | 删除普通查词组件显式旧入口 UI，并新增源码 guard |
| 误删后端 legacy 兼容 | 未删除 `ReviewCard::TARGET_WORD`、legacy route/service/tests、DB 字段或兼容查询 |
| Finished reading 改到绿色学习词 | 新测试断言 `stage < 0` 的学习词 stage 不变；本轮不改变阅读页颜色系统 |
| Finished reading 写 ReviewCard / ReviewLog / FSRS | 新测试断言 ReviewCard count、ReviewLog count、8 个 FSRS 字段不变 |
| Finished reading 跨用户/跨语言 | 既有 user 过滤保留；本轮新增 language 过滤并用测试锁定 |
| 把阅读中刷卡写成已实现 | 仅新增路线冻结文档，明确未实现 |

### 本轮未做

- 不删除 legacy word card 兼容层。
- 不删除 `target_type=word`。
- 不删除 SenseReview、SenseMappingReview、legacy route/service/tests。
- 不改 FSRS 主流程。
- 不改 ReviewCard / WordSense 删除、归档、恢复语义。
- 不实现阅读中刷卡、多例句轮换、真实 AI 调用、WordSense/ReviewCard 生成。

### 子阶段进度

| 子阶段 | 本轮前 | 本轮后 | 说明 |
|---|---|---|---|
| 旧版入口清理执行 | 0% | 80% | 普通查词入口已隐藏，并加源码 guard；后端兼容层未删。 |
| Finished reading 安全护栏 | 0% | 50% | 新增测试护栏并修复 language 过滤；仍需真实页面验收环境稳定后复跑。 |
| 阅读中复习 / 多例句轮换路线 | 20% | 50% | 路线冻结文档已补齐；未实现。 |

> **本轮合计提升 160%（80 + 50 + 30）是三个子阶段进度提升，不是固定五条主线的虚假上涨。**

## 3. 全仓库热点总览

| 文件 | 行数 | 职责 | 风险等级 | 测试状态 | 优先级 | 建议 |
|------|------|------|----------|----------|--------|------|
| `app/Services/TextBlockService.php` | 1271 | 分词、tokenizer 调用、生词创建、token 处理 | 🔴 高 | 极少直接测试 | **A-继续拆 EncounteredWord 创建** |
| `app/Services/VocabularyService.php` | 995 | 词汇搜索、分页、导入后词汇处理 | 🟡 中 | 部分 | **A-查询/搜索测试** |
| `app/Services/DictionaryImportService.php` | 990 | 词典导入（ECDICT/Stardict/EPWING） | 🟡 中 | 极少 | **A-契约锁定** |
| `resources/js/components/Text/TextBlockGroup.vue` | 2182 | 阅读页核心组件、hover/click/vocab box、Vuex 重度 | 🔴 高 | 无前端测试 | **B-Playwright smoke** |
| `resources/js/components/Senses/SenseReview.vue` | 649 | 词义确认流程 | 🟡 中 | 无前端测试 | B-补 smoke |
| `app/Services/FsrsReschedulePreviewService.php` | 715 | FSRS 重新排程预览/确认/应用 | 🔴 高 | 部分 | **暂不动** |
| `app/Services/SettingsService.php` | 772 | 用户设置读写 | 🟢 低 | 部分 | 不动 |
| `resources/js/components/ReviewCards/ReviewCardManage.vue` | 1842 | 复习卡管理页 | 🟢 低 | 后端 256 测试 | 不动 |
| `app/Services/SenseSourceContextService.php` | 328 | 原文位置查询门面 | 🟢 低 | 29 测试 | 已完成 |
| `resources/js/components/TextReader/TextReader.vue` | 646 | 阅读页顶层容器 | 🟢 低 | 无 | C-不动 |
| `resources/js/components/Senses/SenseReview.vue` | 649 | 词义确认 | 🟡 中 | 无 | B-补测试 |
| `app/Services/WordSenseService.php` | 359→413 | WordSense CRUD + `removeSenseFromReviewSystem` | 🔴 高 | 已有 | **暂不动** |
| `app/Services/WordSenseOccurrenceService.php` | 354 | Occurrence 管理 | 🟡 中 | 较少 | B-可拆 |
| `app/Services/ReviewCardService.php` | 251 | resetCard + ReviewCard 创建/管理 | 🟡 中 | 已有 | **暂不动** |
| `app/Http/Controllers/VocabularyController.php` | 399 | 词汇页搜索/筛选/导出 | 🟡 中 | 部分 | B-可拆 |
| `app/Http/Controllers/ReviewCardManageController.php` | 331 | 复习卡管理页后端 | 🟢 低 | 251 tests | **已完成抽取** |
| `app/Services/SenseTokenPayloadService.php` | 300 | Sense token 载荷构建 | 🟢 低 | 部分 | C-不动 |
| `app/Services/AiReadingAssistService.php` | 660 | AI 阅读辅助服务 | 🟡 中 | 有测试 | C-不动 |
| `app/Services/SettingsService.php` | 772 | 用户设置读写 | 🟢 低 | 部分 | C-不动 |

---

## 4. 后端架构热点

### 4.1 TextBlockService（最高优先级）

**当前职责**：
- 文本分词（调用 Python tokenizer，fallback 到英文内置）
- 处理 token 数据（mapStructuralTokens → processTokenizedWords）
- 创建 `EncounteredWord` 记录（`createNewEncounteredWords`，`DB::transaction` 加批量 insert）
- 为阅读页准备读者数据（`prepareTextForReader`，FSRS 熟悉度查询）
- 短语管理、字幕处理
- 多个 private helper 函数（fallback 分词、ECDICT 查询、不规则 lemma 映射）

**风险点**：
1. **单体过重**：1239 行（还在增长），职责覆盖 tokenizer 桥接、生词创建、阅读页准备、短语索引四种完全不同的事。
2. **Python tokenizer 不可用时的 fallback 逻辑**：fallback 使用 ECDICT 词典查 lemma，但 ECDICT 可能不存在。fallback 链复杂，出错难以调试。
3. **createNewEncounteredWords 事务内批量 insert**：未授权时可能影响大文本导入性能。
4. **读者数据准备和 FSRS 熟悉度查询耦合**：`loadFsrsFamiliarityLookup()` 依赖 review_cards/word_senses join，与阅读页展示紧耦合。
5. **低测试覆盖**：极少直接测试。`createNewEncounteredWords`、`prepareTextForReader` 等关键方法无独立测试。

**可拆分边界**：
- Tokenizer 桥接 → 独立 `TokenizerService`（接收文本 → 返回 token 数据结构）
- EncounteredWord 创建 → 去重合并到 `EncounteredWordService`（或 `VocabularyService`）
- 阅读页数据准备 → 独立 `ReaderDataService`（FSRS 熟悉度 + 阅读页 JSON 组装）
- 短语处理 → 继承或合并到现有短语相关逻辑

**不可碰边界**：
- Python tokenizer 协议（`postTokenizer` + Bottle HTTP 通信接口）
- 英文 fallback tokenizer 的 lemma 查找链（ECDICT + `conservativeFallbackLemma` + 不规则表）
- `getReaderData()` 的输出结构（TextBlockGroup.vue props 强依赖）

**建议下一步**：
1. 第一步：拆分 `prepareTextForReader` 和 `loadFsrsFamiliarityLookup` → 新 `ReaderDataService`。
2. 第二步：拆分 `createNewEncounteredWords` → 合入现有 `VocabularyService` 或专门 `EncounteredWordService`。
3. 第三步：拆分 `tokenizeRawText` + fallback 逻辑 → `TokenizerService`。
4. 每步独立 Phase，MCP Chrome 验收阅读页无变化。

### 4.2 SenseSourceContextService

**当前职责**：
630 行，负责查询原文位置（source context）用于阅读页词汇侧栏和复习卡详情页的"查看原文"功能。

**风险点**：
- `sourceContext()` 方法长且分支多，涉及 Chapter/WordSenseOccurrence/各种 token 数据。
- 依赖 `SenseTokenPayloadService`（300 行），职责边界模糊。
- 已有测试但测试量少。

**可拆分边界**：
- 查询层和渲染层分离（查询只返回原始数据，渲染由调用方决定）。
- 可以和 `SenseTokenPayloadService` 合并或明确划分。

**建议下一步**：
补少量 characterization tests → 然后做查询/渲染分离。低风险，高收益。

### 4.3 VocabularyService（可拆）

**当前职责**：
995→1176 行，分词、词汇搜索、导入后处理。依赖 TextBlockService、ReviewCardService、WordSenseService。

**风险点**：
- 职责宽（搜索 + 导入 + 词汇处理）。
- 使用 `DB::` 实现分页和过滤，部分逻辑与 `ReviewCardManageQueryService` 重复。
- 与 TextBlockService 职责重叠。
- 混合 `$itemsPerPage` 等实例属性。

**建议下一步**：
先补测试，再拆分搜索/查询逻辑。引入专用 `VocabularyQueryService`。

### 4.4 FsrsReschedulePreviewService（暂时不动）

**当前职责**：
715 行。FSRS 重新排程的 preview → confirm → apply 全链路。
- `preview()` 读取所有可选卡片 → 计算新 FSRS 参数 → 返回预览。
- `confirmAndApply()` 在 DB::transaction 中批量更新 review_cards。
- 快照机制（`FsrsRescheduleSnapshotService`）。

**风险点**：
1. **高风险批量写操作**：`confirmAndApply` 内 `DB::transaction` 批量更新 ReviewCard FSRS 参数，影响后续所有复习调度。
2. **预览和应用的耦合**：`preview` 生产 hash → `confirmAndApply` 校验 hash → 应用修改。但 preview 数据结构和 apply 数据结构混在同一 Service 中。
3. **FSRS 参数计算不可测试**：`buildPreviewForCard` 内部包含 FSRS 公式，但没有纯函数测试。

**建议下一步**：暂时不动。需要先 CodeBuddy 侦查 + 契约锁定，再进入编码。

### 4.5 WordSenseService（暂时不动）

**当前职责**：
359→413 行。WordSense CRUD + `removeSenseFromReviewSystem()`（核心删除函数）。

**风险点**：
- `removeSenseFromReviewSystem` 涉及 ReviewCard 硬删、WordSense rejected、EncounteredWord 条件性恢复、WordSenseOccurrence 清关联。**高风险核心函数**。
- 已有足够测试覆盖。

**建议下一步**：暂时不动核心语义。如果后续要做 destroy 单卡安全强化，只动 Controller 编排层。

### 4.6 ReviewCardService（暂时不动）

**当前职责**：
251 行。`resetCard()` + 创建 ReviewCard。

- `resetCard()` 已有 `lockForUpdate` + 事务 + ReviewLog 创建。8 个 characterization tests 已补。
- 短期不建议重构核心。

### 4.7 DictionaryImportService

**当前职责**：
990 行。多格式词典导入（ECDICT / Stardict / EPWING）。
- 混合文件解析 + 数据库写入。
- 导入过程复杂，依赖大量 I/O。

**风险点**：
- 极少测试覆盖。
- 格式解析和 DB 写入混合。
- EPWING 解析依赖外部库。

**建议下一步**：
补 characterization tests → 如果常出问题再考虑拆分解析和写入。

---

## 5. 前端架构热点

### 5.1 TextBlockGroup.vue（最高优先级前端）

**当前职责**：
2182 行。阅读页核心组件：
- 渲染 token 文本 + 颜色（熟悉度/新词/未知词）。
- hover 词汇 → 显示 VocabularyHoverBox（位置计算 + 字典查询）。
- click 词汇 → 打开 VocabularyBox（完整的查词/学习界面）。
- 与 Vuex store 交互（`hoverVocabularyBox`、`vocabularyBox`、`userTranslation` 等 store modules）。
- 文本选择、短语管理、Anki 设置、API 字典查询。

**风险点**：
1. **单体极重**（2182 行），hold 了 hover/click/vocab box 所有状态。
2. **与 Vuex store 重度耦合**：直接 commit/state 操作发生在 props/web 各处。
3. **hover 位置计算**用 `document.querySelector` + `getBoundingClientRect`，不通过 Vue 响应式。
4. **huge methods**：`handleVocabularyHover` 和 `showVocabularyBox` 超长，包含分支逻辑。
5. **零前端测试**。

**建议下一步**：
**不拆组件结构**。先补 characterization tests（用 Playwright/Web 测试工具）覆盖核心用户路径：
- 点词查字典
- hover 弹出词义
- 侧栏显示词义列表

确认这些路径稳定后，再考虑拆分。TextReader 是学习流程核心，拆分风险最高。

### 5.2 ReviewCardManage.vue

**当前职责**：
1826 行。复习卡管理页（全功能：搜索/筛选/排序/批量归档/恢复/删除/导出/编辑）。

**风险点**：
- 大单文件但逻辑清晰。
- 弹窗交互（归档弹窗/恢复弹窗/删除弹窗/重置弹窗/编辑弹窗）。
- 批量操作 + skipped 反馈已在 Complex-1 和 Phase20-1 中完成。

**建议下一步**：
待多组件拆分条件成熟时可考虑拆，但优先级低。后端测试全面（251 tests），前端风险可控。

### 5.3 WordSensesList.vue / VocabularyBox.vue / VocabularySideBox.vue

**当前职责**：
- WordSensesList.vue（678 行）：词义列表展示（复习卡管理页内的子组件？）
- VocabularyBox.vue（509 行）：点词后的主查词/学习界面。
- VocabularySideBox.vue（470 行）：阅读页侧栏词义列表。

**风险点**：
- 三个组件有功能重叠，但各自独立。
- Vuex store 共享状态。
- 无前端测试。

**建议下一步**：补最少测试（核心用户路径），不重构。

### 5.4 SenseReview.vue / SenseMappingReview.vue

**当前职责**：
- SenseReview.vue（649 行）：词义确认流程（概念 review 卡）。
- SenseMappingReview.vue（455 行）：GPT sense-mapping 导入预览。

**风险点**：
- SenseReview.vue 涉及"接受/拒绝"操作，影响后续阅读页点词候选。
- 无前端测试。

**建议下一步**：补最少测试，不重构。

---

## 6. tokenizer / import / reader 链路热点

### 6.1 tools/tokenizer.py（708 行）

**当前职责**：
- Bottle HTTP 服务，监听多语言 spaCy tokenization 请求。
- 核心函数 `tokenizeText()` 处理多语言分词 + lemma + POS。
- `health_check()` 端点简单返回语言可用性。
- 英文 fallback 在 PHP 端实现（TextBlockService 内）。

**风险点**：
- 无 Python 测试。
- health 检查只返回 bool（语言是否可用），不返回详细原因（模型未安装/加载失败/运行中）。
- 模型懒加载（首次调用 `getTokenizerDoc` 检查 `global_$lang_nlp`）。
- 多语言模型每个都有自己的 spaCy pipeline，内存占用大。

**建议下一步**：
1. 给 tokenizer.py 补 `health_check` 增强版：返回每个语言的具体状态（available/loading/failed/not_installed）。
2. 拆分 health 逻辑（纯 trival：不改变 tokenization 协议）。
3. PHP 端健康检查和 fallback 逻辑独立出 TextBlockService。

### 6.2 import → ProcessChapter → TextBlockService → EncounteredWord 链路

**链路**：
```
ImportController → ImportService → (文件上传/journal) → ProcessChapter Job
  → VocabularyService (phrase indexing)
  → TextBlockService (tokenize → createNewEncounteredWords)
```

**风险点**：
1. **导入链路长**，跨越 Controller/Job/Service 三个层级。
2. **ProcessChapter Job**（131 行）相对简洁，但 handle 方法混合了 phrase 索引、广播事件、统计。
3. **EncounteredWord**（77 行模型）很简单，但创建逻辑分布在 TextBlockService 和别处。
4. **导入失败回滚**：当前 import → TextBlockService → `createNewEncounteredWords` 在事务内，但 ProcessChapter Job 的失败处理较简单（只记录失败，不重试）。

**建议下一步**：
1. **只补测试**：补 `ProcessChapter` 的单元测试（mock TextBlockService 验证调用）。
2. 短期内不适合重构导入链路——链路长、依赖多、验收成本高。

---

## 7. 下一批任务排序（PostStabilization-1 刷新）

> 已完成任务的候选条目已清理。以下为当前最新排序。

### 7.1 候选任务优先级排序

| 排名 | 任务名 | 当前状态 | 收益 | 风险 | 类型 |
|------|--------|----------|------|------|------|
| ✅ 已完成 | **DictionaryImportService-CharacterizationTests-1** | 13 tests 覆盖文件识别/CSV测试/导入validation/导入成功路径 | 🟡 中 | 🟢 低 | A |
| ✅ 已完成 | **TextBlockGroup-SmokeTests-1** | MCP Chrome 真实验收完成（读取渲染/hover 词汇/点词打开侧栏/console error 检查/不创建学习数据） | 🟢 高 | 🟢 低 | A |
| ✅ 已完成 | **VocabularyService-QuerySearchContractTests-1** | 15 tests 覆盖返回结构/text+reading搜索/stage/translation/only words+only phrases+union/4种排序/分页/export | 🟡 中 | 🟢 低 | A |
| ✅ 已完成 | **SenseReviewMapping-SmokeTests-1** | MCP Chrome 空状态 smoke：/senses/review 与 /reviews/senses 页面打开、标题/统计/筛选/批量区/空状态、console、无数据副作用 | 🟡 中 | 🟢 低 | A |
| ✅ 已完成 | **FsrsReschedulePreviewService-ContractScouting-1** | 只读侦查 + 缺口契约测试。侦查已输出 18 个风险点 + contract tests 计划。Gap 测试补了 5 个 preview + 5 个 confirmPreflight，全 58 个测试通过 | 🟡 中 | 🔴 高 | B-先契约 |
| ✅ 已完成 | **TextBlockService-CreateNewEncounteredWordsContractTests-1** | 12 个 characterization tests 锁定 createNewEncounteredWords 行为：新词创建/去重/隔离/study_base/skip/CJK/lemma 保留/batch insert | 🟢 高 | 🟡 中 | B-先契约 |
| ✅ 已完成 | **EncounteredWordCreationService-Extract-1** | 从 TextBlockService 提取 encountered_words 写入逻辑到独立 Service。createNewEncounteredWords 保持 public facade。行为无变化 | 🟢 高 | 🟢 低 | A |
| 🔍 已审计 | **WordSenseService-DestroyRestore-RiskAudit-1** | 只读审计 WordSense 删除/归档/恢复链路 — 输出 14 个风险点 + contract tests 计划 | 🟢 低 | 🔴 高 | C-暂缓 |

### 7.2 候选任务详情

#### ~~候选 1~~ 已完成：DictionaryImportService-CharacterizationTests-1

**状态**：已完成。13 个 characterization tests 已锁定现有行为。
**更新日期**：2026-07-01

**覆盖内容**：
- CEDICT/HanDeDict/dict.cc/wiktionary 文件识别 + unsupported txt/tsv
- CSV sample testing（成功/错误路径）
- 自定义 CSV 导入 validation（非法表名/过长名称/表已存在/表不存在可建）
- 自定义 CSV 导入成功路径（建表/写行/创建 Dictionary 记录/清理）

**改动范围**：
- 仅修改 `tests/Feature/DictionaryImportTest.php`
- 未改 `DictionaryImportService.php`
- 未改导入流程

**下一个候选**：候选 2（TextBlockGroup.vue Playwright smoke）— 阅读页零前端测试。

#### 候选 2：TextBlockGroup.vue Playwright smoke tests

**当前状态**：未开始。2182 行，零前端测试，是前端最核心的组件。

**推荐模型**：复杂度 20
**是否需要 CodeBuddy**：可选
**是否需要 WorkBuddy**：✅ 需要（确认用户操作路径）
**是否需要 MCP Chrome**：✅ 需要（或用 Playwright + MCP Chrome 联合验收）
**为什么现在做**：阅读页是学习流程核心，零测试风险高。Playwright 可以捕获 hover/click/vocab box 等关键交互。不需要改业务代码。
**允许修改文件**：
- `tests/Browser/TextReaderSmokeTest.php`（新增）
- `playwright.config.js`（新增/修改）
- `docs/plans/*`
**禁止范围**：
- 不改 TextBlockGroup.vue
- 不改 reader 组件
- 不改后端

#### ~~候选 3~~ 已完成：VocabularyService-QuerySearchContractTests-1

**状态**：已完成。15 个 contract tests 已锁定搜索/分页/过滤行为。
**更新日期**：2026-07-01

**覆盖内容**：
- 返回顶层结构（wordCount/words/books/pageCount/currentPage/languageSpaces）
- text 搜索匹配 word + reading
- stage 过滤
- translation = not empty 过滤
- only words / only phrases / words+phrases union
- orderBy words/words desc/stage/stage desc
- pagination（30 条/页，pageCount float 兼容）
- exportToCsv 复用搜索查询

**改动范围**：
- 仅新增 `tests/Feature/VocabularySearchTest.php`
- 未改 `VocabularyService.php`
- 未改查询语义

#### ~~候选 4~~ 已完成：SenseReviewMapping-SmokeTests-1

**状态**：已完成。MCP Chrome 空状态 smoke 基线已完成。
**更新日期**：2026-07-02

**覆盖内容**：
- `/senses/review` 页面（词义确认）：
  - 标题"词义确认"
  - 筛选区（状态/词元/GPT判断/最低置信度/自动FSRS/刷新/清空）
  - 批量操作区（选择当前页/已选择0/批量确认/批量拒绝/批量高置信度）
  - 空状态："没有匹配的词义记录。"
  - 统计标签（待确认0/已绑定1/已忽略0/已拒绝0）
- `/reviews/senses` 页面（词义复习）：
  - 标题"词义复习"
  - 统计区（到期数量0/已复习0/剩余0/今日已复习0）
  - 空状态："当前没有到期词义卡。"

**未覆盖（后续增强）**：
- `/reviews/senses` 有卡片时的"显示答案"、评分按钮真实点击、更多菜单写入动作
- `/senses/review` 有 pending occurrence 时的确认/改绑/新建/拒绝/忽略写入动作
- 当前仅为空状态 smoke 基线，未创建 WordSense/ReviewCard/ReviewLog，未执行评分/确认/拒绝/忽略/改绑/新建保存/归档/重置/删除

**改动范围**：
- 仅修改 `docs/plans/repo-architecture-hotspot-audit.md`（后续收口任务）
- 未改 SenseReview.vue / SenseMappingReview.vue
- 未改任何 Vue 页面组件
- 未改 Controller / routes / Service / 模型 / 测试文件

**下一个候选**：候选 5（FsrsReschedulePreviewService contract + scouting）

#### ✅ 侦查 + 缺口测试已完成：FsrsReschedulePreviewService-ContractScouting-1 + GapContractTests-1

**状态**：只读侦查 + 缺口契约测试已完成。未改业务代码，未执行 FSRS 重排。
**侦查日期**：2026-07-02
**缺口测试完成日期**：2026-07-02

**侦查范围**：
- `app/Services/FsrsReschedulePreviewService.php`（780 行）
- `app/Services/FsrsSchedulingService.php`
- `app/Services/FsrsRescheduleSnapshotService.php`
- `app/Models/ReviewCard.php`、`WordSense.php`、`RescheduleSnapshot.php`、`RescheduleSnapshotItem.php`
- `app/Http/Controllers/SettingsController.php`（reschedulePreview/rescheduleConfirm）
- 现有测试：`FsrsReschedulePreviewTest`（31 tests）、`FsrsRescheduleConfirmTest`（18 tests）

**关键发现**：
1. **preview 已充分测试**（31 tests）：覆盖 candidate 排除条件、hash 稳定性、只读保证、20 samples cap、non-english 语言
2. **confirmPreflight 已基本覆盖**（18 tests）：hash 过期/409、missing hash/422、confirm=false/422、non-english、threshold 拦截、只读保证
3. **confirmAndApply 测试已覆盖成功写入与部分拒绝写入路径**：当前本地 `FsrsReschedule` 过滤器为 98 tests / 479 assertions 全绿；仍不把成功写入语义改成新产品决策
4. **高风险写操作**：事务内 lockForUpdate 后写 `fsrs_due_at/stability/difficulty`，不写 ReviewLog/reps/lapses/last_reviewed_at
5. **snapshot 链路完整**：appliedCount > 0 时创建 snapshot（含之前/之后的 due_at/stability/difficulty）
6. **candidateIds 来自 preview data**，apply 时重新校验但不重新查询数据库，存在 stale candidate 风险

**已识别的 18 个风险点**（详见下方 §7.3 清单）：
- **高**：hash 校验后重查 computePreviewData 的开销/一致性问题、snapshot 边界条件、threshold 重合
- **中**：skipped_count 不一致、write_enabled 命名误导、preview 和 apply 之间 FSRS params 变化时仅校验 hash
- **低**：days_change 符号代码冗余、newly_due_today 不覆盖降低今日负荷场景

**缺口测试已补强**：FsrsReschedulePreviewService-GapContractTests-1 — 在已有 51 个测试基础上新增 5 个 preview + 5 个 confirmPreflight 缺口测试。覆盖 empty candidate hash、stability/difficulty hash 敏感度、语言隔离、confirmPreflight apply=false high/blocked/不写 snapshot/risk_confirm 忽略。
**缺口测试 Scope Fix 已收口**：FsrsRescheduleGapContractTests-ScopeFix-1 — 将越界写入 `FsrsRescheduleSnapshotTest.php` 的两个测试移回其职责边界内（语义已在 ConfirmTest 中有等价覆盖）。总测试语义不减少，65 测试全绿。无业务逻辑变更。
**拒绝写入路径已补强**：Codex-ArchitectureFinalGoalMode-1 — 新增 2 个 confirmAndApply contract tests，锁定 `apply=true` 高风险未二次确认与 blocked 超量两类场景均不写 ReviewCard、不建 snapshot、不写 ReviewLog。不改业务代码，不执行真实重排。

**剩余候选**：如果继续 FSRS 方向，先只读侦查 stale candidate、FSRS params 变化、appliedCount=0 snapshot 等剩余边界；不要直接改写入语义。

### 7.3 FsrsReschedulePreviewService 风险清单

**高（6项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| H1 | **candidateIds 来自 preview 数据可能 stale** | confirmAndApply 使用 preview 时的 `$data['cards']->pluck('review_card_id')`，preview→apply 间被删除的卡静默跳过，applied_count 减少但不报错 | preview 显示 10 张但实际只应用 8 张，用户无感知 |
| H2 | **preview_hash 校验后重查 computePreviewData** | confirmPreflight 和 confirmAndApply 各自调用 `computePreviewData()` 并重新校验 hash，FSRS 参数在此期间可能变化，hash 校验通过但 params 不同 | 轻微偏差，概率低但难排查 |
| H3 | **snapshot 在 appliedCount=0 时不创建** | 所有 candidate 被跳过时 snapshot_batch_id 返回 null，虽然无副作用但 undo 不可用 | 用户体验：无 log 可查 |
| H4 | **threshold 重合：high 阈值 == max 阈值** | getHighNewlyDueTodayThreshold=200 与 getMaxNewlyDueToday=200 相同，newlyDueToday>200 既触发 high 风险又需要 risk_confirm | 语义混淆：风险级别和绝对限制共用阈值 |
| H5 | **skipped_count 在 preview 和 apply 之间可能不同** | preview 中的 skip（buildPreviewForCard 返回 null）和 apply 中的 skip（lockForUpdate 找不到、re-validation 失败、build 失败）条件不完全一致 | user sees different numbers |
| H6 | **extension unavailable 时 success=true** | preview/confirm 返回 `success=true` 但 `preview_available=false`，前端可能误以为操作成功 | 误导性 API 语义 |

**中（7项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| M1 | **`write_enabled` 字段名易造成误解** | confirmPreflight 始终返回 `write_enabled=false`，confirmAndApply 成功返回 `write_enabled=true`。字段名暗示"是否允许写入"，实际含义是"是否已写入" | 前端开发者可能混淆 |
| M2 | **preview→apply 间 FSRS params 变化仅靠 hash 检测** | computePreviewData 在 apply 中重新计算 params，但 hash 校验通过后不会额外通知用户 params 变化 | 用户无感知的精度变化 |
| M3 | **lockForUpdate 范围是 candidateIds 但不全表锁** | 只锁了 `ReviewCard::whereIn('id', $candidateIds)`，不影响同用户其他卡片的并发复习 | 可接受，但需注意并发场景 |
| M4 | **没有对 snapshot 的 TTL 做软限制** | snapshot `expires_at` 默认 7 天，但 undo 路径检查过期，过期后直接拒绝 | 用户 8 天后不能 undo，但数据仍存在 |
| M5 | **noRiskAssessment() 返回 `can_apply=true`** | 空状态时 risk_assessment 返回 `can_apply=true`，但实际没有可应用的卡片 | 无害但易误导 |
| M6 | **preview 包含总卡片数但无分页** | total_candidates 理论上可能非常大（>2000），但 samples 只返回 20 条 | 大用户预览响应慢 |
| M7 | **candidateCardsQuery 无显式排序** | 查询结果顺序由数据库决定，但 preview_hash 内部排序了（sortBy review_card_id），hash 不受影响 | preview 表格顺序不可预期 |

**低（5项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| L1 | **days_change 符号逻辑重复** | diffInDays($currentDueAt, false) 已返回带符号值，后续 if/else 重新计算符号（L394-401） | 代码冗余，可读性差 |
| L2 | **is_newly_due_today 不覆盖减少今日负荷场景** | 只检测"从未来变今天"，不检测"从今天变未来"（即减少今天负荷） | 不完全的负担描述 |
| L3 | **emptyPreviewResponse 仍计算和返回 preview_hash** | total_candidates=0 时仍计算 hash，前端可能误以为有候选卡 | 轻微误导 |
| L4 | **non-english 语言返回 preview_hash=null** | 与 empty 场景不同，non-english 返回 null hash，前端需特殊处理 | API 不一致 |
| L5 | **getDefault parameters 无缓存** | computePreviewData 每次调用 computePreviewData→getActiveFsrsParameters→可能 fallback 到 get_default_parameters()，无缓存 | 轻微性能浪费 |

#### ~~候选 6~~ 已完成：TextBlockService-CreateNewEncounteredWordsContractTests-1

**当前状态**：contract tests 已完成。未提取 Service，未改业务逻辑，未改 tokenizer，未改 import 流程。
**完成日期**：2026-07-02

**覆盖行为（12 tests）**：
- **新词创建**：English processed words → encountered_words 记录（word/lemma/base_word/study_base/stage/translation 正确）
- **去重**：已存在的 word 不再重复插入
- **user/language 隔离**：不同用户的同词各自创建
- **UserStudyBaseRule**：surface→study_base 映射覆盖 grammatical lemma
- **VocabularyTokenFilter**：NEWLINE 等 filter token 被跳过
- **words_to_skip**：当前 config 的标点词被 filter 层跳过（words_to_skip 块内 stage=1 分支在现有 config 下不可达，属于二次安全网）
- **CJK base clearing**：japanese 语言中 lemma==word 时清空 base_word/lemma/study_base/base_word_reading
- **English lemma 保留**：English 语言中 lemma==word 时保留（如 series）
- **uniqueWords/processedWords 关系**：lookup 用 uniqueWords，insert 遍历 processedWords
- **lowercasing**：word/lemma 被小写化
- **255 长度边界**：word > 255 时 DB 报错，无应用层跳过
- **batch insert**：3 个新词同时插入

**改动范围**：
- 仅新增 `tests/Feature/TextBlockCreateNewEncounteredWordsTest.php`
- 未改 `TextBlockService.php`
- 未改任何业务代码

**候选 6a 已完成**：EncounteredWordCreationService-Extract-1 — 成功提取创建 encountered_words 的写入逻辑到独立 Service。TextBlockService::createNewEncounteredWords() 保持 public facade。12 个 characterization tests 全部通过。新增 EncounteredWordCreationService 直接调用测试。

**下一个候选**：候选 7（WordSenseService destroy/restore 只读风险审计）或候选 7b（TextBlockService 剩余 phrase/index/read data 逻辑继续拆解）

#### 🔍 已审计：WordSenseService-DestroyRestore-RiskAudit-1

**当前状态**：只读风险审计已完成。未改业务代码，未执行删除/归档/恢复。
**审计日期**：2026-07-02

**审计范围**：
- `app/Services/WordSenseService.php`（413 行）：rejectSense / archiveSense / removeSenseFromReviewSystem / restoreEncounteredWordIfNoActiveSenses
- `app/Services/ReviewCardService.php`
- `app/Http/Controllers/ReviewCardManageController.php`（destroy / bulkDestroy）
- `app/Http/Controllers/SenseOccurrenceController.php`（archiveSense）
- `app/Models/WordSense.php`、`ReviewCard.php`、`ReviewLog.php`、`WordSenseOccurrence.php`、`EncounteredWord.php`
- 现有测试：`tests/Feature/WordSenseTest.php`（3106 行，但无 removeSenseFromReviewSystem / archiveSense / rejectSense 直接测试）

**关键事实**：

| 方法 | 行为 | 事务 | 入口 |
|------|------|------|------|
| `rejectSense()` | 仅 status→rejected，不碰其它表 | ❌ | ❌ 无 controller 调用（方法存在但无 UI 路径） |
| `archiveSense()` | status→rejected，ReviewCard fsrs_enabled=false，不清 occurrence（与 removeSenseFromReviewSystem(false) 语义不一致） | ✅ | PUT /senses/{id}/archive (SenseOccurrenceController) |
| `removeSenseFromReviewSystem(delCard=true)` | status→rejected，delete ReviewCard，preserve ReviewLog，clear occurrence refs，restore EncounteredWord if last sense | ✅ | DELETE /review-cards/manage/{id} |
| `removeSenseFromReviewSystem(delCard=false)` | status→rejected，fsrs_enabled=false，clear occurrence refs（不 restore EncounteredWord） | ✅ | 同方法但当前无 controller 传 false |
| `restoreEncounteredWordIfNoActiveSenses()` | Learning→New 当为最后一个 confirmed sense；只按 encountered_word_id 找 | (私有) | 仅从 removeSenseFromReviewSystem(true) 调用 |

**关键发现**：
1. `rejectSense()` 方法存在但无任何 controller 调用 —— 疑似 dead code。
2. `archiveSense()` 不清除 occurrence 的 `review_card_id` 和 `auto_fsrs_allowed`，与 `removeSenseFromReviewSystem(false)` 语义不一致。
3. `deleteReviewLogs=true` 参数存在但无任何前端入口 —— 前端永远传 `apply=false`。
4. `findManageableSenseCard()` 只允许 `status=confirmed`，归档/拒绝后的卡片无法通过该路径操作，除非直接走 occurrence 层。
5. WordSenseTest 有 3106 行但无 removeSenseFromReviewSystem / archiveSense / rejectSense 测试。

**风险清单**（详见下方 §7.4）：14 个风险点（4 高 / 5 中 / 5 低）

**候选 7a 已完成**：WordSenseService-DestroyRestoreContractTests-1 — 15 个 contract tests 锁定归档、永久删除、ReviewLog 保留/删除、Occurrence 解绑、EncounteredWord restore 条件、跨用户隔离、legacy card 不受影响、rejectSense 当前行为。不改业务逻辑，不做 UI，不做真实数据删除。

### 7.4 WordSenseService Destroy/Restore 风险清单

**高（4项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| H1 | **archiveSense 不清除 Occurrence review_card_id** | archiveSense 设置 fsrs_enabled=false 但不清除 Occurrence 引用。removeSenseFromReviewSystem(false) 却会清除。两条归档路径行为不一致 | Occurrence 仍指向已禁用的卡，后续操作可能误触 |
| H2 | **rejectSense 无调用方（疑似 dead code）** | 方法存在但无任何 controller 调用，也无事务包裹。如果被误调用，只改 WordSense status 不改其他表 | 可能留下可复习的卡片 |
| H3 | **deleteReviewLogs 无前端入口** | removeSenseFromReviewSystem 参数接受 deleteReviewLogs=true，但前端没有提供此选项。如果未来前端直接传 true，随机删除日志不可逆 | 日志删除不可恢复 |
| H4 | **permanent delete 默认不删 ReviewLog** | 删除 ReviewCard 后 ReviewLog 保留，形成孤儿 log（review_card_id 指向已删除记录） | 数据不一致 |

**中（5项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| M1 | **findManageableSenseCard 只允许 status=confirmed** | 已归档/拒绝的 WordSense 无法通过 review-card-manage 端点操作。用户需通过 SenseOccurrenceController::archiveSense 操作 | 用户体验：只能业务层操作 |
| M2 | **rejectSense 无事务** | 同行多个 rejectSense 调用，其中一个失败时另一个已写入 | 数据一致性问题 |
| M3 | **restoreEncounteredWordIfNoActiveSenses 只查 encountered_word_id** | 不按 lemma 匹配，不同 encountered_word_id 的同 lemma 其他 sense 不会阻止 restore | EncounteredWord 可能被错误恢复 |
| M4 | **archiveSense 不 restore EncounteredWord** | 归档时不调 restoreEncounteredWordIfNoActiveSenses，即使这是最后一个活跃 sense | 词一直处于 Learning 状态 |
| M5 | **bulkDestroy 批量删除无额外确认** | 批量永久删除走 mutationService，但 controller 层没有增加额外的风险确认机制 | 批量误删 |

**低（5项）**：

| # | 风险点 | 说明 | 影响 |
|---|--------|------|------|
| L1 | **restore 不恢复 Known/Ignored/New** | 当前设计有意不恢复，符合预期 | 行为正确 |
| L2 | **status=rejected 被查询排除** | rejected 状态 sense 不在阅读页候选（符合预期） | 行为正确 |
| L3 | **restore setStage(2,true) 设置 next_review=null** | 从 Learning→New 取消 next_review | 行为正确 |
| L4 | **WordSenseOccurrence rows 不删除** | 即使 occurrence 已无实用价值，当前设计保留行 | 数据膨胀但可控 |
| L5 | **restore 忽略成就更新 (ignoreAchivement=true)** | 不触发送达目标的计算 | 符合恢复语义 |

### 7.5 总设计师复判（DesignerWorkflow-CodeBuddyRiskRoleAndPlanRefresh-1）

CodeBuddy 事实描述多数准确，但风险分级需要降噪。总设计师根据"反驳核验规则"（§4.x）重新评定：

| CodeBuddy 原始风险 | CodeBuddy 等级 | 复判结论 | 复判等级 | 理由 |
|---|---|---|---|---|
| archiveSense 不清除 occurrence review_card_id | **高** | 保留 | **中高** | 两条归档路径语义确实不一致，但用户数据不会被直接破坏，优先补测试锁定 |
| rejectSense 无调用方 | **高** | 降级 | **低** | 方法存在但无调用路径，不影响当前用户。标记为疑似遗留方法 / 清理候选，不是紧急 bug |
| deleteReviewLogs=true 无前端入口 | **高** | 降级 | **低** | 参数存在但前端从未传入 true。未来风险，不是当前 bug |
| permanent delete 默认不删 ReviewLog | **高** | 保留 | **中** | 设计意图是有意保留日志，不是 bug。需要 contract tests 锁定此设计行为 |
| findManageableSenseCard 只允许 status=confirmed | **中** | 保留 | **中** | 已归档卡不能通过管理页操作，但用户可通过 occurrence 层操作。记录为设计约束 |
| rejectSense 无事务 | **中** | 降级 | **低** | 无调用方的方法无事务影响。若未来启用，需先加事务 |
| restore 只按 encountered_word_id 不按 lemma | **中** | **不采纳** | — | 这是防误伤的安全设计：用 encountered_word_id 精确匹配，不会误恢复同 lemma 其他词。不是 bug |
| archiveSense 不 restore EncounteredWord | **中** | **不采纳** | — | 归档 ≠ 永久删除。归档语义是暂停复习，不是把词恢复 New。设计正确 |
| bulkDestroy 批量删除无额外确认 | **中** | 保留 | **中** | 前端确认弹窗和 UI 风险需核实，属于待核实项 |

**更新后统计**：3 项优先补测试（中高/中/中）+ 2 项待核实 + 2 项降级（低）+ 2 项不采纳（安全设计/归档语义）

**下一推荐任务**：`WordSenseService-DestroyRestoreContractTests-1`
- 范围：按总设计师复判后的风险范围补 contract tests；
- 优先锁定：archiveSense occurrence unlink 行为、permanent delete ReviewLog 保留设计、bulkDestroy 确认机制、restore 条件；
- 不做 UI；
- 不做恢复功能；
- 不做真实数据删除。

---

## 8. 最推荐的下一阶段（PostStabilization-1 刷新）

**推荐任务**：**候选 1：DictionaryImportService characterization tests**

推荐原因：
1. **最低风险**：只补测试，不改业务代码。DictionaryImportService 990 行且极少测试，存在真实的回归风险。
2. **最高独立度**：不依赖其他任务，不依赖前端，不需要 MCP Chrome。
3. **最容易验收**：测试通过即验收通过。
4. **为后续开路**：候选 6（createNewEncounteredWords 提取）需要先稳定的导入测试防线。先做候选 1 可以降低后续风险。
5. **所有高危任务已有足够防线**：ReviewCardManage（256 测试）、SenseSourceContext（29 测试）、Tokenizer health（6 测试）、ReaderDataService（26 测试）。DictionaryImport 是唯一完全没有 regression 测试的大型 Service。

**复杂度**：10。主要是理解多格式解析和写入流程，但只补测试不改变实现。

**是否需要 CodeBuddy**：可选。测试编写相对独立。

**是否需要 WorkBuddy**：可选。不涉及页面。

**是否需要 MCP Chrome**：否。

**候选 1 已完成**：DictionaryImportService-CharacterizationTests-1（13 tests）。

**候选 2 已完成**：TextBlockGroup-SmokeTests-1 — MCP Chrome 真实验收阅读页渲染、token 点击、vocab 侧栏。

**候选 3 已完成**：VocabularyService-QuerySearchContractTests-1 — 15 tests 覆盖搜索全链路。

**候选 4 已完成空状态 smoke 基线**：SenseReviewMapping-SmokeTests-1 — MCP Chrome 验收 `/senses/review`（词义确认页空状态）和 `/reviews/senses`（词义复习页空状态），仅覆盖页面打开/标题/统计区/筛选区/批量操作区/空状态文案/console 检查/无数据副作用。未覆盖有卡片或有 pending occurrence 的写入路径（评分/确认/拒绝/忽略/改绑/新建/归档/重置/删除）。

**候选 5 已完成侦查 + 缺口测试补强 + Scope Fix**：FsrsReschedulePreviewService-ContractScouting-1 + GapContractTests-1 + ScopeFix-1 — 只读侦查 + 10 个缺口契约测试 + scope 收口共 65 测试全绿。未改业务代码，未补 confirmAndApply 成功写入测试，未执行真实重排。

**候选 6 已完成 contract tests**：TextBlockService-CreateNewEncounteredWordsContractTests-1 — 12 个 characterization tests 锁定 createNewEncounteredWords 行为。未提取 Service，未改业务逻辑，未改 tokenizer。

**候选 6a 已完成 Service 提取**：EncounteredWordCreationService-Extract-1 — 从 TextBlockService 提取 encountered_words 写入逻辑到独立 Service。createNewEncounteredWords() 保持 public facade。12 个原 characterization tests + 1 个直接调用测试共 13 测试全绿。

**候选 7 已完成只读风险审计**：WordSenseService-DestroyRestore-RiskAudit-1 — 审计 WordSense 删除/归档/恢复全链路。CodeBuddy 原始风险分级（4 高/5 中/5 低）经总设计师反驳核验后（§7.5）调整为：3 项优先补测试 + 2 项待核实 + 2 项降级 + 2 项不采纳（安全设计/归档语义）。未改业务代码，未执行删除/归档/恢复。

**候选 7a 已完成 contract tests**：WordSenseService-DestroyRestoreContractTests-1 — 15 个 contract tests 锁定全部归档/删除/恢复语义。不改业务逻辑，不做 UI，不做真实数据删除。

**候选 7b 已完成文案收口**：ReviewCardDeleteSnackbar-HistoryPreservedCopy-1 — 根据产品决策，补管理页删除成功 snackbar / fallback 文案。单张删除 fallback、批量删除 fallback、SenseReview 删除 fallback 均明确"复习历史已保留"。MCP Chrome 真实验收确认后端返回文案已包含"阅读记录和复习历史已保留"。仅改前端文案，不改删除逻辑，不改后端。

**Codex 交接文档治理已完成**：CodexHandoff-DocsAndWorkingPlanRefresh-1 — 新增 `current-working-handoff.md` 临时工作台、协作规则 §4.y（AI 上下文/文档分层/可执行验收）。后续 Codex 应先读 current-working-handoff，再读 master plan，再读相关模块文档，不从头扫描所有旧文档。不因 Codex 更强就取消测试，不因 Codex 上下文长就读全部旧文档。

**OpenCode-ArchitectureTargetMode-Batch1 阶段性完成**：SenseReview 到期卡评分流程已验收（MCP Chrome 显示 + Good 评分 + ReviewLog 创建）、TextBlock fallback 只读侦查（fallback 仍可调用但无测试）、ReviewCardManage logs payload 只读侦查（字段已盘点，contract tests 待补）。pending occurrence 写入路径未跑通（数据准备问题），logs payload contract tests 待补。

**OpenCode-AIStudyCardArchitectureScouting-And-ProgressRuleFix-1 已完成**：AI 示意卡架构侦查完成，新增 `docs/plans/ai-study-card-architecture-scout.md`，覆盖 12 个代码接入点、5 个危险区、最小目标建议。进度规则修正为固定五条主线，零进度任务不得单独派发。

**新的最推荐下一阶段**：候选 A（AIStudyCard-RecommendationModal-ArchitectureAndHarness-1 — 设计并加护栏后再做 AI 推荐弹窗）或候选 B（TextBlockService-TokenizerFallbackScouting-1 — 补 fallback 测试缺口）或候选 C（ReviewCardManage-LogsContractTests-1 — 补 logs payload contract tests）。

---

## 9. 禁止事项

- ❌ 不改 `.env`
- ❌ 不改 `AGENTS.md`
- ❌ 不清库（`migrate:fresh` / `db:wipe`）
- ❌ 不运行 `DCP`
- ❌ 不运行 `notification script`（包括 `notify.ps1`）
- ❌ 不使用 `--force`
- ❌ 不进入功能开发（只做重构/测试/文档）
- ❌ 不动高风险核心语义（FSRS 算法、`WordSenseService::removeSenseFromReviewSystem`、`ReviewCardService::resetCard`）
- ❌ 不单独修改 `tools/tokenizer.py`（除非跟着候选任务走）

## Recent Update: Codex-FinalArchitectureClosureTargetMode-1

- Architecture-closure phase finalized. See new section §2.5 above for the closure conclusion, five-line final progress, and three-step next-stage roadmap.
- Three frozen-plan documents added: `final-architecture-closure-report.md`, `ai-study-card-v1-frozen-plan.md`, `frontend-review-entry-unification-plan.md`.
- MCP Chrome read-only observation confirmed the current entry state (nav still exposes internal names "单词复习 / 词义确认"; homepage "开始复习" still points to legacy word review; "待 AI 解释" button does not yet exist).
- Five-line progress: Overall architecture closure 81% → 100% (not full project completion), Review mainline 86% → 91%, Page real acceptance 90% → 91%, AI study card planning 25% → 55%, Frontend entry cleanup 50% → 65%.
- Next stage should NOT continue infinite scouting. Recommended next minimum implementation is AI study card v1 (pending item only).
- This task did NOT change business code, tests, Vue, Controller, Service, routes, migration, DB schema, FSRS semantics, delete/archive/restore semantics, ReviewLog retention, legacy word card compatibility layer, SenseReview, or SenseMappingReview.

## Recent Update: Codex-AIStudyCardV1-And-ReviewEntryUnification-1

- Added §2.6 documenting the first implementation boundary and tests for AI study card pending markers plus frontend review entry round 1.
- Updated next-stage recommendation away from "pending marker implementation" because it is now complete; next AI study card work must focus on recommendation modal architecture/harness before generation.
- Progress updated to: architecture closure 100%, review mainline 94%, page acceptance 96%, AI study card planning 90%, frontend entry cleanup 92%.
- No AI generation, FSRS integration, WordSense/ReviewCard/ReviewLog writes, or legacy route deletion was introduced.

## Recent Update: GLM-AIStudyCardV2-GenerationLoop-1

- Added §2.7 documenting the V2 generation loop phase-1 boundary, tests, and progress for AI study card pending item list, dismiss/restore, and preview modal placeholder.
- Updated five-line progress to: architecture closure 100%, review mainline 96%, page acceptance 100%, AI study card planning 100%, frontend entry cleanup 98%.
- New sub-phase: AI study card generation loop 70%. **This 70% is the sub-phase progress, NOT a fake uplift of the five main lines.**
- No AI calls, no WordSense/ReviewCard/ReviewLog writes, no FSRS changes, no delete/archive/restore changes, no new migration, no SenseReview/SenseMappingReview/legacy word card removal.
