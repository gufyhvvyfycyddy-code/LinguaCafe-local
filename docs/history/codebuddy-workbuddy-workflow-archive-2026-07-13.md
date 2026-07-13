# CodeBuddy / WorkBuddy 工作流历史归档

> **状态：已停用（2026-07-13）。**
> **本文件仅供历史参考，不再适用于当前任务。**
> 当前工作流：GLM 单 Agent 闭环，见 `docs/plans/vibe-coding-collaboration-rules.md` §1.5。
>
> 本归档保留了已被取消的 CodeBuddy / WorkBuddy / 三员工工作流的完整规则正文。
> 这些规则不再具有规范效力：
> - 不再生成 CodeBuddy / WorkBuddy 提示词。
> - 不再要求三员工接力棒。
> - 不再要求多文本框分员工。
> - 不再要求后置 CodeBuddy / WorkBuddy 复核。
>
> 仍然有效的规则（MCP Chrome 真实测试、To-do list、双轨并行、任务打包、阶段推进等）
> 已迁移到 `vibe-coding-collaboration-rules.md` 当前 GLM 章节中，不在此归档重复。

---

## 1. CodeBuddy 风险角色（原 §4.x，已停用）

### 1.1 CodeBuddy 风险角色定位（历史）

1. CodeBuddy 的重点是提出风险线索、可疑路径、代码证据位置、潜在 bug、数据风险和测试缺口。
2. CodeBuddy **不做最终产品判断**。
3. CodeBuddy **不做最终风险分级裁决**。
4. CodeBuddy **不直接决定下一任务**。
5. CodeBuddy 不需要把大量时间花在机械事实核查上；事实核查的最终责任在网页端总设计师。

### 1.2 网页端总设计师反驳性人格（历史）

6. 网页端总设计师必须扮演"反驳性人格"：
   - 先假设 CodeBuddy 的每条风险都可能不成立；
   - 去 GitHub 最新 master 核实代码；
   - 核实 OpenCode 最新 push 是否真实；
   - 核实 CodeBuddy 指出的 bug / 风险 / 错误是否真的存在；
   - 只保留无可置疑、有代码证据、会影响用户数据或体验的风险。

### 1.3 风险降权规则（历史）

以下问题**不自动算高风险**：
- 方法暂时无调用方；
- 参数暂时无前端入口；
- 文档对 AI 不够详细；
- 计划文档冗余；
- 事实搜索不完整但不影响用户数据；
- AI 交接成本问题。

### 1.4 风险升权规则（历史）

以下问题**优先升权**：
- 删除 / 归档 / 恢复操作；
- 跨用户 / 跨语言数据泄漏；
- ReviewCard / ReviewLog / WordSense / WordSenseOccurrence / EncounteredWord 写入；
- 不可逆操作；
- 用户误触；
- 回归会改变学习状态；
- 无测试覆盖的写入链路。

### 1.5 最终等级裁决（历史）

9. CodeBuddy 报告中的"风险等级"只能作为候选意见，最终等级以网页端总设计师核验后的判断为准。
10. 每次 CodeBuddy 报告后，总设计师必须区分：
    a. **代码事实** — 无可置疑、有代码行级证据。
    b. **真实产品风险** — 会影响用户数据或体验的问题。
    c. **AI 交接成本** — AI 之间沟通损耗，不是产品 bug。
    d. **文档洁癖** — 文档不够详尽，但用户不受影响。
    e. **后续可选优化** — 可以做但不紧急的改进。

---

## 2. 三员工工作流（原 §14，已停用）

本项目后续默认采用"三员工 + 网页端 GPT"的工作流。四个角色的边界如下：

