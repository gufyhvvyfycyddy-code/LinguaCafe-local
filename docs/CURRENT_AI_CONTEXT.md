# LinguaCafe 当前 AI 最小上下文

> 状态：Current / Minimal Context
> 日期：2026-08-31
> 用途：新任务先读本文件，再按 `docs/DOCUMENTATION_INDEX.md` 加载一个相关模块。不要默认读取完整 master plan、handoff、热点审计、全部 ADR 或全部字幕。
>
> **2026-08-31 current overlay**：当前工作不再由旧 recovery `CURRENT_MILESTONE.json` 或旧 Anki-aligned roadmap 决定。当前产品权威仍是 `docs/product/LinguaCafe_Product_Rebaseline_English_Reading_First_2026-08-18.md`。Goal plan 已完成 Phase G/G-GATE、`H-00`–`H-09` 与 **H-11 final Web + Android regression**；H-11 backend/runtime repair commit=`eaab88a7a7d96d2c1078b4b5243210430a305970`，报告见 `docs/testing/h11-final-web-android-regression-acceptance-2026-08-29.md`。2026-08-30 用真实 Android 16 / API 36 补齐 E-06/E-07 原生 capability gap，closure commit=`5448554e10fe6df093ac8fa1dd3832a35e312359`，报告见 `docs/testing/e06-e07-native-android-capability-closure-2026-08-30.md`。随后通过 public `origin` 的标准 GitHub-hosted `macos-26` runner 持续执行 H-10 continuation：Xcode 26.6、Mobile 42/42、Capacitor iOS sync、generated-Web integrity、M9 guard、SwiftPM、Simulator build 与 iPhone 17 Pro Simulator boot/install/launch 均已真实 PASS；run `33268124499` 完成 rendered login-shell / Accessibility / tap+input / local-HTTP warning 黑盒验收，run `33268819125` 在保持同一 smoke PASS 的同时把 Simulator cold boot 与 build 工作重叠。随后 run `33279140695` 在 same-runner testing MySQL + native FSRS + PAB sentinel 下完整通过 rendered 登录、Keychain token save/load、relaunch 自动恢复、server token/device owner、普通 Preferences 无 Web session token、UI 设备撤销、token clear 与再次 relaunch 回登录页；run `33282205923` 又通过真实 iOS Sense Review `显示答案 → 良好 → 下一卡 → Undo → 原卡恢复`，testing DB 证明 ReviewLog/operation ledger exactly-once 且 Undo 后 FSRS fingerprint 与评分前精确相同；run `33301226295` 随后通过真实 iOS Reader 竖屏 token→canonical reading-origin new Sense/source binding→existing-Sense `认识 / 记得`/`不认识`，以及横屏 `bank account` 长按拖选词组；run `33308079898` 又通过真实系统 Files picker 的 `.txt` import，覆盖 unsupported extension disabled、invalid UTF-8、oversize、valid UTF-8 import、exactly-one server action/request、MySQL/PAB 与 cleanup。稳定四文件结果已从 fresh Goal 重新提取并以 `6028453a899bdaf03f743d9c8b918ea4e4cbd236` fast-forward 推送。2026-08-31 final full run `33350591521` 又在同一 iPhone 17 Pro Simulator + testing MySQL/native-FSRS/PAB 栈上完整通过登录、Reader、横屏词组、真实 Files 导入、offline article/review/audio cache、离线 Good 入队、重启保留、reconnect exactly-once sync、MySQL/PAB corroboration 与严格 cleanup；final run 的 sync 请求从 1 精确变成 2，且所有 9 个 XCUITest session 均 PASS。随后 release-capability run `33355499203` 又在 Xcode 26.6 下验证 `arm64` capability、iPhone 17 Pro 与 `iPad Pro 13-inch (M5)` 串行 rendered login-shell smoke，并生成 `2064x2752` 的 13-inch iPad 截图；这关闭 universal Simulator 渲染缺口，但不替代真机/签名/商店证据。见 `docs/testing/h10-macos-xcode-simulator-capability-continuation-2026-08-30.md`。**H-GATE 仍为 DEFERRED / Not Complete**：Simulator capability cluster 已 Stage Accepted，不再有已知 Simulator blocker；剩余缺口只在 signed physical iPhone、physical haptics/notification/audio/safe-area、physical Keychain、Apple signing/archive、TestFlight/App Store/App Review。用户已明确启用单窗口直接执行；fixed DIRECT/四窗口流程仅在用户重新启用并行模式时恢复。Reader 的机会式提前 Good、跨文章/跨 session 的完整 24h 正向最小间隔、同 session/card 单次计分、existing-Sense“不认识”→Again 继续以 ADR-0061 与 ADR-0063 为准；AI matched-existing source binding/full-pool rotation 继续以 ADR-0062 与 ADR-0064 为准。

