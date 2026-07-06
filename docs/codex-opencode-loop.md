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
        │                          ├──→ 运行 OpenCode (deepseek-v4-flash)
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
- 使用 DeepSeek Flash 模型（`deepseek-v4-flash`）。
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

在 GitHub 仓库中创建一个 Issue，描述你想要做的事情。例如：

```
标题：修复 ReviewFsrsTest 中的类型错误
内容：运行测试时发现 ReviewFsrsTest 报类型错误，需要修复。
```

### 第 2 步：用 Codex 桌面端分析

在本地电脑上运行 Codex CLI，告诉它 Issue 的链接：

```bash
# 示例（具体命令看你 Codex 的用法）
codex https://github.com/你的用户名/LinguaCafe-local/issues/1
```

Codex 会读取 Issue，分析任务，然后生成一条类似下面的指令：

### 第 3 步：把 /opencode 指令发到 Issue

把 Codex 生成的指令评论到 Issue 中。

> `/opencode`
>
> 任务目标：修复 ReviewFsrsTest 类型错误，确保所有测试通过。
> ...

### 第 4 步：等待结果

GitHub Actions 会自动处理。完成后会在评论中回复执行报告。

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
本次任务必须使用 DeepSeek Flash，也就是 deepseek-v4-flash。
如果无法使用该模型，请失败并停止，不要自动切换模型。

Superpowers 要求：
开始前请使用 OpenCode skills / Superpowers 机制查看可用技能，
根据任务选择合适技能。完成后请报告实际使用的技能名称。

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
| 🏷️ **GitHub 标签** | `auto-fix/attempt-1`、`auto-fix/attempt-2`、`auto-fix/attempt-3` |
| 🔖 **机器可读标记** | 每次报告末尾的 `<!-- opencode-loop: attempt=1 status=failed -->` |

调度器优先读取机器标记，同时用标签作为人工可见状态。

### 如何人工继续

当看到 `needs-human-review` 标签后：

1. 查看 OpenCode 之前的执行报告（评论区里都有）。
2. 手动修复问题，或者：
3. 在 Issue 中评论 **`继续自动修复`**，会重置并开始新一轮循环。

## 需要配置的 GitHub Secrets

| Secret 名称 | 说明 |
|-------------|------|
| `DEEPSEEK_API_KEY` | DeepSeek API 密钥，用于 OpenCode 调用 `deepseek-v4-flash` 模型 |

### 配置方法

1. 打开 GitHub 仓库页面 → Settings → Secrets and variables → Actions。
2. 点击 **New repository secret**。
3. Name 填 `DEEPSEEK_API_KEY`。
4. Secret 填你的 DeepSeek API Key。
5. 点击 Add secret。

## 如何确认 OpenCode 使用的是 deepseek-v4-flash

在 OpenCode 每次执行后的报告评论中，会明确写着：

```
[model: deepseek-v4-flash]
```

如果模型不可用，workflow 会失败并报错，不会自动换成其他模型。

## 如何确认 Superpowers 被调用

每次 OpenCode 报告都会包含：

```
[skills: brainstorming, systematic-debugging, ...]
```

如果显示 `[skills: none]`，说明没有使用任何技能，报告中也会解释原因。

## 需要提前创建好的 GitHub 标签

在 GitHub 仓库中手动创建以下标签（Labels）：

| 标签 | 颜色 | 说明 |
|------|------|------|
| `auto-fix/attempt-1` | `#0E8A16`（绿） | 第 1 次自动修复 |
| `auto-fix/attempt-2` | `#FB9400`（橙） | 第 2 次自动修复 |
| `auto-fix/attempt-3` | `#D93F0B`（红） | 第 3 次自动修复 |
| `auto-fix/passed` | `#2CBE4E`（绿） | 自动修复通过 |
| `needs-human-review` | `#E99695`（粉） | 需要人工介入 |
| `auto-fix` | `#BFDADC`（灰） | 自动修复相关 |

创建方法：GitHub 仓库 → Issues → Labels → New label。

## 如果失败了，看哪里

按顺序检查：

1. **GitHub Actions 日志**：仓库 → Actions → 找到最近的 workflow run → 查看失败步骤。
2. **Issue/PR 评论区**：OpenCode 的执行报告和 Auto-Fix Scheduler 的调度记录。
3. **标签状态**：看当前是 `auto-fix/attempt-N` 还是 `needs-human-review`。
4. **你本地**：如果 3 次都失败，按上述「如何人工继续」操作。

## 安全边界

| 禁止行为 | 说明 |
|----------|------|
| ❌ 自动合并 PR | OpenCode 创建的 PR 永远不会自动合并，必须你手动审核合并 |
| ❌ 直接 push master | OpenCode 只能在独立分支上工作 |
| ❌ 泄露 Secrets | workflow 日志中不会输出任何 API Key、Token |
| ❌ 无限循环 | 最多 3 次自动修复，超出后必须你手动批准才能继续 |
| ❌ 读取无关 Secrets | 工作流只读取 `DEEPSEEK_API_KEY` |
| ❌ 修改核心配置 | 如果限制了禁止范围，OpenCode 不会修改 `.github/workflows/`、`opencode.json` 等文件 |
