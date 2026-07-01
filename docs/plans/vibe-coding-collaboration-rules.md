# LinguaCafe Vibe Coding 协作原则

> LinguaCafe FSRS 改造系列中，网页端 GPT（产品决策方）与本地 OpenCode Agent（执行方）的协作规范。

---

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

## 2. 本地 OpenCode Agent 的职责

1. 本地 Agent **不做网络搜索和产品判断**。
2. 本地 Agent **只执行明确任务**、跑测试、报告结果。
3. 遇到产品不确定，不自行发挥，按提示词执行，并在报告中列为 follow-up。
4. 不为了延长时间添加 sleep、死循环或无意义任务。
5. 任务要做得完整、细致、可验收。

## 3. 用户的责任

1. 用户不需要判断代码正确性。
2. 用户通过网页端 GPT 管理产品方向。

## 4. 报告验收规则

1. 本地 Agent 的测试报告不能直接作为事实。
2. 网页端 GPT 必须查看 GitHub 最新代码（而非仅依赖本地报告）。
3. 测试失败必须写失败——不允许把失败测试写成通过。
4. 如果功能接入了错误入口，必须在报告中标记为 follow-up。
5. 如果提示词中列出的文件范围和实际修改的文件有差异，必须报告。

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

## 14. 当前三员工工作流（2026-06-30）

本项目后续默认采用"三员工 + 网页端 GPT"的工作流。四个角色的边界如下：

| 角色 | 主要职责 | 不做什么 | 交付物 |
|------|----------|----------|--------|
| 网页端 GPT / 总流程设计师 | 产品判断、架构拆分、GitHub 最新代码核验、Accept / Refuse、下一轮提示词 | 不直接相信报告，不让用户承担代码判断 | 模型/档位建议、OpenCode 提示词、产品设计问题、验收结论 |
| CodeBuddy | 架构侦察、风险审计、代码边界核查、指出哪些逻辑应抽 Service | 不改代码、不 commit、不 push、不做产品最终决定 | 架构/风险报告、允许/禁止边界、测试建议 |
| WorkBuddy | 产品 QA、用户可见契约、页面/字段/交互验收、手动体验风险 | 不判断代码实现正确性、不执行危险写操作 | 产品验收报告、字段契约、用户影响分级 |
| OpenCode | 按当前提示词执行最小任务、修改代码、跑测试、commit、push、输出报告 | 不擅自扩大范围、不跳阶段、不自 Accept、不自动进入下一任务 | 完成报告、测试结果、git 状态、合规确认 |

执行顺序默认是：

1. 网页端 GPT 冻结目标。
2. CodeBuddy 做架构侦察。
3. WorkBuddy 做产品 / QA 契约整理。
4. 网页端 GPT 合并边界并给 OpenCode 提示词。
5. OpenCode 执行。
6. OpenCode 直接在当前对话窗口输出最终报告。
7. 网页端 GPT 查 GitHub 最新 commit 后验收。
8. 只有网页端 GPT 给出 Accept，才视为该 Phase 完成。

### 14.1 三员工提示词顺序规则

1. 网页端 GPT 每次同时使用 CodeBuddy、WorkBuddy、OpenCode 时，必须显式写明本轮顺序。
2. 顺序要写成：
   - 第 1 棒：谁
   - 第 2 棒：谁
   - 第 3 棒：谁
3. 顺序不是固定的，可以根据任务类型调整。
4. 调整顺序时必须说明原因。
5. 后一棒必须读取或参考前一棒的结论，不能当成三个互相独立的并行任务。
6. 常见默认顺序：
   - 架构 / 服务边界任务：CodeBuddy → WorkBuddy → OpenCode
   - 产品 / UI / 手动体验任务：WorkBuddy → CodeBuddy → OpenCode
   - 失败复盘 / 报告核验任务：OpenCode → CodeBuddy → WorkBuddy
7. OpenCode 通常放在最后，因为它是执行端，不能在前两棒边界未收敛前直接改代码。
8. 如果本轮只需要一个员工，也必须说明"本轮只使用 X，不启动另外两个员工"的原因。
9. 网页端 GPT 最终仍负责合并三方结论，并决定 Accept / Refuse / 下一轮 Prompt。

