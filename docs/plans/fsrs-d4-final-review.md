# FSRS D.4 最终 Review

> **Review 日期**：2026-06-26
> **最终 commit**：`517de2f`
> **关联**：[linguacafe-fsrs-roadmap.md](./linguacafe-fsrs-roadmap.md)、[fsrs-reschedule-undo-scout.md](./fsrs-reschedule-undo-scout.md)、[fsrs-reschedule-real-smoke-report.md](./fsrs-reschedule-real-smoke-report.md)

---

## 1. Review 范围

涵盖 FSRS D.4 阶段全部子任务：

| 子任务 | 状态 | 说明 |
|--------|------|------|
| D.4-a (preview 后端) | ✅ 已完成 | 只读 preview + preview_hash |
| D.4-b (preview 前端) | ✅ 已完成 | AdminReviewSettings.vue 预览按钮 |
| D.4-c 系列 (confirm/apply) | ✅ 已完成 | 双弹窗 + 倒计时 + 风险确认 |
| D.4-c-e (smoke) | ✅ 已完成 | 真实数据联调验收 |
| D.4-d-scout | ✅ 已完成 | 撤销方案侦察 |
| D.4-d-a (snapshot schema) | ✅ 已完成 | 快照表 + 写入 |
| D.4-d-b (undo API) | ✅ 已完成 | 撤销 API + 测试 |
| D.4-d-b-fix (undo 边界) | ✅ 已完成 | 事务内二次校验 + 过期提示 |
| D.4-d-c (undo UI) | ✅ 已完成 | 撤销按钮 + 弹窗 |
| D.4-d-c-fix (UI 修复) | ✅ 已完成 | 成功提示可见 + 文案修复 |
| D.4-d-e (smoke) | ✅ 已完成 | 全链路 smoke + 文案收口 |

---

## 2. 已完成目标

| # | 目标 | 状态 | 实现方式 |
|---|------|------|----------|
| 1 | 参数保存不会自动重排旧卡 | ✅ | `confirmAndApply` 必须用户主动调用 |
| 2 | 用户必须主动点击预览 | ✅ | `预览` 按钮触发 preview API |
| 3 | 预览只读，不写 ReviewCard | ✅ | preview 方法只读，0 次 save |
| 4 | 预览返回完整统计 | ✅ | candidates/changed/skipped/samples/hash |
| 5 | 确认 preflight 不写卡 | ✅ | `confirmPreflight` 只校验 hash 和风险 |
| 6 | apply=true 才正式写卡 | ✅ | `confirmAndApply` 在 apply=true 时执行 |
| 7 | 高风险需要 risk_confirm | ✅ | `risk_confirm` 严格校验 |
| 8 | total_changed 超阈值 blocked | ✅ | `getMaxTotalChanged()` 硬阻拦 |
| 9 | 正式 apply 写快照 | ✅ | `createSnapshotForAppliedCards()` 在事务内 |
| 10 | 快照与 ReviewLog 解耦 | ✅ | 独立表，零 ReviewLog 写入 |
| 11 | 撤销只恢复最近一次 | ✅ | `ORDER BY created_at DESC LIMIT 1` |
| 12 | 撤销只恢复 3 个字段 | ✅ | due/stability/difficulty |
| 13 | 已复习卡跳过 | ✅ | `last_reviewed_at > snapshot.created_at` |
| 14 | 过期 snapshot 不可撤销 | ✅ | `expires_at < now()` |
| 15 | 7 天内可撤销 | ✅ | `expires_at = now()->addDays(7)` |
| 16 | 撤销不写 ReviewLog | ✅ | 无任何 ReviewLog::forceCreate |
| 17 | optimizer 不读 snapshot 表 | ✅ | snapshot 表与 optimizer 完全隔离 |
| 18 | 前端撤销按钮 + 弹窗 | ✅ | AdminReviewSettings.vue |
| 19 | 前端 success/error 消息 | ✅ | v-alert type="success/error" |
| 20 | 网状协作报告门禁 | ✅ | 已写入 undo-scout.md |

---

## 3. 数据安全结论