## 1. 当前代码事实

- 当前仓库：LinguaCafe 主开发 checkout；实际本机路径由执行环境解析，不写入仓库文档。
- 当前 Goal 工作分支：`goal/linguacafe-a-h-sol-medium-20260809`；执行新任务仍必须 fresh Git preflight，不把文档 SHA 当永久实时值。
- 2026-08-18 已推送产品重基线 commit：`5bf4b8659904de2eaa73109f75cec5c4c5a654eb`（`docs: rebaseline phase g product direction`）。后续本文件中的 2026-08-06 `master` / M0–M18 SHA 记录仅用于历史追溯。
- 当前工作树仍包含多组未提交用户资产，覆盖测试、文档和生成材料；不得批量删除、整体忽略、reset、stash、checkout、clean 或自动纳入提交。精确数量与类别以 `node scripts/workspace-inventory.mjs` 和 `git status --short` 的实时结果为准。
- 2026-08-06 提交前 PHP 最终质量基线：Unit 727 tests / 1778 assertions / 0 failures；Feature 2811 tests / 13306 assertions / 0 failures / 14 skipped / 64 PHPUnit 12 metadata deprecations。Feature 必须直接使用 `php -d memory_limit=512M vendor/bin/phpunit tests/Feature`；`php -d memory_limit=512M artisan test` 的父进程参数不能可靠约束其 PHPUnit 子进程。
- Feature 全套曾因 `ReviewCardMarkerMigrationTest` 的 MySQL DDL 隐式提交留下 1 条 ReviewCard，导致后续 11 项顺序依赖失败；测试已增加显式清理，完整共享进程回归已恢复为 0 failures。
- 2026-08-03 已把 `.playwright-cli/`、`output/`、截图目录、临时登录页面、Cookie 捕获、根目录一次性 PHP/Python 调试脚本等本地产物加入 `.gitignore`。这一步只降低 Git 噪声，不删除任何已有文件。
- 后续收口必须先运行只读工作区盘点，再按一个功能切片一组地核对代码、测试、迁移和验收文档；不得自动清理、覆盖或把无关改动混入同一提交。
- 历史 recovery roadmap / `docs/execution/CURRENT_MILESTONE.json` 只描述已经关闭的 recovery-publication program，不再是当前 Goal 授权入口。2026-08-27 用户明确启用单窗口直接执行；当前窗口按 Goal roadmap 连续推进已命名 milestone。若以后重新启用 fixed DIRECT/四窗口并行，再恢复“执行窗口完成当前 DIRECT 后停止”的默认并行规则。

本文件记录的是本地工作树事实。远端 GitHub、Agent 报告或旧交接与本地不一致时，先说明差异，不能擅自 reset、merge 或把任一方冒充唯一事实。

## 2. 产品主线

LinguaCafe 当前是 **English-only、reading-first、Sense-first** 学习系统：

