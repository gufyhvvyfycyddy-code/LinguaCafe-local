# LinguaCafe Vibe Coding 协作原则

> LinguaCafe 长期本地改造中，网页端 GPT / 总流程设计师（产品决策方）与本地 GLM Agent（执行方）的协作规范。
> **当前工作流（2026-07-13 起）：GLM 单 Agent 闭环。** CodeBuddy / WorkBuddy / 三员工工作流已停用（见 §1.5）。
> 本文是协作规则和红线，不是业务总计划；业务现状先读 `docs/DOCUMENTATION_INDEX.md` 和 `docs/plans/current-working-handoff.md`。

---

## 0. 如何阅读本文

1. 新任务不要把本文全文当作唯一上下文。先读 `docs/DOCUMENTATION_INDEX.md`，按任务只加载相关章节。
2. 本文只记录长期协作规则、安全红线、验收规则和角色边界；业务计划放在 master plan，当前入口放在 current-working-handoff，历史材料放在 `docs/HISTORY_INDEX.md`。
3. 如果本文与当前任务提示词冲突，先遵守更严格的安全边界；如果是产品方向冲突，交给网页端总设计师判断。
4. 文档规则是软约束；高风险规则应逐步转为 tests / smoke / harness，候选见 `docs/plans/spec-to-harness-candidates.md`。

### 0.1 最重要红线

- 不改 `.env`。
- 不清库，不运行 `migrate:fresh` / `db:wipe` / drop / truncate。
- 不修改 `AGENTS.md`，除非任务明确授权。
- 不处理 `.omo/`。
- 不使用 force push。
- 不运行 DCP，除非任务明确授权。
- 不运行 notification script，包括 `notify.ps1`。
- 不自动进入下一任务。
- 页面任务不能用 API 调用替代 MCP Chrome 真实页面验收。

### 0.2 目录

| 章节 | 主题 |
|---|---|
| 1-3 | 网页端 GPT、本地 Agent、用户职责 |
| 1.5 | **GLM 单 Agent 闭环规则（当前生效）** |
| 4-4.y | 报告验收、~~CodeBuddy 风险角色~~（已停用）、上下文/文档分层 |
| 5-9 | 文件范围、实验任务、计划保全、Anki 参考、报告格式 |
| 10-13 | Anti-Mud、Architecture Gate、MCP 视觉验证、安全红线 |
| 14 | ~~三员工工作流与 OpenCode / CodeBuddy / WorkBuddy 规则~~（已停用） |
| 15-16 | 服务边界、切换对话后的接续规则 |
| 17-21 | notification script、~~WorkBuddy~~（已停用归档）、GLM 复杂度、GLM skills、网页端总设计师推进 |
| 22-27 | 进度说明、双层架构侦查、进度条、计划审查、模式选择、高内聚低耦合架构 |

### 0.3 文档治理原则

1. 文档不是越多越好；过期文档和临时修复记录会污染上下文。
2. 入口、总计划、模块文档、ADR、测试/smoke/harness、历史归档必须分层。
3. spec 只固化已经拍板、长期有效、后续不应反复讨论的决定。
4. MVP 或探索阶段可以少写 spec；进入长期迭代后必须补边界和验收。
5. 不把未来规划写成已实现，不把临时方案写成永久原则。
6. 不把所有规则合并成一个巨型万能文档；按需加载比一次性读全更可靠。
7. 旧 handoff / 旧 next task / 旧 phase status 必须降权为历史参考。
8. 关键边界不能只写“不要破坏”，应逐步进入 tests / smoke / harness。

## 1. 网页端 GPT 的职责

1. 提示词必须尽量复杂、全面、详细。
2. 复杂不是无边界，而是：

   - 多 Phase 结构。
   - 明确文件范围（允许改/禁止改）。
   - 明确验收标准。
   - 明确测试命令。
   - 明确禁止事项。
   - 明确最终报告格式。
   - 明确停止条件。
3. 不允许为了让任务跑久而加入 sleep、死循环、无意义扫描或重复测试。
4. 每隔 3 轮，网页端 GPT 必须做一次提示词质量审查。
5. 提示词质量审查内容包括：

   - 之前 3 轮有没有越界改动。
   - 有没有测试不足。
   - 有没有报告不真实。
   - 有没有把产品判断交给本地 Agent。
   - 有没有允许文件范围漏项。
   - 有没有安全风险。
6. 网页端 GPT 可查询官方文档、论坛、博客、程序员经验分享，吸取提示词优化经验。

## 2. 本地 GLM Agent 的职责

1. 本地 Agent **不做网络搜索和产品判断**（Anki 官方资料核查除外，见 §8）。
2. 本地 Agent **只执行明确任务**、跑测试、报告结果。
3. 遇到产品不确定，不自行发挥，按提示词执行，并在报告中列为 follow-up。
4. 不为了延长时间添加 sleep、死循环或无意义任务。
5. 任务要做得完整、细致、可验收。
6. 在 GLM 单 Agent 闭环模式下（§1.5），GLM 同时承担实施、架构、事实核查、页面验收和最终验证。

## 3. 用户的责任

1. 用户不需要判断代码正确性。
2. 用户通过网页端 GPT 管理产品方向。

## 1.5 GLM 单 Agent 闭环规则（当前生效，2026-07-13 起）

> **本节是当前唯一生效的工作流规则。** §4.x（CodeBuddy 风险角色）、§14（三员工工作流）、§18（WorkBuddy 单专家规则）已停用，仅保留为历史参考。

### 1.5.1 工作流取消声明

1. CodeBuddy 与 WorkBuddy 工作流已正式取消。
2. 不再单独生成、发送或等待 CodeBuddy / WorkBuddy 提示词和报告。
3. 不再要求用户把报告转交 CodeBuddy 或 WorkBuddy。
4. 不再出现任何 CodeBuddy / WorkBuddy 后置任务。

### 1.5.2 GLM 内部轨道划分

GLM 在同一任务中内部划分以下轨道，不再分配给外部 Agent：

| 轨道 | 职责 | 前缀 |
|------|------|------|
| 实施轨道 | 架构优化、代码开发 | `DEV-*` |
| 架构轨道 | ADR、实施计划、代码事实侦察 | `ARCH-*` |
| 代码事实核查轨道 | 重新读取最终 diff，不能只根据执行记忆写报告 | `FACT-*` |
| 页面体验验收轨道 | 使用 MCP Chrome 真实操作 | `UX-*` |
| 最终验证轨道 | 测试、构建、数据库、Git | `VERIFY-*` |

### 1.5.3 事实核查要求

1. GLM 的事实核查必须重新读取最终 diff，不能只根据自己的执行记忆写报告。
2. 必须按文件列出：为什么修改、是否在允许范围、是否越界。
3. 必须搜索：ReviewLog 写入、FSRS 写入、lifecycle 写入、AI provider 调用、migration、localStorage、Math.random 卡片选择、单个 exclude session 方案残留、CodeBuddy / WorkBuddy 活跃规则残留。
4. 核查测试是否真的运行。
5. 核查 MCP Chrome 是否真的操作。
6. 核查报告中的数字与终端输出一致。
7. 核查文档状态与真实验收一致。
8. 不把"应该可以"写成通过。
9. 发现问题必须回到开发轨道修复并重验。

### 1.5.4 页面验收要求

1. GLM 的页面验收必须使用 MCP Chrome 真实操作。
2. API、命令行、Playwright、截图推测和代码阅读不能代替 MCP Chrome。
3. 如果 MCP Chrome 不可用：标记 Incomplete；不使用替代工具；不伪造体验验收。

### 1.5.5 报告要求

GLM 报告必须包含：
1. 真实修改文件。
2. 真实 diff。
3. 越界情况。
4. 自动测试。
5. MCP Chrome 结果（或 Incomplete 声明）。
6. Console。
7. Network。
8. Git 状态。
9. 是否进入下一任务：否。

不允许把"自审"写成一句空泛声明；必须有文件、diff、测试和浏览器证据。

### 1.5.6 验收与停止规则

1. 网页端总流程设计师仍然负责最终判断。
2. GLM 报告不能自动等于 Accept。
3. GLM 完成任务后必须停止，不得自动进入下一任务。
4. 网页端总流程设计师可以独立复跑测试或重新检查代码。
5. 如果事实核查发现当前实现错误，GLM 必须在当前任务内修复并重新验证。
6. 如果浏览器验收发现体验错误，GLM 必须在当前任务内修复并重新验收。

### 1.5.7 历史规则保留说明

§4.x、§14、§18 保留为历史参考，但：
- 不再适用于当前任务。
- 不再生成 CodeBuddy / WorkBuddy 提示词。
- 不再要求三员工接力棒。
- 不再要求多文本框分员工。
- 如果历史规则与 §1.5 冲突，以 §1.5 为准。

### 1.5.8 正式目标模式复合任务规则

1. 每个正式目标模式编程任务必须同时包含至少一个来源于真实代码事实的 `ARCH-*`，以及至少一个用户可感知或可执行验证的 `DEV-*`。
2. 验收、测试、浏览器 smoke 和文档更新是复合任务中的轨道，默认不能成为整个目标模式任务的唯一内容。
3. 小补丁应与同领域的架构修复、回归保护和功能关闭合并为一个可验证闭环。
4. 只有存在不可绕过的安全 Gate、缺少产品决定、缺少外部授权或环境，或继续开发必然越界时，才允许纯验收或纯文档任务。
5. 复杂度只表示执行预算，不扩大允许修改的文件范围或产品范围。
6. 不得为了满足 `ARCH + DEV` 形式要求而制造无意义的 DTO、Repository、Interface、Adapter 或其他抽象。
7. 本地 Agent 完成当前复合任务后必须停止，不得自动进入下一任务。

## 4. 报告验收规则

1. 本地 Agent 的测试报告不能直接作为事实。
2. 网页端 GPT 必须查看 GitHub 最新代码（而非仅依赖本地报告）。
3. 测试失败必须写失败——不允许把失败测试写成通过。
4. 如果功能接入了错误入口，必须在报告中标记为 follow-up。
5. 如果提示词中列出的文件范围和实际修改的文件有差异，必须报告。

## 4.x ~~CodeBuddy 风险角色~~（已停用 2026-07-13，归档）

