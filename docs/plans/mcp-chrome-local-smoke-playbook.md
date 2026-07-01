# MCP Chrome 本地验收 Playbook

## 1. 适用场景

* 阅读页（TextReader / TextBlockGroup）
* 复习卡管理页（ReviewCardManage）
* 查词弹窗（VocabularyBox / VocabularySideBox）
* review 页面（SenseReview / Review）
* 导入页面（Import / Books）
* 所有需要登录后的真实页面验收

## 2. 本地测试账号规则

* 网页端 GPT 当前任务提示词会提供本地测试账号和密码。
* 本文档不写具体密码。
* OpenCode 必须使用任务提示词提供的账号密码。
* 如果账号不存在或登录失败，可以创建同名本地管理员测试账号。
* 禁止把具体账号密码写入 GitHub 文档、代码、测试、日志或最终报告。

## 3. 可靠登录步骤（已验证成功）

### 3.1 前置条件

- Laravel 开发服务器运行中（`php artisan serve`）
- 本地 tokenizer 运行中（用于需要分词的页面验收）
- 使用 `isolatedContext` 参数创建浏览器上下文

```javascript
// 创建隔离上下文（只需一次）
chrome-devtools_new_page(url="http://localhost:8000/login", isolatedContext="linguaCafeUser")
```

### 3.2 标准登录流程

1. **使用 `chrome-devtools_new_page` 打开登录页**，指定 `isolatedContext` 参数。这确保 Cookie 在同一上下文中持久化。
2. **截图确认页面**（`take_snapshot`）获取当前 UID。
3. **填写登录表单**（`fill_form`）使用任务提示词提供的邮箱和密码。
4. **点击登录按钮**（`click`）。
5. **等待页面跳转**，验证 URL 不再是 `/login`。
6. **截图确认登录成功**，导航栏应显示"学习语言：英语"及各个功能入口链接。

### 3.3 在同一上下文中导航到目标页面

**方式 A（推荐）：点击页面内链接**

```javascript
// 点击导航栏中的链接
// 例如：uid=74_13 是"复习卡管理"链接
chrome-devtools_click(uid="<nav-link-uid>")
```

**方式 B：在 isolatedContext 内使用 `navigate_page`**

使用 `navigate_page` 时，必须保证在同一 `isolatedContext` 内。经过验证，在 `isolatedContext` 中 `navigate_page` 可以保留会话 Cookie。

```javascript
// 在同一 isolatedContext 中导航
chrome-devtools_navigate_page(url="http://localhost:8000/review-cards/manage")
```

### 3.4 登录后保持同 context 的关键

- **必须使用 `isolatedContext`**。没有 `isolatedContext` 参数的 `navigate_page` 会创建新的浏览器上下文，丢失之前的所有 Cookie。
- **不要混用 localhost 和 127.0.0.1**。统一使用 `http://localhost:8000` 或 `http://127.0.0.1:8000`。
- **不要关闭页面**。一旦关闭页面（`close_page`），Cookie 会丢失。
- **先登录，后点击链接**。不要先 navigate 到 `/login`，先 navigate 到管理页重定向到登录页，然后再登录。

## 4. Cookie / Session 注意事项

* **同 host 同端口**：全程使用同一个 host 和端口。`localhost:8000` 和 `127.0.0.1:8000` 的 Cookie 不通用。
* **同 browser context**：使用 `isolatedContext` 参数创建页面。没有 `isolatedContext` 或不同 `isolatedContext` 的页面不共享 Cookie。
* **不要用 fetch 登录冒充页面登录**：fetch/XHR 登录虽然可以获取 session cookie，但不会在页面上下文中持久化。必须使用真实表单提交。
* **登录成功后必须**在**同一页面上下文**打开目标页。
* **不要跨 pageId**：page 2 和 page 3 是不同的页面，Cookie 不共享，即使在同一 `isolatedContext`。

## 5. 失败时的诊断清单

当 `navigate_page` 重定向回登录页时，按以下顺序检查：

| 检查项 | 方法 |
|--------|------|
| Set-Cookie | 登录 POST 的 response headers 是否有 `laravel_session`？ |
| Session cookie | 浏览器 cookie 列表中是否有 `laravel_session`？ |
| CSRF | 登录 POST 是否携带了正确的 `_token`？ |
| Host/Port | 是否混用了 `localhost` 和 `127.0.0.1`？ |
| selected_language | 用户是否有 `selected_language` 设置？（否则会重定向） |
| Redirect chain | 登录后是否 302 到 `/` 或其他页面？ |
| Login response | 登录 POST 返回 200 还是 302？ |
| Console error | 浏览器控制台是否有 Cookie/SameSite 相关错误？ |
| Network error | 网络面板是否有跨域/重定向错误？ |
| isolatedContext | 是否使用了 `isolatedContext`？所有 navigate 是否在同一 context？ |
| Page context | 登录成功后是否关闭了页面？ |

## 6. MCP Chrome 验收报告格式

MCP Chrome 验收报告必须说明：

```
* 是否真实打开页面：✅ / ❌
* 是否使用账号：✅ 邮箱
* 是否新建账号：✅ / ❌
* 是否登录成功：✅ / ❌
* 是否同 context：✅ / ❌
* 是否出现重定向：✅ / ❌
* 是否有截图或 DOM 证据：✅ / ❌
* 是否只用了 API：✅ / ❌（必须为 ❌）
* 若只用了 API：必须标记 Incomplete
* 若无法验收：必须写原因
```

## 7. 已验证的验收流程

| 路径 | 结果 | 说明 |
|------|------|------|
| A: `new_page(isolatedContext)` → `fill_form` → `click login` → `click nav link` | ✅ 成功 | 推荐路径 |
| B: `new_page(isolatedContext)` → login → `navigate_page(review-cards/manage)` | ✅ 成功 | 仅在 `isolatedContext` 内且同 host 时成功 |
| C: `navigate_page(target)` → 重定向 login → `fill_form` → `click login` → redirect back | ❌ 失败 | Cookie 跨 navigate 丢失 |
| D: `new_page(no isolated)` → login → `navigate_page(target)` | ❌ 失败 | 无 isolatedContext 时 Cookie 丢失 |
| E: Same host, fetch login → `navigate_page(target)` | ❌ 失败 | fetch 不共享 Cookie |
