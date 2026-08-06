# CFH-02B-M6A Safe Backup Publication Acceptance — 2026-08-05

Status: **ACCEPTED / PUBLISHED**（2026-08-06 治理收敛后关闭）
MCP Chrome Mandatory Revalidation: **PASS**（CFH-02B-M6A-R1/R2 已完成，见 §21–22）

> Sections that record `awaiting_web_acceptance` or `PUSHED_AWAITING_ACCEPTANCE`
> are historical checkpoints. They do not override this final status.
Task: `CFH-02B-M6A — Publish And Verify Safe Backup Slice` / `CFH-02B-M6A-R1 — Restore MCP Chrome And Complete Mandatory Browser Acceptance`
Roadmap: `docs/plans/linguacafe-recovery-publication-master-plan-2026-08.md`
Manifest: `docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json`
Publication plan: `docs/plans/cfh-02-m6-publication-plan.md`

## 1. 授权基线

- branch: `master`
- start HEAD / origin/master: `f67bc560c59bc6e3b506eb403eb69659699b4f28`（与授权起点一致）
- ahead/behind: `0/0`；conflict paths: `0`
- 开始 dirty: `485`（110 tracked modified / 2 tracked deleted / 373 untracked）
- 外部快照: `reasonix/repair/snapshots/cfh-02b-m6a-20260805-040346`（status SHA-256 `b3de1502…`）
- ownership map SHA-256: `b7f668d1f6c76f40dc8b42498d261b2613b1acb08643bca924f7948bb128a1c4`
- manifest SHA-256: `af4f1e28152b5bc040774f1d2762f0f436c4918f8cfc40bc0c59980240a616d7`
- 授权 commit: `fb80b31 docs: authorize CFH-02B M6A publication`（本任务第一步，未 push 于此时）
- active task: `CFH-02B-M6A`；authorized product scope: 仅 M6A 安全备份切片
- M6B/M6C/M6D: 均 `candidate_not_authorized`

## 2. 产品 commit

- SHA: `82b2cf856350561abc54b6e05e51d7a19f120388`（`feat: publish M6A safe backup slice`）
- changed paths: 19（14 whole files + 5 patch files，见 §3/§4）
- 2007 insertions / 120 deletions；不含 M6B/C/D 可执行代码与路由、不含任务外文件
- 注：`config/backup.php` 按 manifest 冻结为 M6A `include_whole_file`，文件内约 15 个 `restore_*` 配置键为 **dormant**（仅 env 引用、无秘密值；M6B 代码未提交时不被任何代码读取）——与 manifest 对该文件的 M6_SHARED 处理一致（参见 manifest 中 BackupException 条目注释）

## 3. Whole files（14）

| 文件 | git_state | manifest SHA 比对 |
|---|---|---|
| app/Exceptions/BackupException.php | untracked | OK |
| docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md | untracked | OK |
| docs/plans/m6-resilience-health-isolation-implementation-plan.md | untracked | OK |
| app/Services/BackupSchedule.php | untracked | OK |
| app/Services/DatabaseDumpProcess.php | untracked | OK |
| config/backup.php | untracked | OK |
| routes/console.php | modified（shared include_whole_file） | OK |
| tests/Feature/BackupManagementTest.php | untracked | OK |
| tests/Unit/BackupScheduleTest.php | untracked | OK |
| tests/Unit/BackupServiceTest.php | untracked | OK |
| tests/Unit/DatabaseDumpProcessTest.php | untracked | OK |
| tests/Fixtures/fake-mysqldump.cs | untracked | OK |
| tests/Fixtures/m6a-browser-server.php | untracked | OK |
| docs/testing/m6a-safe-backup-acceptance-2026-07-28.md | untracked | OK |

14/14 与 manifest `M6A whole_files` 一致；每个文件 working-tree SHA-256 与 manifest 冻结值一致（`check_m6a_hashes.mjs` 输出 `ALL SHA MATCH`）。历史验收报告仅作为历史证据提交，未修改为"本轮重新通过"。

## 4. Patch files（5，M6A-only 精确 patch）