- `WordSense` 是正式学习内容。
- `ReviewCard.target_type=sense` 是正式 FSRS 调度对象。
- `ReviewLog` 只记录真实评分和已冻结的审计动作。
- `EncounteredWord` 负责阅读颜色、词形出现和兼容状态。
- `WordSenseOccurrence` 保存来源和例句证据。
- `target_type=word` 是 legacy 兼容层，不进入新功能主线，也不得未经独立决策删除。
- AI Reading Assist 首版继续是“导出提示词/包 → 用户自己的外部 AI → 粘贴 strict result”；相同或实质相同的已有 WordSense 必须 `matched_existing`，不能只因措辞不同制造新 Sense。
- AI 推荐默认不选，中文释义必须由用户确认；默认不得自动建卡、写 ReviewLog 或改变 FSRS。用户确认或 Trust-AI high-confidence 权威接受 `matched_existing` 后，当前**真实 Reader 句子**必须通过现有 WordSenseOccurrence owner 成为该 Sense 的来源例句。所有去重后的真实来源例句都长期进入同一个例句池，不设 10 条/20 条等 top-N 上限；正式复习从完整池随机轮换，多例句时不得连续出现同一 question example。AI 不得生成虚构例句，retry/correction 不得把同一来源留在两个 Sense 池。
- 已学 review-state exact Sense 可以在当前 `fsrs_due_at` 之前因真实阅读回忆提前完成一次正式 Good，但距上一笔有效 non-undone 正式评分必须先满 **24 个实际小时**。不足 24h 无论同篇、跨篇还是新 ReadingSession 都只记 exposure，并且普通 Reader **完全静默**：不弹窗、不 snackbar、不 inline 提示、不 badge、不显示“本次不计复习”或倒计时。满 24h 后自然且无需帮助的 reliable binding 可在 Finish 记 `reading_passive Good`，用户主动点开、自己认出并确认 exact Sense 后选择“认识/记得”可记 `reading_explicit Good`。同一 ReadingSession / ReviewCard 最多一笔正式 reading rating，同篇 10 个 occurrence 也不能叠加。
- 已学 existing Sense 在本次阅读被明确标记“不认识”后，exact Sense 确定时最多写一次 `Again`；真实 failure 不受正向 24h floor 阻止。同一 ReadingSession 后续再遇到并点“认识/记得”不能翻成正向评分。`new_sense` 第一次建立不伪造 Again/Good。Reader 不承担同日 learning/relearning 短步骤，也不新增第二 scheduler/reader_due。

### 2.1 云端主导移动化路线

2026-07-28 用户要求存档新的战略规划：Laravel 与中央数据库继续作为账号、WordSense、ReviewCard、ReviewLog、FSRS、文章和 AI 数据的权威来源；网页端承担完整管理；Android/iOS 承担日常阅读与复习；移动端采用文章包、短期复习包和离线操作队列形成有限离线，不建设 Anki 式完整本地权威数据库与 collection 合并协议。

当前权威计划：`docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`。

