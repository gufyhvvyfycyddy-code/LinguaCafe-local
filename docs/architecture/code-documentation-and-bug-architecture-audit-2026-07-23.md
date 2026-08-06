# LinguaCafe 代码、文档与 Bug 架构审计

> **状态：Historical Snapshot / 2026-07-23。** 本文记录当时的本地 HEAD、工作树和统计口径，不代表当前代码、当前远端或 2026-08-06 状态。当前事实统一从 `docs/CURRENT_AI_CONTEXT.md` 与实时 Git preflight 获取。

日期：2026-07-23
性质：只读分析 + 文档路由优化；不修改业务代码、测试、数据库或运行时配置。

## 1. 审计范围与方法

本轮核查：

- 本地 `master`、`origin/master`、工作区状态和最近提交；
- `DOCUMENTATION_INDEX`、当前 handoff、master plan、Anki-aligned roadmap、热点审计、协作规则和本地 Bug 台账；
- 受 Git 跟踪的生产代码、测试和 Markdown 文件数量与行数；
- 读取当前状态文档的 Node/PHP guard，确认哪些文案已经成为可执行契约；
- 用户上传的 8 份 `.srt` 字幕；
- 未打开或解压任何 `.zip` 文件。

行数统计使用 Git 跟踪文件，排除 `vendor`、`node_modules`、构建产物和临时文件。行数用于定位责任热点，不用于机械判定代码质量。

## 2. Git 与当前状态

本轮开始时：

- 本地 HEAD：`1c17625a docs: close provider gate and roadmap`
- `origin/master`：`e9464bc4 docs: converge long-term AI rules`
- 本地 `master` 超前远端 90 个提交。
- 工作区存在大量本轮之前就有的修改和临时文件。

因此，本轮不能把 GitHub 远端、旧报告或本地工作树中的任一方单独称为“唯一最新事实”。当前本地开发状态应以本地 HEAD + 实际工作树为准；对外发布状态另行核查。禁止为了对齐远端而自动 reset、rebase、覆盖或清理。

## 3. 大计划完成情况

### 3.1 已完成的授权主线

| 阶段 | 状态 | 当前判断 |
|---|---|---|
| Settings architecture convergence | Production Closed | 设置读取/写入已按领域收敛 |
| Preset V1A–V1D | Production Closed | 默认、管理、消费者与 UX 关闭 |
| Browser / ReviewCardManage 3A–3D | Production Closed | Search、Table、Card Info、Mutation family 有 owner |
| Card Marker + Custom Study 1B | Production Closed | 标记与 preview-only 会话关闭 |
| Reviewer convergence | Production Closed | 正式请求、评分事务、history/undo owner 收敛 |
| Reader 6A–6M | Production Closed | 多个纯 policy、lookup API、fallback owner 已建立 |
| AI Study Card 7A–7E | Production Closed | lifecycle/package/validation/source/generation 已分离 |
| Provider Environment Gate | Closed / default-off | 仓库实现门关闭；runtime 外发仍未授权 |

### 3.2 当前准确阶段

当前没有已授权的下一项仓库功能实现。项目已经从“连续建设阶段”进入：

- 真实体验缺陷修复；
- 本地运行可靠性；
- 结构债务按触发点治理；
- Product Gate / Environment Gate 决策；
- 文档与 harness 收敛。

这意味着“阶段完成”和“软件无 Bug”必须分开。当前本地体验台账中的问题不是对已关闭里程碑的否定，而是进入真实使用后暴露的维护工作。

### 3.3 尚未授权的维护候选

建议顺序：

1. Python tokenizer 启动与导入 preflight。
2. 用户初始化与统计竞态。
3. Reader 空状态、词性默认和不可学习 token。
4. fallback 句界与 DOM 语义空格。
5. 可选集成 fail-soft。
6. 查词请求共享与实时连接降噪。
7. 用户生命周期与测试账号治理。
8. Anki 导出 Product Gate。

以上只是排期建议，不等于自动授权。

## 4. 代码数量

### 4.1 按产品区域

| 区域 | 文件数 | 行数 |
|---|---:|---:|
| `app/` + `routes/` + `config/` | 348 | 40,449 |
| `resources/js/` + `resources/sass/` | 236 | 46,466 |
| migrations + seeders | 100 | 3,519 |
| Python tools | 4 | 1,638 |
| 生产与工具合计 | 688 | 92,072 |
| tests | 264 | 79,693 |
| Markdown docs | 178+ | 约 23,600 |

测试代码约为生产与工具代码的 86.6%。这说明关键行为有较多 harness，但也意味着测试维护本身已成为显著成本，尤其是把阶段状态和旧数字写死在文档 guard 中时。

