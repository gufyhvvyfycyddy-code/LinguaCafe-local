# LinguaCafe QA — 浏览器验收测试交接文档（历史快照）

> **Status**: Historical QA Snapshot / Not a current task prompt.
> **Original date**: 2026-07-19.
> **Current verification**: 2026-08-06.
> **Do not execute the “待继续测试项” or reuse the historical identity without a new task, current Git/Issue preflight and real-browser revalidation.**
>
> 第一轮问题后来形成 GitHub Issues `#2`–`#11`；第二轮 `#12`–`#18` 与其中多项重复。2026-08-06 只读核查时这些相关 Issues 仍为 OPEN。本文只记录当时页面与环境，不证明问题在当前 `master` 仍存在。

---

## 一、历史环境与账号（不得作为当前环境事实）

| 项目 | 值 |
|------|-----|
| 应用地址 | http://127.0.0.1:8000/ |
| 框架 | Laravel 11.15.0 + Vue 2 + Vuetify + Vuex |
| PHP 版本 | 8.2.31 |
| 调试模式 | APP_DEBUG=true — 当时观察到错误页暴露完整 stack trace、cookie、服务器源码路径；当前状态必须重新验证 |
| 历史测试身份标识 | testqa@example.com（未保存凭据；不得复用） |
| 当时权限 | 普通用户（非 admin） |

### Chrome DevTools 历史操作须知

以下只记录当时工具用法；当前真实浏览器通道与 testing 身份规则以 `docs/plans/mcp-chrome-local-smoke-playbook.md` 为准。

当时工具前缀：chrome_devtools_fixed（MCP 形式调用）

