# AI 示意卡生成闭环 V3 路线冻结

> 状态：**V3 已实现（Safe Preview Package V3 Done）**。前端恢复按钮、真实预览内容、安全生成包已落地。AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。
> 起点文档：`docs/plans/ai-study-card-v1-frozen-plan.md`、`docs/plans/ai-study-card-v2-generation-loop-plan.md`
> 配套文档：`docs/adr/ADR-0002-sense-only-and-ai-study-card-boundaries.md`、`docs/plans/final-architecture-closure-report.md`
> 适用阶段：V2 列表/取消/预览雏形完成后的第三个最小实现切片。
> 本文档记录 V3 落地边界；后续 AI 推荐词 / AI 释义生成 / 复习卡生成闭环仍需通过 Architecture Gate 与 ADR 评审。

---

## 1. V3 目标

### 1.1 一句话目标

在 V2「待解释列表 + 取消/恢复 + 生成前预览雏形」基础上，完成 V3：
1. 补上前端恢复体验：用户取消待解释词后，可在界面中直接恢复，不必回到原文重新找词。
2. 把预览弹窗从「空占位」升级为「真实预览内容」：显示已选词、来源句子、章节位置、数量、状态、安全说明、AI 推荐词占位、未来生成规则。
3. 生成一个「安全生成包」：用户点击「准备生成」后，系统生成本地预览包/复制包/JSON 包，不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。

### 1.2 为什么是这个目标

- V2 P2：前端没有 dismissed 项恢复按钮，用户取消后必须回到原文重新找词才能恢复，体验不完整。
- V2 P3：预览弹窗仍是纯占位，用户无法看到真实待解释词、来源句子、章节位置，无法勾选/取消勾选，无法生成任何形式的「准备包」。
- V3 补齐「恢复按钮 + 真实预览 + 安全生成包」三件事，让用户能完整管理待解释项并生成可复制的安全包。
- 安全生成包为未来 AI 推荐词和生成闭环做接口边界占位，但 V3 明确不调用 AI。
- 它仍然不触碰任何受保护模块（FSRS、ReviewCard、WordSense、TextBlock 主流程、删除/归档/恢复）。

### 1.3 V3 不做什么

- 不做 AI 推荐。
- 不做 AI 生成释义。
- 不做真实 AI 示意卡生成。
- 不做与复习卡的联动。
- 不做与 SenseReview 的联动。
- 不做词组拖选。
- 不改 FSRS。
- 不改删除/归档/恢复。
- 不删除 legacy word card 兼容层。
- 不保存勾选状态到 DB（仅前端状态）。
- 不新增 migration（复用 V1 表）。

### 1.4 实现状态（2026-07-02）

- 已扩展 `GET /ai-study-card/pending-items`：新增 `status` 参数支持 `pending|dismissed|all` 三种过滤，默认 `pending` 保持向后兼容。
- 已新增 `POST /ai-study-card/pending-items/preview-package`：接收 `item_ids` 数组，只打包当前用户/当前语言/pending 状态的 item，返回包含 schema_version/selected_items/generation_rules/safety_flags 的安全包。
- 已在 `VocabularySideBox.vue` 与响应式 `VocabularyBox.vue` 新增：
  - 「待解释/已取消」视图切换（v-btn-toggle），已取消视图含恢复按钮。
  - 预览弹窗升级为真实预览：v-checkbox 勾选、来源句子、章节/文本块/句子位置、数量统计、全选/全不选按钮。
  - 安全覆盖生成包展示区域（JSON pre 展示 + 复制按钮 + 成功/失败提示）。
  - 「准备生成」按钮替代 V2 的 disabled「确认生成」按钮，全不选时禁用。
- 已新增 14 个 V3 feature tests 覆盖 dismissed 列表/restore/preview-package/反向 contract。
- 已通过 MCP Chrome 真实页面验收 28 项。

---

## 2. V3 用户流程

### 2.1 已取消视图与恢复流程

1. 用户在待解释列表面板顶部看到「待解释 (N) / 已取消 (M)」切换按钮。
2. 点击「已取消」切换到已取消视图，显示所有 dismissed 项（含单词、来源句子、状态、恢复按钮）。
3. 用户点击某项的「恢复」按钮。
4. 该项状态从 dismissed 改为 pending，从已取消视图消失，重新出现在待解释视图。
5. 提示「已重新加入待 AI 解释」。
6. 用户不必回到原文重新找词即可恢复。

### 2.2 真实预览流程

