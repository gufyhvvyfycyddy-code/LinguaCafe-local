# LinguaCafe Goal Mode 全里程碑执行控制面（Codex Sol Medium）

> 日期：2026-08-09
> 适用模型：Codex / GPT-5.6 Sol，reasoning medium
> 目标：从当前代码与 PAB-R3 收尾状态出发，连续完成 LinguaCafe 当前精简产品总计划 Phase A → H 的全部实现、迁移、测试、真实浏览器/设备验收与最终发布门禁。
> 当前用户授权：本持续目标可以在一个 Phase Gate 真正通过后自动进入下一个 Phase，不再要求每个 Phase 人工确认。真实用户/非 testing 数据破坏、秘密/外发/付费、部署/签名/商店提交、不可逆第三方动作，以及最高权威内不可消解冲突仍是硬停止线。

---

## 0. 这份文件是什么

这是 Goal Mode 的持久执行控制面，不是需求愿望清单，也不是一次性报告。

Codex 每次启动、恢复、压缩上下文后都先读本文件，只处理第一个依赖已满足且未完成的 milestone。完成 milestone 后更新本文件的状态和证据摘要，再继续下一个。

本文件只保存：

- 当前权威与产品终局；
- milestone 顺序与依赖；
- 每个 milestone 的完成判据；
- 当前 checkpoint；
- deferred capability clusters；
- 精简的进度/决策记录。

不要把大段执行日志、命令输出、历史聊天或全部 ADR 复制进来。详细证据放既有 testing/audit/report 文档，本文只链接或记录路径 + 结论。

---

## 1. 权威与开始顺序

每次 Goal 恢复时按以下顺序加载，禁止默认读取全部历史：

1. `AGENTS.md`
2. 本文件
3. `docs/plans/linguacafe-slim-product-master-plan-2026-08-07.md`
4. `docs/DOCUMENTATION_INDEX.md`
5. 当前 ACTIVE milestone 直接相关的 ADR / contract / tests / implementation
6. 必要时读取一个现有成功范例
7. 历史报告只作为事实证据，不作为当前命令

当前用户明确要求“完成整个 Phase A→H 大计划”，因此这份持续目标对旧文档中“每个大阶段必须人工确认后才继续”的规则构成本次执行的更高优先级特例：

- Phase 内：自动连续推进 milestone；
- Phase Gate 通过：自动进入下一 Phase；
- Phase Gate 未通过：修当前 Phase，不跳过；
- 真硬停止线：只暂停受影响分支；还有独立 milestone 时继续；
- 只有所有剩余工作都依赖硬停止线时才请求用户。

---

## 2. Private House Code：本 Goal 的默认工程方法

全局 Skill：`$private-house-code`

路径：`C:\Users\Administrator\.codex\skills\private-house-code\SKILL.md`

每个编码、调试、测试、重构、审查 milestone 都必须应用它。

### 2.1 默认选择梯

1. 先复用当前项目已有实现；
2. 再用标准库 / 框架原生能力；
3. 再用项目已经安装且正在使用的依赖；
4. 再写最小直接实现；
5. 只有当前需求、已有契约、已观察失败或可信现实风险真正需要时，才增加第二路径、状态、worker、锁、retry、fallback、cache、adapter 等机制。

### 2.2 不允许用“安全感”购买复杂度

普通 milestone 默认禁止为了“以后可能”新增：

- 第二事实源；
- 双读/双写；
- 多套 parser/runtime；
- blanket retry/backoff；
- recovery worker/watchdog；
- 无当前调用方的 interface/manager/factory/registry；
- 为单个页面新增通用框架；
- 重复 hash/checklist/全仓扫描；
- 与当前行为无关的顺手重构。

### 2.3 已经付过费的复杂度必须保留

以下是 LinguaCafe 的真实边界，不能因为 Private House Code 而删弱：

- 正式 ReviewLog / FSRS 唯一写入入口；
- reading-session / Finish 幂等；
- `reading_action_id` 幂等与 undo/rerate 语义；
- 实际数据库事务与真实并发；
- testing DB machine-global lease；
- 用户/语言隔离；
- 认证与授权；
- migration / backup / restore / delete 的不可逆边界；
- 发布兼容、移动同步、离线 operation ledger；
- 真正存在的网络/外部进程 cancellation 与 cleanup；
- 隐私、账号删除、内容版权和发布平台要求。

原则：保护真实金库，不给每个抽屉装防爆门。

---

## 3. Goal 状态机

只使用 5 个状态：

- `TODO`：依赖尚未完成或尚未开始；
- `ACTIVE`：当前唯一主 milestone；
- `DONE`：全部 Exit Gate 有真实证据；
- `DEFERRED`：实现/自动测试已通过，但特定设备/外部能力证据暂时不可取得；绝不等于完成；
- `BLOCKED`：当前 milestone 本身依赖硬停止线或最高权威冲突，不能继续。

同一时刻原则上只有一个 `ACTIVE` milestone。若当前 milestone 因外部 capability 进入 `DEFERRED`，可选择下一个明确不依赖该缺失行为的 milestone。

不得发明更多 lifecycle 状态。

---

## 4. 每个 milestone 固定执行循环

Sol Medium 每次只完成一个 milestone 的完整闭环，不一次吞掉整个 Phase。

### 4.1 Entry Gate

1. `git fetch origin --prune`；
2. 记录 `origin/master`、当前 branch、HEAD、worktree；
3. 保护用户已有改动，不 reset/stash/clean/restore；
4. 读取当前 milestone 相关代码与测试；
5. 做一个很短的 Architecture Gate：
   - 目标；
   - 明确不做；
   - 当前 owner/seam；
   - 预计改动文件；
   - 数据/兼容边界；
   - 最小验证；
6. 应用 `$private-house-code`，检查是否能复用/删除不必要设计。

### 4.2 实现

- 先最小正确实现；
- 只碰当前责任范围；
- 如果发现新增文件直接服务当前冻结责任，可把它加入当前 allowlist，不必请求用户；
- 如果发现需要改变产品边界、评分/生命周期核心语义、数据权威或安全模型，进入硬停止线；
- 不为“以后”造兼容路径。

### 4.3 验证

按风险递增，不机械全量跑：

1. lint / parse / focused unit；
2. 当前模块 Feature/contract tests；
3. 受保护回归；
4. frontend build（如适用）；
5. DB-backed / concurrency（真实需要时）；
6. 页面任务必须真实浏览器；
7. 移动任务必须真实 emulator/device 或明确 DEFERRED；
8. 迁移/恢复必须有 dry-run、前后数据和 rollback/recovery 证据。

失败后先修当前根因。不要通过 skip、弱化断言、mock 掉真实失败、增加 fallback 或重复重跑来“绿”。

### 4.4 Review

完成改动后至少做一次独立新鲜上下文 review；只检查当前 milestone 的：

- 行为是否满足；
- 是否引入第二事实源/多余状态/不必要 fallback；
- 是否破坏真实边界；
- 测试是否能真实失败；
- 是否越界改动。

只处理 Required/Blocker；纯措辞、偏好或无行为影响建议不阻塞。

### 4.5 Exit Gate

只有同时满足才可 `DONE`：

- 用户结果成立；
- 所需负向路径成立；
- 相关自动测试通过；
- 需要页面时真实页面通过；
- 需要 DB/并发时真实 DB/并发通过；
- 无已知 blocker；
- worktree 没有任务残渣；
- 精确 commit；
- 正常 push 到 Goal branch；
- 本文件更新 checkpoint 和证据摘要。

---

## 5. 网络研究规则

外部搜索是“解决当前决策”的工具，不是每个 milestone 的仪式。

### 必须搜索

- Phase 开始时存在未冻结 UX/产品选择；
- 依赖、平台、Laravel/Capacitor/Android/iOS 行为可能随版本变化；
- Phase H 成本、发布、平台规则；
- 用户明确要求经验分享；
- 当前实现与成熟产品差异会影响产品取舍。

### 来源顺序

1. LinguaCafe 当前冻结合同和代码；
2. 官方文档 / 官方源码；
3. 背词/复习体验：墨墨背单词官方材料优先；Anki 的调度/卡片/过滤学习等问题优先 Anki 官方手册/源码；
4. Laravel、Capacitor、Android、Apple 等官方材料；
5. 高质量工程文章/issue；
6. Reddit、论坛、经验分享只用于“实际体验/坑点”，不得覆盖官方契约。

### 搜索停止条件

找到足够支持一个可逆、可测试的当前选择后停止。不要为了“全面”继续搜。