### 14.2 三员工提示词格式规则

1. 每次给多个员工下发任务时，网页端 GPT 必须按执行顺序排列提示词。
2. 每个员工小节必须以"第 N 棒：发给 X"开头。
3. 每个员工小节下面必须单独写：
   - 模型：
   - 档位：
   - 顺序：
   - 依赖关系：
4. 模型和档位是给用户看的，不写入 OpenCode 的任务正文内部。
5. 如果某个员工必须等待另一个员工的报告，必须写明依赖关系。
6. 如果某个验收必须等待 OpenCode 最终报告和 GitHub 最新 commit，也必须写明。
7. 默认按正常使用顺序排序，不要把后执行的员工写到前面。
8. 顺序可以调整，但必须说明原因。
9. 后一棒必须读取前一棒报告，不能并行假装完成。
10. 如果本轮只使用一个员工，也要写：
    - 第 1 棒，也是唯一一棒
    - 不启动其他员工的原因
    - 依赖关系

格式示例：

```text
## 第 1 棒：发给 CodeBuddy
模型：DeepSeek Pro
档位：中高
顺序：第 1 棒
依赖关系：无。先做架构侦察，供后续员工参考。

## 第 2 棒：发给 WorkBuddy
模型：DeepSeek Flash
档位：中
顺序：第 2 棒
依赖关系：必须先读取 CodeBuddy 报告，再做产品 / QA 契约判断。

## 第 3 棒：发给 OpenCode
模型：DeepSeek Pro
档位：中
顺序：第 3 棒
依赖关系：必须等待 CodeBuddy 和 WorkBuddy 报告完成后才能执行。
```

### 14.3 员工提示词文本框范围规则

1. 网页端 GPT 给用户发员工任务时，必须区分"外部说明"和"员工执行正文"。

2. 以下内容必须写在文本框外，给用户看：
   - 发给谁
   - 推荐模型
   - 推荐档位
   - 发的时候有什么要求
   - 本轮顺序
   - 依赖关系

3. 文本框内部只放该员工要执行的任务正文，例如：
   - 任务名称
   - 任务性质
   - 目标
   - 背景
   - 允许修改文件
   - 禁止事项
   - 验证命令
   - 提交要求
   - 最终报告格式

4. 如果一轮有多个员工，每个员工必须有单独的文本框。不能把 CodeBuddy、WorkBuddy、OpenCode 的任务正文放进同一个文本框。

5. 多员工任务仍然必须按正常使用顺序排列，例如：
   - 第 1 棒：CodeBuddy
   - 第 2 棒：WorkBuddy
   - 第 3 棒：OpenCode

6. 每个员工文本框外面都要单独写：
   - 发给谁：
   - 模型：
   - 档位：
   - 发的时候有什么要求：
   - 顺序：
   - 依赖关系：

7. 后一棒如果必须等待前一棒报告，必须在文本框外的"依赖关系"中写明。

8. 产品设计问题必须放在所有员工文本框外，不写进任何员工执行正文，避免员工误执行。

9. 验收结论也放在文本框外，因为验收是网页端 GPT 给用户看的判断，不是员工执行任务。

10. 如果本轮只使用一个员工，也只给一个文本框，并在文本框外说明：
    - 第 1 棒，也是唯一一棒
    - 不启动其他员工的原因
    - 依赖关系

示例（完整内容，无占位符）：

