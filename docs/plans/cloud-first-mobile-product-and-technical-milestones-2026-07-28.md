# LinguaCafe 云端主导移动化产品路线与技术里程碑

> 状态：Current / Strategic Planning Baseline
> 日期：2026-07-28
> 性质：用户要求存档的产品、架构与成本规划；不代表本文所列业务代码已经获得实现授权。
> 当前路线简称：云端主导、有限离线、网页完整管理、移动端日常学习。

## 1. 决策摘要

LinguaCafe 后续采用接近墨墨背单词等商业学习产品的混合路线，而不是完整复制 Anki 的本地优先架构：

1. Laravel 服务端和中央数据库继续作为账号、WordSense、ReviewCard、ReviewLog、FSRS、文章和 AI 数据的权威来源。
2. 网页端继续承担完整管理、文章导入、Browser、设置、统计、备份、批量治理和高级功能。
3. Android 与 iOS 主要承担阅读、查词、简单词义创建、日常复习、提醒和进度查看。
4. 移动端允许有限离线：下载文章包和短期复习包，离线操作进入本地队列，恢复网络后幂等提交。
5. 不建设 Anki 式每台设备完整权威数据库、任意时长完整离线、完整 collection 合并协议。
6. 继续保留和引入与 LinguaCafe 产品相容的 Anki 成熟能力；不因采用云端路线而删除 FSRS、ReviewLog、Browser、Card Info、标签、统计、Custom Study、撤销或导出。
7. 不进入主线的仍是任意 Note Type、任意 Card Template、任意 Deck/Subdeck 树、sibling cards、通用 Image Occlusion 与通用 Cloze 平台。

## 2. 可继承的当前资产

以下能力直接保留，不因移动化重写：

- WordSense 作为具体词义内容对象；
- `ReviewCard.target_type=sense` 作为正式调度对象；
- ReviewLog 作为真实评分事实；
- WordSenseOccurrence 作为原文与例句证据；
- EncounteredWord 作为阅读颜色与词形总览；
- FSRS、四档评分、每日上限、Preset、队列顺序；
- Reader、Reviewer、Browser、Card Info、Card Marker；
- 多例句池、原文定位、lemma 与熟词僻义前置结构；
- AI Study Card 人工确认链；
- Laravel、MariaDB、Python tokenizer 与当前 Vue 页面的大部分领域逻辑。

移动化主要新增稳定 API、设备身份、幂等写入、触摸交互、下载包、离线操作队列和冲突处理，不重建第二套学习模型。

## 3. Anki 能力保留边界

按当前产品相关的 23 个功能族估算：

- 14 类可以直接保留或新增：FSRS、四档评分、学习/重学步骤、ReviewLog、Card Info、Browser、Saved Search、Card Marker、WordSense Tag、Leech/暂停/归档/恢复、Preset/上限/队列、Statistics、自动备份恢复、`.apkg` 导出。
- 5 类保留但改变实现：撤回/重做、Custom Study、离线复习、跨设备同步、受控扩展能力。
- 4 类不进入主线：任意 Note Type、任意 Card Template、任意 Deck/Subdeck 与 sibling cards、通用 Image Occlusion/Cloze 平台。

因此，用户可感知的 Anki 成熟能力大约有 19/23 类可以继续存在，约为 83%。这个比例用于产品范围判断，不是 Anki 官方功能统计。

在 19 个可保留功能族内部，仍有一批当前代码尚未完整实现的子能力，可以继续吸收：

- “今日忘记”“提前复习”“积压处理”“按标签/状态专项学习”等 Filtered Deck / Custom Study 场景；
- 手动搁置到明天、暂停、恢复、设置到期日、重置为新卡和调度变更记录；
- Easy Days、自动推进、答题计时、最大间隔、学习/重学步骤和工作量模拟；
- Future Due、日历热力图、复习时间、间隔、稳定性、难度、可提取性、按钮分布和真实保持率；
- Browser 自定义列、批量标签、查找替换、重复词义检测、卡片预览和删除恢复；
- 固定模板 `.apkg`、CSV/JSON、LinguaCafe 完整数据包和受控导入更新；
- 音频、发音和媒体完整性检查，但必须晚于云存储与移动缓存基础。