---

## 6. 当前事实基线（启动时必须 fresh verify）

2026-08-09 记录：

- `origin/master`：`1c9bdcd74fa793356ba3938f21c56405f3261e39`
- Backend Action-ID candidate：`20a116703ef217d5aad4e8a165a498399902f58e`
- Reader Active-Intent candidate：`e09569bfdb2b31c7cd8b3fd9b245c0af06905a0c`
- Harness Active-Intent candidate：`dfd98446d8a511dc08a5e243ef86625456377750`
- Infra process-instance-safe repair：`f1e4898e255269ad0aaf3976b40e6c9b18c389c0`
- Browser sentinel helper candidate：`f971df50d8362daed519a9b79919ffb65ee0d2e8`

已知事实：

- Backend + Reader + Harness 最新 non-DB composite 已 merge-clean；357/357 JS、build 和 pure gates 绿；
- Reader F5 outcome-unknown occurrence audit：formal-write safe，production-reachable UI path 无 blocker；
- Infra 旧 numeric-PID kill TOCTOU 已由 `f1e4898e` 改为 retained `proc_open` process resource；47 tests / 524 assertions 绿，但仍需独立 re-audit；
- Sentinel helper `f971df50` 独立 audit 有 blocker：`artisan serve` 可能留下 descendant `php -S`，且 cancellation probe 与 real child loop 分裂；
- 当前最小方向优先“消除多层进程树，直接拥有实际 acceptance server process”，而不是先造 Windows Job Object/通用进程监督框架；只有真实契约要求保留 `artisan serve` 才升级方案。

若启动时 remote 已变化：以 fresh remote + 当前权威代码为准，先解释 drift，再更新本节。

---

# 7. Milestone Ledger

## Foundation / A-B 收尾前置

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| FND-01 | DONE | 建立/恢复单一 Goal branch、fresh authority map、checkpoint | 现有 master/candidate refs、AGENTS、当前计划 | refs/branch/worktree 记录；无用户资产被改；本文件 checkpoint 更新 |
| FND-02 | DONE | 独立 re-audit `f1e4898e`，确认 numeric-PID kill blocker 真关闭 | 现有 47/524 pure suite、TestingDatabaseLease contract | 新鲜代码 audit + targeted/full pure tests；无 numeric PID termination path；lease/process residue=0 |
| FND-03 | DONE | 修复 Sentinel helper cancellation，优先把 acceptance server 变成 helper 直接拥有的单一真实 server process | `f971df50`、现有 lease/sentinel helper | process-level test 证明 cancel 后 child/server/port 全结束；sentinel cleanup 在 lease release 前；不新增第二 lease/worker/通用 supervisor |
| FND-04 | DONE | 独立 audit 修后的 Sentinel helper | FND-03 | blocker=0；pure/process tests 真实通过；无 false-green cleanup |
| FND-05 | DONE | 组合 Backend + Reader + Harness + Infra + Sentinel 到 Goal branch | 五个已验候选 | merge/cherry-pick provenance；无手工语义漂移；non-DB gates 全绿 |
| FND-06 | DONE | 在官方 testing DB lease 下运行 PAB-R3 DB/concurrency integration | 现有 65 DB-backed tests、lease harness | B14/B16/action-id/undo/rerate/explicit-vs-Finish/opened precedence/rollback 等真实 DB tests 绿；lease/sentinel clean |

---

## Phase A — 阅读学习主流程与 AI Reading Assist V2

> 目标：真实文章从首次浏览 → 标生词/词组 → 导出 AI 包 → 人工取得 AI JSON → 上传/校验 → 翻译/词义/消歧 → 核对证据完整闭环；不写被动 Good。

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| A-01 | DONE | 核实并收束首次阅读预览、标生词、长按/拖选词组 | TextReader/TextBlockGroup 既有触摸与标记能力 | desktop + 430/390 真实交互；不破坏普通点词/ECDICT |
| A-02 | DONE | AI V2 schema、稳定 occurrence ID、目标数量校验、20–50 分包完整 | 现有 V2 parser/batching/candidate ownership | strict parser/batching tests；漏项/重复/非法 ID/错 schema fail closed |
| A-03 | DONE | occurrence→WordSense 证据、matched_existing/new_sense/ambiguous 与核对列表闭环 | Reading target/evidence、WordSenseKnownSense、现有确认服务 | 用户可修正并保存；lemma/POS 仅证据，不替代 stable IDs |
| A-04 | DONE | Trust AI 与 AI 新词义策略符合冻结规则 | 现有设置/evidence seam | 仅 strict high-confidence matched_existing 自动成为可消费证据；ambiguous/new/low 不自动正式评分；新 sense 默认确认后加入 |
| A-05 | DONE | Phase A Finish 仍不因未核对强制阻塞，也不产生 ReviewLog/FSRS | 现有 finish preflight/commit seam | DB before/after 证明 ReviewLog/FSRS 无额外写；旧 Finish 行为兼容 |
| A-06 | DONE | 用一篇真实文章完成完整 AI 文件闭环 | 现有 browser/harness | 真实浏览器双 viewport + 词组触摸 + 真实 AI 文件导入；Console/Network 无 blocker |
| A-GATE | DONE | Phase A completion audit | A-01…A-06 | 当前合同逐条证据齐全；无 blocker；可自动进入 Phase B |

---

## Phase B — 完成阅读 Good + 阅读中显式 Sense Review

> 目标：Finish Reading 可把合格已认识词义按一次普通 FSRS Good 正式结算；阅读中可完成 Again/Hard/Good/Easy 的正式 Sense Review；retry/undo/并发不重复。

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| B-01 | DONE | reading-session start/resume、source revision、刷新恢复、完成恢复稳定 | PAB-R3 session candidate | fresh/refresh/duplicate/concurrent start tests；浏览器刷新不造新会话 |
| B-02 | DONE | 显式流程严格保持“显示答案 → pending rating → exact WordSense → 一次正式提交” | Reader inline review + canonical SenseReview | rating 在选 sense 前零写；manual new sense 后沿用同一 pending rating；不问第二次 |
| B-03 | DONE | `reading_action_id` 幂等、unknown retry、undo/rerate | Backend/Reader action-id candidate | same ID replay 一 log；undo 后旧 ID 永久 409；新 ID rerate 一新 active log |
| B-04 | DONE | 被动 Good eligibility/去重/排除 | ReadingFinishSettlementService | opened/helped/explicit/newly-created/newly-resolved/newly-marked same-reading sense 不 passive；每卡/session ≤1 |
| B-05 | DONE | 产品级 explicit > passive = server-acknowledged active intent | Reader opened barrier + Harness precedence | opened ACK 后后续 Finish passive=0；裸 API 无 marker race 允许 first-lock-wins 但绝不双写 |
| B-06 | DONE | Finish preflight/commit、unresolved gate、幂等与 rollback | PAB-R3 finish contract | preflight 零业务写；commit unresolved 零写；重复/并发 Finish 一次；failure injection 全事务回滚 |
| B-07 | DONE | Finish UI 明确显示将 Good/待确认/排除，并能正常继续 | 当前 Reader UI | desktop/430/390；刷新/网络未知恢复；用户文案不暴露工程术语 |
| B-08 | DONE | 普通 Sense Review、undo、analytics、FSRS 回归 | 现有 SenseReview suite | ordinary Sense Review 不回归；ReviewLog/FSRS/analytics 与 undo 正确 |
| B-GATE | DONE | Phase B final testing DB + real browser acceptance | B-01…B-08 | 单义、多义、Trust AI、ambiguous、opened exclusion、4 ratings、新 sense、duplicate Finish、undo/refresh 全真实通过；进入 C |

---

## Phase C — Sense Review 瘦身 + 每日打卡首页 + 四主导航