```
发给谁：CodeBuddy
模型：DeepSeek Pro
档位：中高
发的时候有什么要求：先发送给 CodeBuddy，等待它输出架构侦察报告后，再进入下一棒。
顺序：第 1 棒
依赖关系：无。CodeBuddy 先做架构侦察，供后续员工参考。

【CodeBuddy 文本框内容】
任务名称：Example-CodeBuddy-Scout：示例架构侦察任务
任务性质：只读 scout，不修改文件。
目标：读取指定文件，整理架构风险和服务边界建议。
最终报告：直接输出架构侦察报告。

---

发给谁：WorkBuddy
模型：DeepSeek Flash
档位：中
发的时候有什么要求：必须把 CodeBuddy 报告一起发送给 WorkBuddy，等待它完成产品 / QA 契约判断。
顺序：第 2 棒
依赖关系：必须先读取 CodeBuddy 报告。

【WorkBuddy 文本框内容】
任务名称：Example-WorkBuddy-QA：示例产品 QA 任务
任务性质：只读产品 / QA 契约审查，不修改文件。
目标：基于 CodeBuddy 报告，判断用户可见风险、文案风险和验收边界。
最终报告：直接输出产品 QA 报告。

---

发给谁：OpenCode
模型：DeepSeek Pro
档位：中
发的时候有什么要求：必须等待 CodeBuddy 和 WorkBuddy 报告都完成后，再发送给 OpenCode 执行。
顺序：第 3 棒
依赖关系：必须基于前两份报告执行，不能跳过前两棒直接开发。

【OpenCode 文本框内容】
任务名称：Example-OpenCode-Implementation：示例执行任务
任务性质：最小实现任务，只按当前提示词执行。
目标：根据前两份报告和网页端 GPT 收敛后的边界，完成指定最小修改。
最终报告：直接输出执行报告，不自动进入下一任务。
```

### 14.4 OpenCode 与 CodeBuddy 并行工作流

1. OpenCode 是执行端，负责修改代码、跑测试、commit、push、输出报告。
2. CodeBuddy 是本地代码侦查 / 架构审查 / 风险扫描端，负责读取本地最新代码、使用 skills 做事实评估。
3. 网页端 GPT 负责 GitHub 最新代码核验、合并 OpenCode 报告和 CodeBuddy 报告、做 Accept / Refuse / 下一轮提示词。
4. CodeBuddy 的结论不能直接等于网页端 GPT 的结论；网页端 GPT 必须结合 GitHub 最新代码独立判断。
5. 每轮原则上同时准备两条提示词：
   - OpenCode 提示词。
   - CodeBuddy 提示词。
6. 两种使用方式：
   - 如果 CodeBuddy 要审查 OpenCode 刚刚做的修改，那么提示词外部说明必须写："OpenCode 完成、push、报告贴回网页端后，再把下面的提示词发给 CodeBuddy。"
   - 如果本次 CodeBuddy 要为下一步计划做代码侦查，而不是审查 OpenCode 本轮修改，那么提示词外部说明必须写："本轮先发给 CodeBuddy，CodeBuddy 只做事实侦查；网页端 GPT 根据报告再决定是否给 OpenCode 执行任务。"
7. CodeBuddy 不替网页端 GPT 做最终 Accept / Refuse。
8. CodeBuddy 不直接给 OpenCode 下执行命令。
9. OpenCode 不能因为 CodeBuddy 提到风险就自行扩大任务。
10. 每次 CodeBuddy 提示词必须指定使用一个 skill。

### 14.5 CodeBuddy skills 使用规则

CodeBuddy 每次必须从以下 skills 中选择一个或多个，但至少明确一个主 skill：

1. **api-and-interface-design**：用于 API、Controller/Service 接口、payload、错误语义、模块边界、前后端契约。
2. **code-review-and-quality**：用于 OpenCode 完成后复查 diff、测试、可维护性、越界改动。
3. **context-engineering**：用于任务切换、长上下文漂移、需要决定本轮该读哪些文件/文档/报告。
4. **documentation-and-adrs**：用于更新协作规则、架构原则、ADR、长期决策记录。
5. **doubt-driven-development**：用于高风险、不可逆、权限、删除、数据迁移、批量操作、过度自信判断。
6. **improve-codebase-architecture**：用于寻找浅模块、职责混乱、耦合、重复逻辑、边界泄漏、深模块候选点。

### 14.6 智能体提示词必须显式写 skill

1. **OpenCode / CodeBuddy 的提示词，必须显式写出"本轮使用 skill"。**
   - CodeBuddy 必须从现有 skills 列表中选择（详见 14.5）。
   - OpenCode 根据任务选择适配 skill，例如 documentation-and-adrs、context-engineering、doubt-driven-development、improve-codebase-architecture、api-and-interface-design、code-review-and-quality。
