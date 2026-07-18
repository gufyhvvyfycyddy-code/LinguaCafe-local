# LinguaCafe Anki 对齐产品与架构路线

> **状态**：Current / Authoritative
> **日期**：2026-07-17
> **基线 commit**：`18c8208073029cfadf89f86634b8f4cad68f4854`
> **适用范围**：产品优先级、架构优化顺序、下一阶段任务授权判断

## 1. 一句话结论

LinguaCafe 保留阅读优先、sense-only、原文定位、多例句、lemma 和 AI 示意卡能力。复习、设置、浏览器、Preset、Custom Study、Card Info、Leech、统计和撤销等通用学习能力，以 Anki 官方产品语义和代码分层为第一参考。

Settings 架构收敛和 Preset V1A–V1D 已生产关闭。V1D 已通过纯状态模块、分组回归、双用户、English/French、CRUD、共享修改、刷新持久化、删除重绑定、数据库 delta 和 Chrome 双 viewport 验收。Browser / ReviewCardManage Phase 3A、Phase 3B-1、Phase 3B-2、Phase 3C-1、Phase 3C-2、Phase 3C-3 与 Phase 3C-4 均为 Accepted / Production Closed。Phase 3C-4 已于 2026-07-18 完成 authenticated MCP Chrome 验收：单卡重写包、双卡批量重写包、双卡暂停和恢复均通过真实页面操作，生命周期写入仍由既有 owner 执行，provider/card/ReviewLog safety flags 保持 false，验收后的卡片状态已恢复为 active 4 / suspended 0，Console 无错误或警告，应用资源保持 localhost-only。Phase 3D — Container Closure 为 Planned / Not Authorized。详细边界见 `docs/plans/review-card-manage-architecture-convergence-plan.md`。Card Marker / Custom Study 1B、Reviewer、Reader 与真实 AI provider 继续按顺序延后。

## 2. 本轮依据

### 2.1 仓库事实

当前生产代码规模：

| 区域 | 行数 |
|---|---:|
| `app/` | 43,220 |
| `resources/js/` | 43,930 |
| `tests/` | 89,549 |
| `docs/` | 31,083 |

生产文件体量：

- 28 个生产文件超过 500 行。
- 10 个生产文件超过 1,000 行。
- 1 个生产文件超过 1,500 行。

主要热点：

| 文件 | 行数 | 结构信号 | 风险 |
|---|---:|---|---|
| `resources/js/components/Text/TextBlockGroup.vue` | 2,514 | 阅读 token、选词、查词、键盘与完成阅读仍高度集中 | 高，阅读主链路 |
| `resources/js/components/Senses/SenseReview.vue` | 1,476 | 11 个 axios 引用、4 个 dialog；正式复习编排仍集中 | 中高 |
| `app/Services/TextBlockService.php` | 1,381 | 仍保留多类阅读处理职责 | 高，阅读主链路 |
| `resources/js/components/ReviewCards/ReviewCardManage.vue` | 1,210 | 11 个直接 `axios.` 引用、6 个 `v-dialog`；Card Info/Search/Table/Scheduling/Lifecycle 已有独立所有者 | 中高，结构已明显改善 |
| `app/Services/CustomStudy/CustomStudySessionState.php` | 1,176 | 方法较多，但职责集中在不可变会话状态 | 中 |
| `app/Services/DictionaryImportService.php` | 1,157 | 覆盖多种格式和导入阶段 | 中高 |
| `resources/js/components/Review/Review.vue` | 1,070 | 仍承担 legacy 队列和页面编排 | 中 |
| `app/Services/AiStudyCardPendingItemService.php` | 1,064 | V1–V5 多阶段逻辑聚集 | 高 |

### 2.2 屎山程度评估

当前评分：**6.0 / 10，局部中高负担**。

支撑可维护性的因素：

- WordSense、ReviewCard、ReviewLog、FSRS、Occurrence 已有明确数据职责。
- 自动测试数量高，关键写入路径已有事务、权限和回归护栏。
- 多个 Controller、Query Service、Serializer、Policy 已完成分离。
- ReviewCardManage 已从 3,411 行降至 1,210 行，并形成 Card Info、Search、Table、Scheduling、Lifecycle 五个职责完整的所有者。
- MCP Chrome 验收流程已建立。

