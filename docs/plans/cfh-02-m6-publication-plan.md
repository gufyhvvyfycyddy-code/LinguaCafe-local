# CFH-02 M6 Publication Plan

> 任务：`CFH-02A — Freeze Exact M6 Publication Slice And Shared-Seam Patches`
> 状态：`frozen`（本轮只冻结，不实施、不提交产品代码）
> 机器可读清单：`docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json`
> 决策：`NEEDS_SUPERVISOR_DECISION`（唯一阻塞项见 §15）
> 授权：`product_code_authorized: false`；`auto_advance: false`

## 1. 当前事实

- branch: `master`；start HEAD / origin/master: `6530870a775f14c47d497dd49ca4b0c8687377df`（ahead/behind = 0/0，无冲突）。
- CFH-01（含 A/B）已由网页端 GPT 接受并关闭；归属图 schema v2 未修改。
- 工作区状态快照：`linguacafe-cfh02a-20260805-0240`（112 modified / 0 deleted / 364 untracked / 0 conflict；status SHA-256 `0765b842...`）。
- M6 四阶段（M6A 安全备份 / M6B 恢复安全 / M6C 内容健康 / M6D 隔离收口）均已实现并各有验收报告，但**未提交、未推送**。

## 2. M6 用户可见功能

- 管理员可从页面创建数据库备份并查看备份列表（M6A）。
- 管理员可预览备份内容、输入 `RESTORE` 确认后执行受控恢复，恢复期间应用拒绝写入（M6B）。
- 管理员可在「内容健康」页查看文章健康状态（M6C）。
- 跨用户/跨语言资源读写被拒绝，文件路径穿越被拒绝（M6D）。

## 3. M6A—M6D 职责

| 阶段 | 职责 | 验收报告 |
|---|---|---|
| M6A | 安全备份：dump 进程参数隔离、临时文件、校验和、manifest、原子发布、保留策略 | `docs/testing/m6a-safe-backup-acceptance-2026-07-28.md` |
| M6B | 恢复安全：preview token、SqlDumpInspector、DatabaseRestoreProcess、写入围栏、维护模式、安全快照回滚 | `docs/testing/m6b-restore-safety-acceptance-2026-07-28.md` |
| M6C | 文章健康：健康检查服务、ArticleHealth 页面、tokenizer 探活 | `docs/testing/m6c-article-health-acceptance-2026-07-28.md` |
| M6D | 隔离收口：user/language 谓词、SafeFilePathService、ProcessChapter 作用域证明 | `docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md` |

## 4. 精确提交顺序

1. `M6A`（无依赖）
2. `M6B`（依赖 M6A）
3. `M6C`（依赖 M6A、M6B）
4. `M6D`（依赖 M6A、M6B、M6C）

不得合并无依赖阶段，不得为减少 commit 数量牺牲可验证性。全部四个阶段依赖的 M6 契约文档（ADR-0036、实施计划）随 M6A 提交。

## 5. 整文件提交列表

见 manifest `commit_sequence[*].whole_files`。要点：

- **M6A 整文件**：`app/Exceptions/BackupException.php`、`app/Services/BackupSchedule.php`、`app/Services/DatabaseDumpProcess.php`、`config/backup.php`、`routes/console.php`、M6 契约文档（ADR-0036、实施计划）、M6A 测试与 fixtures、M6A 验收报告。
- **M6B 整文件**：`app/Http/Middleware/RejectWritesDuringRestore.php`、`app/Jobs/ExecuteBackupRestore.php`、`app/Services/BackupRestoreService.php`、`app/Services/DatabaseRestoreProcess.php`、`app/Services/RestoreWriteFence.php`、`app/Services/SqlDumpInspector.php`、`app/Providers/AppServiceProvider.php`、`config/queue.php`、M6B 测试与验收报告。
- **M6C 整文件**：`app/Http/Controllers/ArticleHealthController.php`、`app/Services/ArticleHealthService.php`、`config/article_health.php`、`resources/js/components/Health/ArticleHealth.vue`、`resources/js/components/Layout.vue`、M6C 测试/fixtures/验收报告。
- **M6D 整文件**：`app/Services/SafeFilePathService.php`、M6D 允许的 11 个 controller/service/job、4 个签名适配测试、M6IsolationAuditTest、M6D 验收报告。

## 6. 精确 patch 文件列表