> 先审计并复用现有 SenseReview、StudyOverview、Home、Daily Progress、ReviewCard 管理，不重造第二套系统。

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| C-01 | DONE | Sense Review 问题面/答案面瘦身 | `SenseReview.vue`、`SenseStudyCard.vue`、现有 serializer | 问题面保留原文；答案中文+英文默认；不重复例句；FSRS 工程信息退入更多 |
| C-02 | DONE | 返回/前进成为稳定普通操作 | 现有 previous/session action 能力 | 浏览器真实前进/返回；不重复评分；跨刷新状态正确 |
| C-03 | DONE | 首页每日打卡 read model：连续学习、今日阅读、今日复习、完成状态、继续学习 | `ReviewDailyProgressQueryService`、Sense eligibility、ReadingSession completion、Home | 单一正式事实源；summary GET zero-write；真实浏览器完成空状态→续读→复习优先→评分/撤销→Finish→full reload，DOM/JSON/DB 一致 |
| C-04 | DONE | 四主导航：阅读 / 复习 / 生词 / 我的 | `Layout.vue`、现有 routes/app.js | 阅读=/books、复习=/reviews/senses、我的=/user-settings 已冻结；“生词”使用 C-05 canonical WordSense route；移动底栏最终仅四主入口，Home/secondary 仍可达 |
| C-05 | DONE | “生词”一级入口只面向 WordSense，提供只读基础列表/搜索/查看 | `WordSense`、`WordSenseKnownSenseService`、现有 sense serializer/ReviewCard relation | current user/current language 的 confirmed WordSense 可检索/查看；ReviewCard 仅附属且不得决定 sense 是否可见；legacy Vocabulary 不成为新一级主体 |
| C-06 | DONE | 高级功能统一降到“我的→高级”或桌面高级区域 | Admin/ReviewCard/CustomStudy 等既有页面 | 功能未误删；普通用户不见内部工程名 |
| C-07 | DONE | 响应式/可访问性真实页面收口 | 既有 M17/Web 资产 | 1920/900/430/390；键盘/返回/弹窗/Console/Network 通过 |
| C-GATE | DONE | Phase C 产品 Gate | C-01…C-07 | 首页/Review/Nav/生词/高级入口全真实验收；自动进入 D |

---

## Phase D — Legacy Word Card 迁移 + Sense 生词库 + 词形架构收口

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| D-01 | DONE | 全量 inventory：`target_type=word` 使用者、路由、统计、历史、写入口 | git/code search + 既有 migration/audit tests | 形成可执行分类清单，不修改数据 |
| D-02 | DONE | dry-run 分类器：唯一映射 / 需用户确认 / 无法安全映射 | WordSense/Occurrence/ReviewCard/SenseSourceContext | dry-run 可重复；不猜测 sense；无强制一对多复制 |
| D-03 | ACTIVE | 设计并验证迁移、备份、可逆/可追溯策略 | 既有 backup/operation/review-log infrastructure | testing DB migration 前后快照；ReviewLog 历史不伪造 sense identity |
| D-04 | TODO | 在专用 testing DB 执行迁移闭环 | D-02/D-03 | user/language 隔离；旧 log 不丢；无法判断项保留 legacy/read-only；正式队列只出 sense cards |
| D-05 | TODO | WordSense 生词页完善搜索、编辑、来源总览、legacy 历史说明 | Phase C 生词入口 + existing services | 正常/拒绝/权限/语言 tests + browser |
| D-06 | TODO | per-occurrence lemma/POS 评估与必要最小实现 | WordSenseOccurrence、tokenizer、morphology assets | 新 lemma/POS 与已确认 binding 不一致时只标复核，不自动重写；词典不做 lemma oracle |
| D-07 | TODO | legacy 页面/路由先隐藏→依赖审计→分类保留/只读/删除 | existing UI guards | 不为“代码干净”删除仍有 caller 的能力；阅读颜色正确 |
| D-GATE | TODO | Phase D migration + product Gate | D-01…D-07 | dry-run/backup/reversibility/isolation/log preservation/queue/browser 全通过；自动进入 E |

---

## Phase E — 移动端日常学习 + 有限离线收束

> 旧 M1–M9 是资产，不是自动完成证据。先复用审计，再按当前 A–D 语义对齐；禁止重造第二套 mobile architecture。

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| E-01 | TODO | 审计旧 M1–M9：Mobile API、operation ledger、download packages、sync、Android/iOS/offline | 2026-08-01 M0–M18 audit + actual code | 每项标 `reuse / adapt / obsolete`；不因历史“Closed”自动通过 |
| E-02 | TODO | 移动 IA 与 Phase C 首页/四导航一致 | existing Capacitor/mobile shell | 不设计第二套 IA；Web/mobile 文案/入口一致 |
| E-03 | TODO | 文章下载包包含 token/sentence/lemma/POS、词典摘要、相关 WordSense；review package 对齐 sense-only | MobileArticlePackageService / MobileReviewPackageService | 包版本/来源/version tests；不塞完整 70万+ 词典 |
| E-04 | TODO | 离线显式 rating / passive Good /操作统一复用 operation/idempotency 边界 | MobileIdempotency + queued sync + ReviewCardService | 断网重复/重放不双写；服务器最终权威 |
| E-05 | TODO | conflict / retry / app-kill 恢复用普通用户文案 | queued action assets | kill 后待同步动作仍在；冲突可理解；不新增 speculative recovery worker |
| E-06 | TODO | 移动 Reader/Reviewer 触摸、Bottom Sheet、safe area、返回/前进 | M5/M7/M17 existing assets | Android emulator/device 真 UI；长按拖选词组、评分、导航通过 |
| E-07 | TODO | Android 联网 + 有限离线真实闭环 | existing Android MVP | online/offline/reconnect；下载文章；近期 review；sync；cached assets |
| E-08 | TODO | iOS 工程与当前语义对齐，能在 Windows 完成的 static/build-contract 全做完 | existing Capacitor iOS M9 | 无 macOS/Xcode 时把真机/签名/Keychain/device checks 记入 `iOS capability cluster`，不伪造通过 |
| E-GATE | TODO | Phase E Gate | E-01…E-08 | 所有本地可执行项绿；能力簇诚实登记；自动进入 F，只在下游真依赖 iOS 未验行为时暂停 |

---

## Phase F — 真题 / 我的材料产品化

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| F-01 | TODO | 定义材料元数据：CET-4/CET-6/考研、年份、套次、题型、我的材料 | Book/Chapter existing model/import | 最小字段；不先造内容采购系统 |
| F-02 | TODO | 用户上传材料简单流程复用现有英文 import/tokenizer | `/chapters`、ProcessChapter、health assets | 正常/坏文件/失败恢复；不破坏原 import |
| F-03 | TODO | 阅读库分类与检索 UI | existing Library/Home/Nav | 四级/六级/考研/我的材料可理解；无空壳目录提前展示 |
| F-04 | TODO | material/article version 与 occurrence/source preservation | existing source revision/WordSenseOccurrence | 更新文本版本不破坏历史绑定；冲突 fail closed |
| F-05 | TODO | 目录 + 按套下载 + offline status 对齐 Phase E | existing package/sync | 下载、离线打开、恢复在线真实验证 |
| F-06 | TODO | 删除材料前显示来源与学习历史影响，并只执行已授权产品生命周期 | Book/Chapter deletion boundaries | preview/confirmation/拒绝路径；不直接 broad delete 学习历史 |
| F-07 | TODO | 真实样本闭环 | 用户可合法使用的样本 | CET-4、CET-6、考研各至少一套：上传→阅读→AI包→学词→下载→离线打开 |
| F-GATE | TODO | Phase F Gate | F-01…F-07 | 三类真实样本 + version + delete impact 全通过；自动进入 G |

---

## Phase G — 旧高级功能隐藏 / 退休 / 产品减重

> 原则：先隐藏，再证明无依赖，再决定删除。不得为了“清爽代码”提前删。

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| G-01 | TODO | inventory 一级入口与调用方：Browser/Card Info、Custom Study、Saved Search、Tag/Marker、手动调度、FSRS 技术指标、复杂统计、apkg、备份恢复、文章健康 | routes/components/services/tests | caller/route/data dependency 清单 |
| G-02 | TODO | 普通用户一级入口隐藏，高级能力集中“我的→高级” | Phase C advanced entry | desktop/mobile 真页面；能力仍可达 |
| G-03 | TODO | 对每项做依赖扫描与主流程回归 | existing guards/tests | 新阅读/复习/生词/材料主流程不依赖旧一级入口 |
| G-04 | TODO | 每项分类：保留高级 / 只读兼容 / 可删除 | G-03 evidence | 分类有当前 caller/contract 证据，不凭偏好 |
| G-05 | TODO | 只删除已证明 orphan 的 UI/code/config/tests/docs | G-04 | 最小删除；build/tests/browser 绿；不删历史数据契约 |
| G-06 | TODO | 明确把视频/字幕/非英文/JMDict/字体/词源/泛媒体等移出主线，不误删共享基础 | current product plan | 主导航无这些主线；共享底层若仍被核心使用则保留 |
| G-GATE | TODO | Phase G Gate | G-01…G-06 | 普通用户产品显著减重且无主流程回归；自动进入 H |

