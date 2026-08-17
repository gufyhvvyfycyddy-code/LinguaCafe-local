# LinguaCafe Anki 对齐产品与架构路线

> **状态**：Historical reference / forward authority superseded on 2026-08-18
> **日期**：2026-07-23；supersession 2026-08-18
> **基线 commit**：`18c8208073029cfadf89f86634b8f4cad68f4854`
> **当前用途**：保留 Anki 官方能力映射、已完成架构历史和技术参考。当前产品优先级与下一阶段授权改读 `docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md` + Goal Phase G；Reader spaced-review 读 ADR-0059。本文与这些 current authority 冲突时仅作历史来源。

## 1. 一句话结论

LinguaCafe 保留阅读优先、sense-only、原文定位、多例句、lemma 和 AI 示意卡能力。复习、设置、浏览器、Preset、Custom Study、Card Info、Leech、统计和撤销等通用学习能力，以 Anki 官方产品语义和代码分层为第一参考。

Settings 架构收敛、Preset V1A–V1D、Browser / ReviewCardManage Phase 3A–3D、Card Marker / Custom Study 1B、Phase 5 Reviewer、Phase 6 Reader、Phase 7 AI Study Card service 收敛与 provider Environment Gate 审计均已关闭。用户已接受新的产品规划方向，但尚未授权代码实现；runtime provider 激活仍需单独批准。

## 2. 本轮依据

### 2.1 当前仓库与架构事实

当前代码规模、测试规模、热点文件和架构负担会持续变化，不再在本路线中冻结一次性行数或永久评分。

当前数据只从以下入口读取：

- `docs/CURRENT_AI_CONTEXT.md`；
- `docs/architecture/upstream-code-test-and-architecture-comparison-2026-07-23.md`；
- `docs/architecture/code-documentation-and-bug-architecture-audit-2026-07-23.md`。

稳定结论只有：

- WordSense、ReviewCard、ReviewLog、FSRS 和 Occurrence 已形成明确数据职责；
- Settings、Browser、Custom Study、Reviewer、Reader 和 AI Study Card 已建立领域 owner；
- Reader、Reviewer、导入、启动与默认值仍是主要维护风险；
- 测试和文档必须保护用户行为与数据，不锁定文件精确行数、日期或旧阶段文案。

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
| WordSense Tag（已接受，待规划） | Note Tag | 内容级分类，和 Card Marker 分离 |
| 阅读页 | LinguaCafe 独有内容采集层 | 保留原文、lemma、点词、多例句和 AI 示意卡 |

## 6. 产品总原则

### 6.1 直接学习 Anki 的部分

- 问题面 → 显示答案 → Again / Hard / Good / Easy。
- FSRS、预计间隔、撤销、ReviewLog、生命周期和 Leech 治理。
- Browser 的搜索、保存搜索、列表、编辑区、Card Info 和批量操作。
- Deck Options / Preset 的共享、复制、重命名、删除和默认值。
- Custom Study 的临时会话、今日忘记、逾期、指定范围和额外上限；后续继续学习兼容的 Filtered Deck 能力。
- Card Marker / Flag 和 WordSense Tag 分离。
- 自动备份与恢复、统一搜索、更完整统计、`.apkg` 内容卡导出和文章健康检查。
- 受控插件接口，而不是插件任意写数据库。
- 调度、搜索、统计、设置和 UI 分层。

### 6.2 保留 LinguaCafe 特色的部分

- 阅读页直接产生学习材料。
- WordSense 是学习内容单位。
- 原文定位、当前例句、多来源例句和例句轮换。
- surface → lemma 识别和熟词僻义。
- AI 译文和 AI 示意卡分离。
- AI 推荐必须经过人工确认，中文释义必须由用户确认后再建卡；重复词义整理也采用文件包 + 固定提示词 + 人工确认。
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