2. **WorkBuddy 不使用 CodeBuddy / OpenCode 那套 skills。** WorkBuddy 有自己的内置专家机制，提示词必须写明"本轮使用 WorkBuddy 内置专家：X"（专家名单见 §18.2）。
3. 至少写一个主 skill（OpenCode / CodeBuddy）或一个内置专家（WorkBuddy）。OpenCode/CodeBuddy 的复杂任务可以写主 skill + 辅助 skill（多个 skill）。**WorkBuddy 每轮只能写一个内置专家，不得写多名专家（详见 §18）。**
4. skill 或内置专家必须写在提示词正文里，让智能体执行时能看到。
5. 如果某个任务确实不适合某个 skill 或内置专家，必须在提示词外说明原因。
6. 最终报告：
   - OpenCode / CodeBuddy 报告是否按指定 skill 执行。
   - WorkBuddy 报告内置专家使用情况。
7. 网页端 GPT 如果漏写 skill 或内置专家，视为提示词不完整，应在下一轮补规则或重发提示词。

### 14.7 阶段推进与提示词时机规则

1. 阶段完成后，如果下一步边界清楚，网页端 GPT 应直接给出下一阶段提示词，不要每次都问用户是否进入下一阶段。
2. 只有缺少产品选择、风险授权、手动验收结果时，才向用户提问。
3. 如果需要先等 WorkBuddy / CodeBuddy 报告回来，网页端 GPT 不应提前写 OpenCode 提示词。
4. 这种情况下只说明：
   "我现在需要进行侦查工作，使用 CodeBuddy 和 WorkBuddy。我会在下一轮等你返回报告之后，再给你 OpenCode 的提示词。"
5. 如果 OpenCode 提示词已经可以直接发送，才输出完整提示词。
6. 文末产品问题用于补充下一轮设计，不等于默认暂停流程。

### 14.8 小任务合并与复杂任务搭载规则

1. 低风险、同一文件、同一主题的小任务可以合并给 OpenCode 一次执行。
2. 复杂任务可以顺手搭载一个无风险的小文档更新或规则记录。
3. 合并任务必须列清每个子任务的允许文件、禁止范围和验证要求。
4. 高风险任务不能因为合并而模糊边界。
5. 删除、权限、数据库、FSRS、批量操作仍必须明确验收标准。
6. 如果合并会导致报告不清楚、验收困难，就拆开。

### 14.9 CodeBuddy / WorkBuddy 结论复核规则

1. CodeBuddy 报告不能直接接受。
2. 网页端 GPT 必须尝试反驳和检查 CodeBuddy 结论。
3. 网页端 GPT 反驳方式包括：
   - 查看 GitHub 最新 commit / diff。
   - 查看 GitHub 最新文件内容。
   - 对照 OpenCode 报告。
   - 检查 CodeBuddy 是否漏看文件、误读行为、把建议当事实。
4. 不要求 CodeBuddy 自我反驳；反驳由网页端 GPT 做。
5. WorkBuddy 报告也不能直接当最终结论。
6. WorkBuddy 负责产品体验、QA、文案和用户流程，不负责底层代码安全。
7. 网页端 GPT 必须综合 WorkBuddy 报告、CodeBuddy 报告、OpenCode 报告和 GitHub 最新代码再判断。
8. **CodeBuddy 只输出事实。** CodeBuddy 报告应包含文件位置、风险证据、验证结果。CodeBuddy 不负责给下一步产品或实现建议；如果报告格式需要"建议"字段，也只能写"事实支撑的可选路径"，不得替代网页端 GPT 判断。网页端 GPT 才负责综合判断下一步。CodeBuddy 不给 OpenCode 下命令。

### 14.10 MCP Chrome 本地测试账号规则

1. MCP Chrome 验收任务如果涉及登录，网页端 GPT 给 OpenCode 的任务提示词里**必须直接提供本地测试账号和密码**。
2. OpenCode 登录失败时，必须先使用提示词提供的账号和密码尝试登录。
3. 如果该账号不存在或登录失败，OpenCode 可以创建同名本地管理员测试账号。
4. 该账号仅用于本地 MCP Chrome 验收，不代表线上账号。
5. OpenCode 最终报告必须说明：
   - 是否使用了任务提示词提供的本地账号；
   - 是否新建了管理员测试账号；
   - 是否登录成功；
   - 登录失败时的具体原因。