---

## Phase H — 公开测试、容量、恢复、发布门禁

| ID | 状态 | Outcome | Reuse first | Exit evidence |
|---|---|---|---|---|
| H-01 | TODO | 建立最小可读的 load/observability harness | existing logs/health/tests; standard tooling | 能观测 P95/P99、DB connections、queue backlog、errors；不先建监控平台 |
| H-02 | TODO | 100 同时在线的阅读/查词/复习负载 | current canonical flows | 无主流程错误；记录 P95/P99 和资源曲线；评分不重复 |
| H-03 | TODO | 只针对实际瓶颈做性能修复 | H-02 evidence | 每个 index/cache/query/batch 都有实测瓶颈付费；复测证明改善 |
| H-04 | TODO | 自动备份与真实 testing 恢复演练 | existing M6 Backup/Restore assets | backup 可恢复；write fence/完整性/失败路径；不触开发/生产数据 |
| H-05 | TODO | 用户/语言隔离、账号删除、同步设备撤销、隐私边界 | auth/mobile/device/portable data assets | normal + unauthorized + cross-user tests；真实页面 |
| H-06 | TODO | 登录与公开认证产品收束 | existing email auth; optional Apple/WeChat plan | 不引入短信成本除非当前需要；安全边界和 UX 通过 |
| H-07 | TODO | 重新联网查询上线时最新基础设施与平台价格，给出成本模型 | current providers/official pricing | ¥600–1000/月推荐假设有当日价格支持；更稳档 ¥1200–2500 重新核算，不沿用旧价格 |
| H-08 | TODO | 公共打包内容权利检查 | Phase F material metadata | 只包含用户有权分发/已授权内容；用户自传内容不等于可公开再分发 |
| H-09 | TODO | Android 发布准备 | existing Android M7 assets | current build、package、privacy、device smoke；不自动商店发布 |
| H-10 | TODO | iOS 真机/Xcode/签名/TestFlight capability cluster | existing iOS M9 assets | 若有 Mac/Xcode/Apple 授权：真实 build/install/Keychain/safe-area/offline/TestFlight；若没有保持 DEFERRED，不伪造 |
| H-11 | TODO | 最终 Web + Android + 可用 iOS 全主流程回归 | all phases | 阅读、AI、Finish、Review、生词、材料、offline、恢复、账号边界真实证据 |
| H-GATE | TODO | 最终 Goal completion audit | 全部 milestone + capability clusters | 所有必需 milestone DONE；所有必须 capability cluster 清零；无 blocker；只有此时允许声明“整个大计划完成” |

---

## 8. Phase Gate 自动推进规则

本 Goal 用户已明确授权完成整个大计划，因此：

- `A-GATE DONE` → 自动开始 B；
- `B-GATE DONE` → 自动开始 C；
- 依次到 H；
- 不需要每个 Phase 再询问“是否继续”。

但 Gate 不能靠报告标签通过，必须依据当前代码和真实证据。

如果某 Phase 有 `DEFERRED`：

- 若后续 milestone 不消费该未验证行为，可以继续；
- 若后续 milestone 依赖它，则停该分支；
- `H-GATE` 前所有“完成必需”的 capability cluster 必须清零。

---

## 9. 硬停止线

即使 Goal 要求连续执行，也不得自行做：

