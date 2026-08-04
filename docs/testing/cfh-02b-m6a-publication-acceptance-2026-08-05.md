# CFH-02B-M6A Safe Backup Publication Acceptance — 2026-08-05

Status: **PUSHED_AWAITING_ACCEPTANCE**（等待网页端 GPT 验收）
Task: `CFH-02B-M6A — Publish And Verify Safe Backup Slice`
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
- 2007 insertions / 120 deletions；不含 M6B/M6C/M6D 内容；不含任务外文件

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
3. `git worktree add --detach C:/Users/Administrator/.devspace/worktrees/cfh02b-m6a-tree 4263a7be`（仓库外 disposable worktree）
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
10. 截图存档：`/tmp/m6a_admin_before.png`、`/tmp/m6a_admin_after_create.png`、`/tmp/m6a_admin_after_reload.png`

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

- final HEAD = origin/master = `46f3adea4e40c6644314bc62e56f2f7754ab12a4`（push 后确认，ahead/behind 0/0）
- milestone lock: `active_task: CFH-02B-M6A`、`status: awaiting_web_acceptance`、`product_code_authorized: false`、`commit_product_code_allowed: false`、`database_write_allowed: false`、`browser_required: false`、`auto_advance: false`、`supervisor_unlock_required: true`
- master plan: M6A `PUSHED_AWAITING_ACCEPTANCE`；CFH-02B-M6A 当前完成等待验收；M6B/M6C/M6D `candidate_not_authorized`；不自动进入 M6B

## 20. 最终结论

`M6A_READY_FOR_WEB_ACCEPTANCE`

- 不进入下一任务；等待网页端 GPT 验收
