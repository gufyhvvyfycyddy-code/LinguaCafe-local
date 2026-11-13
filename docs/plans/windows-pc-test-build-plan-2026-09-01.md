# LinguaCafe Windows PC Test Build Plan — 2026-09-01

## Purpose

The A–H roadmap has reached the remaining Apple physical/signing/distribution boundary. Windows PC test work is an independent product-validation lane that can continue without pretending H-10/H-GATE are complete.

This lane gives the product owner a real Windows desktop executable for daily hands-on testing. It reuses the canonical Laravel/Vue application instead of creating a second desktop business implementation.

## Development continuation decision — 2026-11-12 (supersedes old-build startup dependency)

The installed 2026-09 PC Test is historical evidence, **not the supported prerequisite** for continued LinguaCafe development. Its Web/MySQL/Redis/Python containers are stopped; past runs found stale Laravel provider cache, a schema/migration mismatch, and an old installed runtime ZIP whose filename inventory includes confidential environment configuration. Do **not** publish or revive that runtime as if it were the current reviewed source.

**Authorized path, ordered by dependency:**

1. Perform a bounded read-only legacy status, database/owner and port check. If startup still fails or restoring the old runtime requires modifying unclear old volumes, label **B04-LEGACY=BLOCKED**, preserve the old installation and volumes, and immediately move to the fresh-build branch. Do not spend repeated cycles on legacy recovery. Product E/F/K work can proceed independently from a current reviewed GitHub Goal source in a clean worktree.
2. Repair the I05 packaging source pipeline before any new distribution. Exclude *all* `.env*` basenames (including `.envrc`, `.env_backup`, `.env-prod`, and nested copies), credentials, private keys and agent/browser artifacts from Git archives, ZIP members and Docker build context. Verify the ZIP and Web/Python image source belong to the same reviewed commit SHA. Treat an unexpected filename as a hard failure; never read or print secret content. Do not reuse the known-risk legacy installed ZIP.
3. Build a **new** Windows WPF/WebView2 PC Test against the reviewed current Goal/product revision and a newly identified isolated Compose project/database volumes. Stage all artifacts and confirm build/ZIP integrity before switching the installed `app`. Preserve the prior installed `app`, prior backups, and previous shortcut with a recoverable changeover; never delete them beforehand. Test a second consecutive upgrade and forced failure rollback.
4. Start the newly built PC Test through the normal WPF shell; verify the specific bound MySQL/testing instance, login and real UI Reader → dictionary → WordSense → Sense Review. For completion, check the remaining full study workflow against the unique MASTER; no HTTP-only or script-only substitutes.
5. Keep **B04, I05 and G07 open** until their own running-installation GUI/rollback/packaging evidence passes. A new build's success does not retroactively mark the old PC Test recovered; historical I-01–I-04 refer to September 2026 and are not proof that today's installation works.

Authorized by the user's explicit 2026-11-12 04:53 request. The single authority for task status and runtime permissions remains `D:\AI-Tools\STUDY_WORKFLOW_MASTER_TEST_AND_HANDOFF.md` and `docs/execution/CURRENT_MILESTONE.json`. No Git force push, publication of old secret-bearing archives, editing unrelated personal databases, or overwriting dirty original worktrees.

## 2026-11-13 实际 Windows 环境恢复经验（RUN203：安装与交互桌面分开验证）

**适用当前新版 PC Test 和后续 B04/I05/G07，权威任务状态仍只看唯一 MASTER。** 这不是新的平行计划，现场证据记录在 MASTER RUN203。已实测的源码提交为 b5aff4a9 -> 修复后 760028d09fe23cd26eed68bf8368f92c9f9096a3；该提交在受审任务分支，不代表 master 已升级。