6. 禁止把具体账号密码写入 GitHub 文档、代码、测试、日志或最终报告。
7. 具体账号密码只允许出现在当前任务提示词中。
8. 文档只写"使用当前任务提示词提供的账号和密码"，不要写具体邮箱和密码。
9. 禁止修改 .env。
10. 禁止 migrate:fresh / db:wipe / 清库。
11. MCP Chrome 登录必须使用同一 browser context（`isolatedContext` 参数）。
12. 禁止用 fetch 登录替代页面登录。
13. 禁止用 login POST 成功替代页面登录成功。
14. 登录后必须真实打开目标页面。
15. 若 `navigate_page` 导致 Cookie 丢失，必须改用同 context 登录流程（参考 `docs/plans/mcp-chrome-local-smoke-playbook.md`）。
16. 若仍失败，报告 Incomplete，不得伪造页面验收。

### 14.11 OpenCode 不单独出现规则

1. OpenCode 是执行员工，只要安排 OpenCode，就必须同时安排 CodeBuddy。
2. CodeBuddy 可以是"复核 OpenCode 输出"的后置任务，也可以是"与 OpenCode 无关的并行侦查任务"。
3. 如果 CodeBuddy 要复核 OpenCode 刚完成的改动，则外部说明写：
   "OpenCode 完成、push、报告贴回网页端后，再把下面的提示词发给 CodeBuddy。"
4. 如果 CodeBuddy 与 OpenCode 本轮改动无关，则外部说明写：
   "本轮 CodeBuddy 与 OpenCode 并行执行，CodeBuddy 负责下一步代码事实侦查 / master plan 复核 / 架构风险扫描。"
5. CodeBuddy 和 WorkBuddy 可以单独出现。
6. OpenCode 不可以单独出现。
7. 网页端 GPT 禁止给 OpenCode 写"第 1 棒，也是唯一一棒"。
8. 只有 CodeBuddy / WorkBuddy 单独侦查时，才允许写"唯一一棒"。
9. 即使本轮只有文档小改，也要同时给 CodeBuddy 安排一个并行侦查或后置复核任务。

### 14.12 OpenCode 任务打包规则

1. OpenCode 不能只执行一个孤立的小文档补丁。
2. 如果只是小文档任务，必须与多个低风险小任务合并执行，或搭载在一个大任务后面一起执行。
3. 一个大任务如果要搭载小任务，不能只搭载一个零散小任务；应搭载多个同类、低风险、可一起验收的小任务。
4. "多个小任务"必须同主题或同文件范围，不能把无关任务硬塞在一起。
5. 合并任务必须在提示词中列出每个子任务：
   - 目标
   - 允许文件
   - 禁止范围
   - 验证方式
   - 报告字段
6. 如果任务合并后会导致验收困难、风险边界变模糊、报告说不清楚，就必须拆开。
7. 删除、权限、数据库、FSRS、批量操作、导入导出等高风险任务，不能因为"打包"而降低验收标准。
8. **打包规模规则**：3 个小文档补丁和 1 个小文档补丁本质区别不大，不应为了凑数而打包。如果是纯小任务打包，原则上至少要有 10 个清晰子项，且每个子项都能独立验收。小任务可以跨类型（文档、脚本、测试、轻量代码、文件整理），但必须边界清楚。更推荐把小文档补丁搭载在主线任务里顺手完成。不允许为了满足数量要求，把无关高风险任务硬塞进一个任务。
9. **纯小文档规则修正不得单独开 OpenCode 任务。** 应搭载在主线任务中，或与足够多的低风险小任务合并。不准为了一个 docs 修正单独出一个 OpenCode Phase。

### 14.13 OpenCode + CodeBuddy 双角色并行侦查规则

1. OpenCode 和 CodeBuddy 不只是一写一审。
2. 在代码侦查、风险分析、漏洞分析、架构分析时，OpenCode 和 CodeBuddy 也可以同轮并行执行。
3. 两者应扮演不同岗位角色，而不是重复做同一份报告。
4. 岗位角色由网页端 GPT 根据任务决定，可以是互联网公司中的真实岗位，例如：
   - OpenCode：接盘维护工程师 / 后端执行工程师 / 前端实现工程师 / 测试修复工程师。
   - CodeBuddy：架构审计负责人 / 代码质量负责人 / 安全审计员 / 技术负责人 / API 契约审查员。
   - WorkBuddy：产品经理 / QA / 用户体验验收员 / 项目经理。