| 文件 | 阶段 | hunk 锚点 | hunk SHA-256 |
|---|---|---|---|
| `routes/web.php` | M6A | `// backup` → `Route::post('/backups/{backupId}/restore'` | `67074a22...` |
| `routes/web.php` | M6B | `Route::get('/backup-restores/{operationId}'` → `->middleware('web');` | `ba40e3a4...` |
| `routes/web.php` | M6C | `Route::get('/article-health'` → `/article-health/data` | `fd03b7ea...` |
| `bootstrap/app.php` | M6B | `use App\Http\Middleware\AdminMiddleware;` → `use Illuminate\Foundation\Application;`（仅 RejectWritesDuringRestore 行） | `9e6d04ec...` |
| `bootstrap/app.php` | M6B | `->withMiddleware(...)` → `$middleware->api(prepend: [`（仅 prepend 行） | `9d58cdfd...` |
| `resources/js/app.js` | M6C | `const Attributions = ...` → `const Library = ...`（仅 ArticleHealth 行）+ `/article-health` 路由行 | `f0e6057f...` |
| `app/Http/Controllers/HomeController.php` | M6D | `use App\Services\SafeFilePathService;` + `getUserManualFile` 方法 | `c4ec1467...` |
| `app/Http/Controllers/BackupController.php` | M6A | `index`/`store` 方法 | 见 manifest |
| `app/Http/Controllers/BackupController.php` | M6B | `previewRestore`/`restore`/`restoreStatus` 方法 | 见 manifest |
| `app/Services/BackupService.php` | M6A | `createBackup`/`withExclusiveOperation`/`listBackups` | 见 manifest |
| `app/Services/BackupService.php` | M6B | `inspectBackup` | 见 manifest |

锚点均为函数/路由/配置键稳定上下文，不用行号。`hunk_sha256` 由选中 patch 内容计算，已在 manifest 冻结。

## 7. 排除文件

见 manifest `excluded_files`（12 条）。要点：

- **protected_regression_only**：`FsrsSchedulingService`、`ReviewCardService`、`ReviewFsrsTest`、`FsrsSchedulingServiceTest` —— 仅跑回归，不进入 M6 提交。
- **其他里程碑**：`routes/api.php`（移动 API）、`app/Models/*`（ReviewCard/ReviewLog/User/WordSense）、`MobileMediaController`（M18）。
- **归属冲突**：`AdminDashboard.vue`（见 §15）。
- **M18 片段**：`config/filesystems.php` 的 media disk 不在 M6 提交内。

## 8. 测试矩阵

- M6 PHP：`BackupManagementTest`、`BackupRestoreManagementTest`、`ExecuteBackupRestoreTest`、`RestoreWriteFenceTest`、`ArticleHealthTest`、`M6IsolationAuditTest`、`BackupRestoreServiceTest`、`BackupScheduleTest`、`BackupServiceTest`、`DatabaseDumpProcessTest`、`DatabaseRestoreProcessTest`、`SqlDumpInspectorTest`、`EncounteredWordLearningEnrollmentTest`、`MorphologyLemmaDefectFixTest`、`MorphologyMatrixImportRegressionTest`、`ReviewFsrsTest`。
- 前端：`npm run development`（M6C 涉及 Vue 组件与路由注册）。
- Harness：`node --test tests/js/RecoveryPublicationWorkflowDocsGuard.test.mjs tests/js/M6PublicationSliceDocsGuard.test.mjs tests/js/WorkspaceInventory.test.mjs`。
- 回归保护：`ReviewFsrsTest`、`FsrsSchedulingServiceTest`、`ReviewCardManageTest`、`TextReaderSmokeTest`。

## 9. 真实浏览器验收矩阵

| 阶段 | 操作 | 证据 |
|---|---|---|
| M6A | m6a-browser-server fixture（APP_ENV=testing + 专用 testing DB + fake mysqldump）下，管理员登录，POST /backups 创建备份，列表出现新备份，UI 成功提示，Network 无凭据 | `docs/testing/m6a-safe-backup-acceptance-2026-07-28.md` |
| M6B | 真实浏览器：选择备份 → restore-preview → 输入 `RESTORE` → 恢复执行与状态轮询；恢复期间写入被拒 | `docs/testing/m6b-restore-safety-acceptance-2026-07-28.md` |
| M6C | 侧栏「内容健康」入口，`/article-health` 页面健康状态与 tokenizer 探活 | `docs/testing/m6c-article-health-acceptance-2026-07-28.md` |
| M6D | 跨用户/跨语言读写被拒、目录穿越被拒、合法文件正常渲染 | `docs/testing/m6d-isolation-closeout-acceptance-2026-07-28.md` |

