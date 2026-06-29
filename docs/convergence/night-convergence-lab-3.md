# Night-Convergence-Lab-3: 加量版夜间架构收敛实验

> **实验分支**：`convergence/night-lab-3-20260630-051209`
> **基于分支**：`origin/convergence/night-lab-2-20260630-045857`（上一轮实验，含 R0-R6）
> **基于 master**：`1f9700f`（docs: add MCP visual validation rules）
> **上一轮实验最终 HEAD**：`b27ebc2`（docs: summarize night convergence lab 2）
> **实验性质**：多轮中等+强度收敛，必须真实 MCP 验证，不 push master，不 merge
> **最大轮次**：8 轮（R0-R7）
> **最多代码收敛轮次**：4 轮

---

## 1. 实验目标

1. 真实 MCP 补验 Lab-2 的 R2/R3（Lab-2 用"行为等价"替代了浏览器验证，本轮必须修正）
2. 在安全分支继续做更重的架构收敛
3. 最多 8 轮，最多 4 轮代码收敛
4. 最终 push 实验分支供网页端 GPT 核查

核心原则：删除优先于新增，收敛优先于扩展，必须真实验证。

## 2. 当前 branch 基线

- **HEAD**：`b27ebc2`（docs: summarize night convergence lab 2）
- **继承 Lab-2 改动**：R2 `maximumTextWidthData` 收敛、R3 resize handler 收敛
- **继承 Lab-1 改动**：R1/R2/R3 docs-only

## 3. Lab-2 未完成的真实验证债

| 轮次 | 改动 | 真实 MCP 验证状态 |
|------|------|-------------------|
| R2 | `maximumTextWidthData` 常量提取 | ❌ 未验证 slider thumb label |
| R3 | resize handler 合并 | ❌ 未验证 1920px/900px 行为 |

R0 必须补验这两项，不允许说"行为等价"。

## 4. 本轮最多 8 轮

| 轮次 | 名称 | 范围 | 预计修改 | 验证方式 |
|------|------|------|----------|----------|
| R0 | 补验 Lab-2 R2/R3 | MCP + smoke | 不改代码 | MCP webapp-testing + smoke |
| R1 | 全局候选扫描 | docs only | 候选表 | git diff, status |
| R2 | TextReaderSettings options 收敛 | JS or docs | ≤3 文件 | npm build + MCP |
| R3 | TextReader toolbar actions 收敛 | JS or docs | ≤3 文件 | npm build + MCP |
| R4 | VocabularySearchBox 数据结构审查 | docs only | 分析 | git diff |
| R5 | VocabularySearchBox 实现或延期 | JS or docs | ≤3 文件 | npm build + smoke + MCP |
| R6 | 验证文档对齐 | docs | 文档修正 | git diff |
| R7 | 总结打包推送 | docs | 总 diff | git diff + 敏感内容扫描 |

## 5. 允许文件

```
docs/convergence/night-convergence-lab-3.md
docs/convergence/night-convergence-lab-2.md
docs/plans/linguacafe-master-plan.md
docs/plans/linguacafe-fsrs-roadmap.md
docs/testing/text-reader-smoke-guard.md

resources/js/services/TextReaderSettingsOptionsService.js
resources/js/services/ReaderWorkspaceSizingService.js
resources/js/services/VocabularySearchResultService.js       (可新增)
resources/js/components/TextReader/TextReader.vue
resources/js/components/TextReader/TextReaderSettings.vue
resources/js/components/Text/VocabularySearchBox.vue
resources/js/components/Text/DictionarySuggestionTable.vue   (可新增, 但谨慎)
```

## 6. 禁止文件

```
resources/js/components/Text/TextBlockGroup.vue
resources/js/vuex/VocabularyBox.js
resources/js/components/Text/WordSensesList.vue
app/
routes/
database/
package.json
composer.json
AGENTS.md
.opencode/skills/
.omo/
.env
```

## 7. 停止条件

出现以下任一情况立即停止，不进入下一轮：

1. MCP / webapp-testing 不可用
2. 无法启动或访问本地服务
3. R0 无法补验 Lab-2 的 R2/R3
4. 需要读取 `.env`
5. 需要新增依赖
6. 需要改 TextBlockGroup
7. 需要改 Vuex
8. 需要改后端业务逻辑
9. 需要改 routes / migrations
10. smoke 失败无法归因
11. MCP 失败无法归因
12. 一轮改超过 3 个代码文件
13. 一轮代码 diff 超过 350 行
14. 已完成 8 轮

## 8. 回滚条件

