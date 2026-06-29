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

### 10.2 数据写入纪律

6. **AI / 词典 / 手动输入只是"候选来源"。** 不能直接写入学习数据。
7. **任何自动创建 WordSense / ReviewCard / ReviewLog 的任务，都必须单独立项。** 不允许在 UI 任务中"顺手"创建学习数据。

### 10.3 迭代纪律

8. **不为了"完美 UI"无限迭代。** 达到当前目标后提交，剩余问题记录为 follow-up。
9. **修 bug 时必须证明根因。** 不凭截图猜代码。
10. **测试失败必须归因：**
    - 本轮引入。
    - 既有失败。
    - 本地环境阻塞。
11. **每轮报告必须写：**
    - 是否增加耦合。
    - 是否触碰多层。
    - 是否需要后续组件拆分。

### 10.4 任务粒度

12. **如果一个任务修改超过 8 个代码文件，必须在报告中解释为什么没有拆分任务。**
13. **避免"顺手修"：**
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