> **⚠️ 已停用并归档：** 本节描述的 CodeBuddy 风险角色已随 CodeBuddy / WorkBuddy 工作流取消而停用。完整旧规则正文已迁入 `docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md` §1。当前工作流见 §1.5 GLM 单 Agent 闭环规则。不再生成 CodeBuddy 提示词，不再安排 CodeBuddy 后置复核。本节不保留旧命令正文，避免被误执行。
>
> 风险降权 / 升权规则中仍然有效的部分（ReviewCard/ReviewLog/WordSense 写入升权、不可逆操作升权等）已并入 §1.5.3 事实核查要求与 §13 安全红线。

## 4.y AI 上下文、文档分层与可执行验收规则

### 4.y.1 文档不是硬约束

1. AI 不是数据库，也不是规则引擎。写进文档不等于形成稳定约束。
2. 文档能降低错误概率，但不能替代测试和验收。
3. 上下文太满时，关键规则会被淹没。
4. 过期文档、临时修复、废弃方案必须标明状态，否则会污染上下文，导致 AI 后续任务基于已过时假设做决策。
5. 文档没有优先级时，AI 会替人拍板。

### 4.y.2 文档分层

6. 项目文档应分三层：
   a. **根级长期协作规则**（如本文件） — 流程、角色、安全红线、报告格式。
   b. **项目地图 / 文档索引** — 帮助新 AI 快速了解项目结构和当前阶段。如 master plan、roadmap、current-working-handoff。
   c. **模块级事实与测试文档** — 每个模块做什么、为什么这样做、边界、历史决策、测试怎么跑。
7. 长期规则不写过多业务细节，业务细节放在模块文档中。

### 4.y.3 模块文档要素

8. 模块文档必须写清：
   - 这个模块做什么；
   - 为什么这样做；
   - 边界是什么；
   - 关键历史决策；
   - 测试 / smoke / harness 的位置和运行方式。
9. 禁止事项必须具体，不要只写"不要影响旧功能"：
   - 禁止改哪些文件；
   - 禁止改哪些接口字段；
   - 不绕过哪些权限；
   - 不触碰哪些数据写入；
   - 必须跑哪些测试。

### 4.y.4 关键边界必须可执行

10. 高风险业务必须优先变成可执行验收：
    - 已经能跑的核心功能：登录、权限、数据保存、删除、导入导出、复习记录。
    - 反复踩坑的 bug。
    - 出错后难排查的链路。
11. test / smoke / harness 的最低标准：
    - 准备测试条件；
    - 执行检查；
    - 输出通过或失败。
12. AI 是考生，测试 / harness / smoke 是考场和判卷系统。AI 自己说"通过"不能当验收结果。
13. 验收必须发生在 AI 输出之外。
14. 当报告中出现 API 替代页面验收时，必须标记为 Incomplete，除非任务本身明确不要求页面验收。

### 4.y.5 Codex 也不能免验收

15. Codex 虽然能力更强，但仍然受上下文污染、概率生成和验收缺失影响。
16. 给 Codex 的任务可以更大，但验收边界不能取消。
17. Codex 任务必须有：
    - 当前阶段目标；
    - 禁止范围；
    - 验收命令；
    - 关键数据风险；
    - 最终报告格式；
    - 不自动进入下一任务。

## 5. 文件范围漏项处理

1. 如果实现必须新增提示词未列出的支撑文件（例如异常类、辅助类），最终报告必须说明。
2. 如果提示词未列但确实必须新增的文件，报告中标记为"必要越界"，等待网页端验收。
3. 如果发现本可以复用已有文件但为了省事新增了文件，报告为"非必要越界"。

## 6. 实验任务规则

1. 实验任务如果无法真实运行（如无法调用模型 API），不得伪造实验结果。
2. 模型对比文档必须说明：样本内容、测试次数、评分标准、是否真实调用。
3. 文档实验任务可以不改业务代码——全部产出应为文档。
4. 本地 Agent 不做产品结论，只记录实验结果和原始数据。
5. 网页端 GPT 负责最终产品判断，包括选择推荐模型。
6. 如果本地工具模型路由异常或无法调用外部 API，必须在报告开头说明。

## 7. 计划保全规则

1. 已进入 roadmap 的方向不得静默删除。
2. 临时插入任务不等于取消原计划。
3. 如果某个方向暂不做，必须写为 follow-up / planned / postponed，不允许写为"取消"。
4. 不允许把"本轮不做"写成"以后不做"。
5. 本地 Agent 不得擅自改变产品优先级。
6. 网页端 GPT 负责判断是否调整优先级。
7. 用户明确强调保留的方向，必须写入计划文档。
8. 后续每次更新 roadmap，要检查是否误删旧计划。
9. 以下后续方向始终保留（即使中间穿插其他任务）：

   * AI-Reading-Assist-3 到 AI-Reading-Assist-9
   * Lemma-Origin-1
   * Reader-UI-1
   * Mgmt-7-c / Mgmt-8 / Mgmt-9
10. 如果因为技术限制无法实现某功能，必须记录原因和替代方案，不能静默放弃。
11. 每次更新 roadmap（`linguacafe-fsrs-roadmap.md`）时，必须同步检查 master plan（`linguacafe-master-plan.md`）是否也需要更新。
12. master plan 和 roadmap 的内容不能冲突。如有冲突，以 master plan 为准并修正 roadmap。

## 8. Anki 参考规则

1. 涉及以下主题时，网页端 GPT 在提出产品问题或生成开发提示词前，必须先查看 Anki 相关信息：

   - SRS / FSRS
   - 复习卡
   - 复习记录
   - 删除 / 重置 / Forget / Reset
   - Card Info
   - Browser / Browse
   - 统计图
   - review history / revlog
   - 学习队列
   - answer buttons
   - deck options / preset
2. 信息来源优先级：

   - Anki 官方手册
   - Anki 官方代码仓库
   - Anki 官方论坛 / 功能讨论
   - 可靠社区经验
3. **不盲目照搬 Anki**：

   - Anki 是参考基准。
   - LinguaCafe 是阅读学习工具，不是 Anki 克隆。
   - 如果 Anki 做法与 LinguaCafe 已明确的 sense-only 规则或用户明确产品决定冲突，以 LinguaCafe 规则和用户决定为准。
   - 偏离 Anki 时必须在报告中说明原因。
4. 本地 Agent 如果被要求参考 Anki，必须在报告中说明：

   - 查看了哪些 Anki 资料。
   - 借鉴了什么。
   - 哪些地方没有照搬。
5. 不允许在没有查 Anki 的情况下，凭想象回答 "Anki 大概怎么做"。

### 8.6 Anki 冻结产品规则

> **问题来源**：Queue Order 任务发现，Anki 已有明确默认值和设置选项的问题被反复向用户提问，浪费产品决策周期。

1. **Anki 已有明确默认值和设置选项的问题，不再向用户重复提问。** 包括但不限于：
   - 新卡放前、放后还是混合（Anki 默认 mix）。
   - 积压时如何排序（Anki 默认 due date + random）。
   - 跨日学习卡与复习卡的相对位置（Anki 默认 mix）。
   - 新卡收集顺序（Anki 默认 created asc）。
2. **采用 Anki 默认作为 LinguaCafe 默认。** 除非有明确产品理由偏离。
3. **保留适合 LinguaCafe 当前数据模型的选项。** 不机械照搬 Anki 的所有选项。
4. **deck、subdeck、preset、sibling、card type 等当前不存在的能力不机械照搬。** LinguaCafe V1 不实现这些概念。
5. **发生偏离时必须写明：**
   - Anki 原方案；
   - LinguaCafe 映射；
   - 偏离原因；
   - 用户影响。
6. **只有 Anki 没有对应设计，或与 sense-only / 阅读主线冲突时，才提出产品问题。** 不得把 Anki 已有明确答案的问题重新抛给用户。
7. **偏离记录位置**：偏离 Anki 默认的决策必须写入对应 ADR 和 implementation plan，不得只写在最终报告中。

### 8.7 Anki 参考优先规则

> **问题来源**：Task 2000-15。SenseStudyCard 共享卡面展示契约冻结时，需要把"Anki 优先于普通网页产品习惯"明确写入长期协作规则，避免后续卡面 / 字段显示 / 撤销 / 学习步骤等议题被普通网页产品习惯替代 Anki 已有设计。

1. **覆盖范围**。所有与 Anki 相关的功能，在产品判断前必须先核查 Anki 官方手册或官方源码。覆盖范围包括但不限于：
   - 评分（rating buttons / answer buttons）；
   - 队列（review queue / learning queue / day-learn queue）；
   - 自定义学习（custom study / filtered deck）；
   - 卡片正反面（front / back / question side / answer side）；
   - 字段显示（field rendering）；
   - 空字段处理（empty field behavior）；
   - 快捷键（hotkeys）；
   - 撤销（undo）；
   - 学习步骤（learning steps）；
   - 复习顺序（review order / new card order / review sort order）。

2. **决策顺序固定为**：
   1. **Anki 官方设计**（manual / 官方源码）；
   2. **保留 Anki 核心语义的网页适配**（如 LinguaCafe 在 Web 端实现 Anki 语义）；
   3. **Anki 没有对应设计时，才进行 LinguaCafe 独立产品判断**。
   该顺序不允许跳级，不允许在没有查阅 Anki 的情况下直接进入第 3 步。

3. **不得把"普通网页产品习惯"直接替代 Anki 已存在的设计。** 例如：Anki 卡面默认先 Question 后 Show Answer，普通网页产品"一次性展开所有内容"的习惯不得替代该语义；Anki conditional replacement 在字段非空时才渲染标签和内容，普通网页"无内容时显示'无'"的习惯不得替代该语义。

4. **Anki 没有统一默认样式时，文档必须明确写**：
   > "Anki 没有冻结该视觉样式；以下为 LinguaCafe 项目适配。"
   该声明必须出现在对应 ADR 或 implementation plan 中，不得只写在最终报告里。

5. **本次卡面契约（Task 2000-15 冻结）的 Anki 依据**：
   - Anki 卡面支持 HTML/CSS 自定义（Anki Manual — Styling & HTML）；
   - Anki conditional replacement 支持字段非空时才渲染标签和内容（Anki Manual — Card Generation / Conditional Replacement）；
   - Anki 正常流程为：先显示 Question，Show Answer 后显示 Answer（Anki Manual — Studying / Questions）。
   后续 SenseStudyCard 实现必须遵守该依据，不得偏离为"一次性展开"或"无内容显示'无'"。

