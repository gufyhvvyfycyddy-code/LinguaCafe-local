# ADR-0001: Architecture Gate Workflow

## Status

Accepted

> **Partial supersession:** ADR-0031 supersedes ADR-0028 and replaces repeated routine approval inside an explicitly authorized roadmap goal. ADR-0034 adds the delegated decision ladder, testing-only acceptance identity, platform-safety classification, and `Acceptance Deferred — Not Complete` dependency rule. Architecture Gate review, frozen-slice scope, safety, and truthful verification remain active; non-goal tasks keep this workflow unchanged.

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
2. FastCtx 驱动的仓库架构侦查 + 风险报告；`improve-codebase-architecture` 可用且适用时优先使用，但不强制某个报告格式或单一工具
3. `api-and-interface-design` — 如果涉及接口契约、store、props/events、payload 变化
4. `documentation-and-adrs` — 如果需要 ADR（架构决策改变时）
5. `doubt-driven-development` — 实施前对抗性审查
6. **实施判断** — 普通任务不能默认继续；明确持续目标按 ADR-0031/ADR-0034/ADR-0037 使用已冻结切片的 roadmap 执行授权和决策梯
7. 实施 — 普通任务由用户确认后开始；明确持续目标的切片文档若只展开已接受 roadmap，可标记为 `Accepted under current goal authorization`，在范围冻结、依赖证明和保留停止条件满足后直接开始
8. `code-review-and-quality` — 实施后质量门

### 关键约束

- OpenCode 不能在 ADR-0031/ADR-0034/ADR-0037 明确持续目标之外默认继续开发
- OpenCode 不能自行批准新的产品边界；可以冻结并接受当前目标已授权决定的切片化展开
- 普通任务由网页端 GPT/用户判断；明确持续目标由 Architecture Gate 和可执行证据判断切片是否进入实施
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

修改 AGENTS.md 仍需用户明确授权。本 ADR 的一次性例外已结束；2026-07-28 用户对目标模式消阻规则的再次明确授权记录在 ADR-0037。

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
- 更清晰的责任边界（普通任务由用户决策；持续目标由用户预授权边界、Architecture Gate 与证据共同约束）

### 成本

- 流程更慢：高风险任务需要 7 步走完才能编码
- 每轮报告更长：需要包含架构审查、风险表、组件边界
- 普通任务仍需要人工判断；明确持续目标的闸门是自动执行的安全检查，不是逐切片人工暂停点

### 风险

- OpenCode 可能机械执行 skills，需要总流程设计师控制边界
- 闸门可能变成形式主义：如果每次都 say yes，闸门失效
- AGENTS.md 修改后可能扩大 OpenCode 权限——注意后续回归检查

## Validation

- 高风险任务实施前必须有架构报告（或 scouting report）
- 涉及阅读页必须跑 text reader smoke guard
- 实施后必须使用 `code-review-and-quality`
- 最终报告必须直接输出当前对话窗口
- 普通任务不允许自动进入下一任务；明确持续目标按 ADR-0031/ADR-0034/ADR-0037 在完成切片审计后继续，deferred 前置按依赖路径限制且只允许不触及缺失行为的切片
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