增加维护成本的因素：

- Reader 与 Reviewer 的超大 Vue 页面仍同时承担请求、状态、展示、弹窗和业务编排。
- `TextBlockService`、`AiStudyCardPendingItemService` 等热点仍聚集多类职责；Settings 已完成拆分，不能重新膨胀。
- ReviewCardManage 父组件仍有 11 个直接请求和 6 个对话框，并保留无可达表格入口的 legacy `/enabled` 兼容代码；这些属于后续兼容清理债务，不应在当前验收中顺手删除。
- master plan、handoff、hotspot audit 仍很长；当前状态必须只从 Current authority、Open Work Registry 和本路线读取。
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

本轮直接读取并检索了项目上下文提供的 9 个原始 AI 编程字幕文件（共 11,156 行字幕），并重点深读其中 4 个与 spec、harness、长期边界和屎山治理最相关的文件。本路线把可复用结论转成项目门禁：

1. 一个模块用一句话说明职责。无法用一句话说明时，先拆职责。
2. 模块通过稳定接口沟通。页面不得直接复制调度、权限或数据规则。
3. Spec 记录已经稳定的决定。仍在探索的方案保留为候选，不写成长期规则。
4. Harness 锁定高风险不变量。权限、ReviewLog、FSRS、删除、归档、来源绑定必须有可执行检查。
5. 文档分为入口、当前账本、ADR、模块契约、历史五层。当前状态只保留一个权威入口。
6. 每轮只完成一个可独立验收的结构变化。拆分后必须保持用户行为不变。
7. 大文件拆分以职责和数据流为依据，不按行数机械切块。
8. 全局状态和隐式副作用优先治理。纯函数、Policy、Value Object 保持无数据库和无页面依赖。
9. 自然语言结论必须下沉为测试、smoke、状态机或可观测数据库事实；Agent 报告只提供线索，不构成最终验收。
10. 可以并行的只读侦查、文档核查和独立测试应并行；共享代码修改、数据库写入和最终合并必须串行收口，防止互相覆盖。
11. MVP 阶段只冻结产品身份、反向边界和不可破坏项；进入长期迭代后，再把反复验证且稳定的决定收敛为 spec、模块和接口，避免过早写满文档，也避免成熟后继续无边界扩张。
12. 接口和组件拆分本身也会增加复杂度；只有形成独立职责、单一副作用所有者或可执行门禁时才抽取，禁止为了行数制造空壳。
13. Agent 自报完成不是验收。真实页面、测试、数据库 delta 和可观察请求才构成闭环证据。

## 4. Anki 官方架构参考

Anki 官方仓库把核心能力按领域拆分。Rust 核心包含 `card`、`collection`、`deckconfig`、`decks`、`notes`、`revlog`、`scheduler`、`search`、`stats`、`storage`、`undo` 等模块。`deckconfig` 内进一步区分 `service.rs`、`update.rs`、`undo.rs`，说明读取、更新和撤销不应堆进同一个页面或控制器。`proto` 定义前后端通信和部分存储契约，并生成 Rust、Python、TypeScript 绑定。Python 层包装 Rust 核心，Qt `DeckOptionsDialog` 主要承载独立 Web 页面，桌面壳不复制设置领域规则。Browser 目录也把 `sidebar`、`table`、`card_info` 与页面编排分开。

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
| Preset | Deck Options Preset | Preset 归属于用户；每个用户 + 语言唯一绑定；同一用户多语言可共享；不建立 deck 树 |
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
- 复习目标、每日上限、队列顺序、当前状态、高级工具和旧 SRS 分别进入独立区域。
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

状态：V1A–V1D Completed / Accepted / Production Closed（2026-07-15）。

优先级：P1，已完成；不得继续追加未经过产品 Gate 的 Preset V1.1 能力。

权威实施计划：`docs/plans/review-settings-preset-v1-plan.md`。

