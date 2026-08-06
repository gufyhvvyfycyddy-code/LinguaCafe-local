# LinguaCafe 与 Anki 功能及架构通俗对比

> **状态：Historical Snapshot / 2026-07-23。** 本文保留当时的产品与架构比较，不代表当前里程碑、当前授权或最新实现状态；当前事实统一从 `docs/CURRENT_AI_CONTEXT.md`、产品决定文档和实时 Git preflight 获取。
> 日期：2026-07-23
> 性质：产品与架构评估，不代表已授权开发。
> 信息渠道：Anki 官方手册、Anki 官方 GitHub 仓库、Anki Forums。官方手册和仓库用于确认事实，论坛只用于发现用户痛点和趋势。

## 1. 一句话销售定位

**Anki 像一套“万能记忆卡操作系统”**：用户可以自己定义字段、卡片模板、牌组、标签、媒体、同步方式和插件。

**LinguaCafe 像一位“陪你读原文并自动整理学习材料的阅读教练”**：它知道一个词出现在哪篇文章、哪句话、是哪一个词义，并把真实阅读证据直接变成词义复习卡。

LinguaCafe 不需要把 Anki 的所有功能复制一遍。最合理的方向是：

- 保留阅读、词义、原文定位和 AI 辅助这些 Anki 不擅长的部分；
- 补齐同步、备份、标签、标准导出、搜索和统计等成熟学习工具；
- 不引入通用模板和无限牌组树；插件方向已接受，但必须使用受控、版本化、最小权限接口。

## 2. 用户现在已经能得到什么

LinguaCafe 当前已有：

- 导入文章、网页、字幕等阅读材料；
- 阅读页逐词点击、悬停词典、词形与 lemma 识别；
- 一个单词按具体词义建立 WordSense；
- 原文位置、多来源例句和上下文回看；
- sense-only FSRS 正式复习；
- Again / Hard / Good / Easy、预计间隔和撤销；
- ReviewLog、生命周期、埋藏、暂停、归档、恢复和 Leech 治理；
- Preset、每日上限、队列顺序和 Custom Study preview；
- Browser、Saved Search、Card Info、批量治理和多种导出；
- Card Marker；
- AI Study Card 人工确认链，默认不勾选、不自动建卡；
- 阅读确认、学习报告和部分趋势统计。

这已经不是原项目的简单词汇阅读器，而是一套围绕“真实阅读产生词义学习”的完整主线。

## 3. 与 Anki 相比，目前还缺什么

### 3.1 建议优先补齐：用户会直接感受到价值

#### F-A01 跨设备同步与冲突处理

Anki 可以通过 AnkiWeb 在电脑、手机和平板间同步卡片、复习历史和媒体，并处理大部分普通编辑与复习合并。

LinguaCafe 目前主要是本地网页系统，没有成熟的离线客户端、增量同步协议、媒体同步和冲突解决。

用户价值：换电脑或在手机上学习时，不需要复制数据库，也不担心进度丢失。

建议：先做“备份恢复 + 单服务器账户数据”稳定化，再评估真正的离线双向同步。不要直接复制 Anki 的完整同步复杂度。

#### F-A02 自动备份、时间点恢复和删除恢复

Anki 有自动备份、手动 collection 包备份、恢复入口和删除日志。

LinguaCafe 有备份相关代码，但还没有形成像 Anki 那样清晰、经过用户验证的“自动备份—查看—恢复—防误覆盖”产品闭环。

建议：这是比高级模板更优先的安全功能。

#### F-A03 WordSense Tag / 内容级标签

Anki 的 Tag 属于 Note，Flag 属于单张 Card。LinguaCafe 已有 Card Marker，但 WordSense Tag 仍在未来计划。

用户价值：可以给词义打上“技术哲学”“海德格尔”“论文高频”“容易混淆”等多个标签，再搜索或临时复习。

建议：优先级高。它与 LinguaCafe 的知识整理方向天然兼容。

#### F-A04 更强的统一搜索语言

Anki 的 Browser 和 Filtered Deck 共用搜索体系，可以按 deck、tag、note type、card state、flag、interval、lapses、stability、difficulty 和 retrievability 等组合搜索。

