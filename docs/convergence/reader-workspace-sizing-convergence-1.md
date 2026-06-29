# ReaderWorkspaceSizing-Convergence-1: 阅读页宽度计算重复规则收敛

## 1. 当前问题

### 哪些文件重复了宽度断点规则

| 文件 | 方法/属性 | 行号 | 重复内容 |
|------|-----------|:----:|---------|
| `TextReader.vue` | `readerSidebarWidthForContentWidth(width)` | 446-451 | 1500/1280/1080 断点判断 |
| `VocabularySideBox.vue` | `sidebarWidth()` computed | 234-242 | 同一套 1500/1280/1080 断点，返回带 'px' 字符串 |

### 重复规则是什么

```js
if (width >= 1500) return 600;  // TextReader 版本
if (width >= 1280) return 560;
if (width >= 1080) return 520;
return 400;

// VocabularySideBox 版本
if (width >= 1500) return '600px';
if (width >= 1280) return '560px';
if (width >= 1080) return '520px';
return '400px';
```

此外 `TextReader.vue` 的 `vocabularySidebarTest()` (L467-480) 还维护了：
- `minimumReaderWidth`：`width >= 1280 ? 720 : 560`
- `+ 72` 间距常数
- `sidebarEstimate` 的计算

### 为什么这是非必要复杂度

1. **隐式规则**：断点值（1500/1280/1080）和对应的宽度值（600/560/520/400）散落在两个组件中，修改时容易遗漏。
2. **类型分歧**：TextReader 返回 number，VocabularySideBox 返回 string——这个分歧是历史负担，应该消除。
3. **不可维护的重复**：增加一个新断点（例如 1800 → 700）需要在两个文件中同步修改。
4. **违反 DRY**：同一套产品策略散落在不同组件里。

## 2. 本轮收敛策略

### 核心方案

把宽度断点规则从两个组件中移动到纯函数 helper `resources/js/services/ReaderWorkspaceSizingService.js`。

- 组件仍负责读取 DOM 宽度（获取 `#fullscreen-box.clientWidth`）。
- helper 只负责计算（纯函数，不访问 DOM/Vue/Vuex/window）。
- 断点规则只存在于 helper 中，两个组件同时引用同一个函数。
- VocabularySideBox 也不再自己拼 'px'——改为调用返回 string 的 helper 方法。

### 最小修改原则

- 只修改：`ReaderWorkspaceSizingService.js`（新增）、`TextReader.vue`（替换方法体）、`VocabularySideBox.vue`（替换方法体）
- 不修改：`TextBlockGroup.vue`、Vuex、后端、SCSS、AI lookup、WordSense / ReviewCard / FSRS
- 不修改：`TextReader.scss`、`VocabularySideBox.scss`、`VocabularyBox.js`
- 不修改：断点值、间距常数、`sidebarHidden` 逻辑、`vocabularySidebarFits` 的 data 字段

## 3. 最小闭环

| 输入宽度 | 当前行为 | 修改后行为 | 差异 |
|---------|---------|-----------|:----:|
| 1920px | sidebarWidth=600, vocabularySidebarFits=true | 同上 | 无 |
| 1500px | sidebarWidth=600, vocabularySidebarFits=true | 同上 | 无 |
| 1400px | sidebarWidth=560, vocabularySidebarFits=true | 同上 | 无 |
| 1280px | sidebarWidth=560, vocabularySidebarFits=true | 同上 | 无 |
| 1080px | sidebarWidth=520, vocabularySidebarFits=true | 同上 | 无 |
| 900px | sidebarWidth=400, vocabularySidebarFits=false | 同上 | 无 |
| 750px | sidebarWidth=400, vocabularySidebarFits=false | 同上 | 无 |

## 4. 将被修改的文件

| 文件 | 操作 | 说明 |
|------|------|------|
| `resources/js/services/ReaderWorkspaceSizingService.js` | **新增** | 纯函数 helper |
| `resources/js/components/TextReader/TextReader.vue` | **修改** | 删除 `readerSidebarWidthForContentWidth` 方法体，改为调用 helper；`vocabularySidebarTest` 内部改用 helper |
| `resources/js/components/Text/VocabularySideBox.vue` | **修改** | 删除重复断点逻辑，改为调用 helper 的 getReaderSidebarCssWidthForWorkspace |
| `docs/convergence/reader-workspace-sizing-convergence-1.md` | **新增** | 本设计文档 |
| `docs/plans/linguacafe-master-plan.md` | **修改** | 标记本轮收敛完成 |
| `docs/plans/linguacafe-fsrs-roadmap.md` | **修改** | 标记本轮收敛完成 |

