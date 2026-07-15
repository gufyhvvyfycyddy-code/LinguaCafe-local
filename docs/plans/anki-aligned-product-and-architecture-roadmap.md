# LinguaCafe Anki 对齐产品与架构路线

> **状态**：Current / Authoritative
> **日期**：2026-07-15
> **基线 commit**：`a0916784951be69b411066446a03be940373589f`
> **适用范围**：产品优先级、架构优化顺序、下一阶段任务授权判断

## 1. 一句话结论

LinguaCafe 保留阅读优先、sense-only、原文定位、多例句、lemma 和 AI 示意卡能力。复习、设置、浏览器、Preset、Custom Study、Card Info、Leech、统计和撤销等通用学习能力，以 Anki 官方产品语义和代码分层为第一参考。

当前先处理设置页和复习卡管理页的结构债务，再开发 Preset、Card Marker 和 Custom Study 1B。阅读页大组件最后拆分，真实 AI provider 延后。

## 2. 本轮依据

### 2.1 仓库事实

当前生产代码规模：

| 区域 | 行数 |
|---|---:|
| `app/` | 42,529 |
| `resources/js/` | 44,546 |
| `tests/` | 88,456 |
| `docs/` | 29,410 |

生产文件体量：

- 29 个生产文件超过 500 行。
- 12 个生产文件超过 1,000 行。
- 3 个生产文件超过 1,500 行。

主要热点：

| 文件 | 行数 | 结构信号 | 风险 |
|---|---:|---|---|
| `resources/js/components/ReviewCards/ReviewCardManage.vue` | 3,412 | 26 个 axios 引用、12 个 dialog、约 115 个 method/computed 项 | 高 |
| `resources/js/components/Text/TextBlockGroup.vue` | 2,517 | 11 个 axios 引用、约 85 个 `this.*` 状态引用 | 高，阅读主链路 |
| `resources/js/components/Admin/AdminReviewSettings.vue` | 2,165 | 18 个 axios 引用、3 个 dialog | 高 |
| `resources/js/components/Senses/SenseReview.vue` | 1,477 | 11 个 axios 引用、4 个 dialog | 中高 |
| `app/Services/TextBlockService.php` | 1,382 | 26 个方法，仍保留多类阅读处理职责 | 高，阅读主链路 |
| `app/Services/CustomStudy/CustomStudySessionState.php` | 1,177 | 46 个方法，但职责集中在不可变会话状态 | 中 |
| `app/Services/DictionaryImportService.php` | 1,158 | 21 个方法，覆盖多种格式和导入阶段 | 中高 |
| `resources/js/components/Review/Review.vue` | 1,071 | 7 个 axios 引用，仍承担队列和页面编排 | 中 |
| `app/Services/AiStudyCardPendingItemService.php` | 1,065 | 17 个方法，V1–V5 多阶段逻辑聚集 | 高 |
| `app/Services/SettingsService.php` | 1,007 | 25 个方法，多类设置共存 | 高 |

### 2.2 屎山程度评估

当前评分：**6.5 / 10，局部高负担**。

支撑可维护性的因素：

- WordSense、ReviewCard、ReviewLog、FSRS、Occurrence 已有明确数据职责。
- 自动测试数量高，关键写入路径已有事务、权限和回归护栏。
- 多个 Controller、Query Service、Serializer、Policy 已完成分离。
- MCP Chrome 验收流程已建立。

增加维护成本的因素：

- 多个 Vue 页面同时承担请求、状态、展示、弹窗和业务编排。
- `SettingsService`、`TextBlockService`、`AiStudyCardPendingItemService` 继续吸收新能力。
- master plan、handoff、hotspot audit 都超过 1,000 行，当前状态和历史叙述混在一起。
- 旧计划仍把已完成任务写成计划中，容易触发重复开发。
- “总体架构收口 100%”缺少可量化依据，和当前热点数量冲突。

### 2.3 Anki 官方参考来源

本路线只把官方仓库和官方手册当作 Anki 事实来源：

- Anki official repository: <https://github.com/ankitects/anki>
- Repository core modules: <https://github.com/ankitects/anki/tree/main/rslib/src>
- Protocol definitions: <https://github.com/ankitects/anki/tree/main/proto>
- Python wrapper: <https://github.com/ankitects/anki/tree/main/pylib/anki>
- Qt desktop UI: <https://github.com/ankitects/anki/tree/main/qt/aqt>
- Deck Options / Presets: <https://docs.ankiweb.net/deck-options.html>
- Browser: <https://docs.ankiweb.net/browsing.html>
- Custom Study / Filtered Decks: <https://docs.ankiweb.net/filtered-decks.html>
- Leeches: <https://docs.ankiweb.net/leeches.html>

