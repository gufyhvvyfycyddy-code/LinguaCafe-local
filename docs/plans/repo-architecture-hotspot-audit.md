# LinguaCafe 全仓库架构热点审计

> **审计日期**：2026-07-01
> **基准 commit**：`7f3d4b6`
> **审计方式**：只读侦查，不改代码，不进入功能开发。

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

---

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
| 5️⃣ | **FsrsReschedulePreviewService contract + scouting** | 未开始 | 🟡 中 | 🔴 高 | B-先侦查 |
| 6️⃣ | **TextBlockService createNewEncounteredWords 提取** | 未开始 | 🟢 高 | 🟡 中 | B-先契约 |
| 7️⃣ | **WordSenseService destroy/restore 只读风险审计** | 未开始 | 🟢 低 | 🔴 高 | C-暂缓 |

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

#### 候选 5：FsrsReschedulePreviewService contract + scouting

**当前状态**：未开始。715 行，批量写 review_cards，高风险。

**推荐模型**：复杂度 20
**是否需要 CodeBuddy**：✅ 必须（先侦查 preview/confirm/apply 全链路）
**是否需要 WorkBuddy**：可选
**是否需要 MCP Chrome**：否
**为什么现在做**：暂缓。必须先 CodeBuddy 侦查链路，锁定契约，才能进入编码。风险高，需要独立 CodeBuddy 轮次。
**允许修改文件**：
- `docs/plans/*`（仅限侦查文档）
**禁止范围**：
- 不改 FsrsReschedulePreviewService
- 不改 ReviewCard
- 不改 FSRS 算法

#### 候选 6：TextBlockService createNewEncounteredWords 提取

**当前状态**：未开始。TextBlockService 仍保持 1271 行，`createNewEncounteredWords` 是写入 DB 的核心方法。

**推荐模型**：复杂度 20
**是否需要 CodeBuddy**：✅ 需要（先侦查调用链 + 事务边界）
**是否需要 WorkBuddy**：可选
**是否需要 MCP Chrome**：✅ 需要（验收导入后阅读页无变化）
**为什么现在做**：暂缓。需要先做候选 1（补好 DictionaryImport 测试）才能有安全基础来动导入相关代码。候选 6 依赖候选 1。
**允许修改文件**：
- `app/Services/TextBlockService.php`
- `app/Services/EncounteredWordService.php`（新增）
- `tests/Feature/EncounteredWordTest.php`（新增）
- `docs/plans/*`
**禁止范围**：
- 不改 tokenizer
- 不改 import 主线流程语义
- 不改 EncounteredWord 模型

#### 候选 7：WordSenseService destroy/restore 只读风险审计

**当前状态**：未开始。`removeSenseFromReviewSystem` 涉及硬删、拒绝、条件性恢复。

**推荐模型**：复杂度 10
**是否需要 CodeBuddy**：✅ 必须
**是否需要 WorkBuddy**：否
**是否需要 MCP Chrome**：否
**为什么暂缓**：核心删除语义无用户反馈问题，已有足够测试覆盖。没有安全收益就不值得碰高风险。
**允许修改文件**：
- `docs/plans/*`（仅限风险审计文档）
**禁止范围**：
- 不改 WordSenseService
- 不改 ReviewCard
- 不改任何模型

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

**新的最推荐下一阶段**：**候选 5（FsrsReschedulePreviewService contract + scouting）** — 715 行高风险批量写操作，需要先 CodeBuddy 侦查链路。

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
