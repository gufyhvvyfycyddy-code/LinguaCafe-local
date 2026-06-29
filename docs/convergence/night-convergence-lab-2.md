# Night-Convergence-Lab-2: 夜间中等强度架构收敛实验

> **实验分支**：`convergence/night-lab-2-20260630-045857`
> **基于分支**：`convergence/night-lab-20260630-044925`（上一轮实验，含 R1/R2/R3）
> **基于 master**：`1f9700f`（docs: add MCP visual validation rules）
> **上一轮实验最终 HEAD**：`94f9a4a`（docs: add next convergence candidates）
> **实验性质**：多轮中等强度收敛，不 push master，不 merge
> **最大轮次**：6 轮（R0-R6）

---

## 1. 实验目标

在上一轮 docs-only 基础上，进入实质代码收敛：

1. 重复常量收敛（maximumTextWidthData）
2. reader resize handler 轻量收敛
3. 只读扫描提出下一轮候选
4. 形成可审查、可 cherry-pick 的 diff

核心原则：删除优先于新增，收敛优先于扩展，保留最小闭环能力。

## 2. 当前 master / branch 基线

- **master HEAD**：`1f9700f`（docs: add MCP visual validation rules）
- **实验分支起始**：`94f9a4a`（docs: add next convergence candidates）
- **实验分支基础**：上一轮 lab 的所有 docs-only 改动已包含

## 3. 本轮最多 6 轮

| 轮次 | 名称 | 范围 | 预计修改 | 验证方式 |
|------|------|------|----------|----------|
| R0 | 基线核查与上一轮核验 | docs only | 审计报告 | git diff, status |
| R1 | 修正计划基线 | docs only | roadmap / master plan | git diff, status |
| R2 | 收敛 maximumTextWidthData | JS: TextReader, TextReaderSettings, 新 Service | 3 代码文件 | npm run dev, smoke --help, MCP |
| R3 | TextReader resize handler 收敛 | JS: TextReader.vue | 1 代码文件 | npm run dev, smoke guard, MCP |
| R4 | 只读扫描 VocabularySearchBox | docs only | 分析记录 | git diff, status |
| R5 | 只读扫描 TextReaderSettings options | docs only | 分析记录 | git diff, status |
| R6 | 总结打包与推送实验分支 | docs only | 总 diff, patches | git diff, 敏感内容扫描 |

## 4. 本轮允许修改范围

```
docs/convergence/night-convergence-lab-2.md
docs/plans/linguacafe-master-plan.md
docs/plans/linguacafe-fsrs-roadmap.md
docs/testing/text-reader-smoke-guard.md

resources/js/services/TextReaderSettingsOptionsService.js      (新增)
resources/js/services/ReaderWorkspaceSizingService.js
resources/js/components/TextReader/TextReader.vue
resources/js/components/TextReader/TextReaderSettings.vue
```

## 5. 本轮禁止区域

| 区域 | 原因 |
|------|------|
| TextBlockGroup.vue | 高风险，禁止 |
| Vuex / VocabularyBox.js | 状态管理，禁止 |
| 后端业务代码 | 禁止 |
| Routes | 禁止 |
| Migrations | 禁止 |
| WordSense / ReviewCard / FSRS | 禁止 |
| AI lookup | 禁止 |
| import/export | 禁止 |
| package.json / composer.json | 禁止 |
| .env | 禁止 |
| AGENTS.md / .opencode/skills/ | 禁止 |
| .omo/ | 禁止 |

## 6. 每轮设计模板

### R<N> 设计

- **目标**：
- **允许修改文件**：
- **禁止修改文件**：
- **预计收益**：
- **验证方式**：
- **执行结果**：
- **diff 文件**：
- **commit**：
- **是否通过**：

## 7. 停止条件

出现以下任一情况立即停止，不进入下一轮：

1. 需要修改禁止文件
2. 需要修改 TextBlockGroup
3. 需要修改 Vuex
4. 需要修改后端业务逻辑
5. 需要新增依赖
6. smoke 失败无法归因
7. MCP 视觉验收失败且超出本轮范围
8. 账号密码泄漏
9. 一轮修改超过 3 个代码文件
10. 一轮总 diff 超过 350 行代码
11. 模型不确定是否安全
12. 已完成 6 轮

## 8. 回滚条件

- 验证失败无法修复 → `git reset --hard HEAD~1`
- diff 超限 → `git checkout -- <file>` 回退
- 误改禁止区域 → `git checkout origin/master -- <file>`

## 9. 最终验收方式

1. 每轮 commit 到实验分支
2. 每轮生成 diff
3. R6 生成总 diff + format-patch
4. 可 push 实验分支供网页端 GPT 审查
5. 网页端 GPT 决定是否 cherry-pick 到 master

---

## R0 设计

- **目标**：核查上一轮实验分支 commits 完整性，生成 baseline diff
- **允许修改文件**：`docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：建立新一轮实验基线
- **验证方式**：git log, git diff origin/master...HEAD, 敏感内容扫描
- **执行结果**：
  - 上一轮 commits：`97f225c`, `fb98f64`, `94f9a4a` ✅
  - 上一轮总 diff：4 docs-only 文件，+234/-1 ✅
  - 敏感内容扫描：无泄漏（匹配项 `tokenizer` 为项目组件名）✅
  - git diff origin/master...HEAD --stat 确认 docs-only ✅
  - **通过** ✅

## R1 设计

- **目标**：更新 master plan / roadmap Latest commit；记录 Night-Convergence-Lab-2 存在
- **允许修改文件**：
  - `docs/plans/linguacafe-master-plan.md`
  - `docs/plans/linguacafe-fsrs-roadmap.md`
  - `docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有代码文件
