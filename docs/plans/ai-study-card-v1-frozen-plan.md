# AI 示意卡第一版可开发路线冻结

> 状态：**路线冻结（Frozen Plan）**，未实现功能。
> 起点文档：`docs/plans/ai-study-card-architecture-scout.md`
> 配套文档：`docs/plans/final-architecture-closure-report.md`、`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`
> 适用阶段：架构收口 100% 之后的第一个最小实现任务。
> 本文档不授权立即写代码；写代码前仍需通过 Architecture Gate 与 ADR 评审。

---

## 1. 第一版目标

### 1.1 一句话目标

用户在阅读页点词后，可以把该词标记为「待 AI 解释」；系统只记录这个待解释项，不调用真正的 AI，不生成复习卡，不改 FSRS，不改 ReviewCard，不改删除 / 归档 / 恢复，不动 legacy word card 兼容层。

### 1.2 为什么是这个目标

- 这是 AI 示意卡整体路线里**风险最低、可独立交付**的一步。
- 它把「用户意图」和「AI 生成」彻底解耦：先有可追踪的待解释项，未来再接入 AI。
- 它不触碰任何受保护模块（FSRS、ReviewCard、WordSense、TextBlock 主流程、删除 / 归档 / 恢复）。
- 它给出一个可以独立验收的 UI 入口，让设计者能在真实页面看到「待 AI 解释」的反馈。
- 它把侦查阶段（`ai-study-card-architecture-scout.md`）的结论落到一个具体可开发的最小切片。

### 1.3 第一版不做什么

- 不做 AI 推荐。
- 不做 AI 生成释义。
- 不做 AI 示意卡弹窗本身。
- 不做词组拖选（理由见 §4.2）。
- 不做与复习卡的联动。
- 不做与 SenseReview 的联动。

---

## 2. 第一版用户流程

以用户视角描述：

1. 用户在阅读页正常阅读文章。
2. 用户点击文章中某个单词（与现有查词入口相同）。
3. 侧栏（`VocabularySideBox.vue`）出现，显示现有词典查询结果。
4. 用户在侧栏看到一个新按钮「待 AI 解释」。
5. 用户点击该按钮。
6. 页面给出轻量反馈：例如按钮变灰 + 提示「已加入待解释」。
7. 用户可以继续阅读，可以继续查别的词，可以再次标记。
8. 这些待解释项**只**记录在后台；第一版不在阅读页主动弹出列表，也不进入复习。
9. 未来（非第一版）这些待解释项会进入「AI 示意卡弹窗」由用户主动触发 AI 解释。

### 2.1 流程边界

- 第一版**不要求**用户能看到「待解释列表」页面。
- 第一版**不要求**用户能撤销已标记的待解释项（但后端要保留可扩展的删除接口位）。
- 第一版**不要求**待解释项跨设备同步验证，但要保证用户隔离和语言隔离。

---

## 3. 第一版数据边界

### 3.1 记录什么

每一条「待 AI 解释」记录至少需要：

- `id`：主键。
- `user_id`：用户隔离。
- `language`：语言隔离（英文）。
- `chapter_id`：来源章节。
- `text_block_index` 或等价位置：来源句子 / 文本块定位（与现有 TextBlock 索引一致）。
- `word`：被标记的单词（小写、规范化）。
- `word_range` 或选区信息：第一版只支持单词，但字段保留以兼容未来词组。
- `status`：默认 `pending`，预留 `explained` / `dismissed` 等未来状态。
- `created_at` / `updated_at`。
- `review_card_id` / `word_sense_id`：**第一版可为空**，仅留字段位，未来 AI 示意卡生成时再回填。

### 3.2 不记录什么

- 不记录 AI 输出（第一版不调 AI）。
- 不记录 FSRS 字段（不参与调度）。
- 不记录 ReviewLog（不进入复习日志）。
- 不记录 review state、stability、difficulty 等 FSRS 字段。
- 不记录 deletion / archive 状态（不走现有删除 / 彜档 / 恢复通道）。

### 3.3 是否需要新表

**需要新表。** 理由：