6. **官方来源名称**。文档中记录以下名称即可，不需要复制手册原文：
   - Anki Manual — Card Generation / Conditional Replacement；
   - Anki Manual — Styling & HTML；
   - Anki Manual — Studying / Questions。

7. **不得把论坛意见或第三方模板写成 Anki 官方默认设计。** 第三方 Anki 模板（如 popular shared decks、社区 CSS 模板）、Reddit / Discord 论坛讨论、博客经验帖只能作为社区经验参考，不能与官方手册 / 官方源码并列作为"Anki 默认设计"。

8. **本节与 §8 / §8.6 的关系**：
   - §8 规定涉及 Anki 相关主题时必须先查 Anki 资料，以及"不盲目照搬"原则；
   - §8.6 规定 Anki 已有明确默认值的设置项不再向用户重复提问；
   - §8.7（本节）规定决策顺序固定、不得用普通网页产品习惯替代 Anki、以及 Anki 没有冻结视觉样式时必须声明的项目适配条款。
   - 三节互补，不冲突；如有冲突以更严格的边界为准。

### 8.8 GLM 任务最低复杂度 100 规则

> **复杂度规则的唯一权威正文是 §19**（Task 2000-17 收敛）。本节仅保留短引用，避免规则漂移。
>
> 简述：所有 GLM 任务复杂度最低为 100；100 是最低基线，不是上限；复杂任务可设为 120、150 或更高；提高复杂度不扩大范围。详见 §19。

## 9. 最终报告格式规则

自本轮起，OpenCode Agent 的最终报告必须采用以下结构：

```markdown
# <任务名> 完成报告

## 1. Git 状态
- 开始 commit：
- 新 commit：
- 是否 push：
- git status：

## 2. 实际修改文件
| 文件 | 类型 | 修改内容 |
|------|------|----------|
| path/to/file | 代码/文档/测试 | 简要说明每个代码文件改了什么 |

## 3. 代码改动核验点
- 文件 A：
  - 改了什么函数/组件/方法。
  - 行为是否改变。
  - 有无数据风险。
- 文件 B：...

## 4. 测试与构建
- 测试命令及结果。
- 构建命令及结果。
- 浏览器 smoke（如有）。

## 5. 文档更新
- 修改了哪些计划文档。
- 是否更新 master plan / roadmap / Latest commit。

## 6. 简短合规确认
- 代码边界：只修改允许的范围。
- 数据边界：未清库、未误删、未跨用户/语言。
- 安全边界：未改 .env、未用 --force、未执行 DCP、未修改 AGENTS.md、未处理 .omo/。
- 计划边界：未删除既有计划、未自动进入下一任务。
- 涉及数据删除的任务：说明删除范围和隔离条件。
- 涉及 Anki 参考的任务：新增 Anki 参考资料、借鉴点、偏离点小节。
```

## 10. Anti-Mud / 避免屎山规则

### 10.1 架构纪律

1. **不把多个职责继续塞进同一个大组件。** 如果一个 Vue 组件同时负责布局、数据请求、状态同步、表单、候选列表、响应式判断，暂停加功能，先做架构侦察。
2. **每次改 UI 前，先判断问题类型：**
    - 布局问题 → 改 CSS / 模板结构。
    - 数据流问题 → 改 store / props / API。
    - 视觉样式问题 → 改主题 / class。
    - 业务逻辑问题 → 改 Service / Controller。
3. **不用一个任务同时改太多层。** 前端不应同时改后端业务逻辑。
4. **后端保持 Controller → Service → Model 边界。**
5. **前端新增复杂功能时，优先拆为小组件或 helper。** 不继续堆进巨型组件（如 TextReader.vue / TextBlockGroup.vue / VocabularySideBox.vue）。
6. **新增功能或功能优化必须先设计架构接入点。** 执行前先说明它接入现有架构的位置，复用哪些现有组件、service、store、API 或 helper，并说明是否会新增入口、分叉入口或重复逻辑。凡是可能造成宽屏 / 半屏、弹窗 / 侧栏、AI / 词典等路径分叉的任务，必须优先设计统一入口；不允许先堆功能、再事后补架构。网页端 GPT 给任务前也必须先构思架构接入方式，本地 Agent 执行时必须按该接入方式实现，不得自由发挥。最终报告必须说明本轮是否复用了现有架构、是否新增耦合、是否造成分叉、是否需要后续收敛；原因是 AI 会放大已有架构问题，所以必须先控制架构边界，再写代码。

### 10.2 数据写入纪律

7. **AI / 词典 / 手动输入只是"候选来源"。** 不能直接写入学习数据。
8. **任何自动创建 WordSense / ReviewCard / ReviewLog 的任务，都必须单独立项。** 不允许在 UI 任务中"顺手"创建学习数据。

### 10.3 迭代纪律

9. **不为了"完美 UI"无限迭代。** 达到当前目标后提交，剩余问题记录为 follow-up。
10. **修 bug 时必须证明根因。** 不凭截图猜代码。
11. **测试失败必须归因：**
    - 本轮引入。
    - 既有失败。
    - 本地环境阻塞。
12. **每轮报告必须写：**
    - 是否增加耦合。
    - 是否触碰多层。
    - 是否需要后续组件拆分。

### 10.4 任务粒度

13. **如果一个任务修改超过 8 个代码文件，必须在报告中解释为什么没有拆分任务。**
14. **避免"顺手修"：**
    - 不顺手修 lemma。
    - 不顺手修 FSRS。
    - 不顺手改 tokenizer。
    - 不顺手改后端业务逻辑。
14. **下一步前端架构任务建议：**
    - `Reader-Architecture-Scout-1`：阅读页 / 查词栏组件边界侦察，只读，不改代码。

### 10.5 设计理念

15. Big Ball of Mud 经验：缺少清晰架构和持续碎片补丁会导致系统难维护。
16. Technical Debt 经验：短期捷径会增加未来维护成本。
17. Vibe Coding 经验：AI 适合局部实现，但必须由人控制架构、边界、测试和验收。
18. Anki 参考：稳定工作区和字段编辑区要有明确边界，不让工具栏、候选区、字段区互相覆盖。

## 11. Architecture Gate / 架构闸门规则

### 11.1 任务分级

| 等级 | 说明 | 闸门要求 |
|------|------|----------|
| **低风险** | 单文件小修、纯样式、文档更新、smoke 脚本更新 | 不必须启动完整架构闸门，但仍需限制文件范围 |
| **中风险** | 涉及组件 props/events、Vuex 状态、前端 API 调用、工具函数 | 至少使用 `context-engineering` + 开发后 `code-review-and-quality`；涉及接口契约需加 `api-and-interface-design` |
| **高风险** | 跨模块变更、大重构、组件拆分、WordSense/ReviewCard/FSRS/AI lookup/import-export 逻辑变化 | 必须完整启动架构闸门 |

### 11.2 高风险任务标准流程

按此顺序执行：

1. `context-engineering` — 整理最小上下文包
2. `improve-codebase-architecture` — 架构侦查 + 风险报告
3. `api-and-interface-design` — 如果涉及接口契约、store、props/events、payload 变化
4. `documentation-and-adrs` — 如果需要 ADR（架构决策改变时）
5. `doubt-driven-development` — 实施前对抗性审查
6. **网页端 GPT 判断是否进入实施** — OpenCode 不能默认继续开发，不能自 Accept
7. 实施 — 用户确认后才能开始编码
8. `code-review-and-quality` — 实施后质量门

### 11.3 强制高风险区域

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

### 11.4 关键约束

- OpenCode 不能默认继续开发，不能自己 Accept
- 网页端 GPT 是架构闸门的最终判断者
- 用户是产品判断者
- 架构闸门不替代 smoke guard、不替代 PHP 测试、不替代 GitHub 最新代码核验
- 涉及阅读页的改动必须跑 text reader smoke guard
- 最终报告必须直接输出到当前对话窗口
- AGENTS.md 默认禁止修改。修改前必须由网页端 GPT 确认

## 12. MCP 视觉验证优先规则

### 12.1 适用范围

对阅读页、查词栏、复习页、导入导出 UI、任何用户可见交互，优先使用 MCP / webapp-testing / browser automation 做真实浏览器视觉验证。

### 12.2 验收层次

1. **Python smoke guard** 是自动底线，用于快速断言核心行为不变。
2. **MCP 视觉验证** 是更接近用户体验的验收层，模拟真实用户界面交互。
3. 两者互补，不相互替代。

### 12.3 Reader / UI 任务验收顺序

涉及 reader 布局、查词栏、AddSenseForm、AiSuggestionPanel、VocabularySideBox 的任务，验收顺序建议：

1. `npm run development` — 前端构建通过
2. Python smoke guard — 快速自动断言
3. MCP / webapp-testing 视觉验收 — 真实浏览器交互验证
4. 必要的 PHP tests — 后端行为验证

### 12.4 视觉验证检查项

MCP 视觉验证必须检查：

- 页面是否真正打开（无白屏、无报错）
- 是否能真实点击目标元素（点击后目标响应正确）
- 目标面板是否可见（DOM 存在且视觉可见）
- 是否被工具栏或浮层遮挡（z-index / overlap 检查）
- 表单是否在 viewport 内（可滚动到可见区域）
- 900px / 窄屏 fallback 是否正常（响应式行为）
- Network 中是否出现禁止请求，例如误 POST `/senses/manual`
- 截图是否保存到指定目录

### 12.5 验证失败处理

1. **先归因**：定位失败根因（回归、环境、脚本、契约变更）。
2. **属于本轮允许文件范围**：可以修复并重新验证。
3. **超出任务边界**：必须停止并报告网页端 GPT，不能自行扩大任务范围。
4. **不允许降低断言标准**：不允许为了通过验证而放宽检查条件。
5. **不允许绕过产品契约**：验证标准以产品要求为准，不以通过验证为目标。

### 12.6 报告安全要求

报告中禁止写入以下内容：

- 账号
- 密码
- cookie
- auth / session 文件内容
- token
- `.env` 内容

### 12.7 边界规则

OpenCode 不能把 MCP 验证失败当作自动扩大任务范围的授权。验证失败必须先归因，再根据任务边界决定修复还是报告。

## 13. 安全红线