M1：Mobile API Foundation 与正式评分幂等已 `Accepted / Closed`。实现、
API/ADR、testing MySQL 自动化与 Web 评分 HTTP/数据库兼容回归已通过；M5
testing server-bound 真实 `/reviews/senses` 页面验收只产生一次 POST、一次
ReviewLog 与一次 ReviewCard 调度更新，补齐原 deferred Web UI seam。证据见
`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md`。
M2 Operation Ledger 已按 ADR-0035 完成并验收关闭：Mobile 正式评分已接入
中央 operation/change ledger、最近操作查询、线性 LIFO undo/redo、幂等和
version/state 冲突保护；旧 Web rating/undo seam 保持不变。证据见
`docs/testing/mobile-operation-ledger-acceptance-2026-07-28.md`。ADR-0036 将 M6
冻结为 M6A 安全备份、M6B 恢复安全、M6C 文章健康、M6D 隔离审计。
M6A–M6D 均已通过聚焦测试、受保护回归、所需前端构建、真实页面和清理审计，
状态为 Accepted / Closed；证据见
`docs/testing/m6a-safe-backup-acceptance-2026-07-28.md` 与
`docs/testing/m6b-restore-safety-acceptance-2026-07-28.md` 与
`docs/testing/m6c-article-health-acceptance-2026-07-28.md` 与
`docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md`。M6 整体
Accepted / Closed。M10 的统一 Criteria、WordSense Tag、Browser/Saved Search/
Study Overview/export parity 与移动只读搜索也已 Accepted / Closed，证据见
`docs/testing/m10-unified-search-tags-browser-foundation-acceptance-2026-07-28.md`；
M11 Review Control 与手动调度已 Accepted / Closed，证据见
`docs/testing/m11-review-control-manual-scheduling-acceptance-2026-07-29.md`；
M12 Special Study Sessions 已 Accepted / Closed，证据见
`docs/testing/m12-special-study-sessions-acceptance-2026-07-29.md`；
M3 Article Package + Short-term Review Package V1 已 Accepted / Closed，证据见
`docs/testing/m3-mobile-download-packages-acceptance-2026-07-29.md`；
M4 Sync Queue + Conflict Simulator 已 Accepted / Closed，证据见
`docs/testing/m4-queued-action-sync-acceptance-2026-07-29.md`；M5 Mobile
Reader / Reviewer Touch Adaptation V1 也已 Accepted / Closed。M7 的 2026-08-01
Android 12 模拟器范围与 M8 已 Accepted / Closed；2026-08-06 从 `f243a9c`
重新构建的 debug APK 通过测试和构建，但当时没有设备或模拟器连接，因此该精确
APK 没有最新设备复验，也不代表 release/AAB/签名/Play Store。M17 的 Web slice
已关闭，其 Haptics/Notifications Android 事实属于 M7 平台证据；M18 的共享实现与
Web/Android 离线音频证据已关闭。M10–M16 同样均已关闭。
M9 的 22 个 iOS source/config 文件已在 `4be6c39` 入库。2026-08-30 continuation 已在真实 GitHub-hosted macOS/Xcode 26.6 环境重新执行 `cap sync ios`，并通过统一 generated-Web integrity、SwiftPM resolve、Simulator compile、iPhone 17 Pro Simulator boot/install/launch、rendered 登录壳 Accessibility / 用户输入 / 本地 HTTP 警告 smoke，以及 same-runner testing backend 下的真实登录→Keychain save/load→relaunch→server token owner→UI revoke→token clear→relaunch 回登录；run `33282205923` 进一步通过正式 Sense Review Good/Undo 和 exact FSRS restoration；run `33301226295` 又通过真实 Reader 竖屏 token→canonical reading-origin Sense/source binding、existing-Sense `认识 / 记得`/`不认识`、横屏 `bank account` 长按拖选词组；run `33308079898` 进一步通过真实 iOS Files picker `.txt` import 全矩阵；final full run `33350591521` 又关闭 offline article/review/audio cache、离线 Good 入队、relaunch persistence、reconnect exactly-once sync 与 stable empty queue，并重新覆盖 login/Reader/Files 全矩阵。iOS source/native compile/basic simulator/rendered-shell/authenticated Keychain lifecycle/formal Review Good+Undo/Reader touch+source binding/Files `.txt` import/offline+reconnect Simulator capability 已有完整真实证据；剩余 `Not Complete` 能力簇只在 signed physical device、physical Keychain/haptics/notifications/audio/safe-area、Apple signing/archive、TestFlight 与 App Store/App Review。

Anki 兼容扩展已细化为 M10–M18：统一查询/标签/Browser、手动调度治理、专项学习会话、复习设置与负担模拟、Statistics/Card Info、Browser 数据治理、`.apkg` 与便携数据、自动推进/无障碍、媒体与离线音频。它们不要求等待 iOS 完成，但分别依赖 M1、M2、M6、M10 或移动端基础；旧 Tag/Statistics/Custom Study/Browser V2/Export 条目不得作为第二套重复任务执行。

## 3. 大计划完成情况

历史 Anki 对齐的仓库里程碑已经完成；当前 forward 产品工作进入 Goal Phase H：