5. 如果是同一目标的双角色侦查：
   - OpenCode 从"如果我来接手改，我会卡在哪里"的角度报告。
   - CodeBuddy 从"架构、风险、边界、屎山程度"的角度报告。
6. 如果是不同目标的并行任务：
   - OpenCode 可以执行当前小改或文档整理。
   - CodeBuddy 可以同时侦查下一步计划、master plan、风险边界。
7. 两份报告都不能直接当最终结论。
8. 网页端 GPT 必须用 GitHub 最新代码反驳检查两份报告，再决定下一步。

### 14.14 智能体提示词必须使用 To-do list 执行

1. 网页端 GPT 给 OpenCode / CodeBuddy / WorkBuddy 的提示词，必须要求智能体使用 To-do list 执行。
2. 不使用计划模式（直接进入执行而非先输出计划）。
3. To-do list 应像计划已经完成后那样直接进入执行。
4. 最终报告必须说明 To-do list 是否全部完成。
5. 如果某项无法完成，必须说明卡住原因，不能跳过后继续伪装完成。

### 14.15 MCP Chrome 真实测试规则

1. 所有涉及浏览器、页面、用户流程、按钮、弹窗、导入、查词、阅读页、review 的测试，必须使用 MCP 操控 Google Chrome 真实执行。
2. 不允许用"预期行为"、"看代码应该可以"、"截图推测"代替真实测试。
3. 命令行测试、单元测试、doctor 命令只能作为辅助验证，不能替代浏览器真实验收。
4. 如果任务完全不涉及浏览器，也必须在报告中说明为什么 MCP Chrome 不适用。
5. 如果 MCP Chrome 不可用，报告不可用原因，不要伪造测试结果。

## 15. 服务边界 / 架构优化原则

1. **Controller 只做编排。** Controller 可以负责读取 Request、调用 Service、组装 HTTP response，但不应长期持有复杂 query、导出格式、row payload、写操作事务等大块逻辑。
2. **按职责拆 Service，而不是按文件行数机械拆分。** 已形成的方向包括：
   - `ReviewCardManageQueryService`：管理页查询、筛选、排序。
   - `ReviewCardExportService`：JSON / Anki TSV / CSV 的导出字段、转义、格式化。
   - `ReviewCardManageItemSerializerService`：管理页行数据 / 单卡响应 payload。
3. **下一步服务边界重点是危险写操作。** 编辑、归档/恢复、立即到期、重置、彻底删除、批量归档/恢复/删除，都涉及 ReviewCard、WordSense、ReviewLog 或 EncounteredWord 语义，不能直接边写边抽。
4. **危险写操作必须先 scout，后实施。** 先由 CodeBuddy 只读侦察现有写操作调用链、事务边界、权限隔离、日志保留规则，再由 WorkBuddy 判断产品风险和用户提示，最后才决定是否让 OpenCode 抽服务。
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

## 18. WorkBuddy 单专家规则

> **问题来源**：BulkDestroyPhase20-1 发现 WorkBuddy 提示词允许了一轮使用多个专家，导致分工模糊和输出质量下降。

### 18.1 每轮只能使用一个内置专家

WorkBuddy 每轮验收只能使用一个内置专家，不得在一轮中同时写两个专家。

### 18.2 可用专家名单

| 专家名称 | 适用场景 |
|----------|----------|
| 网页端体验师 | 页面真实操作体验、按钮确认、弹窗验证、布局检查、snackbar 检验、操作路径覆盖、真实浏览器验收 |
| 项目经理 | 产品取舍、风险文案评估、功能优先级、用户理解成本、验收报告撰写、自动化测试是否够用、复杂度评估 |

### 18.3 选择规则

- **页面真实体验、按钮、弹窗、布局、snackbar、操作路径**：使用"网页端体验师"。
- **产品取舍、风险文案、功能优先级、用户理解成本**：使用"项目经理"。
- 如果同一轮需要两种视角覆盖，必须分两轮执行，每轮指定一个专家。

### 18.4 禁止使用的角色