1. **DevSpace 的安装器拒绝不是 Windows UAC**。历史提示为 “nested instruction discovery ... paths cannot be resolved statically”。先通过 DevSpace 正式 open_workspace 打开确切安装脚本所属的干净源码 worktree，遵守返回的 AGENTS；直接使用同一 DevSpace start_job 中可静态识别的 PowerShell 字面绝对路径运行安装脚本，例如 & 'C:\Users\Administrator\.devspace\worktrees\LinguaCafe-main-b5877502\scripts\windows\install-pc-test.ps1'。避免再包一层 powershell.exe -Command/-File 让平台无法解析内层目标。**这是平台本身明确推荐的合法归属修复，不是关闭安全规则或换工具规避拒绝**。如果直接形式再次明确被拒，只停本动作，继续其余合法独立任务。
2. **安装器真实 Docker revision 兼容故障已经修复**。原生 PowerShell 会丢失 Docker Go-template 的复杂嵌套引号，产生 “function org not defined”。新代码先以 Docker --format '{{json .Config.Labels}}' 获取 JSON，再在 PowerShell ConvertFrom-Json 后取 org.opencontainers.image.revision。该修复已提交 760028d0，原 WindowsPcTestBuildGuard 真正 PASS，Web/Python 镜像 SHA 在本机实测一致；安装器第二次真正执行 exit0，将 EXE、runtime-source.zip 和 SHA marker 发布至 LocalAppData\LinguaCafePCTestNext\app-760028d09fe2，原桌面快捷方式备份仍保留，旧 App 目录未提前删除。
3. **后台 DevSpace 进程 Session 0 不是交互桌面**。直接通过 DevSpace 后台启动 WPF 曾得到 0x80070578 “无效的窗口句柄”：真实 Get-Process 显示 LinguaCafe PID15228 属 Session0、Explorer 属 Session1。不得为了测试通过而关闭 WebView2、降低 Windows 权限或把 HTTP200 冒充 GUI。应优先由已登录用户在真实 Windows 桌面双击现有 “LinguaCafe PC Test” 快捷方式；若要让自动化启动 GUI，可采用 Windows Task Scheduler 正规 **InteractiveToken + Limited** 一次性 on-demand 任务，且必须先查 desktop Explorer 的真实用户和 SessionId、确认唯一任务名称不存在，action 固定指向已安装版本的 EXE，**不存密码、不提权、不创建通知/重复任务**。任务运行后验证 WPF 进程为与 Explorer 一致的 Session1 且 startup.log 到 “PC 测试版已就绪：/”，只卸载/注销本次精确命名的临时调度任务，保留用户正在运行的应用。确切用户/任务名/安装路径每轮重新读取，不把本轮机器账号写成通用常量。未经用户或当前任务授权不得更改其它计划任务、Windows 服务/ACL 或账户；如果 InteractiveToken 无效，就交给用户手工双击快捷方式。
4. **上述交互式正式方法已有本机端到端例证**：临时 Task Scheduler 名称 LinguaCafe-PCTest-Interactive-Run203，以当前 Explorer 的同一 Windows 用户、LogonType=Interactive、RunLevel=Limited 启动安装 EXE，WPF PID48540 实际属于 Session1；桌面启动日志顺序证实 Docker、数据库/词典检查、WebView2 初始化、正常 /setup 创建专用测试管理员、自动登录，最后写 “PC 测试版已就绪：/”。本轮 exact task 已 Unregister、再次确证不存在，运行的 Session1 WPF 进程仍在。旧的 Session0 失败进程仅按精确 PID 终止，未杀别的 Node/浏览器/用户进程；原用户应用/数据未动。
5. **独立数据库与 UI 边界**：新 v2 MySQL 库 linguacafe_pc_test 的 server_uuid=2d0ba5fa-df3c-11f1-b5e7-22d27065f6c3 已在 RUN202/RUN203 验证；真实导入 ECDICT=768739、--status HEALTHY（本轮两次独立核实）。WPF 首启后来自**当前 v2 Web 容器**的只读回查 users=1、review_cards=0、review_logs=0。旧 RUN197 验收 Web 独占 9394/6004 的容器经 owner 复核后已定点停止，v2 Web 现在实际绑定 127.0.0.1:9394/6004，旧 RUN194 9391/6001 继续保留；不要停止/清理其它同名旧容器卷。
6. **浏览器验收不再只等坏掉的 Chrome MCP**：官方 Chrome DevTools MCP 返回连接失败时，当前 AGENTS/ADR-0033 允许使用现役 playwright-local Skill 的隔离 Chrome。已经真实导航 v2 的 /login，页面有“邮箱”、“密码”、“登录”按钮（独立浏览器没有自动登录 cookie，这是正常会话隔离），但该页面当轮 Console 仍有 2 项 error，尚未逐条归因，需下轮复验。此前旧 RUN197 页面通过不能冒充新 v2；不能拿 API200 当浏览器成功。**当前正式复习卡=0，尚未真实完成 Reader 导入、查词、Sense FSRS**，必须在绑定独立 testing DB 的真实 GUI 条件满足后再做页面写入、录入测试卡及评分。

