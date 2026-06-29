# Agent Skills — Install Report (addyosmani/agent-skills)

## 安装时间

2026-06-30 03:20 UTC+8

## 已安装 Skills 清单

| # | Skill 名称 | 来源路径 | 安装路径 | 大小 |
|---|---|---|---|---|
| 1 | `context-engineering` | `skills/context-engineering/SKILL.md` | `.opencode/skills/context-engineering/SKILL.md` | 11,069 字节 |
| 2 | `api-and-interface-design` | `skills/api-and-interface-design/SKILL.md` | `.opencode/skills/api-and-interface-design/SKILL.md` | 10,306 字节 |
| 3 | `documentation-and-adrs` | `skills/documentation-and-adrs/SKILL.md` | `.opencode/skills/documentation-and-adrs/SKILL.md` | 8,733 字节 |
| 4 | `doubt-driven-development` | `skills/doubt-driven-development/SKILL.md` | `.opencode/skills/doubt-driven-development/SKILL.md` | 16,486 字节 |
| 5 | `code-review-and-quality` | `skills/code-review-and-quality/SKILL.md` | `.opencode/skills/code-review-and-quality/SKILL.md` | 18,455 字节 |

## 来源仓库

- 仓库：`addyosmani/agent-skills`
- 协议：通过 `webfetch` 从 GitHub raw URL 直接读取，未克隆仓库
- 只读取目标 `SKILL.md` 文件，未读取任何其他文件

## 项目安装路径

全部安装到 `.opencode/skills/<skill-name>/SKILL.md`，共 5 个文件。

## 是否修改或创建 AGENTS.md

是。`AGENTS.md` 已存在（76 行），追加了以下新小节：

- `## Architecture and Engineering Skills` — 列出全部 6 个技能及用途
- `## Required Workflow for High-Risk Tasks` — 高风险任务应遵循的技能调用顺序
- `## High-Risk Areas` — 需要架构审查的 15 个高风�区域
- `## Stop Rules` — 编码前必须确认的 6 条停止条件

追加后共 155 行（预估）。

## 是否创建 .opencode/skills/

否。`.opencode/skills/` 目录已存在（之前已安装 `improve-codebase-architecture`），本次在其下创建了 5 个子目录。

## 是否创建 docs/architecture/

否。`docs/architecture/` 目录已存在。

## 是否创建 docs/adr/

否。`docs/adr/` 目录已存在。

## 安全检查结果

对 5 个 `SKILL.md` 逐一进行了内容审查，结果：**全部安全**。

| 检查项 | context-engineering | api-and-interface-design | documentation-and-adrs | doubt-driven-development | code-review-and-quality |
|---|---|---|---|---|---|
| 文件名是 SKILL.md | ✓ | ✓ | ✓ | ✓ | ✓ |
| 内容是 Markdown skill 指令 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无自动运行 shell 脚本 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无读取 .env | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无上传/删除/泄露项目文件 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无修改数据库 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无隐藏安装步骤 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无绕过用户确认 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 无恶意内容 | ✓ | ✓ | ✓ | ✓ | ✓ |

所有 5 个 skill 均为纯指令式 Markdown 文件，不包含可执行代码、远程下载命令或危险操作。

## 哪些内容没有安装

- ❌ commands — 未安装
- ❌ agents — 未安装
- ❌ hooks — 未安装
- ❌ references — 未安装
- ❌ scripts — 未安装
- ❌ plugin.json — 未安装
- ❌ install.sh — 未运行
- ❌ 整个 addyosmani/agent-skills 仓库 — 未克隆
- ❌ 任何非 SKILL.md 的文件 — 未读取

## 是否修改业务代码

否。`app/`、`resources/`、`routes/` 完全未触碰。

## 是否运行脚本

否。未运行任何 `install.sh`、`npm install`、`composer install`、`pnpm install`、`yarn install` 或其他脚本。

## 是否 commit / push

否。未执行 `git commit` 或 `git push`。

## 当前项目 skills 总览

```
.opencode/skills/
├── improve-codebase-architecture/   ← mattpocock/skills (之前安装)
├── context-engineering/             ← addyosmani/agent-skills
├── api-and-interface-design/        ← addyosmani/agent-skills
├── documentation-and-adrs/          ← addyosmani/agent-skills
├── doubt-driven-development/        ← addyosmani/agent-skills
└── code-review-and-quality/         ← addyosmani/agent-skills
```

## 后续推荐使用流程

### 日常开发流程

1. **会话开始时** → 使用 `context-engineering` 获取项目上下文
2. **跨模块变更前** → 使用 `improve-codebase-architecture` 进行架构审查
3. **API/接口变更前** → 使用 `api-and-interface-design` 设计接口
4. **关键决策时** → 使用 `documentation-and-adrs` 记录 ADR
5. **高风�决策时** → 使用 `doubt-driven-development` 进行对抗性审查
6. **完成时** → 使用 `code-review-and-quality` 进行代码审查

### 高风险任务顺序

```
context-engineering
→ improve-codebase-architecture
→ api-and-interface-design (如涉及接口)
→ documentation-and-adrs (如涉及架构决策)
→ doubt-driven-development (实施前对抗审查)
→ 实施 (用户确认后)
→ code-review-and-quality (实施后审查)
```