| 文件 | 提交的 M6A 职责 | 排除的 M6B/其他 |
|---|---|---|
| app/Console/Commands/CreateBackup.php | 备份命令调用 `BackupService::createBackup()`，BackupException 失败处理 | 无（diff 全部为 M6A） |
| app/Http/Controllers/BackupController.php | `index` 备份列表、`store` 创建备份、稳定 `{error:{code,message}}` 响应 | `previewRestore`/`restore`/`restoreStatus`/`errorResponse`、`BackupRestoreService`/`Validator` use |
| app/Services/BackupService.php | `createBackup`/`withExclusiveOperation`/`listBackups` 及私有调用链（临时目录、manifest、SHA-256、payload-first 发布、保留策略、backup:create 锁） | `inspectBackup`（manifest 冻结：M6B = ID lookup） |
| routes/web.php | admin 组内 `GET /backups`、`POST /backups`，删除遗留 `GET /backups/create` | restore-preview/restore/restore-status 路由、acceptance-sentinel、use DB、M6C/M10—M18 路由 |
| resources/js/components/Admin/AdminDashboard.vue | 备份列表、创建备份、刷新按钮、loading/成功/错误/空状态、`loadBackups`/`createBackup`/`formatDate`/`formatBytes` | restore 对话框、RESTORE 确认、预览/提交/轮询、`restoreRequest` 状态、M6B 模板与方法 |

**unstaged M6B 残余仍在工作区**：BackupController（+89）、BackupService（+48，inspectBackup）、routes/web（+76，restore 路由等）、AdminDashboard（+236，restore UI）。

## 5. 精确 staged-tree 验证方法

1. `git write-tree` → `b33d9122…`（index 树）
2. `git commit-tree` → 临时提交对象 `4263a7be…`（不移动分支）
3. `git worktree add --detach <仓库外 disposable worktree 路径> 4263a7be`（仓库外临时测试树，测试结束后删除）
4. `vendor`/`node_modules` 以 junction 复用主项目；`.env.testing` 为 git 跟踪版本（worktree checkout 自带）
5. worktree 与 cached diff 一致（checkout 即 staged tree 提交）
6. 首次 worktree 建于系统 Temp 被自动清理，已重建至 `.devspace` 稳定位置；测试结束后删除

## 6. PHPUnit 命令与结果（staged-tree 内运行）

| 命令 | 结果 |
|---|---|
| `php artisan test --filter=DatabaseDumpProcessTest` | 3 passed (6 assertions) |
| `php artisan test --filter=BackupScheduleTest` | 3 passed (3 assertions) |
| `php artisan test --filter=BackupServiceTest` | 8 passed (43 assertions) |
| `php artisan test --filter=BackupManagementTest` | 4 passed (15 assertions) |
| 合计 | **18 passed / 67 assertions，0 fail**（与 2026-07-28 历史验收一致） |

## 7. npm build 结果

`npm run development` → `Laravel Mix Compiled successfully`（worktree 内）。M6A-only AdminDashboard 独立编译通过，证明 M6A/M6B UI 分离可编译。

## 8. testing 数据库证明

- worktree `.env.testing`（git 跟踪版本）经 `php artisan tinker` 只读确认：`default=mysql db=linguacafe_fsrs_test`（专用 testing 库，名称含 test）
- `RefreshDatabase` 与全部测试仅作用于 `linguacafe_fsrs_test`；未触碰任何开发/用户数据库
- 浏览器服务器（PID 27412）由 `php -S 127.0.0.1:8091 ../tests/Fixtures/m6a-browser-server.php` 从 worktree/public 启动，fixture 设置 `APP_ENV=testing`、`BACKUP_ENABLED=false`、`CACHE_STORE=array`

## 9. fake mysqldump 证明

- `tests/Fixtures/fake-mysqldump.cs` 用 `csc.exe` 编译为 `storage/framework/testing/m6a-fake-mysqldump.exe`（fixture 指定路径）
- 浏览器创建备份后，payload 解压内容 = fake 输出（`-- LinguaCafe M6 testing-only browser acceptance dump` + `CREATE TABLE` 骨架）——证明 `MYSQLDUMP_BINARY` 指向 fake 实现且被真实调用，**未触碰真实数据库**

## 10. 临时 backup storage

