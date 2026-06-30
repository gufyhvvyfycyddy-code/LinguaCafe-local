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

### 14.3 提示词单文本框规则

1. 网页端 GPT 给用户可复制的员工提示词时，必须把本轮完整指令放在一个连续文本框中。
2. 不要把同一轮任务拆成多个代码块。
3. 不要把"发给谁 / 模型 / 档位 / 顺序 / 依赖关系"和任务正文拆开。
4. 如果是三员工任务，也要把三位员工的提示词按顺序放进同一个文本框。
5. 文本框内部仍然要清楚分段，例如：
   - 第 1 棒：发给 CodeBuddy
   - 第 2 棒：发给 WorkBuddy
   - 第 3 棒：发给 OpenCode
6. 每一棒下面仍然要写：
   - 模型：
   - 档位：
   - 顺序：
   - 依赖关系：
7. 产品设计问题可以放在文本框外，因为它不是发给员工执行的提示词。
8. 验收结论可以放在文本框外，因为它是网页端 GPT 给用户看的判断。
9. 如果只有一个员工，也必须把"第 1 棒，也是唯一一棒"写在同一个文本框中。
10. 这条规则的目的不是改变任务内容，而是减少用户复制成本，避免漏复制。

**错误做法**：一个代码块写模型，一个代码块写顺序，一个代码块写任务正文——用户需要复制多次，容易漏掉依赖关系。

**正确做法**：一个代码块内完整包含：
- 发给谁
- 模型
- 档位
- 顺序
- 依赖关系
- 任务正文
- 禁止事项
- 验证命令
- 最终报告格式

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
