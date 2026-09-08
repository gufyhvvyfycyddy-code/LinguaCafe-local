# LinguaCafe → Feable 交接（2026-09-08）

状态：当前 Feable 交接基线
用途：把当前已完成工作、剩余问题、架构优化边界和真实 Gate 一次性交给新的执行方。
重要：本文件里的 SHA、Issue 数量和安全告警数字只是 2026-09-08 的核验结果。每次继续前必须重新读取 GitHub 和当前运行状态。

## 1. 项目定位

LinguaCafe 是阅读优先的语言学习产品。

当前正式学习模型：
- WordSense = 正式学习内容。
- ReviewCard.target_type=sense = 正式 FSRS 调度对象。
- ReviewLog = 真实评分和已冻结审计动作。
- EncounteredWord = 阅读颜色、词形出现、兼容状态。
- WordSenseOccurrence = 来源和例句证据。
- legacy target_type=word = 兼容层，不能未经独立决策删除。
- AI 推荐不能默认自动确认、自动建卡、自动写 ReviewLog、自动改变 FSRS。

当前阶段重点：
源码/安全/架构收口 → current-SHA 真实验收 → Android/iOS 发布 Gate → 生产运维 → 首批真实用户 → 留存/成本/定价。

## 2. GitHub 事实源

Source:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-local

Architecture review:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review

Product / launch:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-product-launch

2026-09-08 交接时 source master：
`fe7328f88972d11331c631302178bc3dc733a030`

该提交合并 PR #51：
Horizon 从旧兼容版本收口到 Laravel 11.56.1 可用的 Horizon 5.47.0，并加入其官方依赖 Sentinel 1.1.0。

2026-09-08 交接时：
- source open PR = 0
- CodeQL open alerts = 0
- Dependabot open = 28（6 high / 18 medium / 4 low）

这些数字继续工作前必须重新查。

## 3. 已完成并可视为正式结果

### 3.1 Horizon / Laravel 11 兼容

已完成：
- 查明 Horizon 旧版 RedisQueue::pop() 与 Laravel 11.56.1 的兼容问题。
- 进一步查明 Horizon 5.29.1 仍缺 Laravel 当前 worker 使用的 `--stop-when-empty-for`。
- 上游核验后升级到 Horizon 5.47.0。
- 只改 `composer.lock`。
- Linux 实际 worker 验证通过。
- 旧 `stop-when-empty-for` 错误计数跨原崩溃周期不再增长。
- PR #51 checks 全绿后合并到 master。

### 3.2 CodeQL mobile 第三方告警

此前 3 条告警来自 Capacitor 安装依赖中的 library 代码。
逐条核验实际可达性和上游实现后，以有证据的 false positive 处理。
当前交接时 CodeQL open = 0。

不要为了保持“0”而关闭扫描或粗暴 paths-ignore。新告警必须重新分析。

### 3.3 Reader / mobile / tokenizer 等近期收口

已完成并已进入主线的重要方向包括：
- Reader AI lookup in-flight 去重。
- Reader 语义空格修复。
- tokenizer reproducible build 与 health/fallback。
- mobile offline queue / replay / idempotency / undo-redo 保护。
- Android release signing gate。
- optional integrations fail-soft。
- Advanced CodeQL + Swift。

历史实现细节请从当前源码、tests、ADR 和 Git history 重建，不要只依赖本交接摘要。

## 4. 当前明确未完成

### 4.1 Architecture #4 — Web-PC current-SHA main user journeys

Issue:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/4

2026-09-08 current master 的 Docker build 已真实通过。
也成功建立过 server-bound testing 容器，并从同一容器内部获得：

`environment=testing`
`database_is_testing=true`
`sentinel_present=true`

但创建“任务专属、非管理员”测试身份时，OpenAI 浏览器工具安全层拒绝真实注册表单填写。
专用 testing DB 中已有账号当时均为管理员，不满足当前 browser playbook 的最小权限要求。

因此：
- Web 只读流程已有大量真实浏览器证据。
- 写入型普通用户 journey 当前仍 Incomplete。
- 不能用 API、数据库 fixture、管理员身份或其他浏览器绕过这次平台拒绝来冒充真实 UI 证据。

### 4.2 Architecture #21 — current UI/UX baseline

Issue:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/21

Web wide/narrow 已有 current UI 的真实操作证据。
current master 相比该 Web 证据基线只新增 `composer.lock` 依赖修复，没有 UI 文件差异。

仍缺：
- 当前 Android 真实业务操作闭环。

### 4.3 Android 工具链

2026-09-08 重新定位到一个重要本机工具问题：

`LinguaCafeM7` x86_64 AVD 原先开启 Fast Boot / snapshot。
上轮表现为：
- emulator 看似启动；
- ADB shell / uiautomator 后续卡死。

本轮用：
- `-no-snapshot-load`
- `-no-snapshot-save`

做冷启动后：
- emulator-5554 正常进入 device；
- `adb shell echo` 连续成功；
- package manager 成功；
- boot_completed=1；
- Android 16 / API 36 / x86_64；
- `com.linguacafe.mobile` 可真实启动；
- UIAutomator 成功读取登录页真实 UI 树。

本机 AVD 已改为：
- `fastboot.forceColdBoot=yes`
- `fastboot.forceFastBoot=no`

这是本机工具修复，不在 Git 仓。

后续还必须做一次“普通启动、不带 no-snapshot 参数”的稳定性复验。