论坛、第三方模板和博客只能补充社区经验，不能被写成 Anki 官方设计。

## 3. 字幕工程原则落地

项目库中的 AI 编程字幕反复强调以下规则，本路线将它们转成项目门禁：

1. 一个模块用一句话说明职责。无法用一句话说明时，先拆职责。
2. 模块通过稳定接口沟通。页面不得直接复制调度、权限或数据规则。
3. Spec 记录已经稳定的决定。仍在探索的方案保留为候选，不写成长期规则。
4. Harness 锁定高风险不变量。权限、ReviewLog、FSRS、删除、归档、来源绑定必须有可执行检查。
5. 文档分为入口、当前账本、ADR、模块契约、历史五层。当前状态只保留一个权威入口。
6. 每轮只完成一个可独立验收的结构变化。拆分后必须保持用户行为不变。
7. 大文件拆分以职责和数据流为依据，不按行数机械切块。
8. 全局状态和隐式副作用优先治理。纯函数、Policy、Value Object 保持无数据库和无页面依赖。

## 4. Anki 官方架构参考

Anki 官方仓库把核心能力按领域拆分。Rust 核心包含 `card`、`collection`、`deckconfig`、`decks`、`notes`、`revlog`、`scheduler`、`search`、`stats`、`storage`、`undo` 等模块。`proto` 定义前后端通信和部分存储契约，并生成 Rust、Python、TypeScript 绑定。Python 层包装 Rust 核心，Qt 层主要负责桌面界面和用户操作。

LinguaCafe 采用相同方向：

- 调度和学习规则放在后端领域层。
- HTTP payload 形成稳定契约。
- Vue 页面负责展示和用户编排，不复制 FSRS、权限、生命周期和统计算法。
- Browser、Reviewer、Settings、Custom Study 分成独立产品区域。
- 新功能优先复用一个领域入口，避免同一规则在多个 Controller、Service 和 Vue 中各写一份。

## 5. LinguaCafe 与 Anki 的对象映射

| LinguaCafe | Anki 参考对象 | 决定 |
|---|---|---|
| 用户 + 当前语言 | Collection 边界 | 作为数据、设置和队列隔离边界 |
| WordSense | Note-like 内容对象 | 保存一个具体词义和解释 |
| Sense ReviewCard | Card | 唯一正式调度对象，日常主线只用 sense card |
| ReviewLog | Revlog | 每次真实评分的历史事实 |
| WordSenseOccurrence | 来源证据 | 保存阅读来源，不独立调度 |
| ReviewCardManage | Browser + Card Info | 搜索、批量治理、详情和历史入口 |
| Saved Search | Browser Saved Search | 保存动态查询 |
| Custom Study | Filtered Deck / Custom Study | 临时学习会话，不污染正常队列 |
| FSRS 设置 + 每日上限 + 队列顺序 | Deck Options | 由 Preset 管理 |
| Preset | Deck Options Preset | V1 绑定用户 + 语言，不建立 deck 树 |
| Card Marker | Card Flag | 卡片级关注标记，和 lifecycle、leech 分离 |
| WordSense Tag（未来） | Note Tag | 内容级分类，暂不和 Card Marker 混用 |
| 阅读页 | LinguaCafe 独有内容采集层 | 保留原文、lemma、点词、多例句和 AI 示意卡 |

## 6. 产品总原则

### 6.1 直接学习 Anki 的部分

- 问题面 → 显示答案 → Again / Hard / Good / Easy。
- FSRS、预计间隔、撤销、ReviewLog、生命周期和 Leech 治理。
- Browser 的搜索、保存搜索、列表、编辑区、Card Info 和批量操作。
- Deck Options / Preset 的共享、复制、重命名、删除和默认值。
- Custom Study 的临时会话、今日忘记、逾期、指定范围和额外上限。
- Card Marker / Flag 和 Note Tag 分离。
- 调度、搜索、统计、设置和 UI 分层。

### 6.2 保留 LinguaCafe 特色的部分

- 阅读页直接产生学习材料。
- WordSense 是学习内容单位。
- 原文定位、当前例句、多来源例句和例句轮换。
- surface → lemma 识别和熟词僻义。
- AI 译文和 AI 示意卡分离。
- AI 推荐必须经过人工确认，中文释义必须由用户确认后再建卡。
- EncounteredWord 继续负责阅读颜色和熟悉度总览。

