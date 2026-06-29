# ADR-0001: Architecture Gate Workflow

## Status

Accepted

## Context

LinguaCafe 正在通过 Vibe Coding + OpenCode 方式推进本地开发（在项目根目录用 AGENTS.md 约束 AI 行为，每轮任务由网页端 GPT 定义范围并验收）。

以下区域已经成为高风险改点：

- 阅读页 / 查词栏（TextBlockGroup.vue 2213 行、VocabularySideBox.vue、WordSensesList.vue）
- WordSense / ReviewCard / ReviewLog 创建逻辑
- FSRS 调度和复习队列
- AI lookup 和数据写入边界
- import/export 和 source context 逻辑

前期已经完成两轮 Anti-Mud 小步重建（`AddSenseForm-Extract-1`、`AiSuggestionPanel-Extract-1`），但 TextBlockGroup 仍是大组件。为避免大组件屎山、边界泄漏、AI 误改和无测试重构，需要建立正式的 Architecture Gate（架构闸门）流程。

6 个项目级 skills 已从以下来源安装：

- `improve-codebase-architecture` → `mattpocock/skills`
- `context-engineering`、`api-and-interface-design`、`documentation-and-adrs`、`doubt-driven-development`、`code-review-and-quality` → `addyosmani/agent-skills`

全部为纯 Markdown 指令文件，无可执行脚本、无远程下载、无数据库操作、无 .env 读写。

## Decision

### 任务分级

| 等级 | 说明 | 闸门要求 |
|------|------|----------|
| **低风险** | 单文件小修、纯样式、文档更新、smoke 脚本更新 | 不必须启动完整架构闸门，但仍需限制文件范围 |
| **中风险** | 涉及组件 props/events、Vuex 状态、前端 API 调用、工具函数 | 至少使用 `context-engineering` + 开发后 `code-review-and-quality`；涉及接口契约需加 `api-and-interface-design` |
| **高风险** | 跨模块变更、大重构、组件拆分、WordSense/ReviewCard/FSRS/AI lookup/import-export 逻辑变化 | 必须完整启动架构闸门 |

### 高风险任务完整流程

1. `context-engineering` — 整理最小上下文包
2. `improve-codebase-architecture` — 架构侦查 + 风险报告（HTML）
3. `api-and-interface-design` — 如果涉及接口契约、store、props/events、payload 变化
4. `documentation-and-adrs` — 如果需要 ADR（架构决策改变时）
5. `doubt-driven-development` — 实施前对抗性审查
6. **网页端 GPT 判断是否进入实施** — OpenCode 不能默认继续开发，不能自 Accept
7. 实施 — 用户确认后才能开始编码
8. `code-review-and-quality` — 实施后质量门

### 关键约束

- OpenCode 不能默认继续开发
- OpenCode 不能自己 Accept
- 网页端 GPT 是最终判断者
- 用户是产品判断者
- 实施前必须有架构报告（或 scouting report）
- 涉及阅读页必须跑 text reader smoke guard
- 架构闸门不替代 smoke guard、不替代 PHP 测试、不替代 GitHub 最新代码核验
- 最终报告必须直接输出到当前对话窗口

### 强制性高风险区域

以下任何改动前必须先过架构闸门：

- `TextBlockGroup.vue`
- `VocabularySideBox.vue`
- `WordSensesList.vue`
- reader 页面状态流
- Vuex/store 逻辑
- WordSense
- ReviewCard
- FSRS
- AI lookup
- sense-only review
- import/export 流程
- source context / 原章节定位
- review scheduling
- 手动释义
- AI 释义候选
- 复习卡生成
- 词义绑定
- 原文定位 fallback

### 本轮特殊例外

本轮提交 AGENTS.md 和 .opencode/skills/ 是用户明确授权的一次性例外。后续默认禁止随意修改 AGENTS.md。

## Alternatives Considered

### 方案 A：不引入架构闸门，继续普通 Vibe Coding

- **问题**：TextBlockGroup 2200+ 行无保护，下一轮重构可能引入更多耦合。没有结构化的审查机制，跨模块变更难以归因。
- **结论**：否定。项目已有足够风险证明需要闸门。

### 方案 B：直接大拆 TextBlockGroup

- **问题**：没有测试保护、没有架构侦察、没有 ADR。拆错了不可逆。
- **结论**：否定。应该先建闸门再拆。

### 方案 C：直接引入完整前端测试框架（Jest/Vitest/Cypress）

- **问题**：Vue 2 + Laravel Mix 环境下引入前端测试框架需要大量适配工作，有破坏现有构建的风险。
- **结论**：否定。闸门先用零依赖 smoke guard 保护，测试框架留到后续评估。

### 方案 D：只靠人工 smoke，不记录 ADR

- **问题**：没有可追溯的架构决策记录，后续开发者不知道为什么这样设计。
- **结论**：否定。ADR 是架构长期可维护性的基础。

## Consequences

### 好处

- 更少越界变更（闸门在实施前拦截高风险操作）
- 更强的可追溯性（ADR 记录每次架构决策）
- 更适合长期维护（不再无限膨胀大组件）
- 更清晰的责任边界（OpenCode 执行技能，网页端 GPT 决策，用户最终确认）

### 成本

- 流程更慢：高风险任务需要 7 步走完才能编码
- 每轮报告更长：需要包含架构审查、风险表、组件边界
- 需要网页端 GPT 判断：闸门不是自动的，每次需要人决策

### 风险

- OpenCode 可能机械执行 skills，需要总流程设计师控制边界
- 闸门可能变成形式主义：如果每次都 say yes，闸门失效
- AGENTS.md 修改后可能扩大 OpenCode 权限——注意后续回归检查

## Validation

- 高风险任务实施前必须有架构报告（或 scouting report）
- 涉及阅读页必须跑 text reader smoke guard
- 实施后必须使用 `code-review-and-quality`
- 最终报告必须直接输出当前对话窗口
- 不允许自动进入下一任务
- 每轮任务必须报告文件变动、数据边界、安全边界
- 架构闸门不替代 GitHub 最新代码核验
- 闸门流程应定期回顾（至少每 10 轮任务一次）
- 对用户可见 UI / reader / review / import-export 页面，Architecture Gate 实施后必须优先考虑 MCP 视觉验证
- MCP 视觉验证不替代测试，而是补充 Python smoke 和 PHP tests
- 验证失败必须归因，不能自动扩大实现范围

## Notes

- 本 ADR 对应的 skills 安装和 AGENTS.md 更新已完成于 2026-06-30
- AGENTS.md 的架构闸门规则已追加（Architecture Gate, Architecture and Engineering Skills, Required Workflow for High-Risk Tasks, High-Risk Areas, Stop Rules）
- 后续任务默认遵守本 ADR 的闸门规则
- 本 ADR 不应被无理由修改；如需修改，必须有新 ADR 说明原因