Android 业务登录闭环仍被平台安全层挡住：
- ADR-0053 原计划允许 testing-only 随机密码通过 ADB 逐字符键盘事件输入。
- 实际执行该动作时，被 OpenAI 工具安全层拒绝。
- 当时确认任务用户未创建，未产生业务写入。

因此 Android 当前状态：
- build/install/真实 UI 渲染：有证据。
- current-master 业务登录/Reader/查词/建 sense/Review/评分/undo：仍 Incomplete。
- 不得把 APK/AAB build 当设备验收完成。

### 4.4 Android release / Play

Architecture #18:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/18

Product #4:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-product-launch/issues/4

剩余：
- authorized upload key
- signed AAB
- signer verification
- stable real emulator / physical-device workflow
- Play Console internal testing
- policy / production path

### 4.5 iOS

Architecture #17:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/17

Product #3:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-product-launch/issues/3

已有：
- macOS / Xcode fresh checkout bootstrap
- npm ci
- cap sync ios
- unsigned simulator build
- Swift CodeQL

仍缺：
- Apple Developer authorization
- signing / provisioning
- physical device
- archive/export
- TestFlight
- App Store Connect

这是外部账户 Gate。

### 4.6 Public repo hygiene

Architecture #1:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/1

当前项目规则禁止未经明确授权读取/修改 `.env` / `.env.*`。
如果要处理 tracked environment paths、history remediation、secret rotation，需要单独授权和安全流程。

### 4.7 Source Issue #1–#11

Source 当前仍有 11 个 open Issues。
不要批量关闭，也不要默认都是真问题。

逐个做：
current master → real reproduction → bounded fix / evidence close / explicit Gate。

## 5. Feable 可以继续做的架构优化

可以交给 Feable 完成。

优先优化目标：
1. 找出重复 owner / 重复状态源。
2. 减少同一业务规则在 Controller、Service、Vue/mobile 多处复制。
3. 把难测试的接口边界做窄。
4. 收紧 public API / module contract。
5. 把真实错误恢复路径做成单一、可观测流程。
6. 清理已经证明确实无调用的 legacy/temporary code，但删除 legacy target_type=word 等冻结兼容层必须单独决策。
7. 继续减小 Reader、Review、mobile sync、Settings 等高频路径的复杂度。
8. 优先修“会让 AI/人以后误改”的架构边界，再做纯风格重排。

架构优化硬边界：
- 文件大不是重构理由。
- 不为了追求“更现代”改框架。
- 不在同一任务里顺手重写产品流程。
- 不改变 FSRS / ReviewLog / WordSense 语义。
- 不创造第二套正式数据真相。
- 不把移动端改成独立完整本地权威数据库。
- 不增加没有现实失败证据的 fallback 链。
- 所有非 trivial 架构改动必须有 tests + code review + real runtime evidence（如适用）。

## 6. 推荐给 Feable 的第一批工作

按顺序：

### P0 — 重新读取实时状态
- source master
- source/architecture/product open issues
- open PR/checks
- CodeQL
- Dependabot
- Docker/Android/iOS tools

### P1 — Android 验收工具链
- 验证默认 cold boot 设置是否让 ADB 长时间稳定。
- 修复/替代可合规的低权限 testing identity 输入通道。
- 完成 current-master Android 真 UI login → Reader → lookup → create sense → Review → rating → undo → summary。
- 精确 cleanup。

### P1 — Web-PC #4
- 解决普通低权限测试身份真实 UI 建立问题。
- 完成 current-SHA browser write journeys。
- 关闭 #4 前必须 MCP Chrome/真实浏览器证据完整。

### P1 — Architecture #21
- Android 真实操作补齐后重新汇总 UI friction。
- 保留当前已发现的 Reader parity / daily hierarchy / review density / Settings accessibility 问题作为候选，不要在没有重新验证时直接改。

### P1 — Source #1–#11
- 一项一项重现。
- 真问题小修；失效问题写证据关闭。

### P1/P2 — 架构优化
- 只围绕真实 seam、重复 owner、测试困难和可观测性。
- 每次一个小闭环，避免一次性大重构。

## 7. 生产与产品 Gate

Product repo 当前仍有：
- hosting / backup / recovery
- privacy / support / account deletion
- first 10 users
- onboarding
- activation / retention analytics
- channels
- incident response
- deployment region
- content rights
- cost model
- pricing
- store listing
- investor timing

这些不应被代码重构自动“完成”。

## 8. 安全和仓库规则

必须：
- isolated worktree 优先。
- exact stage。
- focused tests。
- required build。
- code review / doubt review。
- real browser/device evidence。
- GitHub checks。
- merge 后重新读 remote master。

禁止：
- reset / clean / stash 用户主 checkout
- bulk stage / bulk commit
- `--force`
- 读取或修改 `.env`
- 修改 `AGENTS.md`
- `migrate:fresh`
- `db:wipe`
- 清库
- notification script / notify.ps1
- DCP（默认 false）
- 用 curl/API/静态代码代替要求的真实浏览器验收
- 用 build 成功代替 Android/iOS 商店验收

## 9. Feable 接管方式

建议先执行：
1. clone Source repo。
2. 读 `FEABLE_START_HERE.md`。
3. 读本文件。
4. 打开 Architecture/Product repo 的 open Issues。
5. 重新核验 master/alerts/checks。
6. 先做一个最小闭环，再继续下一项。

不要从历史聊天猜项目状态。
不要重新开发已经正式完成的里程碑。