## 7. 实施顺序

### Phase 0：当前事实收口

状态：Completed / Production Closed（2026-07-15）。

目标：

- Manual Sense shared form 完成生产关闭。
- master plan 建立唯一 Open Work Registry。
- 清除已完成任务仍标记计划中的冲突。
- “总体架构收口 100%”改为“领域边界已识别，结构债务仍在治理”。
- 建立本文件作为 Anki 对齐路线权威来源。

成功标准：

- 当前状态表不再同时出现 `production closed` 和 `web acceptance pending`。
- 已完成的 Reader-UI-4、多例句、SenseReview smoke、ReviewCardManage 1B 不再列为未开始。
- Product Gate、Environment Gate、Planned、Partial、Unverified 使用统一状态词。

### Phase 1：Settings 架构收敛

状态：Completed / Production Closed（2026-07-15，ADR-0023）。

优先级：P0，已完成。

目标：为 Preset 提供干净入口，先拆 `AdminReviewSettings.vue` 和 `SettingsService.php`。

计划边界：

- 页面保留一个薄容器。
- FSRS 参数、每日上限、队列顺序、生命周期/Leech 设置分别进入独立区域。
- 前端请求集中到 Settings API client，不继续在页面增加 axios。
- 后端设置读取和写入按领域拆分，`SettingsService` 保留兼容门面。
- 不改变现有 endpoint、payload 和设置语义。

量化目标：

- `AdminReviewSettings.vue` 从 2,164 行降到 60 行，成为纯组合容器。
- 15 个设置 HTTP 调用全部集中到 `AdminReviewSettingsApi.js`，容器和面板不直接调用 axios。
- `SettingsService.php` 从 1,006 行降到 105 行，仅保留兼容门面；四个设置领域服务承接实现。
- 设置、重排、队列消费者回归、全量测试、构建、DB doctor 和 Chrome 双 viewport 均通过。
- 浏览器验收只执行安全保存和只读预览，没有执行正式重排、恢复默认或撤销。

### Phase 2：Preset V1

状态：Planned / Current Next Task。

优先级：P1，当前下一阶段。

产品决定：**Preset V1 绑定用户 + 语言。**

原因：

- 当前正式队列本来就按用户 + 语言隔离。
- 项目没有稳定、互斥的 deck 或材料组模型。
- 直接绑定动态 Saved Search 会产生一张卡属于多个 Preset 的冲突。
- 语言级 Preset 能先实现 Anki Deck Options 的共享、复制和切换语义，后续再扩展到学习集合。

Preset V1 包含：

- FSRS 参数和 desired retention。
- 每日新卡上限、每日复习上限。
- 队列显示顺序。
- Leech 阈值和处理方式。

Preset V1 不包含：

- today-only 临时覆盖。
- Custom Study 临时条件。
- 卡片 lifecycle 状态。
- 任意 deck/subdeck 树。
- 自动重排旧卡。

Anki 对齐行为：

- 新建语言使用 Default Preset。
- 支持新增、复制、重命名、删除和切换。
- 修改当前 Preset 会影响使用该 Preset 的语言设置。
- 参数变更不自动追溯重排旧卡；需要显式进入重排流程。

### Phase 3：Browser / ReviewCardManage 架构收敛

优先级：P1。

目标：把 3,412 行管理页拆成 Anki Browser 风格的三个区域：

1. 搜索与侧栏。
2. 卡片表格。
3. 详情 / 编辑 / 历史区。

拆分方向：

- Query state、Saved Search、table columns、bulk selection、detail drawer、lifecycle dialogs、delete dialogs 分开。
- 页面容器只协调区域，不直接组装所有 payload。
- 所有写操作继续走现有 Mutation / Lifecycle / Access 服务。
- 不改变删除、归档、重置和 ReviewLog 保留语义。

量化目标：

- `ReviewCardManage.vue` 从 3,412 行降到 1,000 行以内。
- 页面直接 axios 引用从 26 降到 5 以内。
- 12 个 dialog 按功能归入独立组件。
- 搜索、导出、详情、批量操作、危险操作全部有自动测试和 MCP Chrome 验收。

### Phase 4：Card Marker + Custom Study 1B

优先级：P1。

产品决定：

- Card Marker 参考 Anki Card Flag，落在 ReviewCard。
- Marker 和 lifecycle、leech、WordSense status 分离。
- V1 使用有限颜色/等级，不允许自由文本滥用。
- WordSense Tag 作为未来 Note Tag 能力，单独规划。