**阶段性验收边界**：RUN203 可确认“平台脚本调用恢复 + ECDICT v2 HEALTHY + 单次版本化安装成功 + 原生 Session1 WPF/自动登录成功”；仍没有两次连续升级与失败回退实物、完整 Reader → Sense FSRS 真 GUI、SP/CalDAV 05:00、复习记录安全回读等证据，所以 B04/I05/G07 和总工作流继续 INCOMPLETE，不得自动勾选。下一轮优先从当前真实 Session1 运行实例的 Reader/词义/FSRS 开始，完成必要 server-bound 测试数据库身份，并修掉/分类 Chrome Console error；不要再次反复构建词典或重新研究权限阻断。


## Current checkpoint — 2026-09-02

- Production Docker build blocker closed: Windows patch line endings are fixed and the PHP 8.4 FSRS dependency graph is now pinned by repository `Cargo.lock` + `cargo build --locked`.
- Real Windows build of `linguacafe-pc-test-web:latest` passes through native FSRS compilation, Laravel Mix, Composer and image export.
- First-run admin DOM selector was corrected after adversarial review; Compose startup now uses health-aware `--wait` instead of racing MySQL readiness.
- I-01 is DONE: dedicated MySQL/Redis/storage were initialized, all normal migrations/seeds completed against `linguacafe_pc_test`, ECDICT is healthy at 768,739 rows, and the loopback Web runtime returns HTTP 200 without touching existing H-07 containers.
- I-02 is DONE: the WPF/WebView2 shell was launched in the real interactive Windows console session, created the dedicated administrator through canonical `/setup`, logged in through canonical `/login`, and reached ready path `/`.
- I-03 is DONE: exact build `1fe0fae9d6308118bcf20af7674853d74112aa6a` is installed under `%LOCALAPPDATA%/LinguaCafePCTest/app`; the desktop shortcut launches the installed executable; Web/Python images are prebuilt with the same commit tag so ordinary launch reuses the runtime instead of compiling FSRS.
- I-04 is DONE: installed PC-test runtime completed real Home -> Library/import -> Reader/ECDICT -> WordSense -> Sense Review Good -> User Settings -> Admin smoke; browser/API/database evidence agrees and no console warning/error was observed.
- I-05 is the only active PC lane: no product-owner runtime feedback has been reported yet. Until there is real hands-on feedback, do not manufacture defects, repeat the accepted main-flow smoke, or expand into public Windows installer/signing/update work.
- Packaging rejects tracked or untracked Git source changes, stages the complete new build before replacing the previous installed app, and uses a repository-local empty ECDICT placeholder when no real CSV is available instead of a user-specific hard-coded path.
- Step handoffs: `HANDOFF-01` through `HANDOFF-06` under `docs/handoffs/windows-pc-test/` record each completed checkpoint.

## Product contract

- Windows PC test is English-learning LinguaCafe with the same Laravel/Vue owners as Web.
- The first PC test release uses a dedicated local Docker runtime and dedicated persistent MySQL/Redis/storage volumes.
- It must not read/write the normal development, acceptance, staging, or production database.
- The desktop shell hides setup/login and automatically enters a dedicated administrator account, but the server still uses the canonical `/users/create` and `/login` authentication paths. No production authentication bypass route is added.
- The administrator password exists only in the current Windows user's local PC-test state, not in Git, `.env`, reports, screenshots, or shell command arguments.
- First-run ECDICT import may use the existing local `linguacafe_ecdict_en_zh_pipe.csv` read-only mount. If unavailable, the application may still start with the existing English fallback, but the missing dictionary must be shown as a test-build limitation.
- The initial desktop container stack is bound to loopback only. It is a local product test surface, not a public deployment.
- Every user-reported PC experience issue goes into `docs/testing/windows-pc-test-feedback-log.md`; fixes remain open until the product owner has had a chance to retest the rebuilt executable.

## Architecture

### Desktop shell