- 开发、预发布、生产或真实用户数据库 migration/backfill/restore/delete；
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` / drop / truncate；
- 读取或修改 `.env*`、密钥、真实账号密码；
- 真实 AI provider 外发、付费、提高成本上限；
- 部署生产；
- App Store / Play Store 真正提交；
- Apple 签名/发布等不可逆外部动作，除非用户明确授权；
- force push；
- 修改目标外产品边界；
- 同一最高有效权威中无法消解的冲突。

遇到硬停止线时：记录 `BLOCKED` 或 capability `DEFERRED`，继续其他独立 milestone。只有无其他可运行节点时才请求用户。

---

## 10. Testing DB / Browser 规则

### Testing DB

- 所有学习数据写入验收只在专用 testing DB；
- 使用现有 machine-global `TestingDatabaseLease`，不要创建第二套 lock/claim；
- server 必须有 server-bound testing evidence + exact sentinel；
- test 后只清理本任务创建的唯一 marker/sentinel；
- lease/sentinel/server cleanup 失败 = 当前 milestone 不通过。

### Browser

- 页面任务必须真实 DOM + 用户事件；API 200 不代替；
- 记录 Console/Network 和可观察数据变化；
- desktop + 430/390 是 Reader/Home/Review/Nav 的基本目标；
- 多 tab 只在真实合同需要（如 Phase B precedence）时使用，不为“更保险”制造多窗口测试；
- 浏览器通道普通故障可切换允许的真实浏览器工具；平台明确拒绝不得绕过。

### Testing identity

优先使用 testing DB 中已有且最小权限的测试身份；若无法确认，创建 `codex-acceptance-<unique>@example.test` 的临时 testing 身份，通过正常注册/登录 UI 使用。随机密码只存在内存，不写仓库、报告或截图。

---

## 11. Git / Goal branch

持续 Goal 推荐一个长期 branch，例如：

`goal/linguacafe-a-h-sol-medium-20260809`

规则：

- 若已存在则恢复，不重复创建；
- milestone 一个可理解 commit；复杂 milestone 可有少量中间 checkpoint commit，但最终需整洁；
- 精确 stage；
- 不把用户已有 dirty changes 带入；
- 不 force；
- 正常 push Goal branch，便于崩溃恢复；
- 不直接把未过 Gate 的工作推 master；
- H-GATE 后再交付最终 merge/readiness 结论，生产发布仍受硬停止线。

---

## 12. 进度记录格式

每次只在文件顶部/本节维护短记录，不复制测试长日志。

### CURRENT CHECKPOINT

- Goal branch: `goal/linguacafe-a-h-sol-medium-20260809`
- Active milestone: `D-03`（legacy word-card 迁移、备份与可逆/可追溯策略）
- Last DONE: `D-02`
- Current HEAD at FND-01 Entry Gate: `1c9bdcd74fa793356ba3938f21c56405f3261e39`（checkpoint commit 见 Goal branch tip）
- Last verified `origin/master`: `1c9bdcd74fa793356ba3938f21c56405f3261e39`（2026-08-09 10:15 +08:00 fresh fetch）
- Deferred capability clusters: `none yet`
- Blocking issue: `none`

### CLOSED MILESTONE EVIDENCE — B-07

- 用户可见 Finish 主路已唯一接到现有 `preflightFinishSettlement()` → `finishCommitDialog` → `commitFinish()`；legacy `finish()` 在零 caller 审计后删除，没有新增第三套状态机、第二 completion truth、watchdog 或 reconciliation。
- 原始已确认 bug（现已修复）：`preflightFinishSettlement()`、`commitFinish()`、`finishCommitDialog` 都存在，当时可见确认按钮仍 `@click="finish()"` 直走 legacy `/chapters/finish`；因此当时 Phase B 两阶段 Finish UI 实际不可达。现有 JS 合同只检查方法/字符串存在，属于 false-green，现已改成可达行为合同。
- 自动验证：Reader 全套 JS 180/180；最终 focused 54/54；ReadingReviewSettlementContract 19/19（66 assertions）+ ReadingReviewConcurrencyContract 29/29（285 assertions）；npm development 成功。
- server-bound testing 浏览器：eligible 明确展示 1 Good/0 待核对后第二次确认才完成；unresolved 显示 1 待核对并阻止 commit；真实核对后网络离线 commit 不假完成、同一 reading-session `a607491e…` 保留，恢复网络后安全完成；desktop/430/390 均无横向溢出，Console error/warn 为 0。
- testing DB readback：两章各 `read_count=1`、各 1 completion/1 settlement/1 `reading_passive` Good；无重复写。task user/data、owned sentinel、browser、port、lease 与临时脚本均精确清理，最终 lease `active=false / stale_metadata=false`。
### CLOSED MILESTONE EVIDENCE — B-08
- B-08 按验证型 milestone 收口，没有新增第二套普通评分、undo、analytics 或 FSRS 机制；普通 `/reviews/senses` 继续走既有 `sense_review` 正式评分与既有 stack undo。
- testing DB 健康门禁通过；聚焦回归 `ReviewFsrs + SenseReviewActionTransaction + SenseReviewStackUndo + SenseReviewUndoneAnalytics + SenseReviewAnalyticsQueryService + ReadingReviewSourceUndoAnalytics + SenseReviewSessionActions + SenseReviewRatingContract + SenseReviewIntervalPreview` 共 172/172（800 assertions）全绿；补充 `ReviewStats + DailyReport + SevenDayTrend + ThirtyDayCalendar + Statistics V3/Card Info` 共 116/116（893 assertions）全绿；9 个相关前端 guard 文件全绿。
- server-bound testing 真实浏览器使用当前任务本地测试账号完成普通 Sense Review：`Good → 本次操作撤销 → 卡片重新回到到期队列 → Again`。撤销后页面即时恢复为到期数量 1 / 已复习 0；rerate 后总结只计 1 次“忘了”。四种评分的代码/FSRS 合同已由自动测试覆盖；Again/Hard/Good/Easy 全部真实浏览器组合留给紧接着的 B-GATE final acceptance，不在 B-08 重造第二套 fixture。
- testing DB 最终读回：同卡保留 2 条 `sense_review` 审计日志，旧 Good `undone=true / undo_source=sense_review_history`，新 Again 为唯一 active log；卡片为 `relearning / fsrs_reps=3 / fsrs_lapses=1`；今日 analytics 为 total=1、again=1、good=0，证明 undone 不污染有效统计。
- Reasonix 只读复核未发现功能性 bug；确认 reading 三件套与普通评分路径隔离，普通 undo 前置 `ReadingSession` scoped lock 在无匹配 reading session 时为空锁，其锁序意图已有 FND-06/B-03 决策记录并由普通 undo 回归与真实页面撤销证明语义不变。
- 本轮新建 testing 用户与其 ReviewLog/ReviewCard/WordSense/preset/binding/settings 已精确删除，最终计数全 0；browser server 已停止，testing DB lease 最终 `active=false / stale_metadata=false`。生产代码零修改。

### CLOSED MILESTONE EVIDENCE — B-GATE
- server-bound testing 真实浏览器在同一组合文章覆盖单义/多义、Trust AI、ambiguous、opened exclusion、Again/Hard/Good/Easy、新 sense 续接和 Finish；四种 `reading_explicit` 评分分别真实落到 Again/Hard/Good/Easy，新建 `novelword` sense 后沿用原 pending Good 一次提交。
- Finish preflight 真实显示 Trust AI `trustword` 与人工消歧 `ambiguousword` 为 2 个 passive Good；againword/hardword/bank/easyword/river/novelword 共 6 项被排除，DB 对应仅 2 条 `reading_passive` settlement/log，证明 opened/explicit/new-sense 不重复被动 Good。
- duplicate Finish 使用第二 reading-session 将 8 项全部排除后，对最终确认真实连续触发两次 commit 请求；页面只得到一次完成结果，DB 仅 1 completion、0 settlement。第三 reading-session 完成 Good → snackbar 撤销 → full reload；同 UUID `5f764a1e…` 跨刷新保持 active，唯一 Good log 为 `undone_at` 非空 / `undo_source=sense_review_snackbar`，卡片 FSRS 精确恢复 before snapshot。
- 独立 Reasonix 只读复核确认 Gate 权威定义实际为 9 类组合，且全部要求 testing DB + real browser；本轮已补齐此前缺失的 Trust AI、ambiguous、opened exclusion、Hard/Easy、新 sense、duplicate Finish、undo/refresh 组合证据。
- fixture user 71046 及 17 类 user-scoped 关联资产在 testing harness 独占 lease 下逐表精确删除，全部 after=0；未清库、未改生产代码、未运行 notification script、未 DCP。

### CLOSED MILESTONE EVIDENCE — C-04
- C-04 Route/Authority 与 Permission Gate 已证明：阅读复用 `/books`，正式复习复用 `/reviews/senses`，“我的”复用 `/user-settings`；C-05 已提供 canonical WordSense-only `/word-senses`，四主导航现在按冻结映射做顺序真实验收。
- 移动底栏最终只有“阅读 / 复习 / 生词 / 我的”四项；首页 `/` 保留为真实次级入口。现有 drawer 继续承担 Home 与 secondary capabilities，`更多` 不得成为第五个 bottom item；最小方向是在 `Layout.vue` 复用现有 `drawer` 状态提供 mobile-only secondary trigger，不新建导航 store/service、AppBar 或浮动 Home。
- legacy `/vocabulary/search`、Custom Study、ReviewCardManage、StudyOverview、Backup、Article Health、User Manual 等在 C-06 前继续保持可达；特别是 `/admin/{page?}` backup 对普通 authenticated user 可达，不能按 URL 前缀误当管理员专属。

### CLOSED MILESTONE EVIDENCE — C-05
- C-05 V1 只做“我已经保存的词义”的基础只读页面：current user + current selected language + `WordSense.status=confirmed`。内容事实以 WordSense 为主体；ReviewCard 是可选附属状态，confirmed sense 即使缺 ReviewCard 也不能从生词库消失，GET 更不得偷偷创建/修复 ReviewCard。
- 第一版只做基础列表、普通文本搜索和查看；不复制 `/review-cards/manage` 的 FSRS/Leech/lifecycle/批量治理，不复制 `/vocabulary/search` 的 stage/phrase/CSV/legacy word 语义，不进入编辑/删除/来源总览（D-05 后续完善）。
- 优先复用 `WordSenseKnownSenseService` 已验证的 confirmed/user/language 语义、现有 sense payload 字段和 ReviewCard relation；若需要全局分页查询，只新增一个责任清楚的只读 query owner，不为本页增加 Repository/DTO/store/第二搜索系统。实现后必须有 zero-write GET、user/language/status 隔离、search/no-result/full-reload 与 desktop/430 真实浏览器证据。

### CLOSED MILESTONE EVIDENCE — C-06

- “我的”复用现有 `UserSettingsLayout.vue` 增加唯一“高级”页签，集中链接既有词汇搜索、自定义学习、复习卡管理、学习总览、备份与恢复、文章检查；未新增 route、store、service、权限或数据写入路径，管理员设置继续只由原 role-gated drawer 入口承担。
- C04+C06 前端合同 11/11、`npm run development` 与 diff-check 全绿。官方 Browser 在 desktop 逐项真实点击六个入口；exact 430 为 6 项全部可见、无横向 overflow，并真实进入复习卡管理；用户可见内容不含 FSRS/Leech/ReviewCard/target_type/lifecycle 工程词。
- Console 仅 Vue development info、无 error/warn；管理员入口仍 exact 1、`href=/admin`、240×40。browser/server/port 已关闭，TestingDatabaseLease final false/false，TestingDatabaseHealthTest 6/69 通过；fresh review Blocker=0/Required=0。

### CLOSED MILESTONE EVIDENCE — C-07

- 现有 M17/Web 路径只补足真实观察到的触达缺口：移动复习摘要四个操作、WordSense 搜索控件与底栏外“更多”均达到 44px；未新增评分、FSRS、ReviewLog、route、store、backend payload 或第二导航状态。
- 当前 build 的 1920/900/430/390 真页面均无横向 overflow；1920 Home→我的→浏览器返回成立，900 设置弹窗真实打开/焦点保持/显式取消成立，430 底栏四项 56px 且“更多”44px，390 WordSense 输入槽 56px/搜索按钮 44px、高级六项完整可达。
- 官方 Browser Console 只有 Vue development info；备用受控 Playwright 证明原生链接 Enter 导航与弹窗 Tab 焦点，目标页面 HTTP ≥400 为 0，唯一 Console error 是本地 Pusher 未启动的既有降级噪声。Node 40/40、M17 PHP 2/11、build 与 health 6/69 通过。
- task identity 与全部 user_id 资产精确清零；owned sentinel=0、port closed、lease false/false。Reasonix 因 D: workspace policy 未启动；本地五轴 fresh review Blocker=0/Required=0。

### CLOSED MILESTONE EVIDENCE — C-GATE

- Gate 验证绑定已提交 HEAD `9a4d0a8ec2a1a3f384cf76b12806aead86a29d0a`：C-01…C-07 均为 DONE；Node 41/41、Phase C 聚焦 PHP 114/626、`npm run development` 与 testing health 6/69 全绿。
- 官方 Browser 顺序完成 desktop 四主入口及 reload、六个高级入口、exact 430 四主与同一 drawer、exact 390 触达/溢出检查；管理员入口 exact 1、真实一次 click 进入 `/admin` 并见 `#admin-tab-items`/“概览”。Console/Network 补充检查无目标页面失败。
- task admin 与 user-scoped 数据、owned sentinel、browser/server/port/lease 已精确清理；fresh 五轴审查 Blocker=0/Required=0。验收后出现的未提交 `Layout.vue` 外部用户改动未纳入 Gate 证据、roadmap commit 或后续精确 stage，并保持原样。

