# LinguaCafe 本地真实浏览器验收与通道降级 Playbook

> 权威决定：`docs/adr/ADR-0033-real-browser-acceptance-channel-fallback.md`、
> `docs/adr/ADR-0034-goal-mode-autonomous-decisions-and-deferred-acceptance.md`、
> `docs/adr/ADR-0037-goal-mode-nonblocking-execution-frontier.md`

## 1. 适用场景

* 阅读页（TextReader / TextBlockGroup）
* 复习卡管理页（ReviewCardManage）
* 查词弹窗（VocabularyBox / VocabularySideBox）
* review 页面（SenseReview / Review）
* 导入页面（Import / Books）
* 所有需要登录后的真实页面验收

## 2. 本地测试账号规则

* 当前任务明确提供的本地测试账号只有在确认位于专用 testing 数据库且权限不高于当前切片所需时才复用；本文档不记录具体凭据。
* 不满足上述条件或未提供账号时，只在专用 testing 数据库创建一个任务专属、最小权限身份，并让验收服务器连接同一 testing 数据库。
* identity 使用 `codex-acceptance-<unique-marker>@example.test`；同一批次默认最多一个，不默认授予管理员。
* 随机密码只保存在当前进程内存中并直接填入正常注册/登录表单，不进入仓库、shell 参数、命令输出、截图、Network 报告、浏览器密码库或最终报告。
* 不读取、重置或复用既有用户密码；不得在普通开发数据库创建临时账号后直接删号清理。

### 2.1 已预先授权的本地验收动作

在任务范围明确、目标为本地 LinguaCafe 开发环境时，执行 Agent 不需要逐项再次询问，可以：

- 启动或重启 Laravel、前端、队列、tokenizer 和任务需要的浏览器进程；
- 仅在符合 §2 的 testing 数据库与最小权限条件时使用任务提示词提供的账号；否则在 testing 数据库创建任务身份并通过正常 UI 登录；
- 在真实页面执行评分、撤回、Marker、Tag、创建临时词义、归档/恢复和其他任务明确要求且可回滚的验收操作；
- 创建可识别、任务专属的测试数据，并在验收后通过产品入口撤回/恢复，或仅清理本轮明确创建的数据；
- 查询 API、数据库和日志作为页面操作后的补充证据。

上述授权不包括清库、绕过认证、修改其他用户数据、读取 `.env`、执行不可逆任务外写入或隐藏失败。

### 2.2 真实浏览器通道降级顺序

“真实浏览器验收”按是否实际渲染页面并通过 DOM/用户事件操作判断，不按工具名称判断。建议顺序：

1. 官方 OpenAI Browser 或 Chrome 插件；
2. Chrome DevTools / MCP 浏览器；
3. 受控 Playwright，使用独立 browser context 和真实页面点击；
4. Computer Use 或本机系统浏览器自动化；
5. 当前项目已经批准、能够提供真实 DOM、Console 和 Network 证据的其他浏览器通道。

普通连接器拒绝、Cookie context、会话或基础设施错误失败时，自动尝试下一通道，不等待用户再次授权。若 system、developer 或工具输出明确禁止同一结果的 workaround、间接执行、原始命令或 alternate surface，该拒绝高于本 playbook，不得换通道规避；含义不清时按更严格类别处理。不得切换成 fetch/axios 写接口来冒充页面操作。全部平台允许的通道都实际尝试失败，或遇到验证码、双重验证、系统级授权弹窗等必须由人处理的步骤时，持续目标先按 ADR-0034/ADR-0037 记录 `Acceptance Deferred — Not Complete` 并继续独立切片；只有没有其他可运行切片或最终完成审计必须清零该节点时才请求用户。

## 3. Chrome DevTools / MCP 可靠登录步骤（已验证成功）

### 3.1 前置条件

- Laravel 验收服务器运行中。写入前必须取得绑定同一监听 host/port 和进程的
  server-bound 证据，确认实际 HTTP 进程为 `APP_ENV=testing`、连接专用
  testing 数据库并能读取 testing-only sentinel。单独 PHPUnit/CLI testing
  DB 健康检查只是前置条件，不能证明当前服务器。可使用 testing-only 本地
  健康端点，或绑定 PID/端口的启动证明加同源 sentinel 请求，且不得暴露凭据。
  普通 `php artisan serve` 未证明数据库指向时只允许只读诊断，不得执行评分、
  Tag、Marker、导入、fixture 或其他页面写入
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
- **验收批次中复用同一页面**，不要在步骤之间关闭并重建导致 Cookie 丢失；批次结束后仍须按全局 Browser Session Lifecycle 关闭本轮自动化拥有的 page/context/process。
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

## 6. 真实浏览器验收报告格式

验收报告必须说明：