这些能力不要求等待 iOS/Android 全部完成，但必须遵守 M1、M2、M6、M10 等前置依赖，不能直接把高风险批量操作堆到现有页面中。

## 4. 成本规划快照

### 4.1 最低测试路线

- 目标：个人与少量测试用户，联网版移动壳，有限云服务。
- 一次性现金：约 0.7 万—3 万元。
- 云服务：约 150—500 元/月。
- 年度持续现金：约 0.3 万—0.9 万元。
- 风险：单机服务、数据库与任务进程耦合，不适合作为稳定商业环境。

### 4.2 自己 + AI 的认真开发路线

- 一次性现金：约 8 万—25 万元。
- 云服务：约 1000—4000 元/月。
- 年度持续现金：约 5 万—20 万元。
- 主要代价转化为产品设计、测试、移动端验收和运维时间。

### 4.3 稳定推荐路线

- 目标：约 2000—10000 月活，网页、Android、iOS、有限离线、独立数据库、自动备份和基础高可用。
- 一次性专业开发投入：约 40 万—120 万元；自己 + AI 可降低现金但不能免除验收与运维工作。
- 云服务：约 3000—8000 元/月。
- 年度持续成本：约 15 万—50 万元。

### 4.4 较高规模商业路线

- 目标：约 5 万—20 万月活，高可用数据库、扩容、安全、客服和完整监控。
- 一次性开发：约 150 万—500 万元。
- 云服务：约 3 万—10 万元/月。
- 年度总运营：约 150 万—600 万元以上。

上述范围不含广告投放、版权词典/教材、公司人员工资、税务、法律长期服务和应用商店交易抽成。成本必须在真实用户量、文章体积、AI token、CDN 流量和短信量出现后重新核算。

## 5. 当前环境能完成与不能完成的边界

当前 Windows + Laravel + Vue + MariaDB + Python 环境可以完成：

- API 版本与稳定契约；
- 移动设备身份和令牌后端；
- 正式写操作幂等；
- operation/undo 目录与账本基础；
- 文章包、复习包和同步 payload；
- 服务端冲突策略与模拟测试；
- 窄屏网页、触摸策略、Bottom Sheet 和 PWA/Capacitor 前端准备；
- 自动备份恢复、文章健康、用户隔离和安全审计；
- Android 联网壳的部分工作（需要安装相应工具链后）。

当前环境不能完成最终 iOS 真机签名、App Store 发布和完整 iPhone 验收；这些阶段需要 macOS、Xcode、Apple 开发者账号和真机/模拟器环境。

## 6. 技术里程碑总表

### M0 — 路线存档与计划收口

状态：本文件创建后完成文档存档；不改业务代码。

交付：

- 云端权威、有限离线、网页完整管理、移动端日常学习的路线成为当前规划基线；
- 总计划、产品决定和文档索引引用本文件；
- 旧“Mobile / BottomSheet 仅待讨论”状态被新的里程碑替代；
- 完整本地优先、无密码 Profile 主线和开放插件市场不再被误认为当前必做目标。

### M1 — Mobile API Foundation 与正式评分幂等

优先级：P0。当前环境可完成。下一项技术开发推荐。

状态：`Accepted / Closed`（2026-07-29）。Mobile API、幂等、契约和自动化证据见
`docs/testing/mobile-api-foundation-acceptance-2026-07-28.md`；M5 的 testing
server-bound 真实 `/reviews/senses` 页面只产生一次 POST、一次 ReviewLog 和一次
ReviewCard 调度更新，补齐原 deferred Web UI seam，证据见
`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md`。

目标：在不破坏现有网页接口的前提下，建立移动客户端能够长期依赖的版本化 API 和一次评分只生效一次的服务器保证。

范围：