## 5. 明确不改的文件

- `TextBlockGroup.vue` — 仍自计算宽度（独立 seam），本轮不动
- `resources/js/vuex/VocabularyBox.js` — Vuex state 不动
- `resources/js/components/Text/WordSensesList.vue` — 业务逻辑不动
- `resources/js/components/Text/VocabularySearchBox.vue` — 业务逻辑不动
- `resources/js/components/Text/AddSenseForm.vue` — 业务逻辑不动
- `resources/js/components/Text/AiSuggestionPanel.vue` — 业务逻辑不动
- 所有 `app/` 目录文件 — 后端不动
- 所有 `routes/` 目录文件 — 路由不动
- 所有 `database/` 目录文件 — 数据库不动
- 所有 `resources/sass/` 文件 — SCSS 不动
- `package.json`、`composer.json` — 不新增依赖

## 6. 目标结构

### 新 helper

```
resources/js/services/ReaderWorkspaceSizingService.js
```

### 导出函数

```js
// 返回 sidebar 宽度数值（number，单位 px）
// 输入 width 是 workspace 宽度（px）
export function getReaderSidebarWidthForWorkspace(width);
// 1500+ → 600, 1280+ → 560, 1080+ → 520, else 400

// 返回 sidebar 宽度 CSS 值（string，带 'px'）
export function getReaderSidebarCssWidthForWorkspace(width);
// 600px / 560px / 520px / 400px

// 返回最小阅读区域宽度（number，单位 px）
export function getMinimumReaderWidthForWorkspace(width);
// >= 1280 → 720, else 560

// 判断 sidebar 是否能适应 workspace（boolean）
export function doesReaderSidebarFitWorkspace(width, spacing = 72);
// width >= minReaderWidth + sidebarWidth + spacing
```

### 调用方

| 调用位置 | 原代码 | 改为 |
|---------|--------|------|
| `TextReader.vue:readerSidebarWidthForContentWidth` | 内联断点 | 调用 `getReaderSidebarWidthForWorkspace` |
| `TextReader.vue:vocabularySidebarTest` | 内联算术 | 调用 `getMinimumReaderWidthForWorkspace` + `getReaderSidebarWidthForWorkspace` + `doesReaderSidebarFitWorkspace` |
| `VocabularySideBox.vue:sidebarWidth` | 内联断点 + 拼 'px' | 调用 `getReaderSidebarCssWidthForWorkspace` |

### 保留项

- `TextReader.vue:readerWorkspaceWidth()` — 仍负责读取 DOM width
- `TextReader.vue:sidebarWidthValue()` computed — 计算路径不变（readerWorkspaceWidth → helper → number）
- `TextReader.vue:sidebarPaddingWidth()` computed — 拼接 'px !important'
- `TextReader.vue:vocabularySidebarFits` data — 字段名和含义不变
- `TextReader.vue:vocabularySidebarTest()` — 方法名不变，内部逻辑改为用 helper

## 7. 风险

| 风险 | 概率 | 缓解措施 |
|------|:----:|---------|
| 布局行为轻微改变（断点值不同） | 极低 | 断点值不变，helper 的 observable behavior 与原代码完全一致 |
| helper 变成薄包装 | 低 | 本来就是薄包装——目的是消除重复而非增加深度。这是合理的 pure extract |
| smoke guard 不能覆盖所有断点 | 低 | 目前只覆盖 1920px 和 900px。但断点值没有变，行为不变 |
| import 路径写错 | 低 | npm build 会报错 |
| VocabularySideBox 的 `typeof document !== 'undefined'` guard 行为改变 | 无 | 不修改 DOM 读取逻辑，只修改断点判断 |

## 8. 验证方式

1. `npm run development` — 前端构建成功
2. `git diff --check` — 无空白字符错误
3. `python tools/smoke/text_reader_smoke_guard.py --help` — smoke script 可运行
4. text reader smoke guard（如果 auth 可用）— 保证 P0 用例通过
5. 代码审查：确认 helper 是纯函数、不访问 DOM/Vue/Vuex/window
6. 人工检查 diff：确认没有多余修改