- 不允许修改 `.env`。
- 不允许清库、`migrate:fresh`、`db:wipe`、drop / truncate。
- 不允许修改 `AGENTS.md`。
- 不允许处理 `.omo/`。
- 不允许 force push。
- 不允许自动进入下一任务。

## 14. ~~三员工工作流~~（已停用 2026-07-13，归档）

> **⚠️ 已停用并归档：** 本节描述的三员工工作流（CodeBuddy / WorkBuddy / OpenCode 接力棒）已正式取消。完整旧规则正文（§14.1-§14.6, §14.9, §14.11-§14.13 的 CodeBuddy/WorkBuddy 专属内容）已迁入 `docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md` §2。当前工作流见 §1.5 GLM 单 Agent 闭环规则。本节不保留旧命令正文，避免被误执行。
>
> 原 §14 中仍然有效的规则（MCP Chrome、To-do、双轨并行、任务打包、阶段推进）已迁移到以下当前 GLM 章节：
> - §14a GLM 任务打包规则（原 §14.8、§14.12）
> - §14b GLM To-do 与轨道规则（原 §14.14、§14.16）
> - §14c GLM MCP Chrome 真实测试规则（原 §14.10、§14.15）
> - §21 网页端总流程设计师推进规则（原 §14.7）

## 14a. GLM 任务打包规则

1. 低风险、同一文件、同一主题的小任务可以合并给 GLM 一次执行。
2. 复杂任务可以顺手搭载一个无风险的小文档更新或规则记录。
3. 合并任务必须列清每个子任务的允许文件、禁止范围和验证要求。
4. 高风险任务不能因为合并而模糊边界。
5. 删除、权限、数据库、FSRS、批量操作仍必须明确验收标准。
6. 如果合并会导致报告不清楚、验收困难，就拆开。
7. GLM 不能只执行一个孤立的小文档补丁。如果只是小文档任务，必须与多个低风险小任务合并执行，或搭载在一个大任务后面一起执行。
8. "多个小任务"必须同主题或同文件范围，不能把无关任务硬塞在一起。
9. 合并任务必须在提示词中列出每个子任务：目标、允许文件、禁止范围、验证方式、报告字段。
10. 删除、权限、数据库、FSRS、批量操作、导入导出等高风险任务，不能因为"打包"而降低验收标准。
11. **纯小文档规则修正不得单独开 GLM 任务。** 应搭载在主线任务中，或与足够多的低风险小任务合并。不准为了一个 docs 修正单独出一个 GLM Phase。

## 14b. GLM To-do 与轨道规则

1. 网页端 GPT 给 GLM 的提示词，必须要求 GLM 使用 To-do list 执行。
2. 不使用计划模式（直接进入执行而非先输出计划）。
3. To-do list 应像计划已经完成后那样直接进入执行。
4. 最终报告必须说明 To-do list 是否全部完成。
5. 如果某项无法完成，必须说明卡住原因，不能跳过后继续伪装完成。
6. To-do list 必须区分：主线架构（ARCH-*）、主线开发（DEV-*）、支撑小步骤（SUPPORT-*）、验证（VERIFY-*）。
7. ARCH 和 DEV 是主线关键路径。
8. **主线关键路径与小步骤双轨并行规则**：
   - 主线关键路径（ARCH-/DEV-）：任务规格中明确要求的架构优化、后端开发、前端开发、测试先行等关键路径。主线一旦具备开始条件，必须立即推进。
   - 支撑小步骤（SUPPORT-）：文档修正、规则同步、轻量测试、guard、注释整理、静态核验、ADR 修正、handoff 更新等低风险工作。支撑小步骤的目的是提高主线质量，而不是制造工作量。
   - 验证与验收（VERIFY-）：自动化测试、Node guard、npm build、db:doctor、MCP Chrome 真实验收、git commit/push、最终报告。
9. 复杂主线可以由大量低风险、可独立核验的小步骤组成。单个步骤简单，不代表整个任务应拆成简单微任务。
10. 文档修正、规则同步、轻量测试、guard、注释和静态核验优先搭载在相关主线任务中完成。
11. 主线架构或开发一旦具备开始条件，必须立即推进。支撑小步骤不得成为主线的虚假前置条件。
12. 只要主线仍有可执行工作，就不得为了完成文档润色、格式整理或广泛扫描而暂停主线。
13. 独立、无共享写入冲突的读取、测试、文档核对可以并行。同一文件、同一数据库状态或同一关键契约上的修改必须串行。
14. 禁止并行修改同一文件。禁止并行运行会争用同一测试数据库的数据写入测试。
15. 小步骤失败时：若不影响安全和核心契约，记录 Incomplete 并继续主线；若证明主线架构错误或有数据风险，暂停相关主线部分。
16. 禁止为了凑复杂度加入无意义的小步骤。禁止把多个无关高风险任务强行并行。
17. 任务完成后仍然不得自动进入下一主线。不得用 SUPPORT 数量冒充主线完成。

## 14c. GLM MCP Chrome 真实测试规则

1. 所有涉及浏览器、页面、用户流程、按钮、弹窗、导入、查词、阅读页、review 的测试，必须使用 MCP Chrome 操控 Google Chrome 真实执行。
2. 不允许用"预期行为"、"看代码应该可以"、"截图推测"代替真实测试。
3. 命令行测试、单元测试、doctor 命令只能作为辅助验证，不能替代浏览器真实验收。
4. 如果任务完全不涉及浏览器，也必须在报告中说明为什么 MCP Chrome 不适用。
5. 如果 MCP Chrome 不可用，报告不可用原因，不要伪造测试结果，标记 Incomplete。
6. MCP Chrome 验收任务如果涉及登录，网页端 GPT 给 GLM 的任务提示词里**必须直接提供本地测试账号和密码**。
7. GLM 登录失败时，必须先使用提示词提供的账号和密码尝试登录。
8. 如果该账号不存在或登录失败，GLM 可以创建同名本地管理员测试账号。
9. 该账号仅用于本地 MCP Chrome 验收，不代表线上账号。
10. GLM 最终报告必须说明：是否使用了任务提示词提供的本地账号；是否新建了管理员测试账号；是否登录成功；登录失败时的具体原因。
11. 禁止把具体账号密码写入 GitHub 文档、代码、测试、日志或最终报告。
12. 具体账号密码只允许出现在当前任务提示词中。文档只写"使用当前任务提示词提供的账号和密码"，不要写具体邮箱和密码。
13. 禁止修改 .env。禁止 migrate:fresh / db:wipe / 清库。
14. MCP Chrome 登录必须使用同一 browser context（`isolatedContext` 参数）。
15. 禁止用 fetch 登录替代页面登录。禁止用 login POST 成功替代页面登录成功。
16. 登录后必须真实打开目标页面。
17. 若 `navigate_page` 导致 Cookie 丢失，必须改用同 context 登录流程（参考 `docs/plans/mcp-chrome-local-smoke-playbook.md`）。
18. 若仍失败，报告 Incomplete，不得伪造页面验收。

## 15. 服务边界 / 架构优化原则

1. **Controller 只做编排。** Controller 可以负责读取 Request、调用 Service、组装 HTTP response，但不应长期持有复杂 query、导出格式、row payload、写操作事务等大块逻辑。
2. **按职责拆 Service，而不是按文件行数机械拆分。** 已形成的方向包括：
   - `ReviewCardManageQueryService`：管理页查询、筛选、排序。
   - `ReviewCardExportService`：JSON / Anki TSV / CSV 的导出字段、转义、格式化。
   - `ReviewCardManageItemSerializerService`：管理页行数据 / 单卡响应 payload。
3. **下一步服务边界重点是危险写操作。** 编辑、归档/恢复、立即到期、重置、彻底删除、批量归档/恢复/删除，都涉及 ReviewCard、WordSense、ReviewLog 或 EncounteredWord 语义，不能直接边写边抽。
4. **危险写操作必须先 scout，后实施。** 先由 GLM 内部 FACT 轨道只读侦察现有写操作调用链、事务边界、权限隔离、日志保留规则，再由 GLM 内部 UX 轨道判断产品风险和用户提示，最后才决定是否让 GLM 抽服务。
5. **不要把删除类逻辑和普通重构混在一起。** 删除、reset、bulk 操作必须作为独立 Phase；每个 Phase 都要说明数据是否可恢复、是否保留 ReviewLog、是否影响 WordSense 状态。
6. **row payload 字段契约必须稳定。** 管理页行数据字段被 data、export、edit、enabled、dueNow、reset 等多个流程共用；字段 key、日期 ISO 格式、`missing_*` 动态计算、`source_kind` 语义都不能随重构改变。
7. **以"让 OpenCode 更安全接盘"为优先排序。** 架构优化优先处理最容易导致 Agent 误改、越界、重复实现、字段漂移、数据误删的部分，而不是追求形式上的"更优雅"。
8. **每次架构优化都要报告三件事：**
   - 是否减少 Controller 职责。
   - 是否新增清晰 Service 边界。
   - 是否保留 observable behavior。

9. **AI 架构原则 / 防屎山原则**

   1. AI 可以加速实现，但不会自动保证架构正确。
   2. AI 往往会延续既有架构；好架构会被放大，坏架构也会被放大。
   3. 代码局部看起来合理，不代表整体可维护。
   4. 不允许在边界不清楚时继续堆功能。
   5. 架构优先目标是控制复杂度扩散。
   6. 拆分可以降低大模块复杂度，但拆太碎会增加接口成本。
   7. 每次拆分都要说明：
      - 新模块职责是否一句话能说清。
      - 接口是否比实现更小。
      - 是否减少跨文件跳转。
      - 是否减少状态分叉。
      - 是否减少隐式行为。
      - 是否减少重复逻辑。
      - 是否让测试更容易。
   8. 不把测试当成架构混乱的遮羞布。
   9. 本地 Agent 最终报告必须说明：
      - 本轮是否复用现有架构。
      - 是否新增耦合。
      - 是否造成分叉。
      - 是否新增隐式行为。
      - 是否扩大接口成本。
   10. 对高风险删除、批量、权限、数据库、FSRS 逻辑，必须先侦查再实现。
   11. **批量彻底删除的产品方向**：
       - 删除前显示待删除 lemma / 卡片列表。
       - 不需要输入"确认删除"。
       - 必须弹窗询问是否确定删除。
       - 操作按钮可以是"确定删除"。
       - 具体实现必须另起独立 Phase，不在本 docs 任务中做。
   12. **任何新功能必须先做架构。** 较大改动、跨文件改动、用户流程改动，必须先做架构核验。不管用户如何催促，都不能跳过架构先行。架构先行至少要说明当前代码事实、模块边界、数据流、风险点、不做事项、验收样例。只有架构边界明确后，才允许进入 OpenCode 实现。小修文案、纯样式、明显低风险单点修复可以轻量处理，但仍要说明为什么不需要完整架构文档。