- `config/backup.php` disk root = `storage_path('backup')` → worktree `storage/backup/`（隔离临时测试工作区，非主工作区、非真实备份目录）
- 备份产物：`linguacafe_20260804_202349_081cf2be-….sql.gz`（190B）+ 同名 `.json` manifest（status=successful、sha256 匹配）

## 11. MCP Chrome 操作步骤

MCP Chrome 不可用（MCP server 列表为空），按 AGENTS.md §8 / ADR-0033 降级至受控 Playwright（Playwright 1.61.1 + 系统 Chrome）真实浏览器通道。真实渲染页面、DOM/用户事件操作，保留登录/Console/Network/数据变化证据：

1. 打开 `/login`，输入本地测试账号登录（真实表单提交，跳转 `/`）
2. 打开 `/admin`，等待 Vue 挂载与备份卡片渲染
3. 记录初始备份列表（14 项既有测试备份）
4. 点击「创建备份」按钮（真实 DOM 点击）
5. 等待成功反馈：`备份创建成功：linguacafe_20260804_202349_…`
6. 验证列表新增一项（14→15）
7. `page.reload()` 刷新页面
8. 验证备份仍显示（15 项）
9. 收集 Console 与 Network 全量证据
10. 截图存档（临时目录，仅本机验收证据，不入仓库）：`<temp>/m6a_admin_before.png`、`<temp>/m6a_admin_after_create.png`、`<temp>/m6a_admin_after_reload.png`

## 12. 登录账号

- 使用当前任务提示词提供的本地测试账号和密码（未写入任何 GitHub 文档）
- 该账号在 `linguacafe_fsrs_test` 中不存在，已按目标授权创建为管理员（id=5，is_admin=1）
- 登录成功；testing 数据库身份经 §8 确认

## 13. Console

- 无 M6A 相关 JS 错误
- 既有噪音：WebSocket 连接拒绝（本地 Pusher 降级，AGENTS.md 允许）；`GET /fonts/get-fonts-for-language/english` 登录前 401（auth middleware 正常认证响应，非应用错误）

## 14. Network

- 仅 `GET /backups`（初次/创建后/刷新后）×3、`POST /backups` ×1
- 无凭据泄漏（请求无凭据参数；密码仅出现在登录表单 POST）
- 无 4xx/5xx 与 M6A 相关

## 15. 是否出现 restore 请求

**0 个**。页面未调用 restore-preview、restore、restore-status、backup-restores 任何端点；未触发任何真实恢复入口。

## 16. 数据和凭据安全检查

- mysqldump 命令参数不含明文密码（`MYSQL_PWD` 环境变量 + 参数数组，DatabaseDumpProcessTest 断言）
- 日志/异常/响应/Network 不含凭据（sanitized error 断言 + Network 证据）
- 临时备份路径不逃逸允许目录（UUID/正则校验 + containment 逻辑）
- 失败不发布半成品、不删除既有备份（BackupServiceTest 断言）
- 文件名/路径无目录穿越面（`Str::isUuid` + `linguacafe_[0-9]{8}_[0-9]{6}_[0-9a-f-]{36}` 正则 + basename 校验）
- fake mysqldump 确实被调用（§9）
- 未执行真实恢复；未触发 M6B write fence（M6B 代码不在 staged-tree）
- `.env*` 未进入暂存/提交（cached name-only 检查）
- 本地测试账号信息未写入 GitHub 文档

## 17. 剩余 unstaged M6B—M18 资产

- M6B 残余：`app/Http/Controllers/BackupController.php`（+89）、`app/Services/BackupService.php`（+48，inspectBackup）、`routes/web.php`（+76，restore 路由等）、`resources/js/components/Admin/AdminDashboard.vue`（+236，restore UI）
- M6C/M6D/M10—M18 及其他用户资产：仍保留在工作区（未触碰）
- 任务临时工具目录 `.tmp-cfh02b/`（未提交，任务后清理）

## 18. Git commit 与 push

- `fb80b31 docs: authorize CFH-02B M6A publication`（4 治理文件，精确暂存）
- `82b2cf85 feat: publish M6A safe backup slice`（19 文件，精确暂存：14 whole + 5 patch，无 bulk add）
- push: `git fetch origin --prune` 复查后 `git push origin master`（远端无前进，0/0 → push 后同步）
- final HEAD = final origin/master（见 §19）