1. 建立 additive 的 `/api/v1/mobile` 路由边界，不替换现有网页 endpoint。
2. 建立统一成功/失败 envelope、错误码、server time、schema version 和最低客户端版本字段。
3. 新增 authenticated capabilities/bootstrap endpoint，返回当前用户、当前语言、服务能力、版本与只读 readiness。
4. 建立移动设备记录：设备标识、平台、App 版本、最后活动、撤销状态；不保存明文密码或外部密钥。
5. 基于现有 Sanctum 能力建立移动令牌的最小安全入口，并支持撤销设备。
6. 建立 `client_action_id` 幂等存储与 service；同一用户/设备/动作/请求体重复提交返回原结果，不产生第二次副作用。
7. 同一个 `client_action_id` 携带不同请求体时返回明确冲突，不静默覆盖。
8. 将正式 Sense Review 评分作为第一条接入路径：一次点击最多新增一条 ReviewLog、只更新目标 ReviewCard 一次。
9. 成功响应返回稳定 `operation_id`、`client_action_id`、ReviewLog 标识和最新卡片摘要，为后续撤回/同步提供锚点。
10. 现有网页评分不传移动字段时保持原行为与 payload 兼容。
11. 覆盖用户/语言/设备隔离、重复请求、并发重试、冲突请求、撤销设备、事务失败回滚和无重复 ReviewLog 测试。
12. 更新 API 契约、ADR、移动路线和测试说明；真实页面验收证明现有网页评分没有回归。

禁止：

- 不实现离线复习队列；
- 不实现 Android/iOS UI；
- 不改 FSRS 算法；
- 不修改 WordSense/ReviewCard/ReviewLog 的既有产品语义；
- 不读取或修改 `.env`；
- 不删除旧接口；
- M1 未逐项验收时不得宣称完成；明确持续目标只有满足 ADR-0034 的 deferred-acceptance 依赖证明时，才可开始不触及同一未验收 seam 的 M2 切片。

### M2 — Operation Ledger 与统一撤回基础

优先级：P0/P1。当前环境可完成；通常在 M1 验收后进入。M1 处于
`Acceptance Deferred — Not Complete` 时，只能按 ADR-0034 的依赖证明例外进入。

状态：`Accepted / Closed`。M2 Architecture Gate 已证明该切片只依赖已验证的
Mobile API/幂等契约且不触及 M1 deferred Web UI seam；实现、契约、隔离、
LIFO undo/redo、冲突、旧 Web 兼容和受保护回归均已通过。证据见
`docs/testing/mobile-operation-ledger-acceptance-2026-07-28.md`。

目标：将评分和未来移动操作纳入统一服务器操作账本，逐步替代零散的短时 token 撤回。

范围：

- operation 主记录、变化记录、状态、设备和会话来源；
- 最近操作查询；
- LIFO 撤回与 redo 契约；
- first adopter 只迁移正式评分；
- 保留旧评分撤回兼容入口；
- 当前状态/version 冲突时拒绝覆盖；
- 页面刷新后当前账号可继续看到本会话最近操作；
- 不一次迁移所有删除、导入和批量操作。

### M3 — 文章下载包与短期复习包 V1

优先级：P1。当前环境可完成。

状态：`Accepted / Closed`。文章 manifest/章节分页、token/sentence/section
identity、翻译/词义摘要、内容校验与失效规则，以及带用户/语言绑定游标的短期
复习只读包均已通过隔离、零写入、5,000 token/250 卡规模和受保护回归。
证据见 `docs/testing/m3-mobile-download-packages-acceptance-2026-07-29.md`。

目标：先在服务器和网页环境形成可验证的移动数据包，不立即实现手机本地数据库。

范围：

- article package manifest、章节、处理后文本、token/sentence/section identity、翻译、词义摘要、内容版本与校验值；
- review package 包含短期 eligible cards、显示内容、当前调度快照、包版本和生成时间；
- payload 限制、分页/分片、用户/语言隔离；
- 只读生成，不在本阶段接受离线评分回传；
- 文章变化后的版本失效和重新下载规则；
- 大文章和大量到期卡性能测试。

### M4 — 同步操作队列与冲突策略模拟器

优先级：P1。当前环境可完成。

状态：`Accepted / Closed`（2026-07-29）。服务端排队动作上传、独立幂等、
确定性顺序、部分成功、正式评分时间顺序、WordSense 乐观冲突、退避字段和
真实网页模拟器均已关闭。证据见
`docs/testing/m4-queued-action-sync-acceptance-2026-07-29.md`。

目标：在没有原生 App 的情况下，通过后端测试和网页测试客户端验证离线操作上传语义。

