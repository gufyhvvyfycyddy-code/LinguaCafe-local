# AI 示意卡生成闭环 V2 路线冻结

> 状态：**V2 第一阶段已实现（Generation Loop Preview V2 Done）**。待解释列表、取消/恢复、生成前预览弹窗雏形已落地。AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。
> 起点文档：`docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/plans/ai-study-card-architecture-scout.md`
> 配套文档：`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`、`docs/plans/final-architecture-closure-report.md`
> 适用阶段：V1 pending marker 完成后的第二个最小实现切片。
> 本文档记录 V2 落地边界；后续 AI 推荐词 / AI 释义生成 / 复习卡生成闭环仍需通过 Architecture Gate 与 ADR 评审。

---

## 1. V2 目标

### 1.1 一句话目标

在 V1「待 AI 解释」pending marker 基础上，让用户能看到自己的待解释列表、能取消待解释项、能看到「生成 AI 示意卡」入口并打开一个安全的预览弹窗雏形；本轮仍不调用真实 AI，不生成 WordSense/ReviewCard/ReviewLog，不改 FSRS。

### 1.2 为什么是这个目标

- V1 只记录 pending item，用户无法看到列表，无法取消，体验不完整。
- V2 补齐「列表 + 取消 + 预览入口」三件事，让用户能完整管理自己的待解释项。
- 预览弹窗雏形为未来 AI 推荐词和生成闭环做 UI 占位，但本轮明确不调用 AI。
- 它仍然不触碰任何受保护模块（FSRS、ReviewCard、WordSense、TextBlock 主流程、删除/归档/恢复）。

### 1.3 V2 不做什么

- 不做 AI 推荐。
- 不做 AI 生成释义。
- 不做真实 AI 示意卡生成。
- 不做与复习卡的联动。
- 不做与 SenseReview 的联动。
- 不做词组拖选。
- 不改 FSRS。
- 不改删除/归档/恢复。
- 不删除 legacy word card 兼容层。

### 1.4 实现状态（2026-07-02）

