# Text Reader Smoke Guard

## 目的

保护阅读页 / 查词栏核心行为，防止后续 `TextBlockGroup.vue` / `VocabularySideBox.vue` / `WordSensesList.vue` 等组件重构引入回归（regression）。

## 运行前提

- 本地项目 `php artisan serve` 已在 `http://127.0.0.1:8000` 运行
- 浏览器已登录（通过 `/login` 页面）
- 测试用户存在，邮箱：`1816529781@qq.com`（开发数据库已有）
- 章节 `/chapters/read/5` 存在且为英文内容
- 章节 5 已保存 AI 阅读辅助数据（含 `substantive` 在 sentence_index=0 的 AI 建议）
- 设备上已安装 Python + Playwright（`pip install playwright` + `playwright install chromium`）

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
python tools\smoke\text_reader_smoke_guard.py
```

要求：
- Python 3.8+
- Playwright 已安装（`pip list | findstr playwright`）
- `playwright install chromium` 已完成
- 本地 dev server 已在 `http://127.0.0.1:8000` 运行
- 用户已登录（脚本会检查登录状态，如未登录则提示手动操作）

### 手动 smoke（备选）

如果 Python / Playwright 不可用，按以下步骤手动执行：

1. 打开 `http://127.0.0.1:8000/login`，用测试邮箱登录
2. 导航到 `http://127.0.0.1:8000/chapters/read/5`
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