| 角色 | 职责 | 不做什么 | 输出 |
|------|----------|----------|--------|
| 网页端 GPT / 总流程设计师 | 产品判断、架构拆分、GitHub 最新代码核验、Accept / Refuse、下一轮提示词 | 不直接相信报告，不让用户承担代码判断 | 模型/档位建议、OpenCode 提示词、产品设计问题、验收结论 |
| CodeBuddy | 架构侦察、风险审计、代码边界核查、指出哪些逻辑应抽 Service | 不改代码、不 commit、不 push、不做产品最终决定 | 架构/风险报告、允许/禁止边界、测试建议 |
| WorkBuddy | 产品 QA、用户可见契约、页面/字段/交互验收、手动体验风险 | 不判断代码实现正确性、不执行危险写操作 | 产品验收报告、字段契约、用户影响分级 |
| OpenCode | 按当前提示词执行最小任务、修改代码、跑测试、commit、push、输出报告 | 不擅自扩大范围、不跳阶段、不自 Accept、不自动进入下一任务 | 完成报告、测试结果、git 状态、合规确认 |

工作流：
1. 网页端 GPT 冻结目标。
2. CodeBuddy 做架构侦察。
3. WorkBuddy 做产品 / QA 契约整理。
4. 网页端 GPT 合并边界并给 OpenCode 提示词。
5. OpenCode 执行。

### 2.1 三员工提示词顺序规则（历史）

1. 网页端 GPT 每次同时使用 CodeBuddy、WorkBuddy、OpenCode 时，必须显式写明本轮顺序。
2. 顺序要写成：第 1 棒、第 2 棒、第 3 棒分别是谁。
3. 默认顺序：CodeBuddy → WorkBuddy → OpenCode。
4. 如果 WorkBuddy 验收不依赖 CodeBuddy 侦查结果，可以 CodeBuddy ∥ WorkBuddy → OpenCode。
5. 后一棒必须读取或参考前一棒的结论，不能当成三个互相独立的并行任务。

### 2.2 三员工提示词格式规则（历史）

第 1 棒：发给 CodeBuddy
- 必须包含：目标、侦查范围、禁止修改、输出格式（架构/风险报告）。
- 必须要求：不 commit、不 push、不改代码。

第 2 棒：发给 WorkBuddy
- 必须包含：目标、验收范围、禁止修改、输出格式（产品/QA 报告）。
- 必须指定：本轮使用的内置专家。

第 3 棒：发给 OpenCode
- 必须包含：目标、允许文件、禁止文件、测试命令、MCP Chrome 验收要求、commit/push 规则、最终报告格式。
- 必须要求：使用 To-do list 执行，不自动进入下一任务。

### 2.3 员工提示词文本框范围规则（历史）

每个员工的提示词必须放在独立文本框中，不得把多个员工的提示词混在一个文本框里。

### 2.4 OpenCode 与 CodeBuddy 并行工作流（历史）

OpenCode 和 CodeBuddy 不只是一写一审。在代码侦查、风险分析、漏洞分析、架构分析时，OpenCode 和 CodeBuddy 也可以同轮并行执行。

### 2.5 CodeBuddy skills 使用规则（历史）

CodeBuddy 提示词必须显式写 skill。

### 2.6 智能体提示词必须显式写 skill（历史）

所有员工提示词必须显式写 skill。

### 2.7 CodeBuddy / WorkBuddy 结论复核规则（历史）

1. CodeBuddy 报告不能直接接受。
2. 网页端 GPT 必须尝试反驳和检查 CodeBuddy 结论。
3. WorkBuddy 报告也不能直接当最终结论。
4. **CodeBuddy 只输出事实。**

### 2.8 OpenCode 不单独出现规则（历史）

1. OpenCode 是执行员工，只要安排 OpenCode，就必须同时安排 CodeBuddy。
2. CodeBuddy 和 WorkBuddy 可以单独出现。
3. OpenCode 不可以单独出现。

### 2.9 OpenCode 任务打包规则（历史）

OpenCode 不能只执行一个孤立的小文档补丁。如果只是小文档任务，必须与多个低风险小任务合并执行，或搭载在一个大任务后面一起执行。

### 2.10 OpenCode + CodeBuddy 双角色并行侦查规则（历史）

OpenCode 和 CodeBuddy 不只是一写一审。在代码侦查、风险分析、漏洞分析、架构分析时，OpenCode 和 CodeBuddy 也可以同轮并行执行。两者应扮演不同岗位角色。