- 已新增 `GET /ai-study-card/pending-items`（支持 `chapter_id` 过滤，只返回当前用户/当前语言/ pending 状态）。
- 已新增 `POST /ai-study-card/pending-items/{id}/dismiss`（状态 pending → dismissed，不物理删除，幂等）。
- 已新增 `POST /ai-study-card/pending-items/{id}/restore`（状态 dismissed → pending，检查 unique 冲突）。
- 已改造 `createOrGetPending()`：若同一 key 存在 dismissed 项，恢复为 pending 而非新建，避免重复行。
- 已在 `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 新增「待 AI 解释列表」按钮、列表面板（含取消按钮）、「生成 AI 示意卡」按钮、预览弹窗雏形。
- 已新增 16 个 V2 feature tests 覆盖列表鉴权/用户隔离/语言隔离/章节过滤/dismiss/restore/幂等/反向 contract。
- 已通过 MCP Chrome 真实页面验收 24 项。

---

## 2. V2 用户流程

### 2.1 待解释列表流程

1. 用户在阅读页点词 → 侧栏出现「待 AI 解释」和「待 AI 解释列表」两个按钮。
2. 用户点击「待 AI 解释」标记单词 → 提示「已加入待 AI 解释列表中」或「已重新加入待 AI 解释」（若 previously dismissed）。
3. 用户点击「待 AI 解释列表」→ 弹出列表面板。
4. 列表面板显示：单词、来源句子、状态（待解释）、添加时间、取消按钮。
5. 列表默认按当前章节过滤（`chapter_id` 参数）。

### 2.2 取消/恢复流程

1. 用户在列表中点击某项的「取消」按钮。
2. 该项状态从 pending 改为 dismissed，从列表中消失。
3. 提示「已取消」。
4. 用户重新点击同一单词并标记「待 AI 解释」→ 系统检测到同 key 存在 dismissed 项，恢复为 pending，提示「已重新加入待 AI 解释」。
5. 恢复而非新建，避免重复行，符合幂等设计。

### 2.3 生成前预览流程

1. 用户在列表面板底部看到「生成 AI 示意卡」按钮。
2. 点击后打开预览弹窗「生成 AI 示意卡预览」。
3. 弹窗显示：
   - 安全说明：「当前只是预览，不会调用 AI，也不会生成复习卡。」
   - 用户已选词区域（chips）：显示当前 pending items，自动进入生成范围。
   - AI 推荐词区域：占位说明「下一阶段开放。本轮不会请求 AI 推荐。」
   - 规则预览：AI 推荐词默认不选；不与用户已选词重复；需手动确认。
   - 「确认生成（下一阶段开放）」按钮：disabled。
   - 「关闭」按钮：enabled。
4. 弹窗纯前端展示，不触发任何网络请求，不调用 AI。

---

## 3. V2 数据边界

### 3.1 状态机

- `pending`：用户已标记待 AI 解释。
- `dismissed`：用户已取消，不显示在列表中，但可恢复。
- V2 新增 `dismissed` 状态（V1 只有 `pending`）。

### 3.2 不记录什么

- 不记录 AI 输出（V2 不调 AI）。
- 不记录 FSRS 字段。
- 不记录 ReviewLog。
- 不记录 review_card_id / word_sense_id（仍为空）。

### 3.3 Unique 约束与恢复逻辑

- V1 migration 的 unique 约束含 `status` 字段：`['user_id', 'language_id', 'chapter_id', 'text_block_index', 'normalized_word', 'status']`。
- 这意味着同一 key 可同时存在一行 pending 和一行 dismissed。
- V2 选择「恢复已 dismissed 行」而非「新建 pending 行」，避免重复行。
- 若用户 dismissed 后重新标记，Service 先查 dismissed 行，找到则改 status 为 pending；找不到则新建。

---

## 4. V2 前端边界

### 4.1 入口位置

- 「待 AI 解释列表」按钮：在 `VocabularySideBox.vue` / `VocabularyBox.vue` 中，与「待 AI 解释」按钮并列。
- 列表面板：`v-dialog`，max-width 640px。
- 预览弹窗：`v-dialog`，max-width 720px。

### 4.2 列表面板内容

- 标题：「待 AI 解释的词」。
- 每项显示：单词、来源句子、状态、添加时间、取消按钮。
- 底部：「生成 AI 示意卡」按钮。

### 4.3 预览弹窗内容

- 标题：「生成 AI 示意卡预览」。
- 安全说明 alert：「当前只是预览，不会调用 AI，也不会生成复习卡。」
- 用户已选词区域：chips 显示 pending items。
- AI 推荐词区域：占位文本。
- 规则预览列表。
- 「确认生成（下一阶段开放）」按钮：disabled。
- 「关闭」按钮：enabled。

### 4.4 受保护文件

- V2 **不修改** `TextBlockGroup.vue` 主流程。
- V2 **不修改** `TextReader.vue` 主流程。
- V2 **只**在 `VocabularySideBox.vue` / `VocabularyBox.vue` 增加列表/预览组件。

---

## 5. V2 后端边界

### 5.1 已落地接口

- `GET /ai-study-card/pending-items`：列出当前用户的 pending items。
  - 可选参数：`chapter_id`（按章节过滤）。
  - 只返回当前用户、当前语言、pending 状态。
  - 返回字段：id/status/word/lemma/surface/chapter_id/text_block_index/sentence_index/sentence_text/created_at/updated_at。
- `POST /ai-study-card/pending-items`：创建或恢复 pending item（V1 已有，V2 改造恢复逻辑）。
- `POST /ai-study-card/pending-items/{id}/dismiss`：取消 pending item。
- `POST /ai-study-card/pending-items/{id}/restore`：恢复 dismissed item。

### 5.2 不做什么

- 不调用任何 AI 服务。
- 不生成 `WordSense`。
- 不生成 `ReviewCard`。
- 不写入 `ReviewLog`。
- 不写入 `EncounteredWord`。
- 不写入 `WordSenseOccurrence`。
- 不触发 FSRS 任何方法。
- 不物理删除 pending item（dismiss 只改状态）。

### 5.3 必须保证

- 用户隔离：A 用户无法看到/取消/恢复 B 用户的待解释项。
- 语言隔离：当前用户语言为英文时，只能操作英文待解释项。
- 章节归属：列表按 chapter_id 过滤时检查章节归属。
- 幂等：dismiss 已 dismissed 的项不报错；restore 已 pending 的项检查 unique 冲突。

---

## 6. V2 禁止范围

1. 不实现 AI 推荐弹窗（真实推荐）。
2. 不实现 AI 生成释义。
3. 不调用任何 AI API。
4. 不生成复习卡（`ReviewCard`）。
5. 不改 FSRS 算法语义。
6. 不改 `ReviewLog` 保留语义。
7. 不改删除/归档/恢复语义。
8. 不删除 SenseReview / SenseMappingReview。
9. 不大改 `TextBlockGroup.vue`。
10. 不改 legacy word card 兼容层。
11. 不新增 migration（复用 V1 表）。
12. 不把待解释项混入 `WordSenseOccurrence` 或 `EncounteredWord`。
13. 不引入新前端依赖。
14. 不引入新 PHP composer 依赖。
15. 不物理删除 pending item。

---

## 7. V2 验收

### 7.1 后端测试（已通过）

- 登录用户可以列出自己的 pending items。
- 未登录用户不能列出。
- A 用户看不到 B 用户数据。
- 语言隔离。
- 只返回 pending，不返回 dismissed。
- 可以取消 pending item。
- A 用户不能取消 B 用户 item。
- 取消后不创建 WordSense/ReviewCard/ReviewLog。
- 取消幂等。
- dismissed 后重新标记（store 路径）恢复为 pending。
- dismissed 后 restore 路径恢复为 pending。
- restore 不创建学习数据。
- A 用户不能 restore B 用户 item。
- dismiss/restore 不影响既有 sense/card 状态。
- 23 tests / 105 assertions 全绿。

### 7.2 MCP Chrome 真实页面验收（已通过 24 项）

- 登录测试账号成功。
- 打开阅读页 `/chapters/read/5`。
- 点击单词 "landscape"。
- 点击「待 AI 解释」→ 提示「已加入待 AI 解释列表中」。
- 打开待解释列表 → 看到 landscape 和 conceptualize。
- 点击取消 → landscape 消失，提示「已取消」。
- 重新点击同一词并标记 → 提示「已重新加入待 AI 解释」。
- 列表中 landscape 重新出现。
- 点击「生成 AI 示意卡」→ 预览弹窗打开。
- 弹窗显示用户已选词 chips（landscape, conceptualize）。
- AI 推荐词区域为占位文本。
- AI 推荐词默认不选。
- 安全说明显示。
- 「确认生成」按钮 disabled。
- 无外部 AI 网络请求（只有本地 API）。
- WordSense=19（未变）。
- ReviewCard=16（未变）。
- ReviewLog=2（未变）。
- 主复习入口 `/reviews/senses` 正常。
- 旧入口 `/review-cards/manage` 正常。
- Console 无新增错误（只有预期 WebSocket 降级）。
- Network 正常。
- 未用 API 替代页面点击。

### 7.3 前端构建

- `npm run development` 构建成功。

---

## 8. V2 完成后进度

### 8.1 固定五条主线

| 主线 | V1 后 | V2 后 | 说明 |
|------|-------|-------|------|
| 总体架构收口 | 100% | 100% | 保持 |
| 复习主线稳定 | 94% | 96% | dismiss/restore 反向 contract 进一步确认不触碰 FSRS/ReviewCard/ReviewLog |
| 页面真实验收 | 96% | 100% | V2 MCP Chrome 24 项全通过 |
| AI 示意卡规划 | 90% | 100% | V2 列表/取消/预览雏形已落地，路线完全清晰 |
| 前端入口整理 | 92% | 98% | 列表/预览入口与复习入口统一协调 |

### 8.2 新增子阶段

| 子阶段 | V2 后 | 说明 |
|--------|-------|------|
| AI 示意卡生成闭环 | 70% | 列表/取消/预览雏形已完成；AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现 |

### 8.3 明确声明

- **70% 是「AI 示意卡生成闭环」子阶段的进度，不是固定五条主线的虚假上调。**
- 固定五条主线已接近满格，本轮上调基于真实完成的页面验收和 contract 覆盖。
- AI 推荐词还未实现。
- AI 释义生成还未实现。
- WordSense / ReviewCard 生成闭环还未实现。
- 真正 AI 调用还未实现。
- 不允许把 V2 写成 AI 示意卡完整闭环完成。
- 不允许把复习系统写成 100% 全无风险。
- 后续实现必须继续保持：用户确认优先、AI 推荐默认不选、不自动污染 WordSense/ReviewCard/FSRS。

---

## 9. V2 之后的下一步候选

### 9.1 AI 推荐词架构设计

- 下一阶段应先设计 AI 推荐词的架构：推荐来源、排重逻辑、默认不选、用户确认。
- 不直接实现真实 AI 调用。
- 必须先过 Architecture Gate 与 ADR。

### 9.2 AI 释义生成

- 在用户确认推荐词后，调用 AI 生成释义。
- 必须先解决 API key 安全存储问题。
- 必须先过 Architecture Gate 与 ADR。

### 9.3 WordSense / ReviewCard 生成闭环

- 在 AI 释义生成后，用户确认后生成 WordSense + ReviewCard。
- 必须先过 Architecture Gate 与 ADR。
- 必须保持 FSRS 语义不变。