- 不能复用 `WordSenseOccurrence`：那是 sense 候选 pending 系统，语义不同；混用会污染 SenseReview 主线。
- 不能复用 `EncounteredWord`：那是阅读接触记录，没有「待 AI 解释」状态语义。
- 不能复用 `ReviewCard`：第一版明确不生成复习卡，不应让待解释项进入 FSRS 调度。
- 复用任何现有表都会让「AI 示意卡」与「sense-only 复习主线」边界模糊，违反 ADR-0002。

建议表名：`ai_study_card_pending_items`（或同等语义名称，最终以实现轮 ADR 为准）。

### 3.4 本轮 schema 改动

**本轮（路线冻结）不写 migration，不改 DB schema。** 实际 schema 在下一轮经 ADR 评审后再写。

---

## 4. 第一版前端边界

### 4.1 入口位置

- 唯一入口：阅读页点词侧栏 `VocabularySideBox.vue`。
- 按钮放在词典结果区域附近，名称建议为「待 AI 解释」。
- 按钮必须明显，但不打扰现有查词 / 添加释义流程。

### 4.2 是否支持词组

**第一版只支持单词。** 理由：

- 词组拖选涉及 `TextBlockGroup.vue` 的选区逻辑（high-risk 区），不在第一版触碰。
- 单词点选已有稳定入口和事件路径，复用现有 `TextWordTargetService.js`。
- 词组支持放到第二版或更后，单独走 Architecture Gate。

### 4.3 用户反馈

- 点击按钮后：按钮置灰 + 文案变为「已加入待解释」+ 短暂 toast 提示。
- 不弹模态框，不跳转。
- 不影响当前查词结果。
- 不影响手动添加释义流程。

### 4.4 对现有功能的影响

- 不影响现有查词。
- 不影响手动添加释义。
- 不影响 SenseReview 候选展示。
- 不影响 AI 阅读辅助（手动复制粘贴那个）。
- 不影响 legacy word card 兼容层。

### 4.5 受保护文件

- 第一版**不修改** `TextBlockGroup.vue` 主流程。
- 第一版**不修改** `TextReader.vue` 主流程。
- 第一版**只**在 `VocabularySideBox.vue` 增加一个按钮 + 一个 store action 调用。
- 若发现需要改 `TextBlockGroup.vue` 才能拿到选区，则第一版范围必须收缩为「只单词点选」。

---

## 5. 第一版后端边界

### 5.1 需要的接口（建议）

- `POST /ai-study-card/pending-items`：创建一条待解释项。
  - 入参：`chapter_id`、`word`、`text_block_index`、可选 `word_range`。
  - 出参：创建后的记录 id 与状态。
  - 必须用户隔离、语言隔离。
- `GET /ai-study-card/pending-items`：列出当前用户的待解释项（第一版可只给最小列表，主要给后端测试用，UI 可不暴露）。
- `DELETE /ai-study-card/pending-items/{id}`：删除一条待解释项（第一版可保留接口位，UI 可不暴露）。
- `PATCH /ai-study-card/pending-items/{id}/status`：状态流转（第一版可只支持 `pending → dismissed`，UI 可不暴露）。

### 5.2 不做什么

- 不调用任何 AI 服务（OpenAI / 自托管 / 任何）。
- 不生成 `WordSense`。
- 不生成 `ReviewCard`。
- 不写入 `ReviewLog`。
- 不写入 `EncounteredWord`。
- 不写入 `WordSenseOccurrence`。
- 不触发 FSRS 任何方法。
- 不触发 Pusher / websocket 事件。

### 5.3 必须保证

- 用户隔离：A 用户无法看到 / 创建 B 用户的待解释项。
- 语言隔离：当前用户语言为英文时，只能创建英文待解释项。
- 章节来源可追踪：每条记录必须能回到 `chapter_id` + `text_block_index`。
- 句子来源可追踪：通过 `text_block_index` 能定位到原句（与现有 TextBlock 索引一致）。
- 幂等建议：同一 `(user_id, chapter_id, word, text_block_index)` 重复标记应避免产生重复行（实现轮决定 exact unique 还是 upsert，但要在 ADR 里写清）。