### 4.2 按主要扩展名

| 类型 | 文件数 | 行数 |
|---|---:|---:|
| PHP | 633 | 114,035，包含生产、migration 与 PHP tests |
| Vue | 147 | 35,750 |
| Markdown | 178 | 23,593 |
| Node `.mjs` | 79 | 9,626 |
| JavaScript | 53 | 5,917 |
| Sass | 36 | 4,799 |
| Python | 4 | 1,638 |

### 4.3 当前生产热点

| 文件 | 约行数 | 主要职责 | 判断 |
|---|---:|---|---|
| `TextBlockGroup.vue` | 1,762 | Reader DOM、选区、导航、hover、侧栏、副作用编排 | 当前最高交互风险；只按触发 Bug 拆 seam |
| `SenseReview.vue` | 1,219 | 正式复习会话、卡片、报告和错误恢复编排 | 中高；评分语义不能由页面复制 |
| `CustomStudySessionState.php` | 1,080 | 不可变会话状态与验证 | 体量大但职责较集中，不能只按行数拆 |
| `textThemes.js` | 1,034 | 主题数据 | 数据体量，不等同于业务耦合热点 |
| `DictionaryImportService.php` | 990 | 多格式解析、导入与表切换 | 中高；原子导入是承重缺口 |
| `TextBlockService.php` | 970 | tokenizer/import/Reader 兼容门面 | 中；已有 fallback owner，避免职责回流 |
| `Review.vue` | 943 | legacy Reviewer | 只维持兼容，不追加产品能力 |
| `tokenizer.py` | 918 | 多语言 NLP 服务 | 运行依赖和启动可靠性优先于继续加规则 |
| `ReviewCardTableSurface.vue` | 886 | Browser 表格、列、选择、导出 | 职责集中，可继续观察 |

当前有 30 个生产文件超过 500 行、4 个超过 1,000 行、1 个超过 1,500 行。旧路线图中的 `28 / 10 / 1` 已过时。

## 5. 文档数量与上下文问题

### 5.1 最大状态类文档

| 文件 | 行数 | 当前问题 |
|---|---:|---|
| `linguacafe-master-plan.md` | 1,404 | 当前 registry 与大量历史报告混合 |
| `repo-architecture-hotspot-audit.md` | 1,022 | 最新纠正和旧增量审计并存 |
| `linguacafe-fsrs-roadmap.md` | 880 | 历史阶段多，名称仍像当前入口 |
| `current-working-handoff.md` | 851 | 顶部权威正确，正文携带大段已关闭任务 |

四份文件合计 4,157 行，约占当前 Markdown 文档的 17.6%。它们在语义上重复：阶段状态、禁止事项、测试数字、下一步和执行报告被多次复制。

### 5.2 已完成的安全处理

本轮没有直接重写这些文件。原因是仓库测试把其中部分旧文案变成了可执行契约。只精简文档会使 harness 失败，形成“文档更正确、测试却阻止更新”的新矛盾。

本轮实施：

- 新增 `docs/CURRENT_AI_CONTEXT.md`，把当前事实、计划完成度、开放维护项、代码规模和文档冲突收敛到最小上下文。
- 更新 `DOCUMENTATION_INDEX`，新任务优先读取最小上下文；完整 handoff/master/hotspot 只用于追溯。
- 给三份长文档增加读取提示，不删除历史证据。

### 5.3 已发现的冲突与处理状态

#### 漂移 A：执行工作流旧 guard（已处理）

用户已经明确，以下两套旧流程都已停用：

- “一个主执行 Agent 对全部结果负责”；
- OpenCode → CodeBuddy → WorkBuddy 接力复核。

当前实际执行方式为：

1. 本地 Codex 直接处理；或
2. 网页端 GPT 通过 DevSpace 直接处理；
3. 两种方式都优先使用 FastCtx 完成文件发现、内容搜索、读取、替换和命令执行。

当前协作规则已改为“本地 Codex 或网页端 DevSpace，FastCtx-first”；旧 `GlmSingleAgentWorkflowDocsGuard.test.mjs` 已删除并由 `CurrentExecutionWorkflowDocsGuard.test.mjs` 取代。

未来方向是由网页端 GPT 制定计划，并把可独立工作的切片分配给网页端 DevSpace 与本地 Codex++；Codex++ 接入 DeepSeek Flash，并可进一步指挥接入相同 API 的扩展并行工作。该方向尚未完整实现，当前不能把它写成已可用能力。

#### 冲突 B：旧规模数字被 guard 锁死（已处理）

`MasterPlanIntegrityContract.test.mjs` 要求路线图持续包含：