LinguaCafe 已有 Browser、筛选和 Saved Search，但搜索能力仍更像“页面筛选器集合”，还没有形成一套贯穿 Browser、Custom Study、统计和导出的统一查询语言。

建议：把现有筛选先收敛成共享 Search AST / Criteria，不必照抄 Anki 的全部语法。

#### F-A05 更完整的统计与未来负担预测

Anki 提供 Future Due、Calendar、Review Time、Intervals、Stability、Difficulty、Retrievability、Answer Buttons 和 True Retention 等图表。

LinguaCafe 已有今日总结、学习反馈、七日趋势和三十日视图，但整体统计广度、任意筛选范围和长期负担预测仍不足。

建议优先补：未来 7/30/90 天到期负担、真实保持率、最常失败词义、来源文章贡献和阅读转化率。

#### F-A06 标准 Anki 包导入导出

Anki 支持文本、`.apkg` 牌组包和 `.colpkg` collection 包，并可包含媒体和调度信息。

LinguaCafe 已确认首版 `.apkg`：一个 WordSense 一张固定模板卡，释义挖空，全部真实例句有序列出，每条例句翻译挖空；首版不含多媒体，也不承诺完整调度迁移。

已接受“把内容送去 Anki”的内容卡导出。surface/lemma 显示、例句上限、缺翻译、重复导出和是否携带 FSRS 状态仍需独立 Spec；完整双向调度同步仍是单独长期项目。

#### F-A07 文章健康检查

当前产品只接受文章范围，不考虑图片、音频或其他多媒体。健康检查聚焦文章、章节、分词状态、原文定位、孤立来源、结构损坏和修复前备份。

### 3.2 有价值，但要看 LinguaCafe 的产品方向

#### F-A08 完整 Filtered Deck / Custom Study

Anki 的 Filtered Deck 可以使用任意搜索条件，支持考前突击、按标签学习、复习未来卡、处理积压和按不同顺序学习。

用户已接受 Custom Study 继续向 Anki 靠拢。当前代码仍是 preview-only；正式评分、正常队列影响、同日重复和会话结束语义必须在后续讨论中冻结，冻结前不改现有 Harness。

#### F-A09 更完整的 Deck Options

Anki 的设置还包括学习/重学步骤、最大间隔、Easy Days、自动前进、计时器、音频、兄弟卡埋藏等。

LinguaCafe 已覆盖 desired retention、FSRS 参数、每日上限和队列顺序，但没有全部 Anki 设置。

用户已接受：与 sense-only FSRS 兼容的设置尽量学习 Anki；完全依赖 sibling cards、任意 deck 树或通用 Note Type 的设置放弃。

#### F-A10 更完整的 Browser

Anki Browser 可以在 Cards/Notes 两种模式间切换，配置列、批量改标签和牌组、查找替换、重复项检测和预览模板。

LinguaCafe Browser 已经适合管理 WordSense ReviewCard，但没有通用 Notes 模式、任意字段列和通用查找替换。

建议补齐与 WordSense 直接相关的能力，不要为了形式引入一个与产品模型不匹配的 Notes 模式。

### 3.3 不建议近期照搬：会把产品变成另一个 Anki

#### F-A11 任意 Note Type 与 Card Template 编辑器

Anki 允许用户定义任意字段，用 HTML/CSS 设计正反面，并从一条 Note 生成多种 Card，包括 Basic、反向卡、Cloze、输入答案和 Image Occlusion。

LinguaCafe 当前是明确的 WordSense → Sense ReviewCard。这种限制让系统知道什么是中文释义、词性、原句、来源和 lemma，也让 AI 和阅读页可以安全协作。

照搬模板系统会导致：

- 所有字段变成任意结构；
- 来源、词义和 AI 数据难以保证；
- 一条词义可能生成多张兄弟卡；
- 删除、归档、FSRS 和 Browser 复杂度大幅增加。

结论：除非产品决定转型为通用制卡平台，否则不建议建设。

#### F-A12 任意层级 Deck/Subdeck 树

Anki 使用 deck/subdeck 组织通用卡片，并允许不同子牌组绑定不同 Preset。

LinguaCafe 的天然边界是用户、语言、阅读材料、词义和动态搜索。强行加入任意牌组树会与文章来源、语言和 Saved Search 产生多套重叠分类。