范围：

- 批量上传 queued actions；
- 每个动作独立幂等；
- 全批次与部分成功的清晰结果；
- 同卡连续评分时间顺序；
- 不同卡自动合并；
- stale WordSense 编辑、删除/编辑交叉和已撤销设备的拒绝策略；
- retry/backoff 所需字段；
- 网络中断、重复批次、乱序和跨设备并发测试。

### M5 — Mobile Reader / Reviewer 触摸适配 V1

优先级：P1。当前环境可完成网页与跨平台前端准备。

状态：`Accepted / Closed`（2026-07-29）。触摸点词/滚动/长按拖选、无 hover、
Bottom Sheet Back 与键盘边界、360/390/430px 和平板布局、正式复习评分以及
testing 数据清理均已验收。证据见
`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md`。

范围：

- 移动 Reader Bottom Sheet；
- 点按、长按、拖选词组、返回键和键盘避让；
- 手机无 hover 的译文显示规则；
- 移动正式复习、撤回、More 菜单和原文查看；
- AI Study Card desktop feature island 的移动入口边界；
- 360/390/430px 与平板 viewport；
- 真实浏览器触摸/窄屏验收；
- 不在本阶段上架应用商店。

### M6 — 自动备份恢复、文章健康与多用户安全

优先级：P0/P1。当前环境可完成；最迟在外部测试用户进入前关闭。

状态：`Accepted / Closed — M6A–M6D Accepted / Closed`。ADR-0036
已将宽泛范围冻结为 M6A 安全备份、M6B 恢复安全、M6C 文章健康和 M6D
隔离审计四个有序切片。M6A 安全备份证据见
`docs/testing/m6a-safe-backup-acceptance-2026-07-28.md`；M6B 的预览、
隔离验证、写栅栏、安全快照、恢复/回滚编排和真实管理页确认门也已关闭，
证据见 `docs/testing/m6b-restore-safety-acceptance-2026-07-28.md`；M6C 的
只读文章健康报告、隔离/无写入测试、前端状态和真实页面验收已经关闭，证据见
`docs/testing/m6c-article-health-acceptance-2026-07-28.md`；M6D 的文件路径、
user/language、队列和导出隔离审计及真实页面验收也已关闭，证据见
`docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md`。M6 整体完成，
M10 统一查询、标签、Browser 与移动只读搜索基础也已完成；M11、M12、M3、
M4、M5 均已关闭，且 M5 真实评分验收已清零 M1 deferred seam。当前按推荐
依赖顺序进入 M7 Architecture Gate。

范围：

- 自动备份、恢复预览、恢复前快照、失败回滚；
- tokenizer/readiness、文章结构、不可学习片段、来源和孤立记录健康检查；
- 全仓 user/language ownership 审计；
- 文件访问、导出、任务队列、缓存键和临时文件隔离；
- 可选集成 fail-soft；
- 不在本阶段建设完整云存储商业配额。

### M7 — Android 联网 MVP

优先级：P2。需要补充 Android/Capacitor 工具链。

状态：`Accepted / Closed`。Android 12 模拟器已完成 server-bound testing 登录、
阅读/查词/创建词义、评分/撤回、摘要、Keystore 密文、本地通知与官方 Haptics
调用验收；APK、数据、端口和模拟器均已清理。证据见
`docs/testing/m7-android-connected-mvp-acceptance-2026-08-01.md`。2026-08-06
发布到仓库的原生工程又通过 29 项移动测试、生产构建、离线 Gradle 测试与 debug
APK 构建；当时没有连接设备，因此最新 APK 只算本地构建复验，不替代也不扩张
2026-08-01 的模拟器验收范围。

范围：

- 登录与设备令牌；
- 在线阅读、查词、简单创建词义；
- 正式复习、撤回和统计摘要；
- 本地提醒；
- 不承诺完整离线；
- 真机与模拟器验收。

### M8 — 有限离线 MVP

优先级：P2。M3、M4、M7 关闭后进入。