- 28 个生产文件超过 500 行；
- 10 个超过 1,000 行；
- 1 个超过 1,500 行；
- `6.0/10`。

测试把一次性统计误当成长期不变量。当前 `MasterPlanIntegrityContract.test.mjs` 已改为保护状态、规划权限和文档路由；代码规模只从最小上下文和审计报告读取。

#### 冲突 C：阶段状态被旧 guard 锁死（已处理）

`ReviewCardManageArchitecturePlanGuard.test.mjs` 要求当前文档仍写：

`Card Marker + Custom Study 1B Planned / Not Authorized`

Anki-aligned 权威路线已标记其为 `Accepted / Production Closed`。Custom Study 权威头部和相关 guard 已同步；ReviewCardManage guard 也不再要求旧阶段状态或精确行数。

#### 冲突 D：最小上下文规则与正文体量冲突

索引和协作规则要求按需读取、历史降权，但 handoff/master/hotspot 仍将数百个完成报告保留在当前文件中。规则正确，文档结构尚未完成物理收敛。

### 5.4 文档目标结构

后续独立治理任务建议：

1. `AGENTS.md`：只放真正长期红线，不复制当前状态。
2. `CURRENT_AI_CONTEXT.md`：100–180 行，当前事实与停止点。
3. `DOCUMENTATION_INDEX.md`：只路由。
4. `linguacafe-master-plan.md`：只保留开放 registry、Product Gate、Environment Gate 和完成阶段链接。
5. `current-working-handoff.md`：只保留一个当前任务或“无活动任务”。
6. `repo-architecture-hotspot-audit.md`：只保留当前测量、风险和建议 seam。
7. 完成报告和旧增量审计移到 `docs/history/`。
8. 文档 guard 验证结构、状态词和链接，不锁定会自然变化的行数、测试计数和临时下一步。

## 6. Bug 与架构关系

### 6.1 启动和初始化架构

涉及：tokenizer 未运行、首次 goals/统计竞态、Jellyfin/Anki/reviewIntervals 缺失。

共同根因：

- 应用启动必需项、可选集成和用户初始化没有统一分类；
- 某些前置条件在页面访问时临时补种；
- Service 假设数据已存在；
- 前端请求顺序被当成一致性保障。

目标设计：

- `ApplicationBootstrap / Readiness`：区分 Required、Optional、Per-user defaults。
- 创建用户时使用事务完成默认 goals 和用户设置。
- 可选集成缺失返回 disabled，不返回 500。
- doctor 只诊断，业务请求不承担隐式修复。

### 6.2 导入架构

涉及：tokenizer 降级、URL 污染、长句、章节字符数输入、ECDICT 半导入风险。

共同根因：

- 导入前缺少统一 preflight；
- 内容分类、分词、结构恢复和持久化之间的契约不完整；
- fallback 继承了 token shape，但没有继承全部结构语义；
- 词典正式表边导边替换。

目标流水线：

```text
Source acquisition
→ Content classification（URL/email/path/code/normal text）
→ Service readiness preflight
→ Tokenization + structure contract
→ Validation preview
→ Atomic persistence/swap
→ Post-import health evidence
```

### 6.3 Reader 语义文本架构

涉及：DOM 无真实空格、hover 空白、生僻词状态、重复请求。

共同根因：

- 视觉布局与语义文本没有同一 contract；
- 加载、空、失败、关闭状态没有统一展示模型；
- 多个 UI surface 各自触发相同 effect。

目标设计：

- `ReaderTokenPresentationPolicy` 继续决定 class，但模板同时生成语义空白节点。
- 词典响应统一为 `loading / result / empty / disabled / error`。
- 建立共享 lookup coordinator，以 `chapter + sentence + surface + lemma` 为 key，复用在途 Promise、缓存和取消。
- SideBox、Popup、BottomSheet 只消费状态，不各自拥有 transport。

### 6.4 可选集成架构

涉及：Jellyfin、Anki、WebSocket/Pusher。

共同根因：功能开关未成为明确 capability，前端通过失败请求探测功能。

目标设计：

- 启动时生成只读 capability snapshot；
- 未启用时不创建客户端、不发请求、不重连；
- enabled 但配置错误时给管理员诊断；
- 普通用户只看到功能不可用或入口隐藏；
- runtime provider/外部服务继续 default-off。

### 6.5 用户生命周期架构

涉及：测试用户堆积、doctor 固定 user 1、注册管理员选项。

共同根因：项目有创建/编辑和按语言删数据，但缺少完整的用户生命周期 owner。

目标设计：

