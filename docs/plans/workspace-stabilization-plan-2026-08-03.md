# LinguaCafe 工作区稳定化计划（2026-08-03）

## 状态

Current Slice Completed / Overall In Progress。当前切片已完成只读盘点、Git 噪声隔离和当前事实修正。正式代码仍需按功能切片验证和存档。没有删除、覆盖、暂存、提交或推送现有改动。

## 目标

让后续 AI 和开发者能够准确判断：

- 哪些是正式代码、测试、迁移和文档。
- 哪些是浏览器日志、截图、Cookie、临时页面和一次性调试脚本。
- 本地 HEAD 是否与 `origin/master` 对齐。
- 每个功能切片应包含哪些文件和验证证据。

## 2026-08-03 初始稳定化快照与实时读取规则

以下数字只记录 2026-08-03 开始稳定化时的初始快照，不代表当前实时状态：

- 分支：`master`。
- 当时的 HEAD 与 `origin/master`：`1c17625a576a9b61c13c50b6c3af297c859f789d`，当时对齐。
- 加入本地产物忽略规则后的当时快照：112 个已跟踪文件变化、338 个未跟踪文件和 2 个已跟踪删除。
- 当时剩余未跟踪内容主要位于 `mobile/`、`docs/`、`app/`、`tests/`、`resources/`、`database/`、`config/`。这些路径包含正式实现，必须继续显示在 Git 状态中。
- 当时的 2 个已跟踪删除文件是旧工作流 guard：`GlmCompositeTaskHardRulesDocsGuard.test.mjs` 与 `GlmSingleAgentWorkflowDocsGuard.test.mjs`。当前已有 `CurrentExecutionWorkflowDocsGuard.test.mjs`、`GoalModeAutonomyDocsGuard.test.mjs` 和 `GoalRoadmapExecutionAuthorizationDocsGuard.test.mjs` 接替；旧名称只出现在历史验收正文中。删除应与当前 guard 收敛文档作为同一切片核对，不能单独恢复旧规则。

当前实时 HEAD、远端基线和工作区数量必须在执行时重新获取，不从本节历史数字推断：

```bash
node scripts/workspace-inventory.mjs
git status --porcelain --untracked-files=all
```

最终动态 HEAD、工作区数量和完成提交只能在全部功能切片验证并提交结束后，再同步到当前上下文文件。

## 已完成

1. `.gitignore` 已加入以下本地产物：
   - `.playwright-cli/`
   - `output/`
   - `screenshots/`、`audit_screenshots/`
   - 本地页面截图和登录页面
   - `cookies.txt`
   - 根目录一次性 PHP/Python 调试脚本
   - 根目录 `.tmp` 和 Windows `nul` 文件
2. 2026-08-03 当时曾将 `docs/CURRENT_AI_CONTEXT.md` 更新到该次稳定化快照；这只是一项历史完成事实。全部功能切片提交结束后，必须再次按实时命令更新最终 HEAD、工作区数量和最终 commit，不能沿用本计划中的旧数字。
3. 新增只读盘点命令：

```bash
node scripts/workspace-inventory.mjs
node scripts/workspace-inventory.mjs --json
```

4. 盘点工具已覆盖 Android/iOS 源码、移动端资源、测试、迁移、文档、改名/复制记录和逐文件未跟踪统计；当前未知类型为 0。

该命令只运行：

- `git status --porcelain=v1 -z`
- `git rev-parse HEAD`
- `git rev-parse --abbrev-ref HEAD`
- `git rev-parse origin/master`

## 后续收口顺序

每轮只选择一个功能切片。每个切片按以下顺序处理：

1. 从盘点报告中选择一组有共同目的的文件。
2. 阅读这些文件的当前内容和 diff，确认没有混入其他功能。
3. 同时找到对应测试、迁移、ADR/计划和验收报告。
4. 运行该切片的最小测试；涉及页面时做真实页面验收。
5. 只按精确路径暂存该切片文件。
6. 检查暂存 diff，确认没有 `.env`、Cookie、截图、日志、构建产物或其他任务改动。
7. 使用一个可回退的提交保存该切片。

优先顺序：

1. 将 2 个旧 guard 删除与 3 个替代 guard、协作规则和当前上下文作为一个“工作流 guard 收敛”切片核对。
2. 移动端 M1–M9 与 Web M10–M18 分开，不混为一个提交。
3. 后端模型/迁移、服务/控制器、前端组件、测试、ADR/验收必须按同一功能配套收口。
4. 工作区稳定前，不开始新的大型组件重构。

## 禁止操作

- `git clean`、`git reset`、`git checkout --`、`git restore`、`git stash`。
- `git add -A`、`git add .` 或按目录整体暂存。
- 批量删除未跟踪文件。
- 把 `mobile/`、`app/`、`tests/`、`database/` 或 `docs/` 整体加入 `.gitignore`。
- 在没有 testing 数据库证据时运行页面写入验收。
- 为了让测试通过而删除测试或降低断言。

## 当前完成标准

本切片完成需要满足：

- 本地产物不再占据普通 `git status`。
- 正式未提交文件仍然可见。
- 当前 AI 上下文不再报告过期的“超前 90 个提交”。
- 工作区盘点工具和测试通过。
- `git diff --check` 通过。