### CLOSED MILESTONE EVIDENCE — D-01

- `docs/audits/d-01-legacy-word-card-inventory-2026-08-12.md` 完整列出 legacy word-card 的唯一创建 owner、评分/CLI/词汇/Finish/语言数据删除写入口、路由/UI、legacy Goal 写读链、全部 `review_card_id` 历史依赖与现有 sense-only barriers；未修改产品数据或代码。
- D-02 合同已冻结 persisted-ID-only candidate、固定分类优先级与 reason codes；明确禁止 lemma/POS/翻译猜测、one-to-many 复制和用 `ReviewLog.source` 伪造历史 sense identity。health 6/69、legacy focused 10/108 通过，lease final false/false。
- fresh review 首轮发现 Finish/shared settlement 与语言数据删除漏项、分类谓词不确定；补齐后复审 Blocker=0/Required=0。外部未提交 Vue/JS 用户资产均排除并保持原样。

### CLOSED MILESTONE EVIDENCE — D-02

- 新增只读 `reviews:classify-legacy-word-cards` 与单一分类服务，以 persisted IDs 收集 legacy word card、encountered target、occurrence、WordSense/Sense ReviewCard 和完整历史依赖；输出固定 schema/order/reason codes，且不写数据库、不猜 lemma/POS/翻译、不做一对多复制。
- 分类优先级覆盖 target 缺失/越界、occurrence 或 WordSense 越界、未解析 evidence、冲突/竞争 candidate、唯一 confirmed mapping 与只读保留；跨作用域 occurrence 即使绑定了 sense 也不会被当作 resolved/conflicting evidence。operation 同时按 card ID 与 captured ReviewLog ID 收集并去重。
- testing DB health 6/69、聚焦 4/80、最终受保护回归 96/535 全绿；PHP lint、命令注册、固定 JSON/LF、过滤器、重复性、零写 fingerprint 与 diff-check 通过。lease final `active=false / stale_metadata=false`。
- 两份独立 fresh review 均为 Blocker=0/Required=0；shared worktree 的外部未提交服务、Vue 与测试资产全部排除并保持原样。

### PROGRESS LOG

格式：

`YYYY-MM-DD HH:mm | milestone | DONE/DEFERRED/BLOCKED | commit | proof summary | next`

每个 milestone 最多 3–6 行摘要。

`2026-08-09 10:15 | FND-01 | DONE | Goal branch tip | fresh origin/master=1c9bdcd7；Goal branch 从该 ref 建立；原 master 的 13 项 dirty/untracked 用户资产保持原样；authority/control files 已落入独立 linked worktree | FND-02`

`2026-08-09 10:24 | FND-02 | DONE | Goal branch tip | f1e4898e 仅保留 PID/command inspection 为只读证据，所有 termination 使用原始 proc_open resource；47/47 tests、523 assertions，1 个与改动无关的 host symlink capability skip；lease inactive、stale metadata=false、PHP probe residue=0 | FND-03`

`2026-08-09 10:27 | FND-03 | DONE | Goal branch tip | artisan serve acceptance 命令转换为 helper 直接拥有的单层 php -S；同一个 cancellation probe 驱动 child loop；18/18 focused、65/65 combined、613 assertions，已知 host symlink capability skip 1；lease inactive、server/probe residue=0；fresh review Required/Blocker=0 | FND-04`

`2026-08-09 10:32 | FND-04 | DONE | Goal branch tip | 独立审查先发现真实 process test 绕过 artisan preparation 的 false-green Required；已改为贯通 artisan→php -S→cancel→proc_close→port closed；复审 Blocker=0/Required=0；18/18 focused、65/65 combined、613 assertions；lease inactive、residue=0 | FND-05`

`2026-08-09 10:43 | FND-05 | DONE | Goal branch tip | 37 个候选提交全部 patch-equivalent，组合无冲突/手工漂移；JS 357/357、npm development、PAB-R3 parallel-safe meta gate、53 PHP lint 全绿；独立审查 Blocker=0/Required=0；Goal worktree 复用现有 vendor/node_modules junction，Git 不跟踪 | FND-06`

`2026-08-09 11:09 | FND-06 | DONE | Goal branch tip | linked-worktree bootstrap 已重定向 optimized classmap/PSR-4/APP_BASE_PATH；health negative proof 精确发现 4 个 pending migration，随后仅在官方 lease 下 forward migrate；PAB-R3 integration 14 gates 全绿、并发 27/27、普通 undo 回归 39/39；独立审查 Blocker=0/Required=0；lease/sentinel/process residue clean | A-01`

`2026-08-09 11:46 | A-01 | DONE | Goal branch tip | Reader JS 45/45、harness 18/18（98 assertions，真实 checked-in wrapper/Laravel HTTP 请求、取消与端口清理）、DB 回归 34/34（201 assertions）及 npm development 全绿；官方 Browser 在 desktop/430/390 完成普通点词、真实拖选词组、持久化、单词标记切换，testing DB before/after 证明 WordSense/ReviewCard/ReviewLog 均为 0，fixture/lease/sentinel/server residue clean；空 testing 设置下既有 Anki settings 500 与未配置 ECDICT 的 graceful state 不阻断本切片；独立复审 Blocker=0/Required=0/Advisory=0 | A-02`

`2026-08-09 12:02 | A-02 | DONE | Goal branch tip | strict parser/occurrence identity/batching/candidate ownership 35/35（154 assertions）、Phase A/Reader JS 16/16、testing DB write boundary 7/7（32 assertions）全绿；补齐 JSON 原生 object/list fail-closed，稳定 ID 的七个 owner 字段回归，51→26+25 与 101→34+34+33 均衡分包，0–19 明确保留单小包；无正式评分写入且 lease clean；独立复审 Blocker=0/Required=0/Advisory=0 | A-03`

`2026-08-09 12:35 | A-03 | DONE | Goal branch tip | evidence API 10/10（91 assertions）、Reader/verification JS 23/23 与 npm development 全绿；真实 server-bound testing 浏览器完成文本导入、2 个 stable occurrence、V2 preview/confirm、new_sense/excluded 修正，刷新与整页重载均保持 0 待核对/1 已核对/1 已排除；DB 证明 2 条 user evidence、0 WordSense/ReviewCard/ReviewLog，随后精确清理零残留；source revision A→B stale 隔离与既有两张卡 full FSRS snapshot 回归闭合；独立复审 Blocker=0/Required=0/Advisory=0 | A-04`

`2026-08-09 12:42 | A-04 | DONE | Goal branch tip | Trust AI boundary PHP 11/11（100 assertions）、Reader policy JS 45/45 与 npm development 全绿；默认关闭、显式 opt-in、仅 current high matched_existing、user evidence precedence、auto-add-new-sense 禁用均由现有单一路径证明；medium/low/ambiguous/new_sense 对 WordSense/ReviewCard/ReviewLog/FSRS 零写且 full card snapshot 不变；独立审查 Blocker=0/Required=0/Advisory=0 | A-05`

`2026-08-09 13:10 | A-05 | DONE | Goal branch tip | Phase A Finish 改回无 reading_session_id/settlement_mode 的既有兼容入口，未核对词义不阻塞；Phase B preflight/commit 原路径保持休眠。PHP 3/3（39 assertions）、JS 32/32、npm development、testing sentinel 绑定真实浏览器完成阅读、Console/Network、端口/租约/精确残留清理均通过；active ReadingSession、ReviewLog/settlement/completion 与 full FSRS snapshot 不变；独立复审 Blocker=0/Required=0/Advisory=0 | A-06`

`2026-08-09 14:31 | A-06 | DONE | Goal branch tip | server-bound testing 正常 UI 完成真实文章、2 个 stable targets、V2 1 包 strict preview/confirm、4 句译文、1 单词/1 词组与 new_sense 核对；cleanup 前 live DB 为 assist1/targets2/evidence1/session1 active，同时 WordSense/Card/Log/settlement/completion 全 0、FSRS N/A；三名 task identity 与任务数据精确清理，sentinel/lease/port/browser residue=0；双 viewport/触摸词组沿用同一切片先前证据；独立复审 Blocker=0/Required=0/Advisory=0 | A-GATE`