验收按 AGENTS.md §8：真实渲染目标页面、DOM/用户事件、保留登录/Console/Network 与数据变化证据；不得用 API 调用冒充按钮验收；写操作前必须有 server-bound testing 证据。

## 10. 数据和恢复安全边界

- 不执行真实恢复；本轮及 CFH-02B 的任何恢复只允许发生在 testing 专用数据库。
- 不执行 migration、回填、drop/truncate、`migrate:fresh/refresh/reset`、`db:wipe`。
- 不修改 `.env`、密钥、既有账号凭据。
- 不绕过权限、认证、user/language 隔离或既有唯一写入入口。
- 恢复路径必须是 `BackupService`（发布/清单权威）→ `BackupRestoreService`（preview/operation 编排）→ `DatabaseRestoreProcess`（隔离恢复进程）；`SqlDumpInspector` 只检查不执行 SQL。
- 恢复期间 `RejectWritesDuringRestore` + `RestoreWriteFence` 必须证明写入被拒。
- fake mysqldump fixture 只编译到 testing 临时目录；任何真实数据库触碰即为停止条件。

## 11. Staging 方法

- 禁止 `git add .` / `git add -A`。
- 整文件：按 manifest `whole_files` 精确路径 `git add -- <path>`。
- 精确 patch：`git diff -- <path>` 取选中 hunk → `git apply --cached`（锚点为稳定上下文）；`BackupController`/`BackupService` 按方法签名选择 hunk。
- 每次 staging 后检查 `git diff --cached --name-only` 与 `git diff --cached --check`。

## 12. Push 方法

- push 前 `git fetch origin --prune`；远端前进、落后或冲突时不 merge/rebase/force，停止并报告。
- commit message 使用 `fix:` / `feat:` / `docs:` 前缀（M6 各阶段按内容选择）。
- 不 force push，不改 upstream 历史。

## 13. 停止条件

- 某个 M6 必需共享文件无法安全分离。
- patch anchor 无法稳定定位或 hunk SHA 无法重建。
- 必须修改产品代码才能完成计划、必须新增或修改 ADR、必须执行 migration 或数据库写入、必须修改 `.env`、必须运行真实恢复。
- ownership map 与当前路径集合无法对应；出现任务外文件变化；远端前进或冲突。
- AdminDashboard.vue 归属未获 supervisor 决定前不进入 CFH-02B。

## 14. CFH-02B 的进入条件

- manifest decision 由 supervisor 更新为 `READY_FOR_CFH02B`（或按决定调整 AdminDashboard.vue 归属后重新冻结）。
- 网页端 GPT 验收本轮治理交付（master plan / milestone lock / guard / manifest / plan 一致性）。
- CFH-02B 授权后才允许实施与提交；本轮 `product_code_authorized` 保持 `false`。

## 15. 唯一阻塞项：AdminDashboard.vue 归属冲突

- **M6 计划证据**：`docs/plans/m6-resilience-health-isolation-implementation-plan.md` L68（M6A allowed files）与 L207（M6B allowed files）均列出 `resources/js/components/Admin/AdminDashboard.vue`；工作区 diff（358 行新增）全部为备份列表/创建/恢复预览/RESTORE 确认 UI。
- **归属图证据**：CFH-01 归属图 `primary_slice=M13`、`shared_with=[]`，但 M13 计划与 M13 验收报告均未覆盖该文件——归属图该条证据与事实不符。
- **契约约束**：manifest 的 `direct_files` 必须与归属图 CFH-02 集合一致，`shared_files` 必须来自 `SHARED_UNRESOLVED`；AdminDashboard.vue 两者皆不满足，无法在本轮登记为 M6 提交文件。
- **结论**：`decision.status = NEEDS_SUPERVISOR_DECISION`，`safe_to_start_cfh02b = false`。请 supervisor（网页端 GPT）复核：确认归属图修正为 CFH-02（M6A/M6B）后更新里程碑锁，或将 manifest 决策更新后再授权 CFH-02B。

## 16. 历史与状态声明

- 本计划只冻结未来提交边界，不改变任何产品功能状态。
- M6 各阶段状态保持 `Accepted / Closed`（实现与验收已完成），但**未提交、未推送**；在提交前不声称已发布。
- 归属图 2 处阶段误标（SqlDumpInspector、SafeFilePathService）已在 manifest 中以 M6 计划为准登记，不修改归属图本身。