状态：`Accepted / Closed`。IndexedDB 用户/语言隔离缓存、持久评分队列、M4 同步
结果收敛、可见冲突/重试状态、真实断服/恢复浏览器验收和 Android 构建均已完成；
Android 12 模拟器还验证了缓存内容、离线音频、杀进程后的 scope/queue 恢复、UI
同步和数据库一动作一 ReviewLog。证据见
`docs/testing/m8-limited-offline-mvp-acceptance-2026-08-01.md`。

范围：

- 手机本地文章包和复习包；
- 操作队列；
- 联网自动同步；
- 同步状态、失败重试和冲突提示；
- 首版仅保证短期离线，不支持完整本地权威数据库。

### M9 — iOS MVP 与商店发布

优先级：P2/P3。需要 macOS/Xcode/Apple 账号。

状态：`Implementation Accepted / Acceptance Deferred — Not Complete (iOS Capability Cluster)`。
官方 Capacitor iOS 工程、Keychain token、真实平台身份、UTF-8 `.txt` 系统文件选择与
幂等服务端导入、隐私清单、退出时本地数据清理和商店材料已经实现并通过 Windows
可执行验证；22 个正式 iOS source/config 文件已在 `4be6c39` 入库。当前 ignored
iOS Web bundle 仍是旧版本并含 sourcemap，必须在授权 macOS/Xcode 环境先受控执行
`cap sync ios` 并核对资源一致性；Xcode 编译、签名、iOS 真机/模拟器、TestFlight 与
App Store 审核仍须在 Apple 工具链能力簇取得真实证据。见 ADR-0054、M9 plan 与
acceptance report。

范围：

- iOS 构建、签名、权限、文件导入、通知和安全区域；
- 真机验收；
- 隐私、数据删除、商店材料与审核；
- 与 Android/网页共用服务端契约。

### M10 — Unified Search Criteria + WordSense Tag + Browser V2 Foundation

优先级：P1。当前环境可完成；M1 验收后可进入，不要求先完成原生 App。

状态：`Accepted / Closed`。ADR-0038 与四个有序切片均已完成；共享 Criteria、
WordSense Tag、Browser/Saved Search/Study Overview/export parity、移动只读搜索及
真实页面验收证据见
`docs/testing/m10-unified-search-tags-browser-foundation-acceptance-2026-07-28.md`。
M1 缺失的页面证据不参与本里程碑的数据、查询或验收 seam。

目标：建立 Browser、Saved Search、Custom Study、Statistics、Export 和移动搜索共用的查询与知识组织基础。

范围：

- 统一 user/language ownership、搜索条件名称、组合规则、排序和分页；
- 建立共享 Criteria / Search AST 或等价领域模型，不要求一次复制 Anki 全部搜索语法；
- WordSense 多标签、创建/重命名/删除/批量添加/移除；
- Card Marker 继续保持单卡关注标记，不与 WordSense Tag 合并；
- Browser 支持可配置常用列、Saved Search 与查询链接；
- 让同一个 Saved Search 可被统计、导出和专项学习复用；
- 移动 API 只读搜索契约与分页上限；
- 不引入任意 Deck/Subdeck、Note Type 或 sibling cards。

### M11 — Review Control 与手动调度操作 V1

优先级：P1。必须在 M2 Operation Ledger 验收后进入。

状态：`Accepted / Closed`（2026-07-29）。M11A–M11D 已完成，证据见
`docs/testing/m11-review-control-manual-scheduling-acceptance-2026-07-29.md`。
M2 operation ledger、复合快照、Web/Mobile adapter、Browser/Reviewer/Card
Info 与真实页面 preview/apply/history/undo/redo 均已关闭。

目标：吸收 Anki 中用户真正需要的手动学习治理能力，并让所有变化可审计、可撤回。

范围：

- 手动搁置当前卡到下一个学习日；
- 暂停/恢复正式复习；
- 设置到期日与“立即到期”的统一预览和确认；
- 重置为新卡、是否重置 reps/lapses 的明确选项；
- 保留 ReviewLog 历史，不把“重置调度”误写成“删除历史”；
- Card Info 显示 Manual operation、operation_id、前后状态与来源设备；
- 所有操作接入 operation ledger、幂等、用户/语言隔离和 LIFO undo；
- 不实现 sibling bury；不允许客户端直接写 due/stability/difficulty。

### M12 — Special Study Sessions / Custom Study V2