## 16. 切换对话后的接续规则

1. 新对话开始时，先查 GitHub 最新 master，不能只用旧聊天压缩内容判断状态。
2. 当前已知服务边界链路应作为背景：
   - 查询逻辑已抽到 `ReviewCardManageQueryService`。
   - 导出格式已抽到 `ReviewCardExportService`。
   - 行序列化已抽到 `ReviewCardManageItemSerializerService`。
3. 下一步不直接碰危险写操作实现，而是先做写操作边界侦察。
4. 推荐下一轮任务名：`ReviewCardManage-MutationBoundary-Scout-1`。
5. 该任务性质应为只读 scout，不改代码，不 commit，不 push。
6. scout 需要覆盖：
   - `update()`
   - `enabled()`
   - `dueNow()`
   - `reset()`
   - `destroy()`
   - `bulkEnabled()`
   - `bulkDestroy()`
   - `findManageableSenseCard()`
   - `WordSenseService::removeSenseFromReviewSystem()`
   - `ReviewCardService::resetCardToNew()`
7. 只有侦察报告明确说明安全拆分方案后，才允许进入真正的写操作 service extraction。

### 16.1 验收后必须继续给下一阶段提示词

1. 网页端 GPT 在 Accept / Refuse / 阶段验收后，如果下一阶段边界清楚，必须直接给出下一阶段可执行提示词。
2. 禁止只输出总结、复盘、建议暂停、建议不要继续。
3. 只有缺少用户产品选择、风险授权、手动验收结果、账号权限、环境信息时，才允许先提问。
4. 如果下一阶段只是验收补闭环、文档修正、风险复核，也必须给出对应智能体提示词。
5. 文末可以有简短说明，但不能代替下一阶段提示词。
6. 所有提示词必须要求智能体使用 To-do list，不使用计划模式。

## 17. Notification Script 合规规则

> **问题来源**：CompexSmokeAndCompliance-1 发现此前最终报告存在"未运行 notification script：✅"同时又写了"运行 notify.ps1"的矛盾记录。

### 17.1 明确通知脚本定义

- `notify.ps1`（位于 `C:\Users\Administrator\.claude\hooks\notify.ps1`）属于 notification script。
- 任何会触发操作系统通知、声音提醒、弹窗的脚本/工具均属于 notification script。

### 17.2 默认禁止

- 默认禁止运行 notification script，包括 notify.ps1。
- 最终报告中，如果实际运行了 notify.ps1，必须标记为违规。
- 不得在"未运行 notification script"一行写 ✅ 的同时，又实际运行了 notify.ps1。

### 17.3 报告的表述规则

- 如果本轮确实没有运行任何 notification script，写：`未运行 notification script：✅`。
- 如果实际运行了 notify.ps1，写：`未运行 notification script：❌ 运行了 notify.ps1（合规修正后已知违规，后续严禁再犯）`。
- 禁止同时出现"未运行：✅"和"运行了 notify.ps1"的矛盾描述。

## 18. ~~WorkBuddy 单专家规则~~（已停用 2026-07-13，归档）

> **⚠️ 已停用并归档：** 本节描述的 WorkBuddy 单专家规则已随 CodeBuddy / WorkBuddy 工作流取消而停用。完整旧规则正文已迁入 `docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md` §3。当前工作流见 §1.5 GLM 单 Agent 闭环规则。本节不保留旧命令正文，避免被误执行。

## 19. GLM 任务复杂度规则

> **本节是复杂度规则的唯一权威正文**（Task 2000-17 收敛）。§8.8 仅保留短引用。本节覆盖任何与本节冲突的旧复杂度表述。

### 19.1 最低基线与上限

1. **所有 GLM 任务的最低复杂度为 100**。无论是代码任务、文档任务、架构契约任务还是验收任务，复杂度都不允许低于 100。
2. **100 是最低基线，不是最高上限**。
3. 历史曾经出现的旧"上限"表述（"20 上限"和"100 上限"）**已废止**（Task 2000-17）。当前规则不允许任何任务低于 100。
4. 复杂任务可设为 120、150 或更高。提高复杂度时应明确说明额外预算用于哪些环节（例如：更深的架构侦察、更广的回归测试、跨阶段一致性核查）。
5. 模型和档位推荐继续写在 GLM 提示词正文之外，不混入复杂度数值。复杂度只表达任务预算下限，不指定模型档位。

### 19.2 复杂度 100 的预算用途

复杂度 100 表示允许 GLM 进行充分核查、测试和自审，包括但不限于：
- Git 基线核查（本地 / origin / 远端一致）；
- 架构事实核查（先读现有代码再写）；
- red → green TDD（先写测试再实现）；
- 逐文件 diff 自审；
- 文档一致性搜索；
- 可执行 guard 测试；
- db:doctor（如适用）；
- npm build（如适用）；
- MCP Chrome（如适用）；
- 完整最终报告。

### 19.3 复杂度不等于放开边界

复杂度 100（或更高）任务不享有以下豁免：
- 仍然必须遵守 AGENTS.md 中所有禁止修改规则。
- 仍然必须写清阶段、允许文件、禁止文件。
- 仍然必须包含测试命令、MCP Chrome 验收（如适用）、commit/push。
- 仍然必须输出最终报告格式。
- 仍然必须写"是否进入下一任务：否"。
- GLM 内部 FACT 轨道负责事实和越界核查（见 §1.5.3）。
- GLM 内部 UX 轨道负责 MCP Chrome 体验验收（见 §1.5.4）。
- 禁止因为复杂度高而跳阶段、隐藏失败或自动进入下一任务。
- 复杂度只规定预算下限，不改变任务范围、禁止范围、停止规则和验收规则。
- 出现低于 100 的复杂度声明时，GLM 仍按 100 最低预算执行，并在报告开头标记"提示词复杂度低于 100，已按最低 100 执行"。

## 20. GLM skills 使用规则

### 20.1 真实可用 skills 规则

1. GLM 进入任务后，必须先列出环境中真实可用的 skills。
2. 使用与任务匹配且真实存在的 skills。
3. 不得声称调用不存在的 skill。
4. 不存在的 skill 只报告不存在，不得伪造调用。
5. 环境中真实存在的 skills 包括但不限于：brainstorming、context-engineering、api-and-interface-design、documentation-and-adrs、code-review-and-quality、doubt-driven-development、superpowers-trae-fixed-flow。

### 20.2 旧 oh-my-opencode-slim 规则（已停用，归档）

> **⚠️ 已停用并归档：** 旧 §20 强制要求每个 OpenCode 任务使用 `oh-my-opencode-slim` skill。该规则属于旧 OpenCode 环境，当前 GLM 环境不强制使用。完整旧规则正文已迁入 `docs/history/codebuddy-workbuddy-workflow-archive-2026-07-13.md` §4。当前规则见 §20.1。

### 20.3 不覆盖其它硬规则

本条规则不覆盖 LinguaCafe 原有禁止规则：
- DCP 默认禁止；
- notification script 默认禁止；
- `.env` 禁止读取/修改；
- `AGENTS.md` 禁止修改；
- `.omo/` 禁止处理；
- MCP Chrome 真实验收规则仍然有效（见 §14c）；
- GLM 内部 FACT 轨道核查仍然有效（见 §1.5.3）；
- GLM 内部 UX 轨道验收仍然有效（见 §1.5.4）。

## 21. 网页端总流程设计师推进规则

### 21.1 自动前进要求

网页端总流程设计师在每次完成验收判断后，必须自动进入下一阶段，不得停在结论处。

### 21.2 "完成验收判断"的定义

"完成验收判断"包括：
- Accept；
- Refuse；
- 阶段性 Accept；
- Incomplete。

### 21.3 自动进入下一阶段必须包含的内容

自动进入下一阶段至少包括：
- 判断下一阶段应该做什么；
- 给出模型和档位推荐；
- 给出下一轮 GLM 提示词；
- 如果暂时不适合开发，则给出架构侦查、风险复核、体验验收或文档收口任务。

### 21.4 禁止的行为

- 不允许只写"下一步可以做什么"后停住。
- 不允许只写"等待用户指示"后停住。
- 不允许只给结论不给下一轮提示词。

### 21.5 各结论的后续动作

- **完全 Accept**：必须继续判断下一阶段，可能进入下一个架构候选、体验验收、文档收口、风险审计、roadmap 重新排序等。
- **Refuse**：必须自动给出修复任务提示词。
- **Incomplete**：必须自动给出补验收或补证据任务提示词。
- **阶段性 Accept**：必须自动说明缺口，并给出收口任务提示词。

### 21.6 约束范围

- 本条规则**只约束网页端总流程设计师**（网页端 GPT），不授权本地 GLM Agent 自动进入下一任务。
- GLM 仍然必须在完成当前任务后停止，并在报告中写"是否进入下一任务：否"。
- 网页端总流程设计师可以自动生成下一阶段提示词，但任务是否执行仍由用户复制给本地 GLM Agent。

### 21.7 不覆盖其它硬规则

本条规则不覆盖 LinguaCafe 原有禁止规则：
- GLM 内部 FACT 轨道核查仍然有效（见 §1.5.3）；
- GLM 内部 UX 轨道验收仍然有效（见 §1.5.4）；
- MCP Chrome 真实验收规则仍然有效（见 §14c）；
- DCP 默认禁止；
- notification script 默认禁止；
- `.env` 禁止读取/修改；
- `AGENTS.md` 禁止修改；
- `.omo/` 禁止处理；
- `migrate:fresh` / `db:wipe` / 清库禁止；
- `--force` 禁止。

## 22. 总设计师提示词前进度说明规则

### 22.1 规则目的

每次网页端总设计师给出 GLM 提示词之前，必须先给用户一个简短进度说明。这防止总设计师自身丢失上下文，也让用户了解当前阶段。

### 22.2 进度说明必须包含的内容

