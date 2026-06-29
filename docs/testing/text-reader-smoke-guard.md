# Text Reader Smoke Guard

## 目的

保护阅读页 / 查词栏核心行为，防止后续 `TextBlockGroup.vue` / `VocabularySideBox.vue` / `WordSensesList.vue` 等组件重构引入回归（regression）。

## 运行前提

- 本地项目 `php artisan serve` 已在运行（推荐通过 `--base-url` 参数指定地址，默认 `http://localhost:8000`）
- 浏览器已登录（通过 `/login` 页面）
- 开发环境需要有一个可登录的本地测试用户（登录方式由用户在本地环境自行准备，不写入仓库）
- 章节 `/chapters/read/5` 存在且为英文内容
- 章节 5 已保存 AI 阅读辅助数据（含 `substantive` 在 sentence_index=0 的 AI 建议）
- 本机已有 Python + Playwright（如果本机已有 Playwright，可运行自动脚本。如果没有，不要在本轮任务中安装依赖，改用手动 smoke）

## 不变量

本轮 smoke 不应违反以下不变量，违反即报告：

1. **不创建 WordSense**
2. **不创建 ReviewCard**
3. **不创建 ReviewLog**
4. **不点击"保存新释义"按钮**
5. **不调用外部 AI API**
6. **不清库**

## P0 用例（必须通过）

| # | 用例 | 检测方式 |
|---|------|----------|
| 1 | 点击 `substantive` 后右侧查词栏出现 | DOM 查询 `#vocab-side-box` 可见 |
| 2 | Vuex 写入 `word`, `lemma`/`studyBase`, `chapterId`, `sentenceIndex` | `page.evaluate()` 读取 Vuex state |
| 3 | AI lookup URL 正确 (`/chapters/ai-assist/lookup/5?word=...`) | Playwright Network 请求拦截 |
| 4 | AI 建议在页面中显示（含 `AI 建议`、`实质性的`、`使用此释义` 文本） | DOM 文本检查 |
| 5 | 点击 AI "使用此释义"后 AddSenseForm 打开并预填 | DOM 查询 `.sense-form` 可见 |
| 6 | 点击词典加号后 AddSenseForm 打开并预填 | DOM 查询 `.sense-form` 可见 |
| 7 | 未出现 `POST /senses/manual` 请求 | Network 监听，确认无此请求 |

## P1 用例（建议通过）

| # | 用例 | 检测方式 |
|---|------|----------|
| 8 | 900px viewport 下右侧栏消失（fallback 到 popup） | 设置 viewport 900px，`#vocab-side-box` 不可见 |
| 9 | 词典结果保持三列布局（grid 样式）+ 加号存在 | DOM 检查 `.dictionary-definition-row` 的 `display: grid` |
| 10 | 工具栏不遮挡右侧查词栏 | 检查 `#toolbar-box` 的 `float: left` 和位置 |

## 运行方式

### 自动 smoke（推荐）

```powershell
python tools\smoke\text_reader_smoke_guard.py --base-url http://localhost:8000
```

如果已有保存的 auth session，可附带 `--auth` 参数（auth 文件不应提交到仓库）：

```powershell
python tools\smoke\text_reader_smoke_guard.py --base-url http://localhost:8000 --auth <path-to-auth.json>
```

要求：
- Python 3.8+
- Playwright 已安装（`pip list | findstr playwright`）
- `playwright install chromium` 已完成
- 本地 dev server 已在指定地址运行（默认 `http://localhost:8000`）
- 用户已登录（脚本会检查登录状态，如未登录则提示手动操作）

### 手动 smoke（备选）

如果 Python / Playwright 不可用，按以下步骤手动执行：

1. 打开登录页面，用本地测试用户登录
2. 导航到 `/chapters/read/5`
3. 点击 `substantive` 单词
4. 确认右侧查词栏出现
5. 确认 AI 建议区域显示 `AI 建议`、`adj`、`实质性的`、`使用此释义`
6. 点击"使用此释义"，确认添加释义表单打开，词性和中文释义已预填
7. 关闭表单
8. 展开词典结果，点击一个词典加号，确认表单预填
9. 关闭表单
10. 设置浏览器宽度为 900px，确认查词栏不再显示
11. 恢复宽度，再次点击 `substantive`，确认查词栏恢复
12. 打开浏览器 DevTools → Network，确认没有 `POST /senses/manual`
13. 截取含查词栏的完整截图，保存到 `D:\Document\lingl\text-reader-smoke-guard-screenshots\manual\`

## 失败处理

如果 smoke 失败：

1. **不要顺手修业务代码**。停止操作，记录失败上下文。
2. 记录失败值、期望值、截图、Network 请求、DOM 文本。
3. 判断是：
   - **保护网缺陷**：smoke 脚本断言不准确 → 修复 smoke 脚本
   - **回归**：业务代码行为变更 → 必须归因到哪次 commit
   - **环境不满足**：章节数据或用户数据不正确 → 补充环境
   - **contract-mismatch**：smoke 期望与当前产品契约不一致 → 记录为 contract-mismatch，不改业务代码
4. 把所有信息贴回给网页端 GPT 做判断。

## MCP / webapp-testing 视觉验收（优先）

### 适用场景

阅读页相关改动优先执行 MCP / webapp-testing 视觉验收。Python smoke guard 仍保留，用于快速自动断言。

### 验收步骤

MCP 视觉验收步骤至少包括：

1. 打开 `/chapters/read/5`
2. 点击 `substantive` 单词
3. 截图阅读页打开状态（含页面整体布局）
4. 截图右侧查词栏（确认宽度、位置、内容正常）
5. 检查 AI 建议区域（确认 AI 建议、词性、释义文本、使用此释义按钮可见）
6. 点击"使用此释义"
7. 截图 AddSenseForm 预填状态（确认词性、中文释义已预填）
8. 展开词典结果，点击词典加号
9. 截图词典加号预填状态
10. 设置 viewport 宽度 900px，截图窄屏 fallback 状态
11. 在 Network 面板确认没有 `POST /senses/manual` 请求

### 截图目录

建议保存到以下目录（不提交到 git）：

```
D:\Document\lingl\mcp-browser-smoke-screenshots
```

### 安全说明

- auth 文件只允许本地使用，不提交到 git
- 不在报告中粘贴 auth 文件内容
- 不写入账号密码
- 截图只用于当前任务验证，不扩散

## 后续使用规则

1. **每次修改以下文件前后，都必须先跑 smoke guard：**
   - `TextBlockGroup.vue`
   - `VocabularySideBox.vue`
   - `WordSensesList.vue`
   - `VocabularySearchBox.vue`
   - `AddSenseForm.vue`
   - `AiSuggestionPanel.vue`
   - `VocabularyBox.js`
2. **每次修改布局相关 file**（`TextReader.vue`、`TextReader.scss`、`VocabularySideBox.scss`），至少跑 P1 用例。
3. **任何 smoke 失败都必须归因**，不归因不可进入下一任务。
4. 如果 smoke 持续通过，可逐步放宽部分断言为 warning。