可接受替代：语言 + Saved Search + WordSense Tag + 临时学习会话。

#### F-A13 受控插件接口

用户已确认插件接口是长期重要能力。LinguaCafe 应优先支持导入器、词典、AI Provider、导出器和只读分析插件，并采用版本化接口、权限声明、失败隔离和兼容提示。插件不能绕过评分、ReviewLog、权限、删除和密钥保护。

#### F-A14 Image Occlusion 与通用 Cloze

Anki 已内置图像遮挡和 Cloze，这对医学、地理和公式学习非常重要。

LinguaCafe 的核心用户目前是外语阅读和哲学文本。该能力不是无价值，但优先级低于同步、备份、标签、导出和统计。未来若扩展到 PPT、图表和哲学概念图，再单独设计更合理。

## 4. 架构差异：像卖产品一样解释

### 4.1 Anki：一座模块化的“记忆工厂”

Anki 的核心能力主要在 Rust 后端，按 card、notes、notetype、decks、deckconfig、revlog、scheduler、search、stats、sync、tags、undo、media、import/export 等领域分开。

前后端通过 Protobuf 契约沟通，并生成 Rust、Python 和 TypeScript 绑定。桌面 Qt/Python 层负责窗口和系统集成，部分页面使用 Web 技术，但核心规则不会散落在每个页面中。

这种架构适合：

- 多个平台共用同一套调度与数据规则；
- 高性能处理巨大 collection；
- 长期兼容复杂导入、同步和插件生态。

代价是代码量大、语言多、构建复杂，新功能需要同时考虑桌面、移动端、AnkiWeb、同步和兼容性。

### 4.2 LinguaCafe：一座“阅读到复习的一体化工作室”

LinguaCafe 是 Laravel + Vue 的 Web 单体，MariaDB 保存用户和学习数据，Python tokenizer 提供语言处理。

它没有通用 Note/Template 系统，而是明确使用：

- WordSense：一个具体词义；
- ReviewCard：一张正式调度卡；
- ReviewLog：一次真实评分；
- WordSenseOccurrence：原文证据；
- EncounteredWord：阅读颜色和词形总览。

这种架构适合：

- 从阅读页直接创建学习材料；
- 保证词义、来源和复习对象一一对应；
- 让 AI 推荐经过人工确认后进入稳定数据结构；
- 网页端快速迭代和多用户管理。

代价是 Reader、导入、tokenizer 和 Web 状态编排比普通卡片软件复杂；当前 142 个 Service 和 223 个路由也增加了查找成本。

## 5. 哪些差异可以优化

### O-A01 建立稳定的 API/Payload 契约层

Anki 使用 Protobuf 生成多语言绑定。LinguaCafe 不必照搬 Protobuf，但应减少页面直接拼 URL、猜字段和复制响应处理。

可行方向：

- 每个领域一个 API client；
- Request/Response DTO 或明确 schema；
- 共享错误码；
- TypeScript 类型生成或最小契约测试；
- Browser、Custom Study、统计共享查询模型。

### O-A02 把 142 个 Service 收进领域包

当前 Service 已经比原项目职责清楚，但目录过平。

建议按领域收拢：

- Reader / Import；
- Sense / Occurrence；
- Review / FSRS；
- Browser / Lifecycle；
- Settings / Preset；
- AI Study Card；
- User / Capability。

保留真正的副作用 owner，合并纯转发且没有独立契约的薄 Service。

### O-A03 统一 Search、Custom Study 和统计筛选

Anki 的 Browser、Filtered Deck 和部分 FSRS 范围共用搜索方式。LinguaCafe 当前多个模块各有筛选结构。

建议建立统一 Criteria / Search AST，让同一 Saved Search 可以用于：

- Browser；
- Custom Study；
- 导出；
- 统计范围；
- AI 批量候选预览。

### O-A04 建立统一 Capability/Readiness

Tokenizer、Jellyfin、Anki 和 WebSocket 不应靠请求失败判断是否启用。统一 capability snapshot 可以明显减少 500、控制台噪声和页面分支。

### O-A05 统一 Operation/Undo 边界

Anki 在核心操作层有集中 undo 模块。LinguaCafe 已有评分撤销、FSRS 重排撤销、阅读确认撤销等多个入口，但还缺少统一的“哪些动作可撤销、保留多久、如何显示”的操作目录。

