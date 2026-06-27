# UI-Review-e 真实浏览器 Smoke 与体验验收报告

> **验收日期**：2026-06-26
> **验收 commit 基线**：`1f64e3a`
> **关联**：[ui-anki-review-scout.md](./ui-anki-review-scout.md)、[linguacafe-fsrs-roadmap.md](./linguacafe-fsrs-roadmap.md)

---

## 1. 验收范围

| 项目 | 内容 |
|------|------|
| 验收页面 | `/reviews/senses` (SenseReview.vue) |
| 验收 commit | `1f64e3a` feat: add sense review keyboard shortcuts |
| 验收功能 | UI-Review-a/b/c 全部 — 信息层级整理 / 显示答案流程 / 键盘快捷键 |
| 验收方式 | Playwright 自动化尝试 + 代码审查 + npm build 验证 |

---

## 2. 真实浏览器结果

### 已真实验证

| 步骤 | 结果 |
|------|------|
| 登录页加载 | ✅ 页面加载成功，Vue Devtools 正常 |
| 页面路由验证 | ✅ `/reviews/senses` 需要 auth 中间件保护，未登录时正确重定向到 `/login` |
| npm run development | ✅ Compiled successfully (5.18s) |
| 代码审查 | ✅ 全文通过 |

### 未完成

| 步骤 | 原因 |
|------|------|
| 真实浏览器登录并进入 SenseReview | Vuetify SPA `v-model` reactivity — Playwright 无法通过标准 DOM 事件触发 Vue 的 input 绑定更新 |
| 显示答案按钮点击验证 | 同上（需要先登录） |
| Space 键功能验证 | 同上 |
| 1/2/3/4 评分验证 | 同上 |
| More 菜单展开验证 | 同上 |
| FSRS 折叠展开验证 | 同上 |
| Console/Network 验证 | 同上 |

### 阻塞原因

Playwright 自动化登录失败（Vuetify SPA 的 `v-model` 绑定在 `v-text-field` 中不响应标准的 DOM `input` 事件和 `value` 设置器）。SenseReview 页面需要 `auth` 中间件认证才能访问。

此问题在 D.4-d-e 和 UI-Review-a 多次报告中已记录，属于已知限制。

---

## 3. 代码审查确认

| 审查项 | 结果 | 说明 |
|--------|------|------|
| showAnswer | ✅ | 新增 `showAnswer: false` data 字段，loadCards() 重置为 false |
| keyup listener | ✅ | `window.addEventListener('keyup', this.handleHotkey)` 在 mounted() 中注册 |
| beforeDestroy | ✅ | `window.removeEventListener('keyup', this.handleHotkey)` 正确移除 |
| Space 逻辑 | ✅ | `!showAnswer` 时 → `showAnswer = true`；`showAnswer` 时不操作 |
| 1/2/3/4 逻辑 | ✅ | 仅 `showAnswer === true` 时调用 `rate('again')` 等 |
| input/dialog 保护 | ✅ | tagName 检查 + dialog 变量检查 |
| rate API 未改 | ✅ | URL、payload、rating 字符串均未改动 |
| More 菜单 | ✅ | 保留在答案面，包含 5 个操作 |
| FSRS 折叠 | ✅ | 保留在答案面，默认折叠，loadCards() 重置 |
| 快捷键提示文案 | ✅ | 问题面 "Space 显示答案" + 答案面 "1/2/3/4" |
| npm build | ✅ | 5.18s — 无 error |

---

## 4. Network / Console 结果

| 检查项 | 结果 |
|--------|------|
| 显示答案是否不发请求 | ✅ 代码审查确认（仅设 `showAnswer = true`） |
| 评分是否发 rate 请求 | ✅ 代码审查确认（`POST /reviews/senses/{id}/rate`） |
| 是否有 Console 错误 | ⚠️ 未真实验证（需要登录后） |
| 代码中无新增 console.log | ✅ grep 确认 |

---

## 5. 人工体验判断

由于无法完成真实浏览器登录，以下为基于代码审查的推断判断：

| 问题 | 判断 |
|------|------|
| 问题面是否更清爽？ | ✅ 相比 UI-Review-a 前，问题面只有 lemma/例句/提示，无答案/评分/管理按钮干扰 |
| 快捷键是否建议保留？ | ✅ Space + 1/2/3/4 设计合理 |
| Space 是否建议自动评分？ | ❌ 不推荐 — 当前保守实现（只显示答案不评分）更安全，避免误触 |
| 近义译法/搭配是否建议折叠？ | ⚠️ 当前已在答案面展开，后续 UI-Review-b 或单独任务可处理 |
| 是否建议进入 UI-Review-d？ | ⚠️ 建议先通过人工真实刷 5-10 张卡后再决定。间隔预估属于增量体验改善，不是阻塞问题 |

---

## 6. 结论

**代码审查通过，但真实浏览器验收 Incomplete。**

| 维度 | 状态 |
|------|------|
| 代码正确性 | ✅ PASS — 全部 3 个 Phase 实现正确 |
| npm build | ✅ PASS — Compiled in 5.18s |
| 真实浏览器验证 | ❌ INCOMPLETE — Vuetify SPA 登录阻塞 |
| 总体 | ⚠️ 代码审查通过，浏览器验证未完成 |

### 已知限制（与 D.4-d-e 相同）

- Playwright 无法通过 Vuetify SPA 登录。
- 此问题非本次引入，属于项目已有的自动化验收能力缺口。
- 建议后续通过人工登录做一次性完整点击检查，或单独安排 Playwright 登录能力修复（P5 优先级）。

---

## 7. 下一步建议

1. **人工补验**：建议用户或开发者在本地手动登录后，打开 `/reviews/senses` 刷 5-10 张卡，确认：
   - 问题面/答案面切换流畅
   - Space + 1/2/3/4 键盘体验
   - More 菜单操作
   - FSRS 折叠展开
2. **不急于进入 UI-Review-d**：评分按钮间隔预估属于增量改善，建议先观察使用体验。
3. **不进入 D.5**：下一功能阶段应在当前体验优化稳定后再决定。