---

## 3. WorkBuddy 单专家规则（原 §18，已停用）

### 3.1 每轮只能使用一个内置专家（历史）

WorkBuddy 每轮验收只能使用一个内置专家，不得在一轮中同时写两个专家。

### 3.2 可用专家名单（历史）

| 专家名称 | 适用场景 |
|----------|----------|
| 网页端体验师 | 页面真实操作体验、按钮确认、弹窗验证、布局检查、snackbar 检验、操作路径覆盖、真实浏览器验收 |
| 项目经理 | 产品取舍、风险文案评估、功能优先级、用户理解成本、验收报告撰写、自动化测试是否够用、复杂度评估 |

### 3.3 提示词必须显式指定（历史）

WorkBuddy 提示词必须包含类似以下格式：

```
本轮使用 WorkBuddy 内置专家：网页端体验师
```

---

## 4. oh-my-opencode-slim 必用 skill 规则（原 §20，已停用）

> 此规则属于旧 OpenCode 环境。当前 GLM 环境不强制使用 oh-my-opencode-slim。

### 4.1 必用规则（历史）

从现在开始，网页端 GPT 给 OpenCode 的每一个任务提示词，都必须包含：

```
必用辅助 skill：oh-my-opencode-slim
```

### 4.2 适用场景（历史）

`oh-my-opencode-slim` 用于检查和约束 OpenCode / agent / model / prompt / skill / MCP / preset / plugin behavior 相关设置。

### 4.3 配置修改权限（历史）

默认不允许 OpenCode 修改 oh-my-opencode-slim 配置。

---

## 5. 三方架构侦查规则（原 §23，已停用）

### 5.1 规则目的（历史）

架构侦查可以由网页端总设计师、CodeBuddy、OpenCode / Codex / Trae 三方分别完成。三方都不能单独替代最终验收。

### 5.2 三方分工（历史）

| 角色 | 负责 | 不做什么 |
|------|------|----------|
| **网页端总设计师** | 产品目标判断、用户体验取舍、阶段性 Accept / Refuse / Incomplete、是否进入下一阶段 | 不直接相信报告，不让用户承担代码判断 |
| **CodeBuddy** | 查看 GitHub 最新 master、核查真实 diff、核查测试事实、核查文档一致性、核查风险 | 不做最终产品判断，不给 OpenCode 直接下命令 |
| **OpenCode / Codex / Trae** | 执行侧侦查、必要的测试补充、必要的文档更新、必要的最小实现 | 不得自动进入下一任务，不得跳过禁止范围 |

### 5.3 最终结论（历史）

三方可以从不同角度报告，但最终结论必须由网页端总设计师基于以下要素综合给出：
- 用户最新反馈；
- GitHub 最新 master；
- OpenCode / Codex / Trae 报告；
- CodeBuddy 报告；
- WorkBuddy 报告；
- 上一轮提示词目标。

---

## 6. 迁移到当前 GLM 章节的有效规则

以下规则原位于 §14，但与 CodeBuddy/WorkBuddy 工作流无关，仍然有效，已迁移到 `vibe-coding-collaboration-rules.md` 当前 GLM 章节中：

| 原章节 | 内容 | 迁移到 |
|---|---|---|
| §14.7 | 阶段推进与提示词时机规则 | GLM 网页端总流程设计师推进规则 |
| §14.8 | 小任务合并与复杂任务搭载规则 | GLM 任务打包规则 |
| §14.10 | MCP Chrome 本地测试账号规则 | GLM MCP Chrome 规则 |
| §14.14 | 智能体提示词必须使用 To-do list 执行 | GLM To-do 与轨道规则 |
| §14.15 | MCP Chrome 真实测试规则 | GLM MCP Chrome 规则 |
| §14.16 | 主线关键路径与小步骤双轨并行规则 | GLM To-do 与轨道规则 |

这些规则的当前版本以 `vibe-coding-collaboration-rules.md` 中的 GLM 章节为准，本归档不重复其正文。