关键流程：
1. list_pages -> 找到当前有效页
2. navigate_page(type=url, url=http://127.0.0.1:8000/...) -> 跳转
3. take_snapshot -> 获取当前 DOM
4. list_console_messages -> 检查 JS 错误

### 已知技术约束
- Page ID 每次 list_pages 重新生成，操作前需重新确认
- 导航超时默认 10-30 秒，大页面可能超时但仍加载完成
- list_pages 返回的旧 page 可能已关闭，需直接用 navigate_page 创建新页

---

## 二、BUG 发现汇总（5 个）

### BUG-001：/admin 暴露原始 JSON 而非友好页面
- URL：GET /admin
- 现象：页面仅显示 JSON 错误信息 + checkbox
- 类型：BUG（权限错误）
- 严重性：中
- 期望：重定向首页 + flash message，或显示友好的权限不足错误页面

### BUG-002：/reviews GET 暴露完整 Laravel debug 页面（含 Cookie）
- URL：GET /reviews（该路由仅支持 POST）
- 现象：MethodNotAllowedHttpException 显示完整 Laravel 错误页面
- 暴露信息：源码行、Session Cookie 明文（XSRF-TOKEN、linguacafe_session）、请求 Header 全部内容、PHP/Laravel 版本、服务器绝对路径
- 类型：BUG + **安全风险**
- 严重性：高

### BUG-003：/import GET 暴露完整 Laravel debug 页面（同上）
- URL：GET /import
- 现象：与 BUG-002 完全一致
- 类型：BUG + **安全风险**
- 严重性：高

### BUG-004：/admin/users 暴露原始 JSON
- URL：GET /admin/users
- 类型：BUG（权限错误）
- 严重性：中

### BUG-005：/settings/fsrs/daily-limits 暴露原始 JSON
- URL：GET /settings/fsrs/daily-limits
- 类型：BUG（权限响应不一致）
- 严重性：低

## 三、UX 问题发现汇总（5 个）

### UX-001：Debug 页面泄露 Session Cookie（安全）
- 问题：Laravel debug 页面 Headers 区块直接显示 cookie 原始值
- 影响：Session Hijacking 或 CSRF 攻击风险
- 类型：UX / 安全
- 严重性：高

### UX-002：404 页面无样式、无导航
- 问题：404 页面仅显示大字 404 NOT FOUND，无 CSS 样式、导航链接或返回首页按钮
- 类型：UX
- 严重性：低

### UX-003：密码修改强制无跳过选项
- 问题：管理员创建的账号首次登录后弹 alert，只有修改密码一个按钮，无跳过选项
- 类型：UX
- 严重性：中

### UX-004：日历导航按钮文本尾部多余空格
- 问题：月份导航左侧按钮末尾有空格，右侧按钮无空格，不一致
- 类型：UX（样式/文案）
- 严重性：低

### UX-005：界面中英文混排
- 问题：页面整体已汉化，但仍有部分 UI 文字为英文未翻译
- 类型：UX（本地化）
- 严重性：低

---

## 四、已验证无异常页面

| 页面 | 状态 |
|------|------|
| / | OK - 首页日历、统计 |
| /books | OK - 空白数据表 |
| /vocabulary/search | OK - 搜索页面 |
| /user-settings | OK - 个人设置 |
| /reviews/senses | OK - 词义复习空状态 |
| /review-cards/manage | OK - 复习卡管理空状态 |
| /attributions | OK - 版权与致谢 |
| /patch-notes | OK - 更新说明 |
| /api/reviews | OK - 干净 404 |
| /api/review-cards/rate | OK - 干净 404 |

## 五、历史待继续测试项（不得直接执行）

### 5.1 高优先级 - 其他 POST-only 路由（可能泄露 debug）
- GET /books, GET /chapters, GET /chapters/create
- GET /dictionaries/search, GET /settings/fsrs/optimize
- GET /vocabulary/search, GET /users/create
- 注意：仅 web 路由（Route::post() 定义）会触发 Laravel debug 页面，API 路由返回干净 404

### 5.2 中优先级 - 交互验收
- [] /books 点击创建书籍/导入 - 检查对话框
- [] 搜索框输入并搜索 - 检查行为
- [] user-settings 主题 tab - 检查 UI
- [] 首页修改密码对话框 - 检查可用性
- [] 首页显示目标下拉菜单
- [] 导航栏更多按钮下拉菜单

### 5.3 低优先级 - 用户体验
- [] 错误登录提示是否用户友好
- [] 注销后访问受保护页面是否重定向
- [] 各页面 list_console_messages 检查 JS 异常
- [] 删除学习数据确认框文案可读性
- [] 对话框是否缺少关闭按钮

---

## 六、风险与建议

1. APP_DEBUG=true 安全风险：任何 MethodNotAllowedHttpException 都会泄露完整的 session cookie。生产环境前必须关闭 APP_DEBUG。
2. 测试账号无学习数据：当前账号 0 词汇、0 卡片，复习和统计功能仅能测试空状态。
3. 浏览器连接不稳定：MCP Chrome DevTools 连接偶发超时，操作间需重试或重新 list_pages。

---

## 七、问题汇总清单

| # | 类型 | 标题 | 严重性 |
|---|------|------|--------|
| 1 | BUG | /admin 返回原始 JSON 而非错误页面 | 中 |
| 2 | BUG/SEC | /reviews GET 暴露完整 Laravel debug 页面及 Session Cookie | 高 |
| 3 | BUG/SEC | /import GET 暴露完整 Laravel debug 页面及 Session Cookie | 高 |
| 4 | BUG | /admin/users 返回原始 JSON 而非错误页面 | 中 |
| 5 | BUG | /settings/fsrs/daily-limits 返回原始 JSON | 低 |
| 6 | UX | Debug 页面泄露 Session Cookie（跨路由） | 高 |
| 7 | UX | 404 页面无样式无导航 | 低 |
| 8 | UX | 密码修改弹窗无跳过选项 | 中 |
| 9 | UX | 日历按钮文本尾部多余空格 | 低 |
| 10 | UX | 界面中英文混排 | 低 |

---

## 八、历史最小继续工作步骤（不得直接执行）

1. list_pages -> 获取可用页
2. navigate_page(url=http://127.0.0.1:8000/...) -> 跳转目标页
3. take_snapshot -> 查看页面内容
4. 必要时 list_console_messages -> 检查 JS 错误
5. 按第五节优先级依次测试
6. 发现新问题补充到汇总表
7. 完成全部测试后考虑做登录/注册流程验收