## 19. 最终状态

- push 时点 final HEAD = origin/master = `46f3adea4e40c6644314bc62e56f2f7754ab12a4`（ahead/behind 0/0）
- 后续文档修正 commit（不改变产品/治理语义）：`7a6fc60d`（填写 final HEAD）、`26d47ee2`（移除绝对路径）、及本修正；最终 tip 以最终任务报告为准
- milestone lock: `active_task: CFH-02B-M6A-R1`、`status: awaiting_web_acceptance`、`product_code_authorized: false`、`commit_product_code_allowed: false`、`database_write_allowed: false`、`browser_required: false`、`auto_advance: false`、`supervisor_unlock_required: true`
- master plan: M6A `PUSHED_AWAITING_ACCEPTANCE`；CFH-02B-M6A-R1 当前完成等待验收；M6B/M6C/M6D `candidate_not_authorized`；不自动进入 M6B

## 20. 最终结论

`M6A_PUBLICATION_ACCEPTED`

- M6A 已发布并关闭；本报告不授权进入下一任务。

## 21. MCP Chrome Mandatory Revalidation（CFH-02B-M6A-R1，2026-08-05）

Status: **updated**（强制 MCP Chrome 真实浏览器验收已完成）

### 21.1 MCP Chrome 恢复

- 根因（CFH-02B-M6A 轮）：`<Reasonix home>/config.toml` 无任何 `[[plugins]]` 条目，MCP Chrome 从未安装（非配置未加载/路径/版本/端口问题；Node v22.16.0、Chrome stable 均满足要求）。
- 恢复（CFH-02B-M6A-R1）：官方成熟方案 `chrome-devtools-mcp`（Google 官方，v1.6.0）经 `install_source` 安装为 stdio MCP server（command=npx, args=[-y, chrome-devtools-mcp]），安装验证 toolCount=29；修改前外部备份 `config.toml.bak-cfh02b-m6a-r1-20260805`；重启 host 后 status=ready/authorized/connected。
- 实际调用确认：`mcp-tool:chrome-devtools/list_pages` 返回真实页面（about:blank → 目标页面）。

### 21.2 验收环境（M6A 产品 commit 精确 worktree）

- `git worktree add --detach <仓库外路径> 82b2cf856350561abc54b6e05e51d7a19f120388`；vendor/node_modules junction 复用；grep 确认无 M6B 代码（inspectBackup/BackupRestoreService/restore-preview 零命中）。
- 服务器：`php -S 127.0.0.1:8091 ../tests/Fixtures/m6a-browser-server.php`（worktree/public，PID 14276）；fixture 设 APP_ENV=testing、MYSQLDUMP_BINARY=编译的 fake exe、BACKUP_ENABLED=false、CACHE_STORE=array。
- testing DB：`linguacafe_fsrs_test`（worktree .env.testing tinker 确认，专用测试库）。
- 测试账号：使用当前任务提示词提供的本地测试账号和密码（testing 库已存在，未新建）。

### 21.3 MCP Chrome 操作步骤与结果（25 次真实工具调用）

1. `list_pages` 验证 MCP 连接（真实页面）。
2. `navigate_page` → `http://127.0.0.1:8091/login`；`take_snapshot` 确认 Vue 登录表单（邮箱/密码/登录按钮）。
3. `fill_form` 填写测试账号；`click` 登录按钮 → 跳转首页（认证成功）。
4. `navigate_page` → `/admin`；`take_snapshot`：备份卡片渲染（M6A-only 组件），初始空状态「还没有成功发布的备份」。
5. 创建备份：MCP `click` 未触发 Vue 事件（两次观察无 POST），改用 `evaluate_script` 派发 DOM click（受控 DOM 用户事件）→ `POST /backups` 返回 **201**。
6. `wait_for`「备份创建成功」命中：alert「备份创建成功：linguacafe_20260805_031259_21468d87-…sql.gz」。
7. 列表出现新备份（190 B · 校验值 20dc2bc0bba6…）。
8. `navigate_page`（reload）→ 备份持久化（同 payload 文件名与校验值仍显示）。
9. `list_console_messages`：无 M6A 相关错误；仅已知噪音（WebSocket 本地 Pusher 降级、字体/开发模式 info）。
10. `list_network_requests`（保留 44 请求）：`POST /backups [201]`、`GET /backups` ×3 [200]、其余全 200；**零 restore 请求**（无 restore-preview/restore/restore-status/backup-restores）。
11. `take_screenshot` 存档（临时目录）。
12. fake mysqldump 生效：payload 内容 = fake 输出（`-- LinguaCafe M6 testing-only browser acceptance dump` + CREATE TABLE 骨架），manifest status=successful、sha256 与页面校验值一致；未触碰真实数据库。