说明不需要很长，但必须包含：
- 当前仍在进行中的主要未完成计划；
- 每个计划的大概进度条或百分比；
- 本轮提示词推进哪一个计划；
- 本轮完成后预计进度变化。

### 22.3 示例

```
当前主线进度（固定五条）：
- 总体架构收口：███████████████░░░░░ 79%
- 复习主线稳定：████████████████░░░░░ 85%
- 页面真实验收：█████████████░░░░░░░░ 65%
- AI 示意卡规划：██░░░░░░░░░░░░░░░░░░░░ 10%
- 前端入口整理：████████████████░░░░░░ 50%

本轮推进：AI 示意卡规划 → 架构侦查
预计完成后：AI 示意卡规划 → 25%，总体架构收口 → 81%
```

### 22.4 使用约束

- 百分比是产品判断估算，不是精确测试覆盖率。后续根据 GitHub 最新代码、测试覆盖和真实页面验收结果调整。
- 不允许把进度条写成"已经完成"的假承诺。
- 如果任务失败或 Incomplete，进度不得上调。
- 必须面向产品设计者，不要使用复杂技术术语。
- 进度上涨必须来自 GitHub 真实代码、测试、文档、页面验收或架构侦查成果。不能为了让进度上涨伪造完成。
- 固定五条主线以外的任务（如纯文档修正）属于阶段性支撑，不作为主线推动，可在主任务说明中临时提及。

### 22.5 零进度任务不得单独派发

如果一个任务完成后不会推动任何固定主线进度，就不得作为 OpenCode / Codex / Trae 的单独任务派发，应合并到一个能推动主线进度的复合任务中。纯小修正只能作为复合任务的附带项。

## 23. 网页端总设计师 + GLM 双层架构侦查与最终验收

### 23.1 规则目的

架构侦查由 GLM 内部架构轨道（ARCH-*）执行，最终验收由网页端总流程设计师独立核查。两层都不能单独替代最终验收。

### 23.2 双层分工

| 角色 | 负责 | 不做什么 |
|------|------|----------|
| **GLM 内部架构轨道（ARCH-*）** | 实施侧侦查、代码事实核查、diff 越界核查、必要的测试补充、必要的文档更新、必要的最小实现 | 不得自动进入下一任务，不得跳过禁止范围，不得自 Accept |
| **GLM 内部 FACT 轨道** | 重新读取最终 diff、核查事实和越界、搜索禁止模式（ReviewLog/FSRS/lifecycle/AI/migration/localStorage/Math.random 写入） | 不根据执行记忆写报告，必须重新读取 diff |
| **GLM 内部 UX 轨道** | 使用 MCP Chrome 真实操作页面、Console、Network 验收 | 不用 API/fetch/Playwright/截图推测代替 MCP Chrome |
| **网页端总流程设计师** | 产品目标判断、用户体验取舍、阶段性 Accept / Refuse / Incomplete、是否进入下一阶段、独立核查 GitHub 最新 master 和真实 diff | 不直接相信报告，不让用户承担代码判断 |

### 23.3 最终结论

最终结论必须由网页端总流程设计师基于以下要素综合给出：
- 用户最新反馈；
- GitHub 最新 master；
- GLM 最终报告；
- 上一轮提示词目标。

### 23.4 架构侦查 ≠ 开发授权

架构侦查不能直接等于开发授权。进入实现前必须明确：
- 目标；
- 禁止范围；
- 验收方式；
- 是否需要 MCP Chrome；
- 是否需要 GLM 内部 FACT/UX 轨道复核。

## 24. 进度条显示规则

### 24.1 进度项显示规则

- 已达到 100% 的进度项，默认不显示。
- 未达到 100% 的进度项必须显示。
- 每次回复只显示一遍进度条，不重复显示同一组。

### 24.2 进度条格式

进度条必须区分：
- 当前已完成进度；
- 本轮预计上涨幅度；
- 本轮目标进度。

推荐样式：
```
██████░░░░ 60%  ╌╌╌╌ +20% → 80%
```

解释：
- `█` 表示当前已完成；
- `╌` 表示本轮预计增加；
- `░` 表示仍未完成。

### 24.3 约束

- 不允许把固定五条主线虚假上调。
- 子阶段进度可以单独上涨，但必须标注这是子阶段，不是固定五条主线。

## 25. 计划审查规则

### 25.1 每轮审查要求

每次进入新任务前，总设计师必须审查当前计划。

### 25.2 审查内容

审查时须列出计划中所有未满 100% 的内容。每个内容都要带进度条，并标注本轮是否会推进。

### 25.3 显示规则

- 如果某项不会推进，可以只显示当前进度，不写预计涨幅。
- 不允许只挑几个好看的进度项显示。
- 不允许遗漏用户刚提出的新任务项。

## 26. 模式选择规则

### 26.1 任务模式分类

| 任务类型 | 推荐模式 | 说明 |
|----------|----------|------|
| 审查任务或必须完成的简单任务 | OpenCode | OpenCode 适合规则、文档、环境、审查类微任务 |
| 其他开发任务 | Codex 目标模式 | Codex 目标模式适合高复杂度、多文件、多测试、多页面验收任务 |

### 26.2 使用顺序

- OpenCode 微任务可以先行完成规则、文档、环境、审查类任务。
- 完成后让 Codex 基于最新 master 开始主任务。
- 不建议 OpenCode 和 Codex 同时改同一批文档，避免 push 冲突。

### 26.3 Codex 目标模式进度设定

- 可以设定 600% 或更高的子阶段进度目标。
- 但必须拆清楚每个子阶段，不得虚假进度。
- 子阶段进度上涨必须来自真实代码、测试、文档、页面验收或架构侦查成果。

### 27.7 Testing DB 健康检查规则

1. **每轮大型任务、GLM 1000% 子阶段、涉及 Feature tests 的任务，必须先跑 DB health check。**
2. 运行：`php artisan test --filter=TestingDatabaseHealthTest` 和 `php artisan test --filter=TestingDatabaseHealthConfigTest`。
3. 如果 health check 失败，必须先修复 testing DB 环境，不允许跳过 health check 直接跑 feature tests。
4. 45 个 test files 使用 `RefreshDatabase` 共享 `linguacafe_fsrs_test` MySQL 数据库。PHPUnit process lock (`tests/bootstrap.php` + `flock`) 防止并发进程冲突。
5. 禁止运行 `php artisan migrate:fresh --env=testing`、`php artisan db:wipe --env=testing`、`drop/truncate/delete` 全表。
6. 如果 health check 发现 testing DB 状态异常：
   - 允许的安全修复：`php artisan migrate --env=testing`。
   - 禁止的命令：`migrate:fresh`、`migrate:refresh`、`migrate:reset`、`db:wipe`。
   - 必须在报告中列出完整的错误信息和 DB 配置。
   - 不得把 health check 失败写成通过。

### 27.8 DevSpace 执行 PHP 测试时的 502 截断规则

1. DevSpace 在运行耗时较长或输出较多的 PHP / PHPUnit 命令时，可能返回 `502` 或截断结果。该现象属于**工具传输失败**，不能直接认定为代码测试失败，也不能认定为测试通过。
2. **PHP / PHPUnit 在 DevSpace 中默认只使用替代检测，禁止先运行原始高输出流式方案。用户于 2026-07-15 明确冻结：Feature 永远分组复核，不再运行 Feature 全量命令。**执行顺序固定为：
   - 将完整输出重定向到仓库忽略目录中的临时日志，只让 DevSpace 返回退出码和末尾摘要；
   - Unit 可以作为独立套件运行；**Feature 永远按文件批次或业务模块分组运行，禁止执行 `php artisan test --testsuite=Feature`、禁止先尝试 Feature 整套命令再回退分组**；
   - Feature 分组必须覆盖全部 Feature test files，记录每组文件数、退出码、passed/skipped/assertions 摘要，最后汇总；
   - 同时运行与本轮改动直接相关的聚焦测试、Node guard、DB health check、`git diff --check` 和构建；
   - 读取日志末尾和单独保存的退出码，不依赖容易被截断的流式输出。
3. 禁止为了绕开 502 使用 SQLite 代替项目 testing MySQL。禁止降低断言、跳过 DB health check、删除失败测试或把 API / 源码推测写成测试结果。
4. 如果替代检测仍无法得到可信的完整 PHP 回归结果：
   - 当前报告必须标记该项为 `Incomplete / DevSpace PHP verification unavailable`；
   - 明确列出已经通过的聚焦测试和仍未取得的完整套件证据；
   - 直接将“完整 PHP 回归 + 结果摘要”交给下一轮相关 Codex 复杂主任务执行，不再回退尝试原始 DevSpace 流式方案；
   - 该附加项只做验证，不得借机扩大业务范围；
   - Codex 必须保存退出码、失败文件名和测试摘要，并在最终报告中单独列出。
5. 如果本轮只修改文档、测试 guard 或计划文件，且相关 guard、DB health check、`git diff --check` 已通过，可以完成本轮文档工作；完整 PHP 套件仍按第 4 条登记给下一轮 Codex，不得伪称“全量回归已通过”。
6. 502 截断本身不能触发清库、重建 testing DB、`migrate:fresh`、`db:wipe`、`--force` 或关闭进程锁。

## 27. 高内聚低耦合架构规则与 GLM 1000% 分层规则

### 27.0 第一硬原则：代码安全性和稳定性优先于功能速度

**本节是 §27 所有子规则之上不可越过的前提。**

1. **原则**：当"更快做功能"与"保持学习数据稳定"冲突时，默认选择稳定。
2. **禁止进入 GLM 1000% 第一轮的事项**（与 §27.4.1 互相印证）：
   - FSRS 调度改动；
   - ReviewLog 写入语义改动或新增写入入口；
   - migration / DB schema 变更；
   - 真实调用外部 AI API 并直接处理返回结果；
   - per-occurrence lemma 数据结构落库；
   - 阅读中刷卡评分实现；
   - 删除 legacy 兼容层；
   - 大规模重写 TextReader / TextBlockGroup；
   - 合并 VocabularySideBox / VocabularyBox / VocabularyBottomSheet 为一个新大组件；
   - 批量数据变更（drop / truncate / delete 全表、清库、`migrate:fresh` / `db:wipe`）。