| 安全项 | 结论 | 证据 |
|--------|------|------|
| 未清库 | ✅ | 未使用 `migrate:fresh` 或 `db:wipe` |
| 未写 ReviewLog | ✅ | 代码零 ReviewLog::create/forceCreate 在 D.4 新增路径中 |
| 未污染 optimizer | ✅ | optimizer 完全不读 snapshot/write 表 |
| snapshot 与 ReviewLog 解耦 | ✅ | 独立两张表，无外键到 review_logs |
| 撤销只恢复 due/stability/difficulty | ✅ | 源码确认：不写 reps/lapses/last_reviewed |
| 已复习卡跳过 | ✅ | restore set 构建前检查 `last_reviewed_at` |
| 只当前用户/语言撤销 | ✅ | `user_id` + `language_id` 过滤 |
| DCP 未执行 | ✅ | `DCP_ALLOWED=false` 全程遵守 |
| --force 未使用 | ✅ | 全程禁止 |
| 不读取 .env | ✅ | 未引入新的环境变量读取 |
| 不修改 AGENTS.md | ✅ | 未触碰 |

---

## 4. 测试覆盖总结

| 测试套件 | 用例数 | 断言数 | 覆盖内容 |
|----------|--------|--------|----------|
| FsrsReschedulePreviewTest | 29 | 109 | 预览候选、过滤条件、preview_hash、409 |
| FsrsRescheduleConfirmTest | 20 | 74 | preflight、apply、阈值、风险确认 |
| FsrsRescheduleSnapshotTest | 9 | 53 | 快照创建、字段正确、不写 ReviewLog |
| FsrsRescheduleUndoTest | 23 | 110 | 撤销全路径、边界、过期、已复习跳过 |
| FsrsRescheduleRealSmokeTest | 8 | 52 | 全链路集成、不写 ReviewLog、ineligible 不变 |
| ReviewFsrsTest | 60 | 334 | FSRS 复习核心逻辑不受影响 |
| FsrsSchedulingServiceTest | 9 | 46 | 调度算法不受影响 |
| **合计** | **158** | **778** | — |
| db:doctor | ✅ | — | 数据库健康 |
| tokenizer:doctor | ✅ | — | tokenizer 健康 |
| npm run development | ✅ | — | 前端构建成功 |

---

## 5. 已知限制

1. **真实浏览器登录后点击链路未完整验证**：
   - D.4-d-e 尝试了 Playwright 自动化但卡在 Vuetify SPA 登录（Vue `v-model` reactivity 无法通过标准 DOM 事件触发）。
   - 当前使用代码审查 + Feature tests（158 tests, 778 assertions）+ API 行为 + 构建结果补充验证。
   - 这不是数据安全阻塞，但属于 UI 验收置信度限制。
   - 建议后续通过人工登录做一次完整的端到端点击检查，或单独安排 Playwright 登录能力修复。

2. **高风险路径未真实触发**：
   - `newly_due_today > 200` 阈值在本地测试数据无法达到。
   - 已通过 `FsrsRescheduleConfirmTest` 中的阈值注入测试覆盖。

3. **撤销成功提示需人工验证**：
   - 成功提示已通过代码审查确认不在 `v-if` 包裹内，但真实浏览器验证仍建议做。

---

## 6. 可以收口

**FSRS D.4 可以阶段性收口。**

达成阶段性目标：

- ✅ 用户可手动预览重排影响
- ✅ 用户可二次确认后正式重排
- ✅ 正式重排写入快照（支持撤销）
- ✅ 用户可撤销最近一次重排（7 天内）
- ✅ 撤销不污染 ReviewLog / optimizer
- ✅ 前端有完整交互：按钮、弹窗、倒计时、成功/错误提示

**已知限制（非阻塞）**：
- 真实浏览器登录后点击链路仍建议人工补验（详见上文第 5 节）。

---

## 7. 不进入下一阶段

本任务只做 FSRS D.4 最终 Review。

- ❌ 不进入 D.5
- ❌ 不进入新功能开发
- ❌ 不修改任何功能代码
- ⏸ 下一阶段必须由网页端 GPT 根据 GitHub 最新代码和用户产品方向发新任务