`2026-08-09 14:55 | A-GATE | DONE | Goal branch tip | clean-tree Phase A PHP 103/103（619 assertions）、全量 JS 355/355、npm development 与 writer-surface audit 全绿；独立审查发现 matched_existing 可见候选改选缺口后，在 server-bound testing 正常 UI 真实完成双候选 bank→河岸、confirm、dialog refresh、full reload；user evidence 持久化到 sense36，ReviewLog0、两张卡 full FSRS snapshot 精确不变；task data、browser/port/sentinel/lease residue=0；复审 Blocker=0/Required=0/Advisory=0 | B-01`

`2026-08-09 15:36 | B-01 | DONE | Goal branch tip | 新增 endpoint lifecycle contract：current fresh/explicit resume 同 UUID，cross-user/language 404、wrong chapter/source revision 409，completed result 连续两次 exact replay；PHP 52/52（387 assertions）+ final focused 8/8（97 assertions）、Reader JS 32/32、npm development 全绿。server-bound testing 正常 UI 初次 `{}`、真实标记触发 component refresh、full reload 三次均复用 UUID 12d0581e…，live DB current active session=1 且 WordSense/Card/Log/FSRS 写入为 0；失败批次与成功批次 task data、browser/port/sentinel/lease 全部精确清零；独立复审 Blocker=0/Required=0/Advisory=0 | B-02`

`2026-08-09 21:05 | B-02 | DONE | Goal branch tip | Private House cleanup 删除客户端按释义/排除 ID 猜新 sense 的第二真相，并让普通 `/senses/manual` 不再承担 Reader 外锁；Reader JS 53/53、B-02/manual 回归 PHP 43/43（402 assertions）、npm development 全绿。server-bound testing 真实 UI matched-existing bank 与 marked-unknown beacon 均只选择一次 Good：bank `/rate` 1 次；beacon `/senses/manual` 1 次 + `/rate` 1 次。DB readback 两张 sense card 均 fsrs_reps=1、各恰好 1 条 `reading_explicit/good` ReviewLog 和 1 条 `explicit_rated` interaction；Pusher 连接拒绝与空 testing Anki settings 500 为非 B-02 fixture/local-service 噪声。testing user 46 及全部 user-scoped 资产、browser/port/sentinel/lease 精确清零 | B-03`

`2026-08-10 10:38 | B-03 | DONE | Goal branch tip | Private House 复核确认现有 action ledger 已足够，无生产代码改动：Reader recovery 25/25、并发/action-id PHP 29/29（284 assertions）。真实 testing 浏览器制造“服务器已 200、页面 ERR_FAILED”，bank action `58ae…8718` 跨刷新保留并由安全重试复用同 ID、同 review_log_id=38，最终 fsrs_reps=1；river 首次 action `b665…9cd6` rate=200→undo=200，随后新 action `8953…11d9` rerate=200。DB 最终为 bank 1 条 active Good；river 1 条 undone + 1 条 active Good、fsrs_reps=1；testing user/关联引用/owned sentinel/browser/port/lease 全部精确清零 | B-04`

`2026-08-10 10:49 | B-04 | DONE | Goal branch tip | Private House 发现 Finish 私有 lifecycle eligibility 与 canonical `ReviewCard::senseReviewEligible()` 漂移：旧副本会错误排除已到期 buried 卡；删除 7 行重复判定并复用唯一 queue scope。补 2 个承重合同：eligible preflight 对 ReviewLog/settlement/completion/read_count/FSRS 全零写，以及 active/disabled/suspended/archived/future-buried/expired-buried 与 canonical queue 一致。focused 19/19（66 assertions），Settlement + LifecycleQueue + ReadingConcurrency 61/61（367 assertions）；same-session marked/resolved、opened/helped/explicit、same-card dedupe、Trust AI high existing binding 与 later-session eligibility 全保留 | B-05`

`2026-08-10 10:54 | B-05 | DONE | Goal branch tip | Private House 判定现有 session-first lock + `ReadingSessionInteraction` 单一 intent ledger 已足够，零生产代码/零新增测试。fresh ReadingConcurrency 29/29（284 assertions）：裸 explicit-vs-Finish 无预确认 marker 时 first-lock-wins 且 formal log 恰好 1；opened 先获服务器 ACK 后 passive 永远 0 且 settlement 0；true opened-vs-Finish、helped-vs-Finish 均无 acknowledged+passive 不可能终态；retry/undo/evidence/source-change 并发回归同时全绿 | B-06`

`2026-08-10 | B-06 | DONE | Goal branch tip | 服务器两阶段 Finish 无需新增机制：ReadingSettlement + ReadingConcurrency fresh 48/48（350 assertions）证明 eligible preflight 零 ReviewLog/settlement/completion/read_count/FSRS 写、unresolved commit 零写、成功 commit exact completion replay、late finish failure 全事务回滚、true concurrent Finish 仅 1 completion/1 read effect/1 passive Good，source-change race 仅 serialized success 或 stale。侦察同时发现 B-07 真 bug：Reader 可见确认按钮仍直调 legacy `finish()`，已有 preflight/commit UI 方法不可达；现有 JS 仅断言方法存在形成 false-green | B-07`

`2026-08-10 12:05 | B-07 | DONE | Goal branch tip | false-green TDD 先红后绿；可见 Finish 已唯一走现有 preflight→commit，legacy `finish()` 零 caller 后删除。Reader JS 180/180、最终 focused 54/54、Finish PHP 48/48（351 assertions）、npm development 全绿；server-bound testing 浏览器覆盖 eligible、unresolved、离线 unknown→同 reading-session 恢复、desktop/430/390 与 Console；DB 最终两章各 exact 1 completion/1 settlement/1 passive Good，task data/sentinel/browser/port/lease/临时脚本精确清零 | B-08`

`2026-08-10 13:29 | B-08 | DONE | Goal branch tip | 零生产代码改动；testing DB health 绿，ordinary SenseReview/undo/analytics/FSRS 聚焦 PHP 172/172（800 assertions），stats/report 补充 112/112（861 assertions）及相关前端 guards 全绿。真实浏览器普通卡完成 Good→撤销→卡回队列→Again；DB 保留 1 undone Good + 1 active Again，FSRS=relearning/reps3/lapses1，日报只计 Again；testing 用户与全部关联资产精确清零 | B-GATE`

`2026-08-10 15:38 | B-GATE | DONE | Goal branch tip | server-bound testing 组合文章真实覆盖 9 类 Gate：Again/Hard/Good/Easy 四评分、bank 多义、Trust AI、ambiguous、新 sense 同 pending Good、opened/explicit passive exclusion；Finish 第一会话仅 2 passive Good，第二会话双 commit 仅 1 completion/0 settlement；第三会话 Good→snackbar undo→full reload 同 UUID，DB undone log 与 FSRS before snapshot 精确恢复。Reasonix 独立复核后无未覆盖 Gate 类别；fixture user 71046 与 17 类关联资产全部 after=0 | C-01`

`2026-08-10 | C-01 | DONE | 8dd8d7ce | Sense Review 问题面保留原文，答案面中文+英文默认，FSRS 工程信息收进“复习信息”；focused regression、build 与 desktop/900 真实浏览器通过，无第二评分路径 | C-02`

`2026-08-10 | C-02 | DONE | Goal branch tip | final code audit Blocker/Required=0；Node final gate 26/26；同一冻结树 npm development + server-bound testing 真浏览器完成评分两张→返回两次→full reload→前进两次→本地返回，只有两次明确评分与两次 canonical undo 写入，forward/reload/local-back 无额外 ReviewLog/FSRS；fixture/server/browser/lease 精确清理 | C-03`

`2026-08-11 | C-03 | DONE | Goal branch tip | code audit Blocker/Required=0；主窗口在正确 goal worktree 补跑 final static Gate（3 PHP syntax + 3 Node guards + diff-check）全绿；server-bound testing 真浏览器完成空状态→active reading CTA→due review priority→两次评分/一次撤销→真实两阶段 Finish→Home/full reload，最终 1 天/阅读1/有效复习1/已打卡/due1 与 JSON/DB 一致，summary GET zero-write；task data/sentinel/browser/server/lease 精确清理 | C-04`

`2026-08-12 | C-05 | DONE | Goal branch tip | canonical `/word-senses` + read-only data owner；Feature 5/44、WordSense 210/926、UI guard 5/5、testing health 6/69 与 npm development 全绿。官方 Browser 真实覆盖 cardless confirmed、lemma/中/英字面搜索、查看/reload/loading/error/retry/empty、desktop/exact430 无横溢；Reasonix 首轮 Required 的 LIKE 通配符与超界分页已修复并回归，复审无未清 Blocker/Required；ReviewCard/ReviewLog=0，fixture/sentinel/browser/server/lease 精确清理 | C-04`