3. **GLM 1000% 第一轮允许的低风险维护性工作**（与 §27.4.2 互相印证）：
   - tests / 参数化 fixture / 软约束转硬测试；
   - MCP 新样本验收（新文章 + 新词 + 真实浏览器点击）；
   - 只读 service / 只读查询抽取（不改路由、payload、Vue 契约）；
   - source / example / known-sense 回归护栏；
   - 安全 DI 优化（只改依赖注入方式，不改业务逻辑）；
   - 文档规则同步。
4. **对 GLM 1000% 第一轮的影响**：
   - 不允许为了凑 1000% 进度，把高风险数据结构变更塞进第一轮；
   - 子阶段合计百分比是子阶段提升，不是固定五条主线（架构收口 / AI 示意卡 / 前端入口统一 / 阅读中刷卡评分 / AI 判断熟词僻义）虚假上涨；
   - 若某子阶段风险过大（如 DI 改动影响范围超过预期），停止该子阶段并报告 Incomplete，不强行完成；
   - 后续如果要做高风险项，必须 ADR / 需求冻结 / 单独任务，不能在 GLM 1000% 第一轮中夹带。

### 27.1 规则目的

本节写入总设计师设定的架构规则，作为所有 GLM 1000% 子阶段和后续开发的硬约束。
GLM Agent 只能在规则内执行，不能自行决定架构原则。
GLM 内部 FACT 轨道负责事实和越界核查（见 §1.5.3）。
GLM 内部 UX 轨道负责 MCP Chrome 体验验收（见 §1.5.4）。

### 27.2 高内聚规则

一个模块只能围绕一个清楚的产品责任组织。以下边界必须保持：

1. **Tokenizer 边界**：`tools/tokenizer.py` 只负责分词和 lemma 标注，不涉及读者数据、复习调度或页面渲染。PHP 侧 `TextBlockService` 中的 tokenizer fallback `conservativeFallbackLemma()` 只负责 tokenizer 降级时的 lemma 安全还原，不扩展为通用 NLP 服务。
2. **Importer 边界**：`ChapterService::processChapterText` / `ImportService` / `BookService` 只负责内容导入和初始分词，不涉及后续读者数据准备或复习卡创建。
3. **Reader 边界**：`ReaderDataService` 和 `TextBlockGroup.vue` 只负责阅读页数据准备和 token 渲染，不涉及词典查询、词义确认或复习评分。
4. **Dictionary 边界**：`DictionaryController` / `DictionaryService` / `DictionaryImportService` 只负责词典查询和词典文件导入，不涉及 WordSense 创建或 EncounteredWord 修改。
5. **WordSense 边界**：`WordSenseService` / `WordSenseKnownSenseService` / `WordSenseOccurrenceService` 只负责词义和 occurrence 管理，已知词义查询必须是只读（`read_only=true`）。
6. **Review 边界**：`SenseReviewService` / `ReviewCardService` / `FsrsSchedulingService` 只负责复习调度和 FSRS，不涉及 tokenizer 或词典。
7. **AI 边界**：AI 模块（`AiReadingAssistService` / `AiStudyCardPendingItemService`）只产出 preview / candidate / package，不直接写 WordSense、ReviewCard、ReviewLog。
8. **MCP 测试治理边界**：MCP 形态测试独立于主业务流程，使用独立测试文章和测试词库，不影响真实学习数据。

**不允许**：
- 一个 Service 同时承担导入、分词、查词、复习、AI 写入、页面展示、日志写入。
- 一个 Vue 组件同时承担过多职责而没有子组件边界。`VocabularySideBox.vue`(1470行)、`VocabularyBox.vue`(1498行)、`TextBlockGroup.vue`(2516行) 属于过渡状态，必须在后续拆分。
- 如果一个文件必须暂时承担多职责，必须在文档中标注"过渡状态"，并有后续拆分路线。

### 27.3 低耦合规则

1. **Controller 不依赖 Controller**。Controller 之间不得互相注入或调用；编排逻辑通过 Service 层完成。
2. **页面组件不直接理解 FSRS 调度细节**。FSRS 状态通过 `SenseReviewCardSerializerService` 等 serializer / payload 契约暴露给前端，不通过组件内直接 import FSRS 模型或算法。
3. **AI 推荐 / AI 判断默认只产出 preview / candidate / package**，不直接写 WordSense、ReviewCard、ReviewLog。V3 `safety_flags` (`no_review_card_created` / `no_word_sense_created` / `no_fsrs_changed`) 和 V4 `safety_flags` (`no_ai_called_by_linguacafe` / `ai_response_pasted_by_user` / `user_confirmation_required_before_card_generation`) 是所有 AI 模块必须遵守的最低安全契约。
4. **阅读页点击 token 优先通过稳定 service / serializer 契约获得数据**，不散落在 Vue 组件内拼装。`ReaderDataService` 和 `SenseReviewCardSerializerService` 是稳定数据入口。
5. **ReviewLog / FSRS 只能由明确的 review service 入口写入**。`SenseReviewService::rateSense` → `ReviewCardService::logReview` → `FsrsSchedulingService::schedule` 是唯一的写入链。其他模块不得绕过这条链直接操作 ReviewLog 或 FSRS 字段。
6. **Source context / example pool / known sense lookup 必须保持只读边界**。`SenseSourceContextService`、`WordSenseExamplePoolService`、`WordSenseKnownSenseService` 三者都不写 ReviewLog、WordSense、ReviewCard、FSRS。
7. **跨模块通信要通过 DTO / payload / serializer / service contract**，不通过隐式共享状态（如 Vuex 中直接存后端模型字段）。
8. **EncounteredWord 单 lemma 限制**：同 surface 多 lemma 场景（如 `left` 是 `leave` 还是方向）通过 `study_base` + 用户修正 + processed_text per-token 数据缓解，不允许为 per-occurrence lemma 添加复杂 migration 或大规模改写 EncounteredWord 语义，除非经过单独的 ADR。

### 27.4 GLM 1000% 分层规则

GLM 1000% 不是"大包大揽乱改"，而是多个安全子阶段合计。必须分层：

| 层 | 职责 | 风险 |
|----|------|------|
| **测试治理层** | 补测试护栏、参数化 fixture、软约束转硬测试 | 低 |
| **MCP 新样本验收层** | 每轮新测试文章 + 新测试词 + MCP Chrome 真实点击 | 低 |
| **低风险 service 边界整理层** | 拆分过大的 Service（TextBlockService、VocabularyService），抽取只读查询 | 低-中 |
| **只读 Vue 展示组件拆分层** | 拆分 `WordSensesList.vue`、`VocabularySideBox.vue` 中的展示子组件 | 中（需 MCP 验收） |
| **文档与 ADR 路线冻结层** | 更新 master plan、ADR、协作规则 | 低 |
| **source context / example pool 回归护栏层** | 补边缘 case 测试、性能回归保护 | 低 |
| **legacy prop / dead prop 清理层** | 移除 Vue 组件中不再使用的 prop、data、computed | 低 |

#### 27.4.1 GLM 1000% 第一轮禁止项

以下事项**禁止在 GLM 1000% 第一轮中直接做**。如果任务涉及以下禁止项，必须先 ADR / 需求冻结 / 单独任务：

1. **Migration** — 新增或修改数据库表结构。
2. **FSRS 调度改动** — 修改 `FsrsSchedulingService`、`ReviewCardService::logReview`、`SenseReviewService::rateSense` 的核心调度逻辑。
3. **ReviewLog 写入改动** — 新增 ReviewLog 写入入口、修改现有 rating 语义、改变 ReviewLog 保留策略。
4. **真实 AI 写入** — 自动调用外部 AI API 并直接处理返回结果（用户粘贴 AI 输出始终允许）。
5. **阅读中刷卡评分** — 在阅读页内实现 WordSense 评分 UI 和 ReviewLog/FSRS 写入（路线已冻结但实现需单独 ADR）。
6. **删除 legacy 兼容层** — 删除 `ReviewCard::TARGET_WORD`、legacy route、`target_type=word` 相关兼容代码。
7. **Per-occurrence lemma 数据结构落库** — 修改 `encountered_words` 表或新增 per-occurrence lemma 表。
8. **大规模重写 TextReader / TextBlockGroup** — 超过 50% 组件内容重写。
9. **合并多个查词入口为一个新大组件** — 将 `VocabularySideBox` / `VocabularyBox` / `VocabularyBottomSheet` 合并为一个组件。

#### 27.4.2 允许第一轮做的示例

- 添加或增强测试（PHPUnit / feature / unit 测试）。
- 导入新测试文章并执行 MCP Chrome 真实点击验收。
- 拆分只读的后端 Service（不改变路由、payload、Vue 契约）。
- 拆分 Vue 展示子组件（不改变父组件 props/events 签名）。
- 更新文档、协作规则、ADR、master plan。
- 添加边缘 case 测试和回归护栏。
- 清理无用的 prop / data / computed。

### 27.5 MCP 词元测试样本治理规则

#### 27.5.1 核心规则

1. **每轮 MCP lemma / 词元测试必须使用不同单词**。不得整轮复用上一轮同一批词。
2. **可以导入新的短测试文章完成测试**。新文章必须满足：短（建议 3-5 句）、可控、无版权长文。
3. **每篇测试文章必须有 marker**。marker 格式：`GLM Real Morphology Completion YYYYMMDD`。
4. **每轮报告必须列出本轮测试词**，并说明是否与上一轮重复。
5. **每轮至少覆盖以下 8 类**，但每类的示例词必须不同：
   - 规则复数（如 `technologies`、`boxes` — 上一轮已用，本轮换词）
   - 不规则复数（如 `mice`、`children` — 上一轮已用，本轮换词）
   - 第三人称单数（如 `goes`、`watches` — 上一轮已用，本轮换词）
   - 过去式（如 `ran`、`went` — 上一轮已用，本轮换词）
   - 过去分词（如 `written`、`published` — 上一轮已用，本轮换词）
   - 进行时（如 `running`、`studying` — 上一轮已用，本轮换词）
   - 比较级 / 最高级（如 `better`、`oldest` — 上一轮已用，本轮换词）
   - 词性歧义（如 `used`、`left`、`broken`、`published` — 上一轮已用，本轮换词）
