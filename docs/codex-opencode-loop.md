# Codex 桌面端 + OpenCode GitHub Actions 自动循环

## 整体流程

```
你 (用户) ──创建 Issue/PR──→ GitHub
        │
        ├──→ Codex 桌面端 读取 Issue，生成 /opencode 派工指令
        │             │
        │             └──→ 评论到 Issue/PR 评论区
        │                          │
        │                          ↓
        │                   GitHub Actions: OpenCode Executor
        │                          │
        │                          ├──→ 读取 /opencode 内容
        │                          ├──→ 创建独立分支
        │                          ├──→ 运行 OpenCode (OpenCode Go 计划)
        │                          ├──→ OpenCode 使用 Superpowers 技能
        │                          ├──→ 修改代码、创建 PR
        │                          ├──→ 运行测试
        │                          └──→ 评论报告回 Issue/PR
        │                                    │
        │                          ┌─────────┘
        │                          ↓
        │                   测试是否通过？
        │                    ├──→ 是 → 添加 auto-fix/passed 标签 ✅ 结束
        │                    └──→ 否 → 是否已做 3 次？
        │                              ├──→ 否 → Auto-Fix Scheduler
        │                              │         └──→ 生成新的 /opencode
        │                              │               评论回 Issue/PR → 再次触发 OpenCode
        │                              │
        │                              └──→ 是 → 添加 needs-human-review 标签
        │                                        └──→ 停止，等你人工处理
```

## 各部分职责

### 🖥️ Codex 桌面端（你本地电脑上的程序）

- **只负责第一次分析和派工。**
- 你创建 Issue 后，在本地运行 Codex CLI，告诉它 Issue 的链接。
- Codex 会读取 Issue 内容，分析任务类型，生成一条 `/opencode` 指令。
- 你把这条指令复制到 Issue 的评论区，发出去。
- 后续的自动修复循环由 GitHub Actions 处理，不需要 Codex 一直运行。

### 🤖 OpenCode（在 GitHub Actions 中运行）

- 收到 `/opencode` 指令后开始工作。
- 使用 **OpenCode Go 计划**，模型为 `opencode-go/deepseek-v4-flash`。
- **不需要 DeepSeek 官方 API Key。** 使用 OpenCode Go API Key。
- 自动加载 Superpowers 技能，根据任务类型选择合适的技能。
- 创建独立的分支来修改代码。
- 运行测试。
- 把结果报告到 Issue/PR 评论区。

### 🔄 GitHub Actions（自动调度）

- **OpenCode Executor**：「听到」`/opencode` 或 `/oc` 评论就启动。
- **Auto-Fix Scheduler**：测试失败时，自动生成下一条指令，触发下一轮修复。
- 最多执行 3 次自动修复。

## 如何触发任务

### 第 1 步：创建 Issue

在 GitHub 仓库中创建一个 Issue，描述你想要做的事情。

### 第 2 步：用 Codex 桌面端分析

在本地电脑上运行 Codex CLI，告诉它 Issue 的链接。Codex 会读取 Issue 并生成指令。

### 第 3 步：把 /opencode 指令发到 Issue

把 Codex 生成的指令评论到 Issue 中。

> `/opencode`
>
> 任务目标：……
> ...

### 第 4 步：等待结果

GitHub Actions 会自动处理。完成后会在评论中回复执行报告。

## 需要什么 API Key

**你不需要 DeepSeek 官方 API Key。**

你使用的是 **OpenCode Go 计划**，所以 CI 需要使用 **OpenCode Go API Key**。

### OpenCode Go API Key 从哪里来

1. 打开 OpenCode 设置页面（opencode.ai 网站 → 你的账户 → API Keys）。
2. 找到你的 OpenCode Go API Key（或者生成一个新的）。
3. 这个 Key 是 OpenCode Go 计划专属的，不是 DeepSeek 的 Key。

### 为什么 GitHub Actions 需要这个 Key

GitHub Actions 中的 OpenCode 需要用这个 Key 来调用 OpenCode Go 的模型服务。没有这个 Key，OpenCode 无法工作。

### Secret 名称

GitHub Secrets 中的名字是：

`OPENCODE_GO_API_KEY`

（不是 `DEEPSEEK_API_KEY`）

## 快速初始化（推荐）

我为你准备了一个初始化脚本，帮你自动完成大部分设置。

1. 打开 **PowerShell**。
2. 进入本项目目录：
   ```powershell
   cd D:\Document\lingl\LinguaCafe-main
   ```
3. 运行初始化脚本：
   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\setup-opencode-go-ci.ps1
   ```
4. 根据提示粘贴你的 OpenCode Go API Key（不会显示在屏幕上）。
5. 如果脚本不能自动设置 Actions 权限，它会告诉你手动去哪设置。

### 脚本会自动完成

- 检查 Git 仓库状态 ✅
- 检查 GitHub CLI 是否已登录 ✅
- 自动创建 6 个 GitHub 标签 ✅
- 把你的 OpenCode Go API Key 写入 GitHub Secrets ✅
- 检查 workflow 文件是否存在 ✅
- 尝试自动设置 Actions 权限 ✅

## 还需要手动确认什么

脚本运行后，还剩一件事可能需要你手动做：

1. 打开 GitHub 仓库 → Settings → Actions → General。
2. 在 **Workflow permissions** 部分：
   - 选择 **Read and write permissions**。
   - 勾选 **Allow GitHub Actions to create and approve pull requests**。
   - 点击 **Save**。

## 第一次使用：做冒烟测试

在运行真实任务前，建议先做一次冒烟测试，确认整个链路能跑通。

**步骤：**
1. 在 GitHub 仓库中创建一个 Issue，标题写 "Smoke test"，内容写：
   ```
   /opencode

   任务目标：
   创建一个文件 docs/opencode-smoke-test.md，写入当前时间。

   禁止范围：
   不要修改任何业务代码。

   模型要求：
   使用 opencode-go/deepseek-v4-flash。

   报告格式：
   完成后输出标准报告格式。
   ```
2. 等待 GitHub Actions 运行。
3. 检查评论区是否有 OpenCode 的执行报告。
4. 确认报告中的 `[model: opencode-go/deepseek-v4-flash]`。
5. 确认报告中的 `[skills: ...]` 列出了使用的技能。
6. 确认 PR 已被创建（但没有自动合并）。

## /opencode 指令模板

Codex 桌面端生成的指令请包含以下内容：

```
/opencode