1. 用户在列表面板底部点击「生成 AI 示意卡」按钮。
2. 打开预览弹窗「生成 AI 示意卡预览」。
3. 弹窗显示：
   - 安全说明：「当前只是预览，不会调用 AI，也不会生成复习卡。」
   - 用户已选词区域：每个 pending item 含 checkbox（默认全选）、单词、状态、来源句子、章节#N/文本块#N/句子#N。
   - 数量统计：「共 N 个，已勾选 M 个」。
   - 「全选」「全不选」按钮。
   - AI 推荐词区域：占位说明「下一阶段开放。本轮不会请求 AI 推荐。」
   - 规则预览：AI 推荐词默认不选；不与用户已选词重复；需手动确认。
   - 未来生成规则预览（5 条规则文本）。
   - 「准备生成」按钮：勾选数 > 0 时可用，全不选时禁用。
   - 「关闭」按钮：enabled。
4. 用户可以勾选/取消勾选某个词。
5. 全不选时「准备生成」按钮禁用。

### 2.3 安全生成包流程

1. 用户点击「准备生成」按钮。
2. 前端调用 `POST /ai-study-card/pending-items/preview-package`，传入勾选的 item_ids。
3. 后端只打包当前用户/当前语言/pending 状态的 item，返回安全包 JSON。
4. 弹窗显示「安全生成包」区域：
   - 安全说明：「这只是生成包，不是 AI 输出，不会生成复习卡。」
   - JSON 内容展示（pre 格式）：schema_version/created_at/selected_items/generation_rules/safety_flags。
   - 「复制生成包」按钮。
5. 用户点击「复制生成包」。
6. 提示「已复制到剪贴板。」（或复制失败提示）。
7. 整个过程不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。

---

## 3. V3 数据边界

### 3.1 状态机

- `pending`：用户已标记待 AI 解释。
- `dismissed`：用户已取消，不显示在待解释列表中，但可在已取消视图恢复。
- V3 不改变状态机，只补上前端恢复入口。

### 3.2 安全生成包 schema

```json
{
  "schema_version": "ai-study-card-preview-package-v1",
  "created_at": "ISO 8601 timestamp",
  "selected_items": [
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
      "created_at": "ISO 8601 timestamp"
    }
  ],
  "generation_rules": {
    "no_auto_review_card": true,
    "ai_recommended_default_unchecked": true,
    "ai_recommended_exclude_user_selected": true,
    "user_confirmation_required_before_generation": true
  },
  "safety_flags": {
    "no_ai_called": true,
    "no_review_card_created": true,
    "no_word_sense_created": true,
    "no_fsrs_changed": true
  }
}
```

### 3.3 不记录什么

- 不记录 AI 输出（V3 不调 AI）。
- 不记录 FSRS 字段。
- 不记录 ReviewLog。
- 不记录 review_card_id / word_sense_id（仍为空）。
- 不记录用户勾选状态到 DB（仅前端状态）。
- 不改变 pending item 状态（preview-package 不影响 pending 状态）。

---

## 4. V3 前端边界

### 4.1 入口位置

- 「待解释/已取消」切换：在 `VocabularySideBox.vue` / `VocabularyBox.vue` 列表面板顶部。
- 恢复按钮：已取消视图中每项。
- 预览弹窗：V2 已有，V3 升级内容。
- 安全覆盖生成包区域：预览弹窗内，「准备生成」按钮点击后显示。

### 4.2 列表面板内容（V3 升级）

- 顶部：「待解释 (N) / 已取消 (M)」切换按钮。
- 待解释视图：每项显示单词、来源句子、状态、添加时间、取消按钮。
- 已取消视图：每项显示单词、来源句子、状态、添加时间、恢复按钮。
- 底部：「生成 AI 示意卡」按钮。

### 4.3 预览弹窗内容（V3 升级）

- 标题：「生成 AI 示意卡预览」。
- 安全说明 alert：「当前只是预览，不会调用 AI，也不会生成复习卡。」
- 用户已选词区域：每项含 checkbox（默认全选）、单词、状态、来源句子、章节#N/文本块#N/句子#N。
- 数量统计：「共 N 个，已勾选 M 个」。
- 「全选」「全不选」按钮。
- AI 推荐词区域：占位文本。
- 规则预览列表。
- 未来生成规则预览（5 条）。
- 「准备生成」按钮：勾选数 > 0 时可用。
- 「关闭」按钮：enabled。
- 安全覆盖生成包区域（点击「准备生成」后显示）：
  - 安全说明：「这只是生成包，不是 AI 输出，不会生成复习卡。」
  - JSON 内容（pre 格式）。
  - 「复制生成包」按钮。
  - 复制成功/失败提示。