- WorkBuddy 不得使用 OpenCode / CodeBuddy / WorkBuddy 自身的代码类 skills。
- WorkBuddy 不使用 Oracle、Fixer、Explorer、Librarian、Designer 等代码类 agent。

### 18.5 提示词必须显式指定

WorkBuddy 提示词必须包含类似以下格式：

```
本轮使用 WorkBuddy 内置专家：网页端体验师
```
或
```
本轮使用 WorkBuddy 内置专家：项目经理
```

未指定专家的 WorkBuddy 提示词为无效提示词，接收方有权要求补充。

## 19. OpenCode 任务复杂度规则

### 19.1 复杂度上限

- 复杂度 20 是普通主线任务的常用上限。
- 复杂度 100 是大型复合型主线任务的上限。

### 19.2 复杂度 100 适用场景

复杂度 100 只用于复合型主线任务，例如：
- 基础设施 + 文档规则 + 测试 + MCP Chrome 真实验收的多阶段任务。
- 架构重构 + 契约测试 + CodeBuddy 后置复核的全链路重构。
- 多阶段但边界清楚的功能收口任务。

### 19.3 复杂度 100 不等于放开边界

复杂度 100 任务不享有以下豁免：
- 仍然必须遵守 AGENTS.md 中所有禁止修改规则。
- 仍然必须写清阶段、允许文件、禁止文件。
- 仍然必须包含测试命令、MCP Chrome 验收、commit/push。
- 仍然必须输出最终报告格式。
- 仍然必须写"是否进入下一任务：否"。
- 仍然必须后置 CodeBuddy 复核。
- 涉及页面时仍然必须后置 WorkBuddy。
- 禁止因为复杂度高而跳阶段、隐藏失败或自动进入下一任务。

### 18.6 最终报告要求

WorkBuddy 最终报告必须说明本轮使用的专家名称，例如：

> 本轮使用 WorkBuddy 内置专家：网页端体验师
> （每轮只使用一个专家）

## 20. oh-my-opencode-slim 必用 skill 规则

### 20.1 必用规则

从现在开始，网页端 GPT 给 OpenCode 的每一个任务提示词，都必须包含：

```
必用辅助 skill：oh-my-opencode-slim
```

OpenCode 每次进入任务后，必须先调用或阅读本地已安装的 `oh-my-opencode-slim` skill。

### 20.2 适用场景

`oh-my-opencode-slim` 用于检查和约束 OpenCode / agent / model / prompt / skill / MCP / preset / plugin behavior 相关设置。

如果任务涉及以下任一内容，更必须使用它：
- MCP Chrome
- agent 行为
- 模型档位
- skills
- OpenCode 执行习惯
- 报告格式
- 重复性工作流摩擦
- preset / plugin behavior

### 20.3 配置修改权限

- 默认不允许 OpenCode 修改 oh-my-opencode-slim 配置。
- 只有当前任务明确授权时，才允许修改以下文件：
  - `~/.config/opencode/oh-my-opencode-slim.json`
  - `~/.config/opencode/oh-my-opencode-slim.jsonc`
  - `<project>/.opencode/oh-my-opencode-slim.json`
  - agent prompt append 文件（`~/.config/opencode/oh-my-opencode-slim/{agent}_append.md`）
- 如果发现配置问题，默认只报告事实和最小修复建议，不擅自改配置。

### 20.4 配置修改报告要求

如果确实修改配置，最终报告必须说明：
- 修改了哪个文件；
- 为什么修改；
- 是否影响模型费用；
- 是否影响 agent 权限；
- 是否影响 MCP 权限；
- 是否需要重启 OpenCode。

### 20.5 不覆盖其它硬规则

本条规则不覆盖 LinguaCafe 原有禁止规则：
- DCP 默认禁止；
- notification script 默认禁止；
- `.env` 禁止读取/修改；
- `AGENTS.md` 禁止修改；
- `.omo/` 禁止处理；
- MCP Chrome 真实验收规则仍然有效；
- OpenCode 后置 CodeBuddy 仍然有效；
- 涉及页面时 WorkBuddy 仍然有效。

### 20.6 未使用说明的后果

如果 OpenCode 报告中没有说明是否使用了 `oh-my-opencode-slim`，网页端 GPT 应标记为 Incomplete 或要求补充说明。