Custom Study 1B：

- 增加“已标记卡片”条件。
- 允许从 Browser / Card Info 进入临时学习。
- 继续保持 preview-only 或明确的正式评分模式，不能混淆。
- 临时会话不改正常队列归属。

### Phase 5：Reviewer 架构收敛

优先级：P2。

范围：

- `SenseReview.vue`、`Review.vue` 的请求、会话状态、报告弹窗和评分控制继续拆分。
- 统一 Review API client、rating request recovery、session state 和 interval preview 接口。
- Legacy Review 保持兼容，不继续新增产品能力。
- SenseReview 保持正式主入口。

目标：

- 两个 Reviewer 页面不复制队列、评分恢复和错误处理逻辑。
- 页面容器只负责当前卡片和会话编排。
- FSRS 计算继续只在后端。

### Phase 6：Reader UI 与阅读架构治理

优先级：P2。

先做产品小步：

- hover 自动查词开关。
- 隐藏或重组低价值常驻面板。
- 查词栏信息密度和焦点顺序优化。

后做结构拆分：

- `TextBlockGroup.vue` 按 token rendering、selection、lookup orchestration、keyboard/hover、reader completion 拆分。
- `TextBlockService.php` 按 tokenizer/fallback、EncounteredWord creation、phrase indexing、reader facade 继续收敛。
- 每次只拆一个职责，先补 harness，再移动代码。

禁止：

- 一次性重写阅读页。
- 改 surface/lemma、原文定位、多例句、AI 示意卡和颜色语义。
- 用组件拆分名义改变用户流程。

### Phase 7：AI Study Card service 收敛与真实 provider

优先级：P3 / Environment Gate。

先拆 `AiStudyCardPendingItemService.php`：

- Pending lifecycle。
- Preview/final package。
- Candidate validation/deduplication。
- Card generation。
- Source binding。

真实 provider 只在以下条件满足后启动：

- provider、模型、成本上限和超时明确。
- secret 存储方案明确。
- 默认关闭和 fail-closed 测试通过。
- 浏览器 Network 能证明没有意外外发。
- AI 推荐仍默认不选。
- AI reason 不自动写入中文释义。

## 8. 架构预算与门禁

从本路线生效后：

1. 超过 1,000 行的生产文件不得继续无计划增加职责。
2. 修改热点文件时，任务必须说明本轮减少了什么职责或为什么暂时不能减少。
3. 新页面功能不得直接把请求、状态、弹窗、规则全部写进页面容器。
4. 新后端业务规则必须有单一领域入口，Controller 只做鉴权、验证和编排。
5. FSRS、ReviewLog、lifecycle、删除、归档、来源绑定不得在前端复制规则。
6. 每个拆分任务先写 characterization test，再移动实现。
7. 每轮架构优化必须保持 endpoint、payload 和用户流程，除非另有 ADR。
8. “架构完成”必须附带可量化指标。禁止用百分比代替文件、接口、测试和验收事实。
9. 历史任务叙述进入 history。master plan 当前区只保留状态、缺口、证据和授权。
10. Harness 聚焦高风险不变量，不追求机械覆盖全部代码。

## 9. 当前优先级

| 顺序 | 任务 | 原因 |
|---:|---|---|
| 1 | Settings 架构收敛 | Preset 的前置地基；当前设置页和 SettingsService 都是热点 |
| 2 | Preset V1 | 补齐 Anki Deck Options 的共享配置能力 |
| 3 | Browser / ReviewCardManage 架构收敛 | 当前最大前端热点，也是后续 Marker 和治理入口 |
| 4 | Card Marker + Custom Study 1B | 复用 Browser 和 Custom Study 1A，补齐 Anki Flag/Filtered Deck 路线 |
| 5 | Reviewer 架构收敛 | 减少两套复习页面重复状态和请求逻辑 |
| 6 | Reader UI 小步 + Reader 架构治理 | 保留特色，降低最高风险阅读热点 |
| 7 | AI provider | 当前手工闭环已可用，外部成本和数据风险更高 |

## 10. 不进入当前路线的事项

- 通用 Note Type / Card Template 编辑器。
- 任意层级 deck/subdeck 树。
- WordSense 自动生成正反两张 sibling cards。
- phrase FSRS。
- 删除 legacy word card 兼容层。
- 手机端适配。
- 自动生成释义后直接建卡。
- 自动评分或 AI 代替用户评分。