状态：**Phase 3A–3D Accepted / Production Closed**。Card Info、Search、Table、Due-now / Reset Scheduling Mutation Surface、Lifecycle Mutation Surface、Delete Mutation Surface 与 Leech Governance Mutation Surface 已分别形成单一职责所有者。Phase 3D 删除父组件无入口的 legacy `/enabled` archive/restore 客户端和旧确认框，`ReviewCardManage.vue` 已收敛为协调容器；Search、Table、Card Info、Scheduling、Lifecycle、Delete 和 Leech Governance 保持独立 owner，后端兼容 route 和既有语义不变。Phase 3D 的 authenticated MCP Chrome 验收记录见 `docs/testing/review-card-container-closure-browser-acceptance-2026-07-18.md`。

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
- **Phase 3D — Container Closure**：Accepted / Production Closed。删除无入口的旧 `/enabled` 父组件兼容客户端和确认框，让页面容器只协调区域。
- 所有写操作继续走现有 Mutation / Lifecycle / Access 服务。
- 不改变删除、归档、重置和 ReviewLog 保留语义。
- 不复制 Anki 的 Cards/Notes 双模式、deck/subdeck 树、Note 删除语义或 Filtered Deck。

关闭结果：

- 页面容器不再拥有子区域弹窗和危险写操作。
- Search、Table、Card Info、Scheduling、Lifecycle、Delete 和 Leech Governance 各有明确 owner。
- 搜索、导出、详情、批量操作和危险操作均有自动测试与真实页面验收。
- 文件行数属于变化中的测量值，不再作为长期 Harness。

### Phase 4：Card Marker + Custom Study 1B

优先级：P1。

状态：**Accepted / Production Closed（2026-07-18）**。ReviewCard Marker、单卡/批量 API、Browser/Card Info 控件、`marked` Custom Study 条件和 preview-only 会话均已通过自动回归、testing MySQL 零写入证明和 authenticated browser 双 viewport 验收。证据见 `docs/testing/card-marker-custom-study-1b-browser-acceptance-2026-07-18.md` 与 ADR-0029。

产品决定：

- Card Marker 参考 Anki Card Flag，落在 ReviewCard。
- Marker 和 lifecycle、leech、WordSense status 分离。
- V1 使用有限颜色/等级，不允许自由文本滥用。
- WordSense Tag 已获得产品接受，作为 Note Tag 类内容能力单独规划。

Custom Study 1B：

- 增加“已标记卡片”条件。
- 允许从 Browser / Card Info 进入临时学习。
- 当前继续保持 preview-only；是否扩展为正式评分模式已进入产品讨论，冻结前不改 Harness。
- 临时会话不改正常队列归属。

### Phase 5：Reviewer 架构收敛

优先级：P2。

状态：**Accepted / Production Closed（2026-07-18）**。正式请求和单次评分事务已形成共享边界；Sense history/undo 动作形成独立 owner；legacy 与 Sense 页面语义、后端 endpoint/payload、FSRS 和 ReviewLog 保持不变。证据见 `docs/testing/reviewer-architecture-convergence-browser-acceptance-2026-07-18.md`。

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

状态：**Accepted / Production Closed**。Phase 6A–6L 完成 Reader frontend convergence；Phase 6M 将生产 English Python-down fallback 收敛到 `EnglishFallbackTokenizerService`，`TextBlockService.php` 从 1,382 行降至 1,077 行并保留兼容门面。

先做产品小步：

