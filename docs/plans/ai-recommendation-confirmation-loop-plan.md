# AI 推荐词确认闭环 V4 路线冻结

> 状态：**V4 已实现（AI Recommendation Confirmation Loop V4 Done）**。AI 推荐词粘贴导入、去重、默认不选、用户确认、最终候选包已落地。AI 真实调用、AI 释义生成、WordSense/ReviewCard 生成闭环仍未实现。
> 起点文档：`docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/plans/ai-study-card-v2-generation-loop-plan.md`、`docs/plans/ai-study-card-v3-safe-preview-package-plan.md`
> 配套文档：`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`、`docs/plans/final-architecture-closure-report.md`
> 适用阶段：V3 安全生成包完成后的第四个最小实现切片。
> 本文档记录 V4 落地边界；后续 AI 释义生成 / 复习卡生成闭环仍需通过 Architecture Gate 与 ADR 评审。

---

## 1. V4 目标

### 1.1 一句话目标

在 V3「安全生成包」基础上，完成 V4：
1. 用户可以把 AI 返回的推荐词 JSON 粘贴进页面。
2. 系统解析 AI 推荐词。
3. 系统自动去重（不和用户已选词重复、AI 推荐词之间也不重复）。
4. AI 推荐词默认不选。
5. 用户可以逐个勾选 / 全选 / 全不选 AI 推荐词。
6. 用户点击「生成最终候选包」后生成本阶段最终确认数据。
7. 最终候选包只用于下一阶段，不创建 WordSense、不创建 ReviewCard、不调用外部 AI、不保存 API key、不触发 FSRS。

### 1.2 为什么是这个目标

- V3 完成安全生成包后，下一个最小切片应该是「让用户把 AI 输出贴回来」。
- 但 V4 仍不调用真实 AI：用户继续在 ChatGPT 网页端操作 GPT，把 GPT 返回的推荐词 JSON 粘贴回 LinguaCafe 页面。
- 这与现有 AI 阅读辅助「复制提示词 + 粘贴 AI JSON」模式一致，保持自动化边界。
- V4 必须解决三个核心问题：
  1. 解析容错（JSON 错误、字段缺失、空数组、非数组）；
  2. 去重（不能让重复词污染用户确认流程）；
  3. 默认不选 + 用户确认（不能让 AI 推荐词自动进入候选包）。
- 最终候选包是下一阶段（AI 释义生成 / WordSense / ReviewCard 生成）的输入边界，但 V4 本身仍不创建任何学习数据。

### 1.3 V4 不做什么

- 不调用任何 AI API。
- 不保存 API key。
- 不新增 AI 配置页。
- 不生成 WordSense。
- 不生成 ReviewCard。
- 不写 ReviewLog。
- 不改 FSRS。
- 不改删除/归档/恢复。
- 不删除 legacy word card 兼容层。
- 不删除 SenseReview / SenseMappingReview。
- 不新增 migration（复用 V1 表）。
- 不保存 AI 推荐词到 DB（仅前端状态）。
- 不保存用户勾选状态到 DB（仅前端状态）。
- 不把最终候选包发送给外部 AI。
- 不自动进入下一任务。

### 1.4 实现状态（2026-07-02）