产品决定：**Preset 归属于用户；每个用户 + 学习语言只绑定一个 Preset；同一用户的多种语言可以共享一个 Preset。**

原因：

- 当前正式队列本来就按用户 + 语言隔离。
- 项目没有稳定、互斥的 deck 或材料组模型。
- 直接绑定动态 Saved Search 会产生一张卡属于多个 Preset 的冲突。
- Anki 的稳定语义是共享配置对象、修改影响所有绑定对象、Add/Clone/Rename/Delete 独立操作、设置变更不自动追溯排程。
- 项目字幕强调先冻结身份、绑定、删除和单一读取入口，再开发管理 UI。

Preset V1 核心配置只包含已经有稳定接口和测试的设置：

- desired retention；
- FSRS 参数及来源元数据；
- 每日新卡 / 复习上限；
- 队列显示顺序。

Leech 配置修正：

- Anki 的 Leech threshold/action 属于 Deck Options；
- LinguaCafe 当前使用更丰富的 stable / struggling / leech Policy，代码中没有稳定的 Leech 设置接口；
- Leech 阈值和处理方式移到 `Preset V1.1 Leech Configuration Product Gate`，禁止在 V1A/V1B 中直接把 Policy 常量搬进 JSON 或前端。

Preset V1 不包含：

- today-only 临时覆盖；
- Custom Study 临时条件；
- 卡片 lifecycle 状态；
- Card Marker 或 Saved Search；
- 任意 deck/subdeck 树；
- 自动重排旧卡。

分阶段：

1. **V1A — Completed / Production Closed**：additive persistence、Default Preset、用户/语言唯一绑定、legacy global snapshot、单一 `ReviewSettingsResolver`、现有 endpoint/payload 兼容、现有设置与调度透明读取当前 Preset；双 viewport、真实 English/French binding、保存和全量回归已由网页端复核。实现决策见 ADR-0024。
2. **V1B — Completed / Production Closed**：新增、复制、重命名、删除、切换 API 与 UI；Default 保护、所有权、共享语言提示与删除重绑定均已通过真实页面验收。实现决策见 ADR-0025。
3. **V1C — Completed / Production Closed**：所有 FSRS / daily limits / queue / simulation 消费者继续以当前 binding + Resolver 为权威；停止 `fsrs_parameters_previous` 新写入/删除，删除无调用方的全局写入辅助方法，旧行仅作为忽略的历史残留。实现决策见 ADR-0026。
4. **V1D — Completed / Production Closed**：Settings UX-1 通过纯状态模块与动作安全护栏；主账号 English/French 完成共享 Preset 的创建、切换、修改、复制、重命名、刷新持久化、删除重绑定；第二本地管理员账号完成页面级隔离；验收前后 ReviewLog、ReviewCard 和到期 checksum 不变，证明未自动重排。

Anki 对齐行为：

- 新语言首次进入时使用该用户自己的 Default Preset。
- Add 从系统默认值创建；Clone 复制当前 Preset。
- 修改当前 Preset 会影响同一用户所有绑定语言。
- 参数变更不自动追溯重排旧卡；需要显式进入现有重排流程。

### Phase 3：Browser / ReviewCardManage 架构收敛

状态：**Phase 3A、Phase 3B-1、Phase 3B-2、Phase 3C-1、Phase 3C-2、Phase 3C-3 与 Phase 3C-4 Accepted / Production Closed**。Card Info、Search、Table、Due-now / Reset Scheduling Mutation Surface、Lifecycle Mutation Surface、Delete Mutation Surface 与 Leech Governance Mutation Surface 已分别形成单一职责所有者。`ReviewCardManage.vue` 当前为 767 行、7 个 direct `axios.` references、2 个 `v-dialog`；`ReviewCardLeechGovernanceMutationSurface.vue` 为 366 行，拥有一个 summary GET、一个 bulk rewrite POST 与两个弹窗，并把暂停写入委托给既有 Lifecycle owner。Phase 3C-4 的 authenticated MCP Chrome 验收记录见 `docs/testing/review-card-leech-governance-browser-acceptance-2026-07-18.md`。**Phase 3D — Container Closure：Planned / Not Authorized**。本轮停止，不进入 3D。详细分期、允许文件、禁止范围和验收合同见 `docs/plans/review-card-manage-architecture-convergence-plan.md`。