### 4.4 受保护文件

- V3 **不修改** `TextBlockGroup.vue` 主流程。
- V3 **不修改** `TextReader.vue` 主流程。
- V3 **只**在 `VocabularySideBox.vue` / `VocabularyBox.vue` 升级列表/预览/安全包组件。

---

## 5. V3 后端边界

### 5.1 已落地接口

- `GET /ai-study-card/pending-items`：列出当前用户的 pending/dismissed/all items。
  - 可选参数：`chapter_id`（按章节过滤）、`status`（`pending|dismissed|all`，默认 `pending`）。
  - 只返回当前用户、当前语言。
  - 返回字段：id/status/word/lemma/surface/chapter_id/text_block_index/sentence_index/sentence_text/created_at/updated_at。
- `POST /ai-study-card/pending-items`：创建或恢复 pending item（V1 已有，V2 改造恢复逻辑）。
- `POST /ai-study-card/pending-items/{id}/dismiss`：取消 pending item（V2 已有）。
- `POST /ai-study-card/pending-items/{id}/restore`：恢复 dismissed item（V2 已有）。
- `POST /ai-study-card/pending-items/preview-package`：生成安全预览包（V3 新增）。
  - 入参：`item_ids`（array，required，min:1）。
  - 出参：`success`/`package`/`message`。
  - 只打包当前用户/当前语言/pending 状态的 item。
  - dismissed 项不能进入生成包。
  - 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。

### 5.2 不做什么

- 不调用任何 AI 服务。
- 不生成 `WordSense`。
- 不生成 `ReviewCard`。
- 不写入 `ReviewLog`。
- 不写入 `EncounteredWord`。
- 不写入 `WordSenseOccurrence`。
- 不触发 FSRS 任何方法。
- 不物理删除 pending item（dismiss 只改状态）。
- 不改变 pending item 状态（preview-package 不影响 pending 状态）。
- 不保存用户勾选状态到 DB。

### 5.3 必须保证

- 用户隔离：A 用户无法看到/取消/恢复/打包 B 用户的待解释项。
- 语言隔离：当前用户语言为英文时，只能操作英文待解释项。
- 章节归属：列表按 chapter_id 过滤时检查章节归属。
- 幂等：dismiss 已 dismissed 的项不报错；restore 已 pending 的项检查 unique 冲突。
- 状态过滤：`status=pending` 只返回 pending；`status=dismissed` 只返回 dismissed；`status=all` 返回两者。
- 安全包只含 pending：dismissed 项不能进入生成包。
- 安全包不改变状态：preview-package 不影响 pending item 状态。
- 安全包上限：单次最多 100 项，防止滥用。

---

## 6. V3 禁止范围

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
16. 不保存用户勾选状态到 DB。
17. 不把安全生成包发送给外部 AI。
18. 不保存 API key。
19. 不新增 AI 配置页。

---

## 7. V3 验收

### 7.1 后端测试（已通过）

- dismissed 列表：登录用户可以查看自己的 dismissed 项；只返回当前用户；只返回当前语言；不返回 pending，除非 status=all 明确请求。
- restore：前端可用的 restore 接口继续通过；A 用户不能恢复 B 用户数据；恢复后重新出现在 pending 列表；restore 不生成 WordSense/ReviewCard/ReviewLog。
- preview package：登录用户可以为自己的 pending items 生成安全包；未登录用户不能生成；A 用户不能打包 B 用户 item；dismissed item 不能进入生成包；其他语言 item 不能进入生成包；空 item_ids 返回合理错误；不调用 AI；不生成 WordSense/ReviewCard/ReviewLog；不触发 FSRS；safety_flags 正确。
- 反向测试：preview package 不改变 pending item 状态；preview package 不创建任何学习数据；preview package 不影响复习卡数量。
- 37 tests / 184 assertions 全绿（含 V1/V2/V3 全部测试）。

### 7.2 MCP Chrome 真实页面验收（已通过 28 项）