状态：**Accepted / Closed（2026-07-29）**。实现与验收见 ADR-0040、
`docs/plans/m12-special-study-sessions-plan.md` 和
`docs/testing/m12-special-study-sessions-acceptance-2026-07-29.md`。

优先级：P1。依赖 M10 统一查询与 M11/M2 正式操作边界。

目标：将当前 preview-only Custom Study 升级为 LinguaCafe 专项学习，而不是复制完整 Anki Filtered Deck 的 deck 移动语义。

首批场景：

- 今日回答 Again 的词义；
- 逾期和积压词义；
- 提前复习未来若干天卡片；
- 预览最近新建词义，不正式评分；
- 按 WordSense Tag、Card Marker、文章来源、章节、生命周期和 FSRS 状态学习；
- 临时提高今日新卡或复习上限；
- 按最逾期、最多 lapses、最低 retrievability、随机或文章来源排序；
- 保存、重建和结束会话。

必须分别冻结 preview-only、正式评分和提前复习对正常队列的影响；会话不得改变卡片长期归属，也不建设任意牌组树。

### M13 — Review Settings 与 Workload Planner V2

优先级：P1/P2。设置读取可在 M1 后规划；会改变旧卡调度的操作必须依赖 M2 与 M6 备份。

状态：`Accepted / Closed`（2026-07-29）。Preset V2、唯一 FSRS 调度入口、
自动推进/计时边界、30/90/365 天只读负担模拟和旧卡重排保护均已验收；证据见
`docs/testing/m13-review-settings-workload-planner-acceptance-2026-07-29.md`。

范围：

- 学习步骤和重学步骤；
- 最大间隔与必要的最小间隔；
- Easy Days：按星期轻微转移未来到期负担，不追溯修改既有卡；
- 新卡/复习顺序的普通用户友好选项；
- 问题面/答案面计时器与自动推进设置；
- 音频自动播放和重复播放偏好；
- 基于真实 stability、difficulty、retrievability、上限和 desired retention 的 30/90/365 天工作量模拟；
- FSRS 参数优化健康检查和错误用法提示；
- 参数变化后的重排必须先预览、建立备份、明确风险并可撤回。

不暴露任意 custom scheduling，不实现 sibling bury 或 deck 继承设置。

### M14 — Statistics 与 Card Info V3

优先级：P1/P2。依赖稳定 ReviewLog 与 M10 统一查询。

状态：`Accepted / Closed`（2026-07-29）。服务端统一指标、M10 查询范围、
响应式摘要、Card Info V3 和 CSV/PDF 输出均已验收；证据见
`docs/testing/m14-statistics-card-info-v3-acceptance-2026-07-29.md`。

范围：

- Future Due 7/30/90/365 天预测；
- 学习日历与热力图；
- 新学、学习中、复习、重学和专项学习数量；
- 复习时间与平均答题时间；
- 间隔分布；
- stability、difficulty、retrievability 分布；
- Again/Hard/Good/Easy 使用分布；
- True Retention 类指标；
- 最常失败、最难和最不稳定的 WordSense；
- 文章来源、阅读量、生词、确认词义和制卡转化；
- 按统一查询条件改变统计范围；
- 网页完整图表、移动端摘要卡片和 CSV/PDF 导出。

指标必须在服务端统一定义，禁止多个页面分别计算同名指标。

### M15 — Browser Knowledge Hygiene V3

优先级：P2。依赖 M10、M2 与 M6 备份恢复。

状态：`Accepted / Closed`（2026-07-29）。列/视图偏好、预览式查找替换、
确定性重复项、自动备份合并、冲突安全 undo 与 30 天最近删除均已通过自动化、
构建和 server-bound 真实页面验收。证据见
`docs/testing/m15-browser-knowledge-hygiene-v3-acceptance-2026-07-29.md`。

目标：吸收 Anki Browser 的管理效率，但将危险批量操作改造成符合 WordSense 的安全流程。

范围：