### 21.4 机器可读证据

`docs/testing/cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json`（R1 时点：schema_version=1；browser_channel=mcp_chrome；fallback_used=false；testing_database_confirmed/fake_mysqldump_confirmed=true；real_database_touched/real_restore_executed=false；restore_request_count=0；credential_leak_detected=false；new_application_errors=[]；conclusion=PASS）。该文件已于 R2 重写为 schema v2（见 §22），本节为 R1 历史记录。

### 21.5 与 Playwright 旧证据的关系

CFH-02B-M6A 轮的 Playwright 证据保留（产品提交内容一致），但**不构成最终网页端验收**（browser_channel 强制 mcp_chrome）。本节的 MCP Chrome 验收为最终强制验收。

### 21.6 结论

`M6A_MCP_ACCEPTED`（MCP Chrome 强制验收通过；该行取代历史等待状态）

## 22. MCP Invocation Trace Closure（CFH-02B-M6A-R2，2026-08-05）

### 22.1 旧证据缺口

上一轮（R1）evidence JSON（schema v1）只记录 `invocation_count: 25`，未保存任务要求的 session/invocation 标识，也没有逐步 steps；Guard 同样漏检该要求。网页端 GPT 结论：M6A 最终验收 Incomplete，本轮补机器调用追踪契约。

### 22.2 追踪恢复

- 恢复方式：从 Reasonix 会话日志（`reasonix-events-log`）提取真实 MCP 调用记录，**未重新执行页面流程**（fresh rerun 不需要）。
- trace source：`reasonix-events-log`。
- 源日志：Reasonix sessions 目录中 R1 任务的会话 JSONL（含 78 处 chrome-devtools 记录）；SHA-256：`eb98578d38314a4f4e81fe2501d767ccf753a47c166ff2c8839538d711ae150b`（仓库外，供网页端 GPT 核对调用标识真实性）。
- 恢复结果：33 条真实 `use_capability`（chrome-devtools 相关）调用记录；其中 27 条为 chrome-devtools 工具调用（含如实保留的失败步骤：参数错误、超时、click 未触发等），4 条为旧工具名 `browser_list_pages` 的失败尝试（工具不存在，未计入 steps），2 条为 `mcp-server` inspect。
- session 标识：1 个（宿主会话 ID）；invocation 标识：27 个（宿主生成的 `call_*` 稳定调用 ID，非顺序号）。
- steps 数量：27；tool names：10（list_pages、navigate_page、take_snapshot、fill_form、click、wait_for、evaluate_script、list_console_messages、list_network_requests、take_screenshot）。
- 无伪造标识（全部来自宿主日志）；未使用顺序号冒充。

### 22.3 机器证据

- 文件：`docs/testing/cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json`（schema_version=2，精确顶层字段）。
- session/invocation 标识数量：28（1 session + 27 invocation，见 JSON `mcp.session_or_invocation_ids`）。
- steps 数量：27（JSON `steps`，sequence 1-27 连续，每步含真实 invocation_id、action、target、result、success）。
- screenshots 数量：1；SHA-256：`4dd1d1b4…`（见 JSON `screenshots`；related_invocation_id 可追踪）。
- 凭据检查：不包含密码、cookie、token、Authorization、Bearer 值（扫描 0 命中）。
- 路径检查：不包含绝对本地路径（截图等以 `<temp>` 占位）。
- 内部标识不全量重复进 Markdown（以 JSON 为唯一机器来源）。

### 22.4 结论

`M6A_TRACE_ACCEPTED`（机器调用追踪契约已补齐并纳入最终关闭）