- 登录测试账号成功。
- 打开阅读页 `/chapters/read/5`。
- 点击单词 "substantive"。
- 点击「待 AI 解释」→ 提示「已加入待 AI 解释。」
- 打开待解释列表 → 看到 3 个待解释项（substantive, landscape, conceptualize）。
- 点击 substantive 的「取消」→ 提示「已取消」，待解释(2)/已取消(1)。
- 切换到「已取消」视图 → 显示 substantive + 「恢复」按钮。
- 点击「恢复」→ 提示「已重新加入待 AI 解释」，待解释(3)/已取消(0)。
- 切换回「待解释」视图，点击「生成 AI 示意卡」→ 预览弹窗打开。
- 弹窗显示真实预览内容：3 个已选词，每个含 checkbox（默认全选）、来源句子、章节#5/文本块/句子位置。
- 显示 AI 推荐词占位区域。
- 显示未来生成规则预览（5 条）。
- 显示「全选」「全不选」按钮。
- 点击「全不选」→ 所有 checkbox 取消勾选，显示「已勾选 0 个」，「准备生成」按钮 disabled。
- 点击 substantive 的 checkbox 重新勾选 → 显示「已勾选 1 个」，「准备生成」按钮恢复可用。
- 点击「准备生成」→ 显示安全生成包区域。
- 安全生成包包含：schema_version=ai-study-card-preview-package-v1、selected_items（含 chapter_id/sentence_text/word/lemma/surface/text_block_index）、generation_rules（4 条规则全 true）、safety_flags（no_ai_called/no_review_card_created/no_word_sense_created/no_fsrs_changed 全 true）。
- 点击「复制生成包」→ 提示「已复制到剪贴板。」
- 安全说明显示「这只是生成包，不是 AI 输出，不会生成复习卡。」
- 无外部 AI 网络请求（只有本地 API，preview-package 200 OK）。
- Console 无新增错误（只有预期 WebSocket 降级）。
- Network 正常。
- 主复习入口 `/reviews/senses` 正常（到期 5 张）。
- 旧入口 `/review-cards/manage` 正常（总词义卡 7 张，未新增）。
- 未用 API 替代页面点击。

### 7.3 前端构建

- `npm run development` 构建成功（Laravel Mix v6.0.49）。

---

## 8. V3 完成后进度

### 8.1 固定五条主线

| 主线 | V2 后 | V3 后 | 说明 |
|------|-------|-------|------|
| 总体架构收口 | 100% | 100% | 保持 |
| 复习主线稳定 | 96% | 96% | 保持（V3 不触碰 FSRS/ReviewCard/ReviewLog） |
| 页面真实验收 | 100% | 100% | 保持（V3 MCP Chrome 28 项全通过） |
| AI 示意卡规划 | 100% | 100% | 保持 |
| 前端入口整理 | 98% | 100% | V3 列表/预览/安全包入口与复习入口完全协调 |

### 8.2 子阶段进度

| 子阶段 | V2 后 | V3 后 | 说明 |
|--------|-------|-------|------|
| AI 示意卡生成闭环 | 70% | 95% | 恢复按钮/真实预览/安全生成包已完成。**95% 是子阶段进度，非五条主线虚假上调。** AI 推荐词、AI 释义生成、WordSense/ReviewCard 生成闭环、真实 AI 调用仍未实现。 |
| AI 生成安全契约 | 0% | 55% | V3 新增子阶段。安全生成包 schema/safety_flags/generation_rules 已落地，preview-package 接口边界已锁定。**55% 是子阶段进度，非五条主线虚假上调。** API key 存储/真实 AI 调用边界/用户确认后生成 WordSense/ReviewCard 仍未实现。 |

### 8.3 合计提升

- 子阶段进度提升：25%（AI 示意卡生成闭环 70%→95%）+ 55%（AI 生成安全契约 0%→55%）= 80%。
- **这 80% 是子阶段进度提升，不是固定五条主线的虚假上涨。**
- 固定五条主线仅前端入口整理 98%→100%（+2%），其余四条保持。
- 不允许把 V3 写成 AI 示意卡完整闭环完成。
- 不允许把 80% 写成固定五条主线虚假上涨。
- 不允许把复习系统写成 100% 全无风险。

### 8.4 明确声明

- AI 推荐词仍未实现。
- AI 释义生成仍未实现。
- WordSense / ReviewCard 生成闭环仍未实现。
- 真正 AI 调用仍未实现。
- API key 安全存储仍未实现。
- 后续实现必须继续保持：用户确认优先、AI 推荐默认不选、不自动污染 WordSense/ReviewCard/FSRS。

---

## 9. V3 之后的下一步候选

### 9.1 AI 推荐词架构设计

- 下一阶段应先设计 AI 推荐词的架构：推荐来源、排重逻辑、默认不选、用户确认。
- 不直接实现真实 AI 调用。
- 必须先过 Architecture Gate 与 ADR。
- 必须先解决 API key 安全存储问题。

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