### 5.4 Controller / Service 位置

- 建议新 Controller：`AiStudyCardPendingItemController`。
- 建议新 Service：`AiStudyCardPendingItemService`。
- 不允许塞进 `ReviewController` / `SenseReviewController` / `DictionaryController`。
- 不允许复用 `ReviewFsrsService` / `FsrsSchedulingService`。

---

## 6. 第一版禁止范围

第一版明确禁止：

1. 不实现 AI 推荐弹窗。
2. 不实现 AI 生成释义。
3. 不调用任何 AI API。
4. 不生成复习卡（`ReviewCard`）。
5. 不改 FSRS 算法语义。
6. 不改 `ReviewLog` 保留语义。
7. 不改删除 / 归档 / 恢复语义。
8. 不删除 SenseReview / SenseMappingReview。
9. 不统一导航（导航统一见 `frontend-review-entry-unification-plan.md`，是独立路线）。
10. 不大改 `TextBlockGroup.vue`。
11. 不改 legacy word card 兼容层。
12. 不在本轮直接写 migration（本轮只是路线冻结）。
13. 不把待解释项混入 `WordSenseOccurrence` 或 `EncounteredWord`。
14. 不引入新前端依赖。
15. 不引入新 PHP composer 依赖。

---

## 7. 第一版验收

### 7.1 后端测试（必须）

- 创建待解释项：成功、用户隔离、语言隔离。
- 重复标记：行为符合 ADR 决议（unique 或 upsert）。
- 列表接口：只返回当前用户、当前语言的记录。
- 删除 / 状态流转：行为正确。
- **反向 contract test**：确认创建待解释项**没有**触发 `ReviewCard` / `ReviewLog` / `WordSense` / `WordSenseOccurrence` / `EncounteredWord` 写入。
- **反向 contract test**：确认没有调用任何 FSRS 方法。

### 7.2 前端 smoke

- 阅读页点词 → 侧栏出现「待 AI 解释」按钮。
- 点击按钮 → 按钮置灰 + 反馈文案 + toast。
- 重复点击 → 行为符合 ADR 决议。
- 不影响查词、不影响手动添加释义。
- 不报 console error。
- 不触发非预期 network 请求。

### 7.3 MCP Chrome

- 用 `mcp-chrome-local-smoke-playbook.md` 的隔离上下文登录流程。
- 真实点词 → 真实点「待 AI 解释」→ 真实观察反馈。
- 只读复核数据库侧是否只写入了 pending item 表（不写其他表）。
- 不修改真实学习数据（用 smoke chapter，例如 chapter 5）。

### 7.4 CodeBuddy

- 后端代码静态审查：确认没有耦合到 FSRS / ReviewCard / WordSense。
- 确认 Controller / Service 边界干净。
- 确认 migration 不影响现有表。

### 7.5 WorkBuddy

- 真实用户视角页面体验复验：按钮是否明显、反馈是否清晰、是否打扰阅读。
- 复验「待 AI 解释」与「AI 阅读辅助」（手动复制那个）是否会让用户混淆。

### 7.6 不允许的验收替代

- 不允许用 `axios` / `fetch` 直接调 API 替代真实页面观察。
- 不允许只跑 PHP 测试就声明完成。
- 不允许跳过 MCP Chrome。

---

## 8. 第一版完成后进度

### 8.1 本轮（路线冻结）之后的进度

- AI 示意卡规划：25% → 55%（本轮贡献）。
- 本轮**不**把 AI 示意卡规划推到 70%。

### 8.2 真正实现第一版之后的进度

- AI 示意卡规划：55% → 约 70%。
- 总体架构收口：保持 100%（不再回退侦查）。
- 复习主线稳定：保持 91%。
- 页面真实验收：91% → 约 93%（新增一个真实可观察入口）。
- 前端入口整理：65% → 约 70%（侧栏多了一个统一风格的入口）。

### 8.3 明确声明

- 本轮只是路线冻结，不是功能实现。
- 路线冻结后，下一轮才进入实现，且实现轮必须先过 Architecture Gate 与 ADR。
- 不允许把「路线冻结」当作「功能完成」上报进度。