- hover 自动查词开关：现有设置已满足产品需求；Phase 6A 已把 `closed` / `local-only` / `search` 与 term 选择收敛到纯 policy，保留原 UI、endpoint、payload、timer、stale-response 和 click lookup 行为。验收见 `docs/testing/reader-hover-lookup-policy-browser-acceptance-2026-07-18.md`。
- hover 位置：Phase 6B 已把水平边界、上下偏好、空间不足翻转和滚动偏移收敛到纯 policy；DOM 测量与 Vuex 提交仍由组件负责。验收见 `docs/testing/reader-hover-position-policy-browser-acceptance-2026-07-23.md`。
- 点词句子上下文：Phase 6C 已把 token-window、缩写/小数/章节边界、长度上限和 `sentence_index` fallback 收敛到纯 policy；Vuex commit、点词和侧栏编排仍由组件负责。验收见 `docs/testing/reader-sentence-context-policy-browser-acceptance-2026-07-23.md`。
- 拖选范围：Phase 6D 已把端点 guard、正反向规范化、既有 phrase-length 边界、换行过滤和原文顺序收敛到纯 policy；鼠标/触摸事件、timer、选中状态应用和侧栏编排仍由组件负责。验收见 `docs/testing/reader-drag-selection-policy-browser-acceptance-2026-07-23.md`。
- 短语实例选区：Phase 6E 已把 backward start、换行桥接、精确 phrase index、unique-word enrichment 和原文顺序收敛到纯 policy；短语轮换、lookup count、Vuex、HTTP 和侧栏编排仍由组件负责。验收见 `docs/testing/reader-phrase-instance-selection-policy-browser-acceptance-2026-07-23.md`。
- 侧栏动作与焦点：Phase 6F 已移除重复发音入口，把普通学习状态与破坏性恢复动作分层，并在打开添加释义时聚焦词典搜索；窄屏 `VocabularyBox` 回退和所有 action owner 保持不变。验收见 `docs/testing/reader-sidebar-action-focus-browser-acceptance-2026-07-23.md`。
- 快捷键意图：Phase 6G 已把 suppression、legacy key code、stage/Shift 参数和 prevent-default 决策收敛到纯 policy；DOM 判断和全部 speech/stage/scroll/Anki/selection/plain-text effect 仍由组件负责。验收见 `docs/testing/reader-hotkey-policy-browser-acceptance-2026-07-23.md`。
- 选择导航：Phase 6H 已把前后方向 anchor、rendered-token skipping、new/highlighted filter 和候选扫描收敛到纯 policy；DOM 测量、hotkey dispatch、`unselectAllWords`、`$nextTick` 和 start/finish effects 仍由组件负责。验收见 `docs/testing/reader-navigation-policy-browser-acceptance-2026-07-23.md`。
- 完成阅读候选：Phase 6I 已把 `definitions_checked`/负 stage 条件、word-before-phrase 顺序、source index/type/ID 收敛到纯 policy；源对象兼容标记、确认框、`/chapters/finish` payload、完成页和全部持久化效果仍由既有组件负责。验收见 `docs/testing/reader-completion-candidate-policy-browser-acceptance-2026-07-23.md`。
- Token 展示：Phase 6J 已把 spaceless language、section marker、句末、严格 AI 译文查找、token class 和两类 furigana 条件收敛到纯 policy；全部模板节点、属性、key、样式、空白注释和事件仍由组件负责。验收见 `docs/testing/reader-token-presentation-policy-browser-acceptance-2026-07-23.md`。
- 查词响应：Phase 6K 已把 stale-response 判断、本地释义拼接、API 释义展平和词形变化展示解析收敛到纯 policy；全部 endpoint、payload、Vuex、错误处理、定位和 timer 仍由组件负责。验收见 `docs/testing/reader-lookup-response-policy-browser-acceptance-2026-07-23.md`。
- 查词 transport：Phase 6L 已把四个 dictionary axios expression 收敛到 `ReaderLookupApi`，精确保留 method/URL/payload/Promise、调用位置/顺序/条件和全部 continuation/effect。验收见 `docs/testing/reader-lookup-api-browser-acceptance-2026-07-23.md`。
- English fallback tokenizer：Phase 6M 已把生产 English Python-down fallback、token shape、保守 lemma 规则和 irregular map 收敛到纯 `EnglishFallbackTokenizerService`；Python-first 决策、ECDICT hooks、doctor-only helper、structural mapping、phrase/import/Reader facade 均保持不变。验收见 `docs/testing/english-fallback-tokenizer-service-browser-acceptance-2026-07-23.md`。

后做结构拆分：