```
* 最终使用的真实浏览器通道：Chrome DevTools/MCP / Playwright / Computer Use / 其他
* 首选通道是否失败：✅ / ❌；若失败，记录错误和后续降级通道
* 是否真实打开并渲染页面：✅ / ❌
* 是否通过 DOM/用户事件完成目标操作：✅ / ❌
* 是否使用任务账号：✅ / ❌（不得写密码）
* 是否新建账号：✅ / ❌
* 是否登录成功：✅ / ❌
* 是否保持同一浏览器 context、host 和 port：✅ / ❌
* 是否出现异常重定向：✅ / ❌
* 是否有 DOM、截图、Console、Network 或数据库 delta 证据：列出
* 是否只用了 API：✅ / ❌（必须为 ❌）
* 是否清理或撤回本轮测试动作：✅ / ❌ / 不适用
* 若最终无法验收：列出已尝试的全部通道和各自失败原因
```

## 7. 已验证的验收流程

| 路径 | 结果 | 说明 |
|------|------|------|
| A: `new_page(isolatedContext)` → `fill_form` → `click login` → `click nav link` | ✅ 成功 | 推荐路径 |
| B: `new_page(isolatedContext)` → login → `navigate_page(review-cards/manage)` | ✅ 成功 | 仅在 `isolatedContext` 内且同 host 时成功 |
| C: `navigate_page(target)` → 重定向 login → `fill_form` → `click login` → redirect back | ❌ 失败 | Cookie 跨 navigate 丢失 |
| D: `new_page(no isolated)` → login → `navigate_page(target)` | ❌ 失败 | 无 isolatedContext 时 Cookie 丢失 |
| E: Same host, fetch login → `navigate_page(target)` | ❌ 失败 | fetch 不共享 Cookie |
| F: Chrome/MCP 出现普通 localhost 连接/会话失败，且未禁止替代通道 → Playwright 独立 context → 真实表单登录 → 页面点击 | ✅ 可接受 | 若输出明确禁止 workaround/alternate surface，本路径禁用；成功路径仍须保留真实 DOM、Console/Network 和操作后数据证据 |
| G: Chrome/MCP 与 Playwright 普通失败且均未禁止替代通道 → Computer Use/系统浏览器真实操作 | ✅ 可接受 | 显式平台安全拒绝不得走本路径；仍需同一 host、真实登录和可观察结果 |
| H: 直接 fetch/axios 评分后读取数据库 | ❌ 不接受 | 只能补强后端证据，不能冒充按钮验收 |

## 8. Lemma / Morphology Click Sample Rotation

### 8.0 Sample tracker

每轮真实浏览器形态点击验收必须记录到 `docs/plans/morphology-test-sample-tracker.md`。该 tracker 是活文档，追加行不删除历史。tracker 包含：marker、`/chapters/read/{id}`、本轮测试词、与上一轮重复词、重复比例、8 类覆盖、是否新文章、最终浏览器通道、是否真实点击、是否 API 替代、是否 Incomplete。

### 8.1 规则

硬规则参考 `AGENTS.md` §8 和 `vibe-coding-collaboration-rules.md` §27.5。本节记录 Chrome DevTools/MCP 的可靠操作方式以及其他真实浏览器通道的降级规则。

### 8.2 每轮新文章准备

1. 创建新的短测试文章（3-5 句，无版权内容），包含 8 类形态变化各至少 2 个词，以及 3-4 个词性歧义词。
2. 文章第一行 marker 格式：`GLM Real Morphology Completion YYYYMMDD`。
3. 通过页面或 API 导入章节。
4. 记录新文章的 `/chapters/read/{id}` 路径。

### 8.3 词元测试执行步骤

1. 按 §3.2 标准登录流程登录。
2. 使用 `navigate_page` 或点击导航链接打开新测试文章。
3. 按 8 类形态逐一点击 token（每类至少 2 个词）。
4. 每点击一个词后关闭 vocab-box（按 Esc 或点击外部空白）。
5. 每轮记录：本轮测试词列表、与上一轮重复词数、使用的新文章 ID。
6. 如果连续点击失败，按以下顺序尝试：
   a. 关闭 vocab-box 后再点击同词；
   b. 刷新页面后重试；
   c. 单个词打开新页面 `/chapters/read/{id}` 再点击；
   d. 使用 `take_snapshot` 定位 token DOM 后点击；
   e. 使用截图辅助坐标定位后点击；
   f. 换词测试（该词无法定位时跳过，换同类另一词）；
   g. 换文章测试（整篇文章 token 无法正常渲染时）；
   h. **不得退回 API 验证**。

### 8.4 报告格式

MCP 形态点击验收报告必须包含：

```
* 本轮测试文章 ID：/chapters/read/{id}
* 是否新文章：✅ / ❌
* 本轮测试词列表：[词1, 词2, ...]
* 与上一轮重复词数：N
* 重复比例：N%
* 是否全部真实点击：✅ / ❌（必须为 ✅）
* 是否使用 API / axios / fetch 替代：❌（必须为 ❌）
* 是否覆盖 8/8 类：✅ / ❌
* 8 类分别列出 surface → lemma 结果
* MCP 不可用时是否报告 Incomplete：✅ / ❌
```