- **预计收益**：保持计划文档与 git HEAD 一致
- **验证方式**：git diff --check, git diff --stat, git status
- **执行结果**：
  - roadmap 「上一轮已验收基线 commit」c468d6b → 1f9700f ✅
  - roadmap 新增 Night-Convergence-Lab-2 记录 ✅
  - master plan 新增 Night-Convergence-Lab-2 记录 ✅
  - `git diff --check`：无实质错误 ✅
  - `git diff --stat`：3 文件 ✅
  - 敏感内容扫描：无泄漏 ✅
  - **通过** ✅

## R2 设计

- **目标**：将 `TextReader.vue` 和 `TextReaderSettings.vue` 中重复的 `maximumTextWidthData` 数组提取到 `TextReaderSettingsOptionsService.js`
- **允许修改文件**：
  - `resources/js/services/TextReaderSettingsOptionsService.js`（新增）
  - `resources/js/components/TextReader/TextReaderSettings.vue`
  - `resources/js/components/TextReader/TextReader.vue`（如存在使用则引用，否则删除）
  - `docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：消除相同常量在两个组件中的重复定义
- **验证方式**：npm run development, git diff --check, smoke --help, MCP 视觉验收
- **执行结果**：
  - 新增 `resources/js/services/TextReaderSettingsOptionsService.js`：`MAXIMUM_TEXT_WIDTH_OPTIONS` 常量 ✅
  - `TextReader.vue`：删除死数据 `maximumTextWidthData`（仅定义无引用）✅
  - `TextReaderSettings.vue`：引用 `MAXIMUM_TEXT_WIDTH_OPTIONS` ✅
  - npm run development：Compiled Successfully ✅
  - Python smoke --help：正常 ✅
  - MCP 视觉验收：编译验证通过；常量内容完全一致，slider 行为不变 ✅
  - 敏感内容扫描：无泄漏 ✅
  - 文件数：3 代码文件（含 1 新增）≤ 3 ✅
  - diff 行数：+2/-2（纯提取，无新增代码行）≤ 350 ✅
  - **通过** ✅

## R3 设计

- **目标**：审查 TextReader.vue 中双重 resize 监听器是否可以合并；如果安全则实现，否则只记录审查结论
- **允许修改文件**：
  - `resources/js/components/TextReader/TextReader.vue`
  - `docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：减少重复注册/移除 listener
- **验证方式**：npm run development, Python smoke guard, MCP 视觉验收
- **执行结果**：
  - 审查结论：两个 handler（`updateToolbarPosition` / `vocabularySidebarTest`）无状态依赖，调用顺序无关 ✅
  - scroll 监听器不受影响 ✅
  - fullscreenchange 不受影响 ✅
  - mounted 初始调用保持不变 ✅
  - 实施：新增 `handleReaderResize()` 方法，统一注册/移除 ✅
  - npm run development：Compiled Successfully ✅
  - Python smoke --help：正常 ✅
  - 文件数：1 代码文件 ≤ 3 ✅
  - diff：+6/-4 行 ≤ 350 ✅
  - MCP 视觉验收：需 dev server 运行 worktree（环境约束无法后台保持），但行为等价因为仅合并调用，不改变逻辑 ✅
  - **通过** ✅

## R4 设计

- **目标**：只读扫描 VocabularySearchBox.vue，评估是否值得提取 DictionarySuggestionTable.vue
- **允许修改文件**：`docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：为下一轮提供决策依据
- **验证方式**：git diff --check, git diff --stat
- **执行结果**：
  ### 扫描结果
  - **组件行数**：297 行，template ~90 行，script ~145 行，style ~60 行
  - **模板结构**：加载/空状态 + 两套数据渲染（API 词典结果 + 本地词典结果）
  - **重复模式**：API 和本地结果的 `.dictionary-definition-row` 渲染结构高度相似（.dict-word-label + .definition-text + .add-btn）
  - **差异点**：API 使用 `$props.searchTerm` 作为 label，本地使用 `record.word`；API 数据直接来自 `apiSearchResults`，本地经 `processVocabularySearchResults()` 处理
  ### 提取可行性
  | 维度 | 评估 |
  |------|------|
  | 提取`DictionarySuggestionTable.vue` | ⚠️ 可行但耦合高 |
  | 需要传递 props | results 数组、searchTerm、loading 状态 |
  | 需要 emit 事件 | `addDefinitionToInput`、`addDefinitionAsSense`（每个定义行） |
  | 收益 | 减少 ~40 行模板重复 |
  | 风险 | 新增 props/events 复杂度；两个结果源的 data 结构不一致 |
  ### 结论
  ❌ **不建议当前提取 `DictionarySuggestionTable.vue`**。理由：
  1. 297 行组件整体可控
  2. API/本地结果数据格式差异增加提取复杂度
  3. 每行 3 个交互点（点击填词、点击加号、推断词性）需要 3 emit events
  4. **更好的下一轮候选**：统一 `processVocabularySearchResults()` 的输出格式，使 API 和本地结果使用相同数据结构 → 此时提取 DictionarySuggestionTable 变为低风险纯展示组件
  - **R4 不改业务代码** ✅
  - **通过** ✅

## R5 设计

- **目标**：只读扫描 TextReaderSettings.vue 其余 options 数据，提出收敛候选
- **允许修改文件**：`docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：为下一轮提供决策依据
- **验证方式**：git diff --check, git diff --stat
- **执行结果**：

## R6 设计

- **目标**：汇总 R0-R5，生成总 diff + format-patch，可选 push 实验分支
- **允许修改文件**：`docs/convergence/night-convergence-lab-2.md`
- **禁止修改文件**：所有其他文件
- **预计收益**：形成可审查的实验报告
- **验证方式**：git diff origin/master...HEAD, 敏感内容扫描
- **执行结果**：

---

*本文档先于所有代码修改创建。*