- 自定义列、列顺序、排序和保存视图；
- WordSense 与 ReviewCard 详情预览；
- 批量标签、Marker、生命周期和安全调度操作；
- 查找替换必须先限定字段、预览差异、显示影响数量并支持撤回；
- 重复词义检测：完全重复、近义但缺少区分、可能合并、应保留；
- AI 只能输出建议，正式合并必须人工确认；
- 合并前自动备份、来源/ReviewLog/ReviewCard 影响预览和 operation undo；
- 删除日志、最近删除查看和受控恢复；
- 不提供任意字段系统或 Note Type 转换。

### M16 — Portable Data 与 Anki Interoperability V1

优先级：P2。依赖 M6 数据安全、M10 标签/查询与稳定 schema。

状态：Accepted / Closed（2026-07-29）。固定 `.apkg`、JSON/CSV、全量
`.lcpkg`、受控回导、恢复点、真实浏览器与清理审计均已验收；证据见 M16
acceptance report。

范围：

- 固定 LinguaCafe 模板的 `.apkg` 导出；
- 一个 WordSense 对应一张 sense card；
- surface/lemma、中文释义、真实例句、翻译和来源字段的冻结映射；
- 是否包含调度信息由用户显式选择，默认内容导出不携带学习历史；
- CSV/JSON 的内容导出和受控回导；
- LinguaCafe 全量数据包：数据库逻辑数据、文章、结构、设置、标签与校验清单；
- 重复导入的更新、跳过、冲突和预览规则；
- 只支持可明确映射的 Anki 包导入，不承诺任意 Note Type/Card Template 完整转换；
- 导入前健康检查、恢复点和失败整体回滚。

### M17 — Review Experience、自动推进与无障碍 V2

优先级：P2。网页部分依赖 M13；移动体验依赖 M5/M7。

状态：`Accepted / Web and Android Slices Closed`。Web 自动化、构建、
server-bound 真实页面、390px 触控，以及 Android 官方 Haptics impact callback 与
Local Notifications RTC alarm 均已验收并清理；iOS 平台体验仍随 M9。证据见 M17
acceptance report。

范围：

- 自动推进问题面与答案面；
- 屏幕计时器、单卡答题时间和会话时间；
- Previous Card Info 与最近操作入口；
- 复习中快速 Marker、Tag、暂停、搁置和查看原文；
- 键盘、触摸、读屏和焦点顺序一致；
- 字号、对比度、减少动画和单手操作；
- Android/iOS 触觉反馈和本地提醒只在原生环境实现；
- 不改变 FSRS 评分含义，不用计时器自动替用户选择评分。

### M18 — Media、发音与离线音频完整性 V1

优先级：P3。依赖 M6 文件安全、云存储规划和 M7/M9 移动缓存能力。

状态：`Accepted / Implementation, Web and Android Slices Closed`。
testing-bound 官方浏览器已完成真实上传、播放、移除、Check Media、导出开关与
390px 验收；Android 12 模拟器已证明在线下载、IndexedDB 命中、断服后的零网络
音频播放和 AudioFocus 获取/释放。iOS 平台证据仍随 M9。

范围：

- WordSense/例句的发音、TTS 或用户音频附件；
- 服务端 media manifest、内容哈希、引用关系和用户隔离；
- 按需下载、移动缓存、缓存淘汰和离线播放；
- Check Media：缺失、孤立、重复和不兼容格式检查；
- 备份、恢复和导出时的媒体包含选项；
- 存储配额、版权来源和删除保留策略；
- 首版优先 MP3/M4A 等跨平台稳定格式，不建设通用视频学习平台。

## 7. 依赖关系与推荐穿插顺序

这些 Anki 能力不需要等 M9 完成后才开始，也不能全部提前阻塞移动端。建议按以下门禁穿插：

1. **共同地基**：M1 → M2。
2. **外部用户安全门**：M6 必须在任何公开测试前完成。
3. **知识管理地基**：M10 在 M1 后可开始；M12、M14、M15、M16 都依赖它。
4. **高风险学习操作**：M11 依赖 M2；M12 的正式评分模式依赖 M11。
5. **移动数据链**：M3 → M4 → M5 → M7 → M8；不必等待全部 Anki 扩展完成。
6. **高级学习能力**：M13、M14 可在 M10/M2 后安排；M15、M16 必须晚于 M6。
7. **端侧体验**：M17 在网页端先做，原生部分随 M7/M9；M18 最后进入。

推荐的大阶段顺序是：