优先级：P1。

目标：参考 Anki Browser 的 `sidebar`、`table`、`card_info` 分层，把 3,411 行管理页按职责拆成三个区域：

1. 搜索与侧栏。
2. 卡片表格。
3. 详情 / 编辑 / 历史区。

拆分方向：

- **Phase 3A — Card Info Drawer Extraction**：Accepted / Production Closed。ADR-0014 锁定的只读详情抽屉、单一 detail 请求、tabs、异步竞态保护和清理边界已迁入 `ReviewCardInfoDrawer.vue`，真实页面验收已完成。
- **Phase 3B-1 — Search / Filter / Saved Search Surface**：Accepted / Production Closed。`ReviewCardSearchSurface.vue` 负责搜索输入、服务端错误、Saved Search、当前筛选状态和高级筛选；继续复用 `ReviewCardSavedSearchPanel.vue` 与 `ReviewCardManageFilterState.js`，不改服务端搜索语法。
- **Phase 3B-2 — Table / Columns / Pagination / Selection / Export**：Accepted / Production Closed。`ReviewCardTableSurface.vue` 负责表格、列、排序、分页、compact mode、current/selected 分离和只读导出；父页面保留列表请求和全部写操作。
- **Phase 3C-1 — Due-now / Reset Scheduling Mutation Family**：Accepted / Production Closed。`ReviewCardSchedulingMutationSurface.vue` 负责两项调度写操作、请求锁与确认框，父页面只协调事件。
- **Phase 3C-2 — Lifecycle Mutation Family**：Accepted / Production Closed。`ReviewCardLifecycleMutationSurface.vue` 负责 descriptor、单卡/批量生命周期请求、确认框、冲突处理、状态说明和请求锁；父页面只传递意图与只读快照。Authenticated Chrome 双 viewport 验收已于 2026-07-17 完成。
- **Phase 3C-3 — Delete Mutation Family**：Accepted / Production Closed。
- **Phase 3C-4 — Leech Governance Mutation Family**：Accepted / Production Closed。
- **Phase 3D — Container Closure**：Planned / Not Authorized。
- **Phase 3D — Container Closure**：消除重复状态所有者，让页面容器只协调区域。
- 所有写操作继续走现有 Mutation / Lifecycle / Access 服务。
- 不改变删除、归档、重置和 ReviewLog 保留语义。
- 不复制 Anki 的 Cards/Notes 双模式、deck/subdeck 树、Note 删除语义或 Filtered Deck。

量化目标：

- `ReviewCardManage.vue` 最终优先降到 1,200 行以内；1,000 行是 stretch target，不得为了数字制造无职责的空壳组件。
- 页面直接 `axios.` 引用从 24 降到 5 以内。
- 当前剩余 6 个 dialog 继续按真实功能族归入独立组件。
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
11. 对外部响应形成的 UI 状态，优先用纯展示状态模块统一归一化，再由页面消费；禁止在模板和多个 method 中重复拼接同一状态规则。
12. 文档只登记决策和入口。能够影响安全、状态或旧功能的规则必须落入测试、guard 或 Chrome smoke，避免只靠 Agent 读取长文档。

## 9. 当前优先级

| 顺序 | 任务 | 原因 |
|---:|---|---|
| 1 | Preset V1A–V1D | Default、绑定、管理动作、共享提示、消费者收敛、高级工具 UX 和最终生产矩阵均已完成 |
| 2 | Browser / ReviewCardManage 架构收敛 | Phase 3A、3B-1、3B-2、3C-1、3C-2、3C-3 与 3C-4 Accepted / Production Closed；父组件 767 行、7 个请求、2 个弹窗；Phase 3D Planned / Not Authorized；见 `review-card-manage-architecture-convergence-plan.md` |
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