不建议立刻重写所有撤销；先建立 operation registry 和共同响应格式。

### O-A06 让测试保护语义，不保护行数和文案历史

Anki 的成熟来自核心行为稳定，不是文件永远保持某个行数。LinguaCafe 应删除或改写锁定日期、精确行数、旧 Agent 名称和过期阶段状态的 guard，把测试预算留给评分、权限、导入、同步、删除和来源等承重行为。

## 6. 哪些复杂度不可避免

### U-A01 Reader 与 Tokenizer 的复杂度

Anki 的用户通常先准备好卡片；LinguaCafe 要从连续文本中识别词、lemma、句子、段落、来源和上下文。Tokenizer 健康检查、fallback 和结构恢复是产品特色带来的必要成本，不能简单删除，只能明确 owner 和 readiness。

### U-A02 WordSense 与来源证据

Anki 可以把字段当普通文本；LinguaCafe 必须知道一个释义对应哪一个词义、哪次阅读和哪句话。这会增加表、服务和验证，但也是产品价值本身。

### U-A03 Profile、远程访问与移动身份尚待选择

Anki 桌面端确实支持无密码 Profile 选择，每个 Profile 有独立 collection 和设置；但 AnkiDroid 不支持桌面式多 Profile，需要同步的 Profile 还要使用独立 AnkiWeb 账号。用户希望 LinguaCafe 复刻本地 Profile 并取消密码，但远程访问、手机端、同步和付费账号尚未决定，所以当前权限代码不能提前删除。

### U-A04 阅读中直接刷词义卡 V1（后续已由 PD-012 冻结）

本文撰写时该方案仍在讨论；2026-07-24 已由 PD-012 冻结为：先在浏览器内暂存评分，确认具体 WordSense 后再通过正式 Sense Review 路径一次性写入，不先污染 ReviewLog；取消或切换目标词时清空待确认评分。当前仍只允许 Architecture Spec、ADR 与 Harness 迁移设计，业务代码尚未授权，现有 Harness 暂不删除。

### U-A05 兼容迁移期

Legacy word review 与 sense-only 暂时并存是渐进迁移的代价。在产品批准清退前，不能为了减少代码先删除兼容测试。

## 7. 推荐产品优先级

### 第一组：先让用户放心使用

1. Tokenizer readiness 和导入前检查。
2. 自动备份、恢复和数据完整性诊断。
3. 可选集成 fail-soft，不再用 500 探测。
4. 标准内容导出与明确的 Anki 互操作方案。

### 第二组：让学习材料更好管理

5. WordSense Tag。
6. 统一搜索 Criteria。
7. 更完整的统计、未来负担和保持率。
8. 文章健康检查。

### 第三组：扩展学习方式

9. Saved Search → Custom Study。
10. 更多安全的临时学习条件。
11. 受控插件接口。
12. Browser V2 与 AI 重复词义文件闭环。

### 明确暂缓

- 任意 Note Type / Card Template；
- 任意层级 Deck/Subdeck；
- 通用模板和任意层级 Deck/Subdeck；
- 未经产品冻结就把阅读动作直接写成正式评分；
- 完整双向 Anki 调度同步。

## 8. 论坛信息如何使用

2025–2026 年论坛仍持续讨论同步失败、FSRS 模拟和参数、Browser 列、插件兼容、Image Occlusion、移动端交互和无障碍。这说明即使 Anki 已经非常成熟，这些功能也会长期产生边界问题。

因此：

- 官方手册定义当前产品语义；
- 官方仓库确认真实模块和接口；
- 论坛用于发现用户困惑、兼容问题和新需求；
- 论坛建议不能直接写成 LinguaCafe 产品决定。

## 9. 最终判断

LinguaCafe 当前不是“功能较少的 Anki”，而是“把阅读、词义和复习连成一条线的专业产品”。

最值得学习 Anki 的，是其稳定的调度、搜索、统计、备份、同步、导入导出、插件扩展思想和领域分层；不应照搬的是任意模板、无限牌组树和插件无边界修改内部数据。

下一阶段的最佳策略不是追求功能数量相等，而是补齐用户信任与管理能力，同时保护 LinguaCafe 的阅读优先和 sense-only 身份。