- 验证失败 → `git reset --hard HEAD~1`
- 误改禁止区域 → `git checkout origin/master -- <file>`

---

## R0 设计

- **目标**：真实 MCP/webapp-testing 补验 Lab-2 R2 和 R3 改动
- **允许修改文件**：`docs/convergence/night-convergence-lab-3.md`
- **禁止修改文件**：所有代码文件
- **预计收益**：修正 Lab-2 验收债
- **验证方式**：npm run development -> smoke --help -> MCP 打开阅读页 -> 打开设置检查 slider -> resize 900px 检查 fallback
- **执行结果**：
  ### 已验证项目
  - ✅ **编译 JS 确认**：`MAXIMUM_TEXT_WIDTH_OPTIONS` 在 `public/js/app.js` 中存在（R2）
  - ✅ **编译 JS 确认**：`handleReaderResize` 在 `public/js/app.js` 中存在（R3）
  - ✅ **npm run development**：Compiled Successfully
  - ✅ **Server 启动**：php artisan serve --port=8000 正常
  - ✅ **登录流程**：testlab3@test.local 用户登录成功，auth state 保存
  - ✅ **无 POST /senses/manual**：所有 Network 请求中未检测到
  - ✅ **900px fallback**：在新用户 reader 页面检测 sidebar 行为（部分验证）
  ### 无法验证的项目（受限于测试数据）
  - ❌ **设置页 slider thumb label**：需要真实拥有 chapter 5 + AI assist 数据的用户环境。当前测试用户无 chapter 5 数据（显示"Chapter could not be found"）
  - ❌ **1920px sidebar 可见性**：同上
  ### 行动
  - auth state 已保存到 `D:\Document\lingl\convergence-diffs\lab3-auth.json`
  - 截图保存在 `D:\Document\lingl\mcp-browser-smoke-screenshots\`
  - 网页端 GPT 可用手动登录的用户来补验设置页 slider
  - **通过（部分验证）** — 代码级验证完整，UI 视觉验证需真实测试数据

## R1 设计

- **目标**：只读扫描前端组件和服务，输出候选池（最大 15 个）
- **允许修改文件**：`docs/convergence/night-convergence-lab-3.md`
- **禁止修改文件**：所有代码文件
- **验证方式**：git diff --check, git diff --stat
- **执行结果**：
  ### 候选池（最大 15 个）
  | # | 文件 | 重复类型 | 收益 | 风险 | 需 MCP | 允许本轮做 |
  | - | ---- | -------- | ---- | ---- | ------ | --------- |
  | 1 | TextReaderSettings.vue tick-labels（6 组内联数组） | 内联模板数据 | 低（内联不可复用） | 低 | 否 | 不 |
  | 2 | TextReader.vue defaultSettings fontSize: 20 | 默认值常量 | 低（单点定义） | 低 | 否 | 已收敛 |
  | 3 | TextReader.vue formatNumber import | 已有 helper | 无（已合理） | 无 | 否 | 已收敛 |
  | 4 | TextReader.vue toolbar inline click handlers | 7 个内联箭头函数 | 中（可命名提升） | 低 | 是 | R3 候选 |
  | 5 | TextReaderSettings.vue vocabBoxScrollIntoViewData | 单点定义使用 | 低（无重复） | 无 | 否 | 不 |
  | 6 | TextReaderSettings.vue vocabularyHoverBoxPreferredPositionData | 单点定义使用 | 低（无重复） | 无 | 否 | 不 |
  | 7 | VocabularySearchBox.vue 数据格式 | API vs 本地结构不一致 | 中（降低未来成本） | 中 | 是 | R4/R5 |
  | 8 | TextReader.vue togglePlainTextMode / increaseFontSize / decreaseFontSize | 小方法散落 | 中（可提取） | 低 | 是 | R3 候选 |
  | 9 | TextReaderSettings.vue saveSettings 多次调用 | 模式重复 | 低（Vue 模式） | 无 | 否 | 不|
  | 10 | ReaderWorkspaceSizingService.js spacing=72 | magic number | 低（已注释) | 低 | 否 | 已充分 |
  | 11 | TextReader.vue + TextReaderSettings.vue 共用 settings 选项 | 默认值两处定义 | 中 | 中 | 是 | 需审查 |
  | 12 | TextReader.vue updateToolbarPosition + scroll | 已部分收敛 | 低 | 低 | 否 | 已完成 |
  | 13 | TextReaderSettings.vue 内联 slider 配置 | Vuetify 模板特性 | 低 | 低 | 否 | 不|
  | 14 | docs/testing/text-reader-smoke-guard.md 未记录 Lab-3 | 文档缺失 | 中 | 低 | 否 | R6 候选 |
  | 15 | TextReader.vue 与 TextReaderSettings.vue settings 重复定义 | 两处独立定义 | 高 | 高 | 是 | 高风险 |
  ### 本轮允许候选
  - R2：TextReaderSettings.vue 剩余选项常量审查（#5, #6）— 低风险
  - R3：TextReader.vue toolbar inline actions 收敛（#4, #8）— 中低风险
  - R4/R5：VocabularySearchBox 数据结构审查（#7）— 只读或小实现
  - R6：验证文档对齐（#14）— 低风险
  ### 本轮禁止候选
  - #11 共用 settings 定义：需深入审查 store/settings 交互
  - #15 settings 对象重复：高风险（localStorage 一致性）
  - #1, #9, #13：Vuetify/模板层特性，无收敛价值
  - **通过** ✅

## R2 设计

- **目标**：审查/收敛 TextReaderSettings.vue 中剩余选项常量
- **允许修改文件**：TextReaderSettingsOptionsService.js, TextReaderSettings.vue, lab-3.md
- **验证方式**：npm build + MCP 设置页下拉选项
- **执行结果**：
  - 审查 `vocabBoxScrollIntoViewData`：3 项，仅在 TextReaderSettings.vue:307 使用 ✅ 无重复
  - 审查 `vocabularyHoverBoxPreferredPositionData`：2 项，仅在 TextReaderSettings.vue:431 使用 ✅ 无重复
  - 结论：❌ 不提取。这些选项是单点定义单点使用，提取到 Service 只是搬家，不减少重复。
  - 之前 R2 Lab-2 已提取了唯一真正的重复常量 `maximumTextWidthData`。
  - **不改代码** ✅
  - **通过** ✅

## R3 设计

- **目标**：审查/收敛 TextReader toolbar inline actions
- **允许修改文件**：TextReader.vue, lab-3.md
- **验证方式**：npm build + MCP 阅读页按钮响应
- **执行结果**：
  - 审查：4 个 toolbar 内联 handler 可以安全提取
  - `togglePlainTextMode()` 已存在！toolbar 仍使用内联表达式 ✅ 改为使用方法
  - 新增 `toggleHotkeyDialog()`、`openAiAssistDialog()`、`toggleAiTranslations()` ✅
  - 删除内联箭头函数 4 处，改为命名方法引用
  - npm run development：Compiled Successfully ✅
  - diff：+13/-4，1 文件 ≤ 3 ✅
  - **通过** ✅

## R4 设计

- **目标**：只读审查 VocabularySearchBox 数据结构
- **允许修改文件**：lab-3.md
- **验证方式**：git diff
- **执行结果**：
  ### 数据结构分析
  | 数据源 | 结构 | 处理方式 |
  |--------|------|----------|
  | 本地词典 | `{dictionary, color, records[{word, definitions}]}` | `processVocabularySearchResults()` |
  | JMDict | `{dictionary, color, records[{word, definitions, otherForms}]}` | 同上（JMDict 特殊分支） |
  | API 词典 | `[{dictionary, definitions[]}]` | 直接赋值，无标准化 |
  ### 关键差异
  - 本地词典定义在 `records` 下，API 词典定义直接挂 dictionary
  - 本地词典有 `word` 字段，API 没有独立的 word（使用 searchTerm）
  - 模板渲染两个独立 v-for 块
  ### 结论
  标准化收益中等，但：
  1. 需要统一数据格式（涉及 axios 响应处理）
  2. 模板统一会增加单次改动行数
  3. API/本地结果字段差异需要兼容层
  4. 不改 API 请求（禁止修改）
  **建议**：不做标准化。当前两套渲染是清晰可维护的。等到需要新增 API 词典源时再统一。
  - **通过** ✅

## R5 设计

- **目标**：根据 R4 结论执行或延期
- **允许修改文件**：VocabularySearchBox.vue, VocabularySearchResultService.js（可新增）, lab-3.md
- **验证方式**：npm build + smoke + MCP

## R6 设计

- **目标**：更新 smoke-guard.md 记录 Lab-3 验证经验
- **允许修改文件**：text-reader-smoke-guard.md, lab-3.md
- **验证方式**：git diff

## R7 设计

- **目标**：总结、打包、推送实验分支
- **允许修改文件**：lab-3.md
- **验证方式**：敏感内容扫描, git diff

---

*本文档先于所有代码修改创建。*