- 管理员创建模式与匿名注册分离；
- 后端验证操作者权限，不信任 `isAdmin` payload；
- 永远保留至少一个管理员；
- 完整删除使用事务和依赖清单；
- doctor 接受 email/user-id 并默认现存管理员；
- 测试账号有标识、数量上限和清理流程。

## 7. 字幕读取结果

本轮读取以下 8 份用户上传字幕：

1. `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
2. `AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt`
3. `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
4. `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
5. `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
6. `Vibe Coding 第二讲：像架构师一样用 AI 做复杂产品.srt`
7. `AI可以帮你写代码，但帮不了你成为架构师.srt`
8. `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`

没有读取：

- `Architecture_Skills_LinguaCafe_OpenTeam_2026-07-17.zip`
- `Architecture_Skills_Only_2026-07-17.zip`
- 任何其他压缩包。

### 7.1 可落地原则

- **上下文像有限容器**：当前状态、模块契约和历史证据分层，不能一次装入。
- **Spec 滞后于稳定决定**：MVP/探索期只写产品身份、排除项和高代价边界；真实验收后再晋升。
- **Harness 保护承重点**：权限、正式评分、FSRS、ReviewLog、删除、导入、来源绑定等需要正常与拒绝路径。
- **文档不等于约束**：重要规则必须进入测试、状态机、权限校验、smoke 或数据库 delta。
- **AI 不能代替架构师**：用户和总设计师决定责任、边界与取舍；AI负责执行和提供证据。
- **模块化有成本**：按责任和副作用 owner 拆分，不能按行数制造空壳。
- **难测试是架构信号**：输入、输出、依赖或副作用不清时，先收敛接口。
- **小步闭环**：每轮一个可陈述结果；共享写入串行，独立只读核查可并行。

### 7.2 对 LinguaCafe 的直接结论

当前最重要的文档优化不是继续写更多规则，而是：

1. 让 AI 默认只读最小当前上下文；
2. 把历史完成报告移出当前状态文件；
3. 让 guard 锁定不变量，不锁定会变化的统计数字；
4. 每个 Bug 修复先定位共同 owner，再决定是否需要拆分；
5. 外部服务、数据库写入和正式评分继续以可执行拒绝路径为核心。

## 8. 推荐后续阶段

### Docs-Governance-1：阶段性完成

本轮已同步完成：

- 当前协作规则改为本地 Codex / 网页端 DevSpace + FastCtx-first；
- 删除旧单主 Agent guard，新增当前执行方式 guard；
- Custom Study 1B 当前状态同步为 Production Closed；
- Master Plan integrity guard 改为保护当前产品状态和规划权限；
- ReviewCardManage guard 改为保护职责 owner、接口边界和危险写操作，不再锁定行数；
- 新产品决定、待讨论议题和四轮讨论路线进入独立产品文档。

仍未物理清理的主要项目是 handoff/master/hotspot 中的大量历史执行正文；它们已经通过索引降权，后续可以分批迁入 history，不应与任何业务功能任务捆绑。

### Runtime-Remediation-1：Tokenizer readiness

如果用户优先解决真实使用问题，第一切片应只处理：

- tokenizer 启动、依赖诊断和健康检查；
- 导入前 readiness；
- 重新导入测试文章；
- 哲学文本词元真实页面验收。

不要同时处理 URL、DOM 空格、Jellyfin 或注册页面。

## 9. 验证结果

### 9.1 当前治理验证

以下定向 Harness 已通过：

- `CurrentExecutionWorkflowDocsGuard.test.mjs`：6/6；
- `CustomStudySessionArchitectureDocsGuard.test.mjs`：88/88；
- `ReviewCardManageArchitecturePlanGuard.test.mjs`：通过；
- `MasterPlanIntegrityContract.test.mjs`：通过。

这些测试现在保护当前执行路径、Custom Study 完成状态、Browser 职责边界和产品规划权限，不再保护旧 Agent 名称、旧阶段状态或文件精确行数。

没有运行前端构建或 PHP 业务测试，因为本轮没有修改业务代码、接口、数据库、路由或运行时配置。

## 10. 本轮结论

- 大计划的已授权建设序列已完成。
- 当前主要风险从“缺功能”转为“本地运行可靠性、Reader 数据质量、可选集成，以及历史文档维护与上下文路由负担”。
- 代码架构已有较清晰领域 owner，但 Reader、Reviewer、导入和启动流程仍是主要风险区。
- 文档问题不能靠继续写长规则解决；当前最小上下文和关键 guard 已收敛，长历史正文仍需后续分批迁移。
- 本轮未修改业务代码、数据库、`.env`、`AGENTS.md`、`.omo/` 或 `.playwright-cli/`；只修改文档和文档/架构 guard。