6. **每轮必须说明是否使用新文章**。如果使用旧文章只测新词，必须说明。
7. **MCP 不可用必须 Incomplete**。不允许伪造页面验收。
8. **不允许用 API / axios / fetch 替代真实点击**。
9. **如果为了定位 token 使用 DOM 查询，只能用于定位坐标，最终仍必须真实点击**。
10. **如果 Playwright / MCP 连续点击失败，按顺序尝试**：换词 → 换文章 → 刷新页面 → 单词逐个重新打开页面 → DOM 辅助定位后真实点击。不得退回 API。

#### 27.5.2 候选词池建议

为每类形态准备至少 20 个候选词，每轮从候选词池中选择不重复的子集。禁止连续两轮使用超过 30% 的重复词。候选词池建议（仅供参考，不作硬编码）：
- 规则复数：books / cats / dogs / cars / pens / tables / chairs / windows / doors / rooms
- 不规则复数：feet / teeth / men / women / oxen / sheep / deer / fish / people / geese
- 第三人称单数：makes / takes / gives / tells / asks / keeps / puts / lets / gets / sets
- 过去式：ate / drank / swam / sang / spoke / broke / drove / wrote / rose / fell
- 过去分词：eaten / driven / spoken / broken / drawn / known / thrown / grown / blown / flown
- 进行时：making / taking / giving / telling / keeping / putting / getting / setting / riding / writing
- 比较级 / 最高级：bigger / smaller / faster / slower / higher / lower / richer / poorer / wider / deeper
- 词性歧义：walked / turned / opened / closed / finished / started / changed / worked / played / showed

#### 27.5.3 测试文章管理

- 每轮新测试文章应使用不同句子，避免测试用户"背出"特定文章内容。
- 测试文章不要求有完整作品版权，仅用于验证 tokenizer lemma 和页面点击。
- 测试文章创建后通过 `/chapters/read/{id}` 访问，执行完毕后不删除（保留为历史记录）。
- 旧测试文章可以复用，但必须测不同词。

### 27.6 视频字幕架构经验规则

基于以下经验，当总设计师未设定明确架构约束时，AI（包括 OpenCode / Codex / Trae）倾向于沿既有架构延续而非创造新架构：

1. AI 更擅长延续已有架构，**不擅长从混乱中凭空创造好架构**。架构混乱时，AI 会沿着混乱继续扩张，而不是自动修复。
2. **每个 GLM 1000% 主任务前必须先冻结边界**。不能假设 AI 会自动识别哪些代码不该改。
3. **总设计师负责设定架构规则**（本节即为此目的）。规则必须明确禁止范围和允许范围。
4. **OpenCode / GLM 只能在规则内执行**，不能自我授权扩大范围。
5. **GLM 内部 FACT 轨道只核查事实和越界**，不做架构规则设定。
6. **GLM 内部 UX 轨道只做体验验收**，不判断代码实现正确性。
7. **不能让执行模型自己决定架构原则**。总设计师必须在每个主任务前设定明确的模块边界、禁止事项和验收条件。

## 28. 需求放置、复合任务与报告闭环硬规则

> **本节为唯一权威正文**（Task 2000-17 新增）。`linguacafe-master-plan.md`、`current-working-handoff.md`、`DOCUMENTATION_INDEX.md` 只允许写短引用与本轮应用结果，**禁止复制本节全文**。本节覆盖任何与本节冲突的旧规则。

### 28.1 单一权威来源

1. 本节是“需求处理 / 复合任务 / 报告闭环”三组硬规则的**唯一权威正文**。
2. 其他文档只能写：
   - 当前规则已生效；
   - 权威章节位置（即本节 `vibe-coding-collaboration-rules.md §28`）；
   - 本轮应用结果。
3. 不得在多个文件复制全文，避免上下文污染和规则漂移。
4. 本节冲突时以本节为准；与 §19 复杂度规则冲突时，复杂度规则以 §19 为准。

### 28.2 需求处理规则

1. 用户突然提出一个需求时，**不得默认把它变成下一轮立即实现的功能**。
2. 网页端总流程设计师必须先判断它在总控大计划中的**最合理位置**。
3. 合理位置必须**同时满足**：
   - 不跳阶段；
   - 不破坏当前模块边界；
   - 不制造临时跨层补丁；
   - 数据来源和依赖已经具备，或明确登记前置 Gate；
   - 可以通过测试和真实验收形成闭环；
   - 不把稳定模块重新搅在一起；
   - 不显著增加未来修改成本；
   - 屎山和耦合增长最低。
4. 需求必须被分类为以下之一：
   - 当前阶段阻塞需求；
   - 当前阶段兼容的附带需求；
   - 未来阶段需求；
   - 独立主线需求；
   - 暂不进入计划的候选需求。
5. 只有“当前阶段阻塞”或“当前阶段天然兼容”时，才允许进入下一任务实现。
6. 未来需求只登记到正确 roadmap / ADR / implementation plan，**不得抢占当前任务**。
7. 需求落位时必须说明：
   - 放在哪个阶段；
   - 为什么放在那里；
   - 前置条件；
   - 不应该提前实现的原因。
8. 用户说“写入大计划”时，默认含义是**登记未来位置，不等于下一步实现**。
9. 如果位置仍不明确，应**冻结需求并提出产品问题**，不得让 GLM 自己拍板。

### 28.3 每轮任务必须同时包含架构与开发

1. 每一个正式 GLM 主线任务必须**同时具有**：
   - `ARCH-*` 架构修复、架构优化或架构收口；
   - `DEV-*` 可验证的功能开发。
2. **不能只开发功能而不检查架构**。
3. **不能只写架构文档而完全不推进当前主线功能**。
4. 小型规则修正、文档更新和 guard 应搭载到相关复合任务中。
5. 架构任务必须来源于**真实代码事实**，不得只输出抽象建议。
6. 架构优化必须回答：
   - 当前职责是否清楚；
   - 数据流是否显式；
   - 是否存在字符串协议、隐式状态或重复逻辑；
   - 是否能减少跨文件耦合；
   - 是否让未来测试更容易；
   - 是否会因为拆得过碎而增加接口成本。
7. 每轮**不是**强行进行大重构。
8. 如果当前代码架构没有值得修改的问题，可以把架构轨道定义为：
   - 契约收口；
   - harness 补强；
   - 重复实现防护；
   - 数据流显式化；
   - 删除隐式协议；
   但必须有真实成果。
9. **禁止为了满足"有架构任务"而制造无意义的新接口、新 DTO 或新 Service**。

### 28.4 字幕架构核查规则

1. 网页端总流程设计师在设计每个复合型开发任务前，除了核查代码和大计划，还必须检查与本轮架构问题相关的项目字幕。
2. **不要求每轮机械读取全部字幕**；应按任务类型选择最相关文件，避免上下文污染。
3. 必须记录本轮查看的字幕文件名和采用的架构原则。
4. **GLM 只有在本地确实能访问字幕时，才能声称自己读取过字幕**。
5. 如果字幕只存在于 GPT 网页端项目文件中：
   - 由网页端总流程设计师负责读取；
   - 在 GLM 提示词中给出经过筛选的架构结论；
   - **GLM 不得伪造“已读取原字幕”**。
6. 字幕经验**不能直接覆盖真实代码事实、项目 ADR 或用户最新决定**。
7. 字幕中仍处于探索性的意见，**不得自动写成产品 spec**。
8. 已经稳定、被用户明确确认的工作流程，才允许进入长期硬规则。

**Task 2000-17 网页端查看的相关字幕**：
- `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
- `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
- `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
- `AI可以帮你写代码，但帮不了你成为架构师.srt`
- `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
- `AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt`
- `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`

**Task 2000-17 采用的字幕架构原则**：
1. 文档说明决策，测试和 guard 形成门禁。
2. 临时补丁不得升级为长期架构规则。
3. 规则必须有优先级和唯一权威来源。
4. 先冻结边界，再填充实现。
5. 高内聚低耦合，但避免过度拆分。
6. 测试难写通常说明职责、输入输出或副作用不清。
7. 已稳定的决策才进入 spec。
8. 不让执行模型自己决定架构原则。

### 28.5 报告处理与下一步规划流程

每当用户返回 GLM 报告，网页端总流程设计师必须**依次执行**：

1. 阅读报告。
2. 核查报告中的 commit、push、文件和测试是否属实。
3. 核查 GitHub 最新 master，不只看报告。
4. 分析真实代码：
   - 功能是否完成；
   - 是否越界；
   - 是否留下隐式协议；
   - 是否增加耦合；
   - 是否与现有架构一致。
5. 分析架构状况。
6. 检查用户在报告旁边是否提出了新要求。
7. **查看总控大计划，即使用户没有提出新要求也必须查看**。
8. 将新需求登记到最合理阶段，**而不是默认下一步实现**。
9. 查看与本轮有关的字幕架构原则。
10. 综合：用户最新反馈、GLM 报告、最新 master、当前代码、当前架构、总控大计划、上一轮提示词、字幕架构经验，给出 Accept / Refuse / 阶段性 Accept / Incomplete。
11. 再生成最优的下一步复合任务提示词。

**禁止**：
- 只读报告就 Accept；
- 只看测试绿就忽略架构；
- 只看代码就漏掉用户新要求；
- 跳过大计划；
- 把用户刚提出的需求直接变成下一轮功能；
- 让用户承担代码判断。

### 28.6 下一步提示词构成规则

1. 每个下一步提示词都是复合型任务。
2. 核心任务至少包括：
   - 一个明确的架构优化、架构修复或契约收口；
   - 一个明确的功能开发。
3. 杂项可以包括：文档同步、ADR 更新、guard、lint、build、db health、MCP Chrome、commit / push。
4. **杂项不能冒充主线**。
5. 提示词必须写清：当前代码事实、当前架构问题、开发目标、允许文件、禁止范围、测试、FACT 自审、Git 提交、最终报告。
6. 测试属于开发与架构闭环，**不是任务最后的装饰步骤**。
7. 测试失败时：
   - 必须定位真实原因；
   - 修复代码或架构；
   - 重新运行；
   - **循环直到通过**。
8. 只有以下情况才允许停止为 Incomplete：
   - 环境不可恢复；
   - 缺少用户授权；
   - 发现会越过数据安全边界；
   - 继续修复必然超出允许范围。
9. **禁止把失败测试隐藏、删除或改弱以换取通过**。
10. **禁止把结构字符串 guard 当成唯一行为证据**。
11. 每个任务必须创建 To-do list。
12. 每个任务完成后必须停止，**不自动进入下一任务**。