1. Settings 架构收敛：Production Closed。
2. Preset V1A–V1D：Production Closed。
3. Browser / ReviewCardManage Phase 3A–3D：Production Closed。
4. Card Marker + Custom Study 1B：Production Closed。
5. Reviewer 架构收敛：Production Closed。
6. Reader Phase 6A–6M：Production Closed。
7. AI Study Card service Phase 7A–7E：Production Closed。
8. Provider Environment Gate：以 default-off / fail-closed 形态关闭。

“历史里程碑完成”不代表全产品完成。Phase G 与 G-GATE、H-00 deletion-first convergence、H-01 load/observability harness、H-02 representative 100-user load、H-03 bottleneck diagnostics、H-04 backup/restore drill、H-05 isolation/privacy boundary、H-06 public authentication convergence、H-07 public runtime/cost gate、H-08 public package/content-rights gate、H-09 Android release readiness 与 H-11 final Web + Android regression 均已 DONE。H-10 iOS capability cluster 当前仍为 DEFERRED / Not Complete，但 Simulator subset 已 Stage Accepted：真实 Xcode/SwiftPM/Simulator compile+launch + rendered login-shell + authenticated Keychain session lifecycle + formal Sense Review Good/Undo + Reader touch/source-binding + real Files `.txt` import + offline/reconnect exactly-once sync 均已关闭。当前 forward boundary 仍是 H-GATE；必须继续保留 physical device、physical Keychain/haptics/notifications/audio/safe-area、signing/archive/TestFlight/App Store/App Review 缺失能力，不能因 Simulator cluster 全绿而声明完整跨平台 Goal 完成。

## 4. 当前本地维护账本

详细原因、影响和验收标准见：

`docs/plans/local-experience-bug-optimization-ledger-2026-07-23.md`

建议修复顺序尚未获得实现授权：

1. P0：恢复 Python tokenizer，建立统一启动、健康检查和导入前阻断/警告。
2. P1：修复首次账号目标初始化与统计读取竞态。
3. P1：补生僻词悬浮空状态。
4. P1：手动词义表单不再无条件默认 `verb`。
5. P1：URL、邮箱、路径等不可学习片段不得污染词汇。
6. P1：fallback 必须尊重段落、换行和 section 句界。
7. P1：普通阅读模式 DOM 必须包含真实空格。
8. P1/P2：Jellyfin 等可选集成缺失时返回安全默认值，不抛 500。
9. P2：重复查词请求、WebSocket 失败重连和其他控制台噪声收敛。
10. Historical Product Accepted：自动备份恢复、WordSense Tag、统一搜索、统计 V2、`.apkg`、文章健康、Browser V2 等能力已有历史实现/验收；2026-08-18 forward user-surface 是否继续保留由 Product Rebaseline / G-06G 重新分类，不再把旧“已接受”直接等同于当前用户功能。
11. P0 工具链：Reasonix 监督发送、排队与直接引导尚未具备事务性可靠性。当前 Playwright 监督台 + WinApp UIA + 会话日志只属于 `Workaround Active`；超时后必须按唯一 marker 对账，未在当前轮次找到 `role=user` 消息时不得宣称引导成功。所有 workaround 必须保留根治任务和退出验收。详见 `docs/plans/reasonix-supervision-toolchain-bug-ledger-2026-08-05.md`。

旧产品决定与讨论来源仍可从 `docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md` 追溯；其 Tag/Saved Search/Browser/Manual Scheduling/Reader four-rating 等与 2026-08-18 Product Rebaseline 冲突的 forward 结论已经 supersede。通用 Note Type/Card Template、任意 Deck/Subdeck 和 sibling cards 仍不进入当前产品计划。

账号治理规则已登记：保留一个永久本地主管理员，开发数据库用户总数不超过 10；临时测试用户完成后清理。具体账号信息只留在本地任务上下文，不复制到公开文档或报告。

## 5. 当前代码规模

下表保留的是 **2026-07-23 历史对比快照**，只用于理解项目相对原项目的量级变化，不代表 2026-08-06 或当前工作树的实时计数。实际当前数量必须重新运行工作区盘点；统计不含 `vendor`、`node_modules`、构建产物和未跟踪临时文件：