`2026-08-12 | C-04 | DONE | Goal branch tip | 单一 `mainNavigation` 驱动 desktop/mobile；guard 7/7、npm development 与 diff-check 全绿。官方 Browser 顺序真实点击四主入口并逐页 reload；Home/legacy Vocabulary/Backup secondary 可达；exact430 四等宽、More 在 bottom 外且 drawer 可达，document 无横溢；admin link exactly1/240×40/href=/admin/real click 成功，ordinary user adminCount=0；task ordinary identity、sentinel/browser/server/lease 精确清理 | C-06`

`2026-08-12 | C-06 | DONE | Goal branch tip | “我的→高级”复用现有 settings layout 集中 6 个既有能力，desktop secondary 与 role-gated admin 仍可达；C04+C06 guard 11/11、build、desktop 六路真实点击、exact430 可见/点击/无横溢与 Console 全通过；server/port/browser/lease clean，health 6/69；fresh review Blocker=0/Required=0 | C-07`

`2026-08-12 | C-07 | DONE | Goal branch tip | 真实修复并复验复习摘要、WordSense 搜索和“更多”44px 触达；1920/900/430/390、Enter、返回、persistent 弹窗焦点/取消、Console/Network 全闭合；Node 40/40、M17 2/11、build、health 6/69 全绿，identity/sentinel/browser/server/lease 精确清理；fresh review Blocker=0/Required=0 | C-GATE`

`2026-08-12 | C-GATE | DONE | Goal branch tip | committed HEAD 9a4d0a8e 上 Node 41/41、PHP 114/626、build、health 与 desktop/430/390 官方 Browser 产品矩阵通过；admin exact one real click 进入 /admin；task identity/sentinel/browser/server/lease clean；未提交 Layout.vue 外部资产排除并保留 | D-01`

`2026-08-12 | D-01 | DONE | Goal branch tip | legacy word-card 创建/评分/词汇/Finish/语言删除/统计/历史依赖与 sense-only barriers 全量 inventory；冻结 persisted-ID-only D-02 分类优先级/reason codes；health 6/69、focused 10/108、lease final false/false；fresh review Blocker=0/Required=0；零数据/产品代码修改 | D-02`

`2026-08-12 | D-02 | DONE | Goal branch tip | persisted-ID-only 只读分类器与稳定 JSON 报告完成；跨域/缺失/冲突/唯一映射优先级及完整依赖可追溯；health 6/69、focused 4/80、protected regression 96/535、lint/command/diff/lease 全绿；双 fresh review Blocker=0/Required=0 | D-03`

### DECISION LOG

只记录会影响后续任务且不是显而易见实现细节的决定：

`date | milestone | decision | evidence | removal/revisit condition`

`2026-08-09 | FND-01 | Goal branch 使用 linked worktree D:\\Document\\lingl\\LinguaCafe-goal-a-h-sol-medium-20260809 | 原主工作树相对 origin/master 的 5 个用户修改文件重叠，直接 switch 会覆盖或冲突；Git worktree 保持原资产不变 | 原主工作树安全清理且 Goal 完成后再评估移除 linked worktree`

`2026-08-09 | FND-03 | Sentinel helper 对受支持的本地 artisan serve acceptance 命令先转换为 127.0.0.1 上的直接 php -S 进程，并复用唯一 cancellation probe | Laravel 当前 ServeCommand 本身只再启动一层 php -S；直接拥有实际 server resource 消除 descendant cancellation blocker，真实 process/port test 通过 | Laravel server entry/CLI contract 变化时复审转换器`

`2026-08-09 | FND-06 | linked worktree 的统一 test bootstrap 重定向共享 optimized Composer classmap、项目 PSR-4 与 APP_BASE_PATH 到当前 worktree | shared vendor 原 classmap 指向主工作树，曾让 integration 测到旧代码；反射回归与全 integration 已证明当前根 | 改为本 worktree 独立 vendor 或 Composer vendor/classloader 布局变化时复审`

`2026-08-09 | FND-06 | reading undo 对同 review_session_id 的 ReadingSession 先取 scoped 行锁，再锁 ReviewLog/card | 与 explicit retry/Finish 的 session-first 锁序一致，真实 retry-vs-undo 并发 27/27 通过；普通 Sense Review 无匹配 session 时语义不变 | review_session_id 或 reading settlement 锁合同变化时复审`

`2026-08-09 | A-01 | 浏览器 acceptance 的直接 php -S 对每个请求先执行当前 worktree tests/bootstrap，再交给 Laravel vendor router，并显式移除 PHP_CLI_SERVER_WORKERS | shared optimized vendor 曾使 HTTP server 解析主工作树旧 classmap；真实 sentinel endpoint、Reader 浏览器验收、注入 worker 变量后的取消/端口回归均通过 | worktree 改为独立 vendor，或 Composer/Laravel/PHP built-in server 布局与进程合同变化时复审`

`2026-08-09 | A-02 | V2 采用最少包数的均衡分包：总数 0–19 保留单包，总数 ≥20 时每包 20–50；严格解析同时保留 typed JSON 形状检查与既有 associative normalization | 固定 50 切块会产生 51→50+1 的不合约尾包；仅 associative decode 会混淆空对象与空数组；边界回归和复审均通过 | 产品冻结的包大小或 PHP JSON 解码合同变化时复审`

`2026-08-10 | B-07 | Reader 可见 Finish 只保留现有 server preflight→用户确认 commit 一条主路，legacy `finish()` 在零 caller 证明后删除 | false-green 测试真实先红；浏览器 eligible/unresolved/offline recovery 与 testing DB exact-once readback 均证明单一路径 | Finish backend contract 或产品两阶段确认语义发生明确变化时复审`

`2026-08-10 | C-03 | 首页签到与任务完成分离：active day=正式 ReadingSessionCompletion 或有效正式 ReviewLog；今天未 active 时 streak 延续截至昨天；checked_in=今天已有任一正式学习活动；CTA 优先 due Sense Review，其次 current-source active reading，最后 /books | 四份 C-03 authority/UX/test/legacy audit 一致确认旧 GoalAchievement、Chapter.read_count、EncounteredWord.next_review 不能作为新首页正式事实，且现有 Review/Reading facts 足够只读派生 | 正式阅读 completion authority、formal ReviewLog 口径或用户明确产品定义变化时复审`

`2026-08-11 | C-04/C-05 | 四主导航最终“生词”不得指向 legacy /vocabulary/search；先完成 current-user/current-language confirmed WordSense 的基础只读 C-05 页面，再回到 C-04 原子接线。移动 bottom 最终仅四主入口，Home/secondary 继续复用现有 drawer；“我的”复用 /user-settings | C-04 Route/Authority、Test、Permission reports + 主窗口实际读取 Layout/Vocabulary/UserSettings/routes；confirmed WordSense 无 ReviewCard 的状态被现有 Reader/FSRS doctor tests 明确覆盖 | 若发现现成 canonical WordSense library route，或用户改变四主导航/生词定义时复审`

不要记录“用了哪个变量名”“跑了哪条普通 lint”之类噪声。

---

## 13. 最终 Definition of Done

整个 Goal 只有在以下全部满足时结束：

1. FND、A、B、C、D、E、F、G、H 的所有完成必需 milestone 均为 `DONE`；
2. 所有 Phase Gate `DONE`；
3. 正式评分、Finish、offline sync、migration、restore 的 idempotency/concurrency/rollback 均有真实证据；
4. Web 主流程有真实浏览器证据；
5. Android 必需流程有真实设备/emulator 证据；
6. iOS 若被定义为最终完成必需，则 capability cluster 已通过真实 macOS/Xcode/device/TestFlight 证据；没有则 Goal 仍为 Not Complete；
7. testing DB/lease/sentinel/server 最终 clean；
8. 没有未解释的 skipped/incomplete/false-green；
9. 无未知 blocker；
10. 所有 Deferred capability clusters 对“最终完成必需项”清零；
11. Goal branch 已正常 push，最终 completion audit 可复验；
12. 未执行任何未授权生产/外部不可逆动作。

达到这些条件后，才输出：

`LINGUACAFE_A_H_GOAL_COMPLETE`

否则必须输出当前最准确状态，而不是“基本完成”。