`M1 → M2 → M6 → M10 → M11 → M12 → M3 → M4 → M5 → M7 → M8 → M13 → M14 → M15 → M16 → M9 → M17 → M18`

这不是强制一次做完整条链。普通任务仍在单项完成后停止；明确持续目标按
ADR-0031/ADR-0034/ADR-0037 的依赖图、逐项审计和 deferred-acceptance 限制自动选择
下一切片，不再为已委托的可逆选择重复等待用户。

## 8. 首个技术基线与持续目标推进

没有明确持续目标时先执行 M1，不并行进入 M2—M18。当前 M0–M18 持续目标
按 §7 推荐顺序推进；若 M1 仅剩 ADR-0034 定义的 deferred evidence，可在
依赖证明后进入 M2，但不得把 M1 写成完成。

理由：

1. 稳定 API、设备身份和幂等评分是所有移动端、弱网、离线队列和跨设备同步的共同地基。
2. M1 可以在当前 Windows、Laravel、Vue、MariaDB 环境中完整开发和测试。
3. 它不要求先安装 Android Studio 或购买 Mac。
4. 它能立即提高现有网页评分对重复请求和网络异常的安全性，不是只为未来写空架构。
5. 若 M1 契约设计错误，越早发现成本越低；如果先做移动 UI，后续会被迫重写请求层。

## 9. 阶段推进规则

- 每个里程碑独立验收、独立报告。普通任务完成后停止；明确持续目标按 ADR-0031 完成逐项审计后自动进入下一个满足依赖的命名里程碑。
- 涉及页面必须真实浏览器验收，API 200 或代码审查不能替代页面行为。真实浏览器按 ADR-0033 的结果标准判断，不绑定单一工具；一个通道拒绝 localhost 时自动降级到下一已授权浏览器通道。
- 本地任务所需且可回滚的评分、Marker、Tag、临时词义、归档/恢复等页面动作已预授权给执行 Agent，但写入前必须证明验收服务器连接专用 testing 数据库。全部平台允许通道失败或出现验证码、双重验证、系统级确认时，持续目标先记录 deferred 并继续独立切片；只有没有其他可运行切片或最终完成审计需要该动作时才请求用户。
- system、developer 或工具若明确禁止同一结果的 alternate surface/workaround，不得用本条绕过；按 ADR-0034 记录平台安全拒绝。
- `Acceptance Deferred — Not Complete` 不是完成。缺失证据按 ADR-0034/ADR-0037
  归入具名能力簇；只允许 Architecture Gate 已证明不依赖该能力簇缺失行为的下游
  切片继续。同一能力簇内可以累计由同一外部能力恢复后共同验证的检查，不把检查
  数量本身当作阻断；跨能力簇依赖必须逐项证明，最终完成审计必须清零全部具名检查。
- 涉及 ReviewLog、FSRS、删除、恢复、备份和同步，必须有正常路径与拒绝路径测试。
- 批量替换、重复词义合并、调度重排、导入和恢复必须先有预览、备份与拒绝路径。
- 新增 Anki 能力时先映射到 WordSense/ReviewCard/ReviewLog，不得为了表面一致引入通用 Note/Deck 模型。
- 本文档中的成本、规模和阶段顺序是规划基线，不是融资承诺或准确报价。
- 路线变化时更新本文和 Open Work Registry，不把旧讨论继续冒充当前计划。

## 10. Anki 官方参考基线

本扩展轨道依据 Anki 官方手册中的 Deck Options、Filtered Decks、Browsing、Statistics、Studying、Backups、Exporting、Media 与 Syncing。官方行为只作为成熟产品参考；LinguaCafe 继续以阅读优先、sense-only、云端权威和有限离线为产品边界。

- <https://docs.ankiweb.net/deck-options.html>
- <https://docs.ankiweb.net/filtered-decks.html>
- <https://docs.ankiweb.net/browsing.html>
- <https://docs.ankiweb.net/stats.html>
- <https://docs.ankiweb.net/studying.html>
- <https://docs.ankiweb.net/backups.html>
- <https://docs.ankiweb.net/exporting.html>
- <https://docs.ankiweb.net/media.html>
- <https://docs.ankiweb.net/syncing.html>