- 后端：新增 `POST /ai-study-card/pending-items/final-candidates-package`，包含三重隔离（用户/语言/状态）、后端二次去重、空结果 422、数量上限保护。
- 前端：`VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 新增 V4 完整 UI（粘贴文本框 + 解析/清空按钮 + 解析错误提示 + 解析摘要 + 推荐词列表带 checkbox 默认 unchecked + 全选/全不选 + 用户已选词/AI 推荐词视觉分区 + 「生成最终候选包」按钮 + 最终候选包展示 + 复制按钮）。
- 测试：新增 18 个 V4 feature tests，覆盖鉴权/用户隔离/语言隔离/dismissed 排除/去重/默认不选/空选择/只有用户已选词/无效 AI/不调用 AI/不生成 WordSense/ReviewCard/ReviewLog/不触发 FSRS/safety_flags 正确。
- MCP Chrome 真实页面验收 33 项全部通过。

---

## 2. V4 用户流程

### 2.1 AI 推荐词粘贴导入流程

1. 用户在 V3 安全生成包区域下方看到 V4 新增的「粘贴 AI 推荐词 JSON」文本框。
2. 用户在 ChatGPT 网页端拿到 GPT 返回的推荐词 JSON，复制粘贴进文本框。
3. 用户点击「解析推荐词」按钮。
4. 系统解析 JSON：
   - JSON 格式错误 → 显示「JSON 格式错误：...」，页面不崩溃。
   - `recommended_items` 不是数组 → 显示「recommended_items 必须是数组。」
   - 全部推荐词无效 → 显示「没有有效的推荐词。」
   - 解析成功 → 显示推荐词列表 + 解析摘要。
5. 用户可以点击「清空推荐词」按钮，清空文本框和已解析的推荐词列表。

### 2.2 去重流程

1. 系统在解析时自动去重：
   - 和用户已选词重复的推荐词被丢弃，计入「与用户已选词重复丢弃」数量。
   - AI 推荐词之间重复的项被丢弃，计入「内部重复丢弃」数量。
   - 同一个词只保留第一条有效推荐。
2. 去重标准：优先使用 `lemma`；没有 `lemma` 时使用 `word`；大小写不敏感；前后空格忽略。
3. 解析摘要显示：原始推荐数量、有效推荐数量、缺 word 丢弃数量、与用户已选词重复丢弃数量、内部重复丢弃数量。
4. 前端先做去重；如果调用后端 final-candidates-package 接口，后端再次二次去重。

### 2.3 默认不选 + 用户确认流程

1. 解析成功后，AI 推荐词列表显示在「用户已选词」区域下方的独立分区。
2. 每条 AI 推荐词显示：checkbox（默认 unchecked）、word、lemma、surface、reason、confidence、sentence_text。
3. 用户可以：
   - 逐个勾选某个推荐词；
   - 点击「全选推荐词」勾选所有推荐词；
   - 点击「全不选推荐词」取消所有勾选。
4. 用户已选词区域仍保持 V3 的勾选状态，不被 AI 推荐词覆盖。
5. 用户已选词和 AI 推荐词视觉分区，不能混成一组。
6. 重复词不显示两次。
7. 没有用户确认就不能进入最终候选包。
8. 页面不暗示已经生成复习卡。

### 2.4 最终候选包流程

1. 用户完成勾选后，点击「生成最终候选包」按钮。
2. 前端调用 `POST /ai-study-card/pending-items/final-candidates-package`，传入：
   - `selected_item_ids`：用户已选词的 item_id 数组；
   - `selected_ai_recommendations`：用户勾选的 AI 推荐词数组；
   - `unselected_ai_recommendations`：用户未勾选的 AI 推荐词数组；
   - `dedupe_summary`：去重摘要；
   - `source_preview_package`：可选的 V3 安全生成包引用。
3. 后端做三重隔离 + 二次去重 + 空结果检查 + 数量上限保护。
4. 后端返回最终候选包 JSON：
   - `schema_version`: `ai-study-card-final-candidates-v1`
   - `source_preview_package_schema_version`
   - `user_selected_items`
   - `ai_recommended_selected_items`
   - `ai_recommended_unselected_items`
   - `dedupe_summary`
   - `generation_rules`（5 条）
   - `safety_flags`（6 条）
5. 前端展示最终候选包 JSON（pre 格式）+ 「复制最终候选包」按钮。
6. 用户点击「复制最终候选包」→ 提示「已复制到剪贴板。」（或失败提示）。
7. 整个过程不调用 AI、不生成 WordSense/ReviewCard/ReviewLog、不触发 FSRS。

---

## 3. V4 数据边界

### 3.1 状态机

- `pending`：用户已标记待 AI 解释。
- `dismissed`：用户已取消。
- V4 不改变状态机，不改变 pending item 状态。
- 最终候选包不写入 DB，仅作为前端 + 后端响应数据。

### 3.2 最终候选包 schema

```json
{
  "schema_version": "ai-study-card-final-candidates-v1",
  "source_preview_package_schema_version": "ai-study-card-preview-package-v1",
  "created_at": "ISO 8601 timestamp",
  "user_selected_items": [
    {
      "item_id": N,
      "chapter_id": N,
      "text_block_index": N,
      "sentence_index": N,
      "word": "string",
      "normalized_word": "string",
      "surface": "string",
      "lemma": "string",
      "sentence_text": "string",
      "status": "pending",
      "source": "user_selected"
    }
  ],
  "ai_recommended_selected_items": [
    {
      "word": "string",
      "lemma": "string",
      "surface": "string",
      "reason": "string",
      "sentence_text": "string|null",
      "confidence": "number|null",
      "source": "ai_recommended"
    }
  ],
  "ai_recommended_unselected_items": [
    {
      "word": "string",
      "lemma": "string",
      "surface": "string",
      "reason": "string",
      "sentence_text": "string|null",
      "confidence": "number|null",
      "source": "ai_recommended"
    }
  ],
  "dedupe_summary": {
    "original_count": N,
    "valid_count": N,
    "dropped_missing_word": N,
    "dropped_duplicate_with_user": N,
    "dropped_ai_internal_duplicate": N
  },
  "generation_rules": {
    "no_auto_review_card": true,
    "ai_recommended_default_unchecked": true,
    "ai_recommended_exclude_user_selected": true,
    "user_confirmation_required_before_generation": true,
    "user_confirmation_required_before_card_generation": true
  },
  "safety_flags": {
    "no_ai_called_by_linguacafe": true,
    "ai_response_pasted_by_user": true,
    "no_review_card_created": true,
    "no_word_sense_created": true,
    "no_fsrs_changed": true,
    "user_confirmation_required_before_card_generation": true
  }
}
```

### 3.3 AI 推荐词输入 JSON schema

```json
{
  "schema_version": "ai-study-card-recommendations-v1",
  "recommended_items": [
    {
      "word": "string",
      "lemma": "string",
      "surface": "string",
      "reason": "string",
      "sentence_text": "string",
      "confidence": 0.82
    }
  ]
}
```

字段容错规则：
- 缺少 `word`：该项无效，计入「缺 word 丢弃」。
- 缺少 `lemma`：用 `word` 代替。
- 缺少 `surface`：用 `word` 代替。
- 缺少 `confidence`：显示为未知（null）。
- 缺少 `reason`：显示为「无说明」。
- 缺少 `sentence_text`：显示为 null。

### 3.4 不记录什么

- 不调用 AI（V4 不调 AI）。
- 不保存 AI 推荐词到 DB。
- 不保存用户勾选状态到 DB。
- 不记录 FSRS 字段。
- 不记录 ReviewLog。
- 不创建 review_card_id / word_sense_id。
- 不改变 pending item 状态。
- 不保存 API key。
- 不把最终候选包发送给外部 AI。

---

## 4. V4 前端边界

### 4.1 入口位置

- 「粘贴 AI 推荐词 JSON」文本框：V3 安全生成包区域下方。
- 「解析推荐词」「清空推荐词」按钮：粘贴文本框下方。
- 解析错误提示：按钮下方。
- 解析摘要：错误提示下方。
- AI 推荐词列表：解析摘要下方，与「用户已选词」区域视觉分区。
- 「生成最终候选包」按钮：v-card-actions 中，与 V3「准备生成」按钮并列。
- 最终候选包展示区：点击「生成最终候选包」后显示。

### 4.2 受保护文件

- V4 **不修改** `TextBlockGroup.vue` 主流程。
- V4 **不修改** `TextReader.vue` 主流程。
- V4 **只**在 `VocabularySideBox.vue` / `VocabularyBox.vue` 升级 V4 粘贴导入/去重/勾选/最终候选包组件。

### 4.3 视觉分区要求

- 用户已选词区域：标题「用户已选词 (N)」，每项含 checkbox（默认勾选）、单词、来源句子、章节位置。
- AI 推荐词区域：标题「AI 推荐词 (N/M)」，每项含 checkbox（默认 unchecked）、word、lemma、surface、reason、confidence、sentence_text。
- 两个区域使用 v-divider 分隔。
- 不允许两个区域混成一组。
- 不允许重复词显示两次。

---

## 5. V4 后端边界

### 5.1 已落地接口

- `POST /ai-study-card/pending-items/final-candidates-package`：生成最终候选包（V4 新增）。
  - 入参：
    - `selected_item_ids`（array，nullable）
    - `selected_ai_recommendations`（array，nullable）
    - `unselected_ai_recommendations`（array，nullable）
    - `dedupe_summary`（array/object，nullable）
    - `source_preview_package`（array/object，nullable）
  - 出参：`success`/`package`/`message`。
  - 只打包当前用户/当前语言/pending 状态的 item。
  - dismissed 项不能进入最终候选包。
  - 后端二次去重：AI 推荐词与用户已选词去重 + AI 推荐词内部去重 + unselected 与 selected 去重。
  - 空结果保护：`selected_item_ids` 与 `selected_ai_recommendations` 都为空 → 返回 422。
  - 查询后再次保护：用户已选词被全部过滤掉且 AI 推荐词也为空 → 返回 422。
  - 数量上限：最多 100 个用户已选词；最多 200 个 AI 推荐词（selected + unselected）。
  - 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。

### 5.2 不做什么

- 不调用任何 AI 服务。
- 不保存 API key。
- 不生成 `WordSense`。
- 不生成 `ReviewCard`。
- 不写入 `ReviewLog`。
- 不写入 `EncounteredWord`。
- 不写入 `WordSenseOccurrence`。
- 不触发 FSRS 任何方法。
- 不改变 pending item 状态。
- 不保存 AI 推荐词到 DB。
- 不保存用户勾选状态到 DB。
- 不把最终候选包发送给外部 AI。

### 5.3 必须保证

- 用户隔离：A 用户无法打包 B 用户的 item。
- 语言隔离：当前用户语言为英文时，只能打包英文 item。
- 状态隔离：dismissed 项不能进入最终候选包。
- 二次去重：后端必须再次校验 AI 推荐词与用户已选词、AI 推荐词内部、unselected 与 selected 之间的去重。
- 空结果拒绝：空 `selected_item_ids` + 空 `selected_ai_recommendations` 返回 422；查询后无有效结果也返回 422。
- 数量上限：单次最多 100 用户已选词、200 AI 推荐词。
- safety_flags 6 条全 true：`no_ai_called_by_linguacafe` / `ai_response_pasted_by_user` / `no_review_card_created` / `no_word_sense_created` / `no_fsrs_changed` / `user_confirmation_required_before_card_generation`。
- generation_rules 5 条全 true：`no_auto_review_card` / `ai_recommended_default_unchecked` / `ai_recommended_exclude_user_selected` / `user_confirmation_required_before_generation` / `user_confirmation_required_before_card_generation`。

---

## 6. V4 禁止范围

1. 不实现真实 AI 调用。
2. 不实现 AI 释义生成。
3. 不调用任何 AI API。
4. 不生成复习卡（`ReviewCard`）。
5. 不生成 `WordSense`。
6. 不写 `ReviewLog`。
7. 不改 FSRS 算法语义。
8. 不改 `ReviewLog` 保留语义。
9. 不改删除/归档/恢复语义。
10. 不删除 SenseReview / SenseMappingReview。
11. 不大改 `TextBlockGroup.vue`。
12. 不改 legacy word card 兼容层。
13. 不新增 migration（复用 V1 表）。
14. 不把待解释项混入 `WordSenseOccurrence` 或 `EncounteredWord`。
15. 不引入新前端依赖。
16. 不引入新 PHP composer 依赖。
17. 不保存 AI 推荐词到 DB。
18. 不保存用户勾选状态到 DB。
19. 不把最终候选包发送给外部 AI。
20. 不保存 API key。
21. 不新增 AI 配置页。
22. 不自动进入下一任务。

---

## 7. V4 验收

### 7.1 后端测试（已通过）

新增 18 个 V4 feature tests，覆盖：

1. 登录用户可以为自己的 pending items + AI 推荐词生成最终候选包。
2. 未登录用户不能生成。
3. A 用户不能打包 B 用户 item。
4. dismissed item 不能进入最终候选包。
5. 其他语言 item 不能进入最终候选包。
6. AI 推荐词与用户已选词去重。
7. AI 推荐词内部去重。
8. 默认不选逻辑在数据结构中可体现（unselected_ai_recommendations 字段）。
9. 空 `selected_item_ids` + 空 `selected_ai_recommendations` 返回 422。
10. 只有 `selected_item_ids`、没有 AI 推荐词时允许生成。
11. 全部 AI 推荐词无效时不崩溃。
12. 不调用 AI。
13. 不生成 WordSense。
14. 不生成 ReviewCard。
15. 不写 ReviewLog。
16. 不触发 FSRS（验证 ReviewCard 的 fsrs_stability/fsrs_difficulty/fsrs_due_at/fsrs_state/fsrs_reps/fsrs_lapses/fsrs_enabled 字段不变）。
17. safety_flags 正确（6 条全 true）。
18. unselected_ai_recommendations 与 selected_ai_recommendations 之间去重。
19. 数量上限保护（>100 用户已选词 / >200 AI 推荐词 返回 422）。
20. source_preview_package 字段正确保留。

测试结果：AiStudyCardPendingItemTest 56 tests / 294 assertions 全绿（含 V1/V2/V3/V4 全部测试）。

### 7.2 MCP Chrome 真实页面验收（已通过 33 项）

- 登录测试账号成功。
- 打开阅读页 `/chapters/read/5`。
- 点击单词 substantive → 标记「待 AI 解释」。
- 打开待解释列表 → 看到 3 个待解释项。
- 点击「生成 AI 示意卡」→ 预览弹窗打开。
- 点击「准备生成」→ 显示 V3 安全生成包。
- 看到 V4「粘贴 AI 推荐词 JSON」文本框 + 「解析推荐词」「清空推荐词」按钮。
- 粘贴合法 JSON（agency + mediation）→ 点击「解析推荐词」。
- 推荐词列表显示 2 条，每条含 checkbox（默认 unchecked）、word、lemma、reason、confidence、sentence_text。
- 解析摘要显示：原始 2 / 有效 2 / 缺 word 丢弃 0 / 与用户已选词重复丢弃 0 / 内部重复丢弃 0。
- 确认默认 0 勾选。
- 粘贴重复 JSON（substantive + agency + Agency）→ 点击「解析推荐词」。
- 解析摘要显示：原始 3 / 有效 1 / 与用户已选词重复 1 / 内部重复 1。
- substantive 因与用户已选词重复被排除。
- Agency 因内部重复被排除。
- 粘贴 malformed JSON → 点击「解析推荐词」→ 显示「JSON 格式错误：Unexpected end of JSON input」，页面不崩溃。
- 点击「清空推荐词」→ 文本框和列表清空。
- 重新粘贴合法 JSON + 解析。
- 勾选 agency 一个推荐词 → checkbox 变为 checked。
- 点击「全选推荐词」→ 所有 AI 推荐词被勾选。
- 点击「全不选推荐词」→ 所有 AI 推荐词取消勾选。
- 再次勾选 agency。
- 点击「生成最终候选包」→ 显示最终候选包 JSON。
- 最终候选包包含：
  - `schema_version=ai-study-card-final-candidates-v1`
  - `user_selected_items`（3 条用户已选词）
  - `ai_recommended_selected_items`（1 条 agency）
  - `ai_recommended_unselected_items`（1 条 mediation）
  - `dedupe_summary`（原始 2 / 有效 2 / 丢弃 0）
  - `generation_rules`（5 条全 true）
  - `safety_flags`（6 条全 true）
- 点击「复制最终候选包」→ 提示「已复制到剪贴板。」
- Network 面板无外部 AI 请求（只有本地 `POST /ai-study-card/pending-items/final-candidates-package` 200 OK）。
- 数据库无新增 WordSense / ReviewCard / ReviewLog。
- ReviewCard 的 FSRS 字段不变。
- 主复习入口 `/reviews/senses` 正常。
- 旧入口 `/review-cards/manage` 正常。
- Console 无新增错误（只有预期 WebSocket 降级）。
- Network 正常。
- 未用 API 替代页面点击。

### 7.3 前端构建

- `npm run development` 构建成功（Laravel Mix v6.0.49）。

### 7.4 其他测试套件回归

- `ReviewFsrsTest`：61 tests / 364 assertions 全绿。
- `FsrsSchedulingServiceTest`：9 tests / 46 assertions 全绿。
- `WordSense`（WordSenseDestroyRestoreTest + WordSenseTest）：149 tests / 595 assertions 全绿。

---

## 8. V4 完成后进度

### 8.1 固定五条主线

| 主线 | V3 后 | V4 后 | 说明 |
|------|-------|-------|------|
| 总体架构收口 | 100% | 100% | 保持 |
| 复习主线稳定 | 96% | 96% | 保持（V4 不触碰 FSRS/ReviewCard/ReviewLog） |
| 页面真实验收 | 100% | 100% | 保持（V4 MCP Chrome 33 项全通过） |
| AI 示意卡规划 | 100% | 100% | 保持 |
| 前端入口整理 | 100% | 100% | 保持 |

### 8.2 子阶段进度

| 子阶段 | V3 后 | V4 后 | 说明 |
|--------|-------|-------|------|
| AI 示意卡生成闭环 | 95% | 95% | 保持（V4 不改变 AI 示意卡生成闭环子阶段进度） |
| AI 生成安全契约 | 55% | 85% | V4 新增最终候选包 schema（`ai-study-card-final-candidates-v1`）、6 条 safety_flags、5 条 generation_rules、后端二次去重、18 个反向 contract tests。**85% 是子阶段进度，非五条主线虚假上调。** API key 安全存储、真实 AI 调用边界仍未实现。 |
| AI 推荐词确认闭环 | 0% | 80% | V4 新增子阶段。粘贴导入、去重、默认不选、用户确认、最终候选包已落地。**80% 是子阶段进度，非五条主线虚假上调。** AI 真实推荐（自动调用 AI 获取推荐词）仍未实现。 |

### 8.3 合计提升

- 子阶段进度提升：30%（AI 生成安全契约 55%→85%）+ 80%（AI 推荐词确认闭环 0%→80%）= 110%。
- **这 110% 是子阶段进度提升，不是固定五条主线的虚假上涨。**
- 固定五条主线全部保持不变。
- 不允许把 V4 写成 AI 示意卡完整闭环完成。
- 不允许把 110% 写成固定五条主线虚假上涨。
- 不允许把复习系统写成 100% 全无风险。

### 8.4 明确声明

- AI 真实推荐（自动调用 AI 获取推荐词）仍未实现。
- AI 释义生成仍未实现。
- WordSense / ReviewCard 生成闭环仍未实现。
- 真正 AI 调用仍未实现。
- API key 安全存储仍未实现。
- 后续实现必须继续保持：用户确认优先、AI 推荐默认不选、不自动污染 WordSense/ReviewCard/FSRS。

---

## 9. V4 之后的下一步候选

### 9.1 AI 真实推荐（自动调用 AI 获取推荐词）

- 下一阶段可以设计 AI 真实推荐：用户点击「获取 AI 推荐」按钮，LinguaCafe 调用 AI API 获取推荐词。
- 必须先解决 API key 安全存储问题。
- 必须先过 Architecture Gate 与 ADR。
- V4 的去重/默认不选/用户确认逻辑可以直接复用。

### 9.2 AI 释义生成

- 在用户确认推荐词后，调用 AI 生成释义。
- 必须先过 Architecture Gate 与 ADR。

### 9.3 WordSense / ReviewCard 生成闭环

- 在 AI 释义生成后，用户确认后生成 WordSense + ReviewCard。
- 必须先过 Architecture Gate 与 ADR。
- 必须保持 FSRS 语义不变。

### 9.4 AI 生成安全契约后续

- API key 安全存储方案。
- 真实 AI 调用的请求/响应边界。
- 用户确认后生成 WordSense/ReviewCard 的事务边界。
- 失败回滚与重试策略。

---

## 10. 与 V3 的衔接

- V4 在 V3 安全生成包区域之后继续，不重复实现 V1/V2/V3。
- V4 复用 V3 的预览弹窗结构、用户已选词列表、勾选/全选/全不选逻辑。
- V4 新增的部分：粘贴文本框、解析按钮、清空按钮、解析错误提示、解析摘要、AI 推荐词列表（默认 unchecked）、最终候选包展示。
- V4 后端新增 `final-candidates-package` 接口，复用 V3 的 `preview-package` 接口的隔离/去重/数量上限设计。
- V4 的 `safety_flags` 在 V3 的 4 条基础上新增 2 条：`ai_response_pasted_by_user`、`user_confirmation_required_before_card_generation`。
- V4 的 `generation_rules` 在 V3 的 4 条基础上新增 1 条：`user_confirmation_required_before_card_generation`。
- V4 不调用 V3 的 `preview-package` 接口；V4 的 `source_preview_package` 字段是可选的引用，用于追溯。