任务目标：
（描述要做什么）

禁止范围：
（不要修改哪些文件或目录）

重点文件：
（需要重点关注的文件）

模型要求：
本次任务必须使用 OpenCode Go 计划模型：opencode-go/deepseek-v4-flash。
不要使用 deepseek/deepseek-v4-flash 或裸 deepseek-v4-flash。
如果模型不可用，请失败并停止，不要自动切换模型。

Superpowers 要求：
开始前请使用 OpenCode skills / Superpowers 机制查看可用技能，
根据任务选择合适技能。完成后请报告实际使用的技能名称。
如果技能系统不可用，请说明原因。

测试要求：
（需要运行哪些测试）

报告格式：
【attempt 编号】
【实际使用模型】
【实际使用技能】
【修改文件】
【完成内容】
【测试结果】
【失败点】
【下一步建议】
【机器可读状态标记】
```

## 自动循环规则

1. **第 1 次**：Codex 桌面端首次派工 → OpenCode 执行。
2. **第 2 次**：如果第 1 次失败 → Auto-Fix Scheduler 自动生成新指令 → OpenCode 再执行。
3. **第 3 次**：如果第 2 次失败 → 同上再试一次。
4. **停止**：如果 3 次都失败 → 添加 `needs-human-review` 标签，不再自动执行。
5. **通过**：任何一次测试通过 → 添加 `auto-fix/passed` 标签，停止。

### Attempt 次数记录方式（双保险）

| 方法 | 说明 |
|------|------|
| 🏷️ GitHub 标签 | `auto-fix/attempt-1`、`auto-fix/attempt-2`、`auto-fix/attempt-3` |
| 🔖 机器可读标记 | 每次报告末尾的 `<!-- opencode-loop: attempt=N status=passed/failed -->` |

调度器**优先读取机器标记**，同时用标签作为人工可见状态。
如果两者冲突，调度器会停止循环并请求人工审核。

### 如何人工继续

当看到 `needs-human-review` 标签后：

1. 查看 OpenCode 之前的执行报告（评论区里都有）。
2. 手动修复问题，或者：
3. 在 Issue 中评论 **`继续自动修复`** 或 **`/opencode continue`**，会重置并开始新一轮循环。

## 如何确认使用的是 opencode-go/deepseek-v4-flash

在 OpenCode 每次执行后的报告评论中，会明确写着：

```
[model: opencode-go/deepseek-v4-flash]
```

如果使用了 DeepSeek 官方 API（deepseek API）或者其他模型，workflow 会检查出来并报错。

## 如何确认 Superpowers / skills 被调用

每次 OpenCode 报告都会包含：

```
[skills: brainstorming, systematic-debugging, ...]
```

如果显示 `[skills: none - 原因：……]`，说明没有使用任何技能，报告中会解释原因。
如果报告完全缺少 `[skills:]` 字段，workflow 日志会发警告。

## 需要提前创建好的 GitHub 标签

初始化脚本（`scripts/setup-opencode-go-ci.ps1`）会自动创建以下标签：

| 标签 | 颜色 | 说明 |
|------|------|------|
| `auto-fix/attempt-1` | 绿 | 第 1 次自动修复 |
| `auto-fix/attempt-2` | 橙 | 第 2 次自动修复 |
| `auto-fix/attempt-3` | 红 | 第 3 次自动修复 |
| `auto-fix/passed` | 绿 | 自动修复通过 |
| `needs-human-review` | 粉 | 需要人工介入 |
| `auto-fix` | 灰 | 自动修复相关 |

## 如果失败了，看哪里

按顺序检查：

1. **GitHub Actions 日志**：仓库 → Actions → 找到最近的 workflow run → 查看失败步骤。
2. **Issue/PR 评论区**：OpenCode 的执行报告和 Auto-Fix Scheduler 的调度记录。
3. **标签状态**：看当前是 `auto-fix/attempt-N` 还是 `needs-human-review`。
4. **你本地**：如果 3 次都失败，按「如何人工继续」操作。

## 安全边界

| 行为 | 说明 |
|------|------|
| ✅ 自动合并 PR | ❌ **禁止**。所有 PR 必须人工审核 |
| ✅ 直接 push master | ❌ **禁止**。所有修改在独立分支上完成 |
| ✅ 泄露 Secrets | ❌ **禁止**。日志中不会输出任何 Key |
| ✅ 无限循环 | ❌ **禁止**。最多 3 次自动修复 |
| ✅ 自动降级模型 | ❌ **禁止**。如果 `opencode-go/deepseek-v4-flash` 不可用，直接失败 |
| ✅ 调用 DeepSeek API | ❌ **禁止**。用户没有 DeepSeek 官方 Key |