| 区域 | 官方原项目快照 | 2026-07-23 本地快照 | 增量 |
|---|---:|---:|---:|
| 后端 PHP | 13,543 | 46,233 | +32,690 |
| 前端 JS/Vue/Sass | 28,993 | 50,020 | +21,027 |
| migration / seeder | 2,982 | 3,971 | +989 |
| Python tools | 1,055 | 1,816 | +761 |
| **生产与工具合计** | **46,573** | **102,040** | **+55,467** |
| 测试 | 214 | 93,128 | +92,914 |
| Markdown 文档 | 1,178 | 38,788 | +37,610 |

2026-08-06 工作树抽查的主要热点：

- `TextBlockGroup.vue`：当前工作树约 2,141 行，Reader DOM、选择和副作用编排。
- `SenseReview.vue`：当前工作树约 1,389 行，正式复习会话编排。
- `CustomStudySessionState.php`：约 1,176 行，状态职责集中但体量大。
- `DictionaryImportService.php`：约 1,157 行，多格式导入和数据切换风险。
- `TextBlockService.php`：当前工作树约 1,077 行，Reader/import 兼容门面。
- `Review.vue`：约 1,025 行，legacy Reviewer。
- `ReviewCardManage.vue` 已收敛到当前工作树约 776 行，但仍是管理页的高影响入口，修改时继续使用现有子组件边界和页面验收。

两个仓库没有共同 Git 祖先，因此代码增量是快照差值，不是提交历史意义上的精确新增行数。详细对比见 `docs/architecture/upstream-code-test-and-architecture-comparison-2026-07-23.md`。

大文件本身不自动构成重构任务。只有当前 Bug 触及该职责、现有 owner 不清或无法可靠测试时，才拆一个可验证 seam。

## 6. Bug 与架构的关系

当前问题不是单一“大组件导致一切”，主要分为六类：

1. **启动与默认值所有权缺失**：tokenizer、Jellyfin、Anki、reviewIntervals、首次 goals 初始化分散在页面访问和运行时补种中。
2. **导入前置条件不完整**：服务健康、特殊片段识别、结构边界和原子词典替换没有统一 preflight。
3. **语义文本与视觉渲染混用**：CSS margin 提供视觉空格，但 DOM 没有语义空格。
4. **展示状态缺口**：词典无结果、功能未配置、搜索失败没有统一状态模型。
5. **副作用所有者重复**：桌面侧栏和弹出词汇框各自加载同一 AI/词典上下文，缺少共享请求键和在途复用。
6. **可选集成未 fail-soft**：未配置的扩展功能被当成服务器故障，产生 500 和控制台噪声。

推荐目标结构见：

`docs/architecture/code-documentation-and-bug-architecture-audit-2026-07-23.md`

## 7. AI 文档加载规则

每轮只加载：

1. `AGENTS.md`。
2. 本文件。
3. 一个任务相关 ADR / 模块契约。
4. 将修改的源码、调用方和相关测试。
5. 一个既有验收范例。

只有需要追溯历史决定时才读取：

- `docs/plans/current-working-handoff.md`
- `docs/plans/linguacafe-master-plan.md`
- `docs/plans/repo-architecture-hotspot-audit.md`
- `docs/history/`

这些文件仍包含被旧文档 guard 锁定的历史正文，不能作为当前状态的第一入口。

## 8. 当前执行方式与 Guard 收敛状态

当前 LinguaCafe 使用 fixed DIRECT 并行闭环：