- `TextBlockGroup.vue` 的 frontend extraction 目标已完成：pure rules 与 dictionary transport 均有 owner，组件保留编排和 effects。
- `TextBlockService.php` 按 tokenizer/fallback、EncounteredWord creation、phrase indexing、reader facade 继续收敛。
- 每次只拆一个职责，先补 harness，再移动代码。

禁止：

- 一次性重写阅读页。
- 改 surface/lemma、原文定位、多例句、AI 示意卡和颜色语义。
- 用组件拆分名义改变用户流程。

### Phase 7：AI Study Card service 收敛与真实 provider

优先级：P3 / Environment Gate。

状态：**Accepted / Production Closed**。Phase 7A lifecycle、7B package、7C validation、7D source binding 与 7E generation 均已关闭，五项职责分别有独立 owner；coordinator 从 1,065 行降至 61 行。Phase 7E 的完整保护矩阵通过 1,093 tests / 6,124 assertions，frontend build 成功，official Browser 完成真实人工确认生成与夹具清理。独立 provider Environment Gate 也已按 ADR-0030 以 default-off / fail-closed 形态关闭；runtime 外发仍需具体授权。

先拆 `AiStudyCardPendingItemService.php`：

- Pending lifecycle。
- Preview/final package。
- Candidate validation/deduplication。
- Card generation。
- Source binding。

Environment Gate 已按 ADR-0030 以 **default-off / fail-closed** 形态关闭。真实 runtime provider 仍只在以下条件满足后启动：

- provider、模型、成本上限和超时明确。
- secret 存储方案明确。
- 默认关闭和 fail-closed 测试通过。
- 浏览器 Network 能证明没有意外外发。
- AI 推荐仍默认不选。
- AI reason 不自动写入中文释义。

### Phase 8：已接受的新产品规划（尚未授权实现）

权威产品决定：`docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md`。

已接受：

- 自动备份与恢复；
- WordSense Tag；
- 统一 Search Criteria；
- Statistics V2；
- 固定模板 `.apkg` 内容卡导出；
- 只针对文章的健康检查；
- Browser V2 与 AI 重复词义文件闭环；
- 与 sense-only FSRS 兼容的更多复习设置；
- 受控插件接口；
- Custom Study 继续向 Anki 靠拢，但正式评分语义待讨论。

PD-012“阅读中直接刷词义卡 V1”已完成产品冻结，但当前只允许 Architecture Spec / ADR / Harness 迁移设计，尚未获得业务代码授权。尚未冻结的议题包括：无密码 Profile、AI 新文章入口、整体翻译布局、移动端身份细节、同步身份和商业模式；冻结前不得修改对应现有 Harness。

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

既有授权阶段均已关闭。PD-012 已获得产品冻结资格，但不是 active task，当前只允许架构设计；其他新功能目前只获得产品规划资格，尚未冻结最终实施顺序。

规划池包括：备份恢复、WordSense Tag、统一搜索、统计 V2、`.apkg`、文章健康检查、Browser V2、兼容设置和插件接口。Custom Study 扩展必须等评分语义讨论完成。

PD-012 进入实现前必须先完成 Architecture Spec、ADR 与 Harness 迁移设计，并取得新的业务代码授权。其他未冻结方向继续按产品讨论推进：AI 阅读与翻译排版；档案/手机/同步身份；三平台/插件/商业模式。

## 10. 不进入当前路线的事项

- 通用 Note Type / Card Template 编辑器。
- 任意层级 deck/subdeck 树。
- WordSense 自动生成正反两张 sibling cards。
- phrase FSRS。
- 删除 legacy word card 兼容层。
- AI 代替用户评分。
- 自动生成释义后未经确认直接建卡。

手机端、无密码 Profile 和商业模式不再列为永久排除项，但仍属于未冻结讨论，不得直接实施。阅读中直接刷词义卡 V1 已由 PD-012 完成产品冻结，但在 Architecture Spec、ADR、Harness 迁移设计和新业务代码授权完成前仍不得实施。