`desktop/windows-pc-test/` is a small .NET 8 WPF application using Microsoft's stable WebView2 package and the Windows Evergreen WebView2 runtime.

Responsibilities:

1. check/start Docker Desktop;
2. unpack the exact Git snapshot bundled with the PC build;
3. build/start the dedicated local Docker stack;
4. run normal migrations/seeds only against the dedicated PC-test database;
5. ensure ECDICT is available when the known local CSV exists;
6. use a hidden WebView2 page to create the first administrator through `/setup`, then log in through `/login`;
7. reveal the ordinary LinguaCafe UI only after authentication succeeds.

The shell does not own study data, FSRS, Reader, dictionary search, backups, user management, or application routing.

### PC test runtime

`desktop/windows-pc-test/docker-compose.pc-test.yml` owns one isolated Compose project:

- MySQL 8.4 persistent PC-test volume;
- Redis 7.2 persistent PC-test volume;
- current tokenizer image;
- current production Web image;
- persistent app storage volume;
- isolated Compose project `linguacafe-pc-test-study-v2` (does not attach old `linguacafe-pc-test-next` volumes);
- loopback Web port `9394` (old test `9391` remains untouched);
- loopback browser Reverb port `6004` mapped to container `6001` (old `6001` remains untouched); the rendered `lc-ws-port` metadata configures the actual browser websocket endpoint, while other runtime layouts default to `6001`.

The launch path rechecks ECDICT health after a scoped re-import and stops with an error if its CSV is missing or the dictionary remains unhealthy; an incomplete import cannot silently pass as a successful start.

The runtime uses test-only database/reverb credentials and never reuses repository `.env` values.

## Packaging

`scripts/windows/install-pc-test.ps1` publishes the WPF executable, adds an exact `git archive` source snapshot and commit marker, and creates one desktop shortcut named `LinguaCafe PC Test`.

The installed build is deliberately a test build for the current Windows PC. Code signing, auto-update, installer distribution and public Windows release are outside this first lane.

## Phase I milestones

| ID | Status | Outcome | Exit evidence |
|---|---|---|---|
| I-00 | DONE | Freeze PC-test isolation/auth/feedback contract | This plan + adversarial architecture review |
| I-01 | DONE | Dedicated persistent PC Docker runtime | Compose health wait; normal migrate/seed against `linguacafe_pc_test`; ECDICT 768,739 / HEALTHY; loopback HTTP 200; existing H-07 containers unchanged |
| I-02 | DONE | Windows executable shell + hidden normal admin setup/login | `HANDOFF-04-WEBVIEW2-AUTO-ADMIN-READY-2026-09-02.md`; real console-session WebView2 launch; dedicated `is_admin=1`; `AdminProvisioned=true`; startup log reaches `PC 测试版已就绪：/` |
| I-03 | DONE | Package current Goal snapshot and desktop shortcut | `HANDOFF-05-EXACT-BUILD-DESKTOP-SHORTCUT-2026-09-02.md`; exact build `1fe0fae9...`; installed `.exe` + `runtime-source.zip` + commit marker + commit-tagged Web/Python images; real desktop `.lnk` reaches ready path `/` without rebuilding FSRS |
| I-04 | DONE | Real PC smoke | `HANDOFF-06-REAL-PC-MAIN-FLOW-SMOKE-2026-09-02.md`; real DOM flow Home → Library/import → Reader/ECDICT → WordSense → formal Sense Review Good → User Settings → Admin; 66 observed XHR/fetch=200; Console warn/error=0; DB counts reconcile 1 book/1 chapter/1 sense/1 card/1 log/1 source occurrence |
| I-05 | ACTIVE | Product-owner feedback loop | every reported issue recorded with build, expected/actual, disposition, fix commit and retest status |
| I-GATE | TODO | Product owner says the PC test build is stable enough for the next packaging decision | no unresolved blocker chosen as required for current PC test; feedback log reflects owner retest |

## Explicitly deferred

- public Windows installer/update channel;
- Windows code signing / SmartScreen reputation;
- replacing Docker with bundled MySQL/Redis/PHP/Python runtimes;
- offline-first full desktop authority;
- separate desktop business logic;
- multi-user public authentication design changes;
- Apple H-10/H-GATE closure.