1. 主窗口负责 fresh Git/报告验收、产品/架构判断和下一批 4 份 DIRECT。
2. 四个 GPT-5.6 Sol 执行窗口按真实依赖图并行；无依赖立即工作，只有真实 predecessor 才等待。
3. OpenCode 是免费辅助层：`opencode/deepseek-v4-flash-free` → `opencode/mimo-v2.5-free`；两个 free 都不足且确需更强独立审查时，才允许 Reasonix `opencode-go/mimo-v2.5`。
4. Codex 新任务只有用户当前明确点名授权才可创建；已授权运行中的 Codex 可以监督/复核。
5. FastCtx/DevSpace 是本地文件/Git/命令首选；本地 Agent 运行时 owner 继续做独立工作，不空等。
6. 每个执行窗口完成当前 DIRECT 后停止，不自动领取下一任务；主窗口验收后只生成下一批提示词，用户启动后才进入下一实现批次。
7. 同文件 writer、正式 ReviewLog/FSRS 写链、shared testing DB writer、Git integration owner 仍必须事实串行。

CodeBuddy / WorkBuddy 旧固定接力不是当前默认流程；只有用户以后明确重新启用时才按当轮授权处理。

文档 guard 收敛：

1. ✅ `MasterPlanIntegrityContract.test.mjs` 已更新：不再锁定旧代码规模，转为验证当前权威状态和已关闭里程碑。
2. ✅ `ReviewCardManageArchitecturePlanGuard.test.mjs` 已更新：不再锁定精确行数或旧阶段文案，转为验证语义所有权边界。
3. handoff 和 master plan 仍包含大量历史完成报告。它们继续作为追溯材料，不再作为新任务默认上下文。新增状态只能先写入本文档或当前模块文档，禁止继续把整段执行报告堆回 handoff 顶部。
4. 工作区收口优先于继续扩大产品范围：先把现有正式改动按功能切片盘点、验证和存档，再进入新的大功能。

未来方向仅登记为规划：网页端 GPT 制定计划，把任务拆给网页端 DevSpace 和本地 Codex++；Codex++ 接入 DeepSeek Flash，并可进一步指挥接入同一 API 的扩展并行执行。该能力尚未完整实现，不能作为当前可用工具链或验收事实。

## 9. 字幕原则

本轮从用户上传的 8 份 `.srt` 中提炼了以下稳定原则；未读取任何压缩包：

- 上下文容量有限，当前事实和历史证据必须分层。
- Spec 记录已经稳定、反复相关、违反代价高的决定；探索期意见不能提前冻结。
- Harness 锁定少数承重不变量，必须覆盖正常路径和拒绝路径。
- AI 更擅长延续清晰架构，不能替代产品与架构判断。
- 模块化按责任和数据流进行；过度拆分也会增加接口成本。
- 测试困难通常提示责任、输入输出或副作用边界不清。
- 每次只做一个可独立验收的小切片，最终验收发生在 Agent 输出之外。

## 10. 历史 roadmap 状态与当前停止点

- 历史持续目标按云端主导、有限离线路线推进 M0–M18；这是已完成工作的路线记录，不是当前 Phase H 的执行顺序。
- 当前执行边界以 Goal Phase G + fixed DIRECT 为准。主窗口可以规划下一 milestone，但执行窗口不能自行 auto-advance；任何新实现批次都由用户实际启动对应 DIRECT。
- M1–M8、M10–M16 已 Accepted / Closed；M17 Web slice 已关闭，Android Haptics/Local Notifications 证据由 M7 平台验收持有；M18 共享实现与 Web/Android 离线音频证据已关闭。M9 source/config 与发布材料为 Implementation Accepted。
- M5 testing-bound 真实 `/reviews/senses` 页面评分已清零 M1 deferred seam。
- 2026-08-01 booted Android 12 模拟器完成了 M7/M8 以及相关 Haptics/Notifications/离线音频平台证据；2026-08-06 最新重建 debug APK 没有设备复验。当前唯一未完成的产品能力簇仍是 M9 iOS sync/Xcode/签名/模拟器/真机/TestFlight/App Store，且 sync 前必须清除旧 generated bundle 与 sourcemap。
- M0–M18 最终审计见
  `docs/testing/m0-m18-goal-completion-audit-2026-08-01.md`：仓库内实现切片已经清零，
  整体目标在上述 iOS 能力簇取得真实证据前仍为 `Not Complete`。
