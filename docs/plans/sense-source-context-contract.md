# SenseSourceContext 输出契约与拆分边界

> **契约锁定日期**：2026-07-01
> **基准 commit**：`36f86ec`
> **契约性质**：本轮只锁定契约和补测试，不拆 Service，不改页面，不改 API shape。

---

## 1. 目标

1. 锁定 `SenseSourceContextService::sourceContext()` 的输出契约。
2. 记录所有 `source_kind` 及其触发条件、写回行为。
3. 记录 fallback 顺序。
4. 记录输出 JSON 的稳定字段。
5. 补充少量 characterization tests。
6. 不修改 `SenseSourceContextService`。
7. 不修改 `SenseTokenPayloadService`。
8. 不改 Vue / Controller / route / API shape。

---

## 2. 当前调用链

**前端入口**：
- 阅读页词汇侧栏：点击"查看原文"
- 复习管理页：更多菜单 → "查看原文"
- 复习页弹窗：source context 区域

**Controller**：
`SenseOccurrenceController::sourceContext(int $id)` — `GET /senses/{sense}/source-context`

**Service**：
`SenseSourceContextService::sourceContext(int $userId, string $language, int $senseId): array`

**Helper Service**：
`SenseTokenPayloadService` — token 简化、句子规范化、合成 token

---

## 3. source_kind 列表

| source_kind | 触发条件 | source_available | chapter_id | context_tokens | target_indexes | fallback_message | 写回 DB | 用户效果 |
|-------------|----------|:-:|:-:|:-:|:-:|:-:|:-:|----------|
| `chapter` | WordSenseOccurrence 有 chapter_id + sentence_id/sentence_hash，且 Chapter 存在且能找到目标句子 | true | ✅ | ✅ | ✅ | null | 不写 | 显示原文段落，目标词高亮 |
| `chapter_recovered` | WordSense/发生无 source_chapter_id，但 example_sentence_en 可在章节正文字中找到 | true | ✅ | ✅ | ✅ | `"已根据复习卡例句定位到原章节。"` | ✅ 写回 sense + occurrence | 显示原文段落，目标词高亮 |
| `chapter_title` | example_sentence_en 与章节标题匹配 | true | ✅ | ✅（合成） | ✅ | `"该例句来自章节标题。"` | ✅ 写回 sense + occurrence | 显示例句，标注来自标题 |
| `chapter_fuzzy` | example_sentence_en 模糊匹配章节正文（相似度≥阈值） | true | ✅ | ✅ | ✅ | `"已根据复习卡例句模糊定位到原文位置。"` | ✅ 写回 sense + occurrence | 显示模糊匹配的段落和原文 |
| `chapter_fuzzy_title` | example_sentence_en 模糊匹配章节标题 | true | ✅ | ✅（合成） | ✅ | `"已根据复习卡例句模糊定位到章节标题。"` | ✅ 写回 sense + occurrence | 显示例句，标注模糊匹配标题 |
| `card_example` | 无章节匹配，但有 example_sentence_en | true | null | null | ✅（合成） | ✅ | `"未找到原章节位置，以下为复习卡保存的例句。"` | 不写 | 显示复习卡保存的例句 |
| `null`（unavailable） | 无章节、无 example_sentence_en | false | null | null | [] | [] | `"暂无可用原文位置"` | 不写 | 显示"暂无可用原文位置" |

---

## 4. fallback 顺序

```
1. chapter (WordSenseOccurrence chapter_id + sentence_id/sentence_hash → 章节正文匹配)
2. chapter_recovered (example_sentence_en 在章节正文中精确匹配 → 写回)
3. chapter_title (example_sentence_en 匹配章节标题 → 写回)
4. chapter_fuzzy (example_sentence_en 模糊匹配 → 写回) [阈值: 含目标词 ≥ 0.55, 不含 ≥ 0.82, 短句 < 5词 ≥ 0.75]
5. chapter_fuzzy_title (example_sentence_en 模糊匹配章节标题 → 写回)
6. card_example (无章节匹配但有 example_sentence_en → 不写回)
7. unavailable (无任何数据 → 不写回)
```

---

## 5. 输出 JSON 契约

所有 source_kind 共用的稳定字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| `sense_id` | int | WordSense ID |
| `source_available` | bool | 是否有源上下文 |
| `source_kind` | string\|null | 见 §3 列表 |
| `chapter_id` | int\|null | 章节 ID |
| `chapter_title` | string\|null | 章节名称 |
| `sentence_id` | string\|null | 句子 ID/索引 |
| `sentence_hash` | string\|null | 句子 hash |
| `context_tokens` | array | token 数组 |
| `target_indexes` | array | int[]，目标词在 context_tokens 中的索引 |
| `fallback_message` | string\|null | 用户可见的 fallback 说明 |
| `debug` | array\|null | 仅在 `config('app.debug')=true` 且 fuzzy 匹配时出现 |

---

## 6. 查询 / 渲染边界

**查询层**（下一轮拆分时可独立）：
- `sourceContext()` — 查询 WordSense → WordSenseOccurrence → Chapter
- `sourceContextFromChapter()` — 解析章节 processedText → 定位句子
- `findSourceSentenceKey()` — 按 sentence_id / sentence_hash / 文本精确匹配
- `recoverSourceContextFromExampleSentence()` — 遍历章节精确匹配
- `recoverSourceContextByFuzzyMatch()` — 遍历章节模糊匹配
- `writeBackRecoveredSource()` — 写回 sense + occurrence 源字段

**渲染层**（下一轮拆分时可独立）：
- `syntheticSentenceTokens()` — 为无原文例句合成 token 结构
- `simplifyContextToken()` — 简化 token 结构
- `tokenMatchesSenseTarget()` — 判断 token 是否为目标词
- `contextEntriesAroundGroup()` — 获取目标句子周围 5 句

**下一轮拆分原则**：
- 查询层不负责 token payload 细节
- 渲染层不负责 DB 查询或写回
- 查询层返回原始数据 → 渲染层加工为前端所需结构

---

## 7. 写入边界

**写回的路径**：
- `recoverSourceContextFromExampleSentence` (chapter_recovered) → `writeBackRecoveredSource`
  - WordSense: `source_chapter_id`, `sentence_id`
  - WordSenseOccurrence: `chapter_id`, `sentence_id`
- `recoverSourceContextByFuzzyMatch` (chapter_fuzzy, chapter_fuzzy_title) → `writeBackRecoveredSource`
  - 同上

**绝对只读的路径**：
- `sourceContextFromChapter` (chapter) — 只读
- `fallbackCardExampleSourceContext` (card_example) — 只读
- `emptySourceContext` (unavailable) — 只读

**下一轮拆分时不得改变写入条件**。

---

## 8. 拆分已完成（SenseSourceContext-QueryRenderExtract-1）

### 新增的文件

- `app/Services/SenseSourceContextResolverService.php` — 查询/定位层

### 迁移到 Resolver 的逻辑

| 方法 | 目标 |
|------|------|
| `resolveSense()` / `resolveSourceOccurrence()` / `resolveExampleOccurrence()` | Resolver |
| `findChapterById()` | Resolver |
| `groupTokensBySentenceWithIndexes()` | Resolver |
| `findSourceSentenceKey()` | Resolver |
| `contextEntriesAroundGroup()` | Resolver |
| `findMatchingChapterByExampleText()` | Resolver |
| `findMatchingChapterByFuzzyMatch()` | Resolver |
| `writeBackRecoveredSource()` | Resolver |
| `meaningfulTextTokens()` / `targetTerms()` / `fuzzySourceScore()` | Resolver |
| `collectTargetIndexes()` / `logSourceContextResult()` | Resolver |

### 保留在 SenseSourceContextService 的逻辑

- `sourceContext()` public 入口（门面方法，委托 Resolver + Payload）
- 响应组装（`buildChapterResult`、`buildContextToken`）
- `buildContextToken()` 仍使用 SenseTokenPayloadService

### 保持不变的契约

- `sourceContext(int $userId, string $language, int $senseId): array` 签名不变
- API response JSON shape 不变
- `source_kind` 字符串不变（chapter/chapter_recovered/chapter_title/chapter_fuzzy/chapter_fuzzy_title/card_example/null）
- fallback 顺序不变
- `fallback_message` 文案不变
- `writeBackRecoveredSource` 写回条件不变
- fuzzy 阈值不变（含目标词≥0.55 不含≥0.82）
- `radius=5` 不变
- 用户/语言隔离不变

### 下一轮禁止做什么

【以下为历史保留，拆分已完成】

下轮禁止：
- 不改 Vue 组件（TextBlockGroup / VocabularySideBox / review 弹窗）
- 不改 `GET /senses/{id}/source-context` route
- 不改 Controller 返回值结构
- 不改 `WordSense` / `WordSenseOccurrence` 模型
- 不改 `ReviewCard` / `Chapter` 模型
- 不改阅读页跳转逻辑
- 不改复习页弹窗交互
- 不新增 migration

---

## 9. 下一轮禁止做什么

下轮禁止：
- 不改 Vue 组件（TextBlockGroup / VocabularySideBox / review 弹窗）
- 不改 `GET /senses/{id}/source-context` route
- 不改 Controller 返回值结构
- 不改 `WordSense` / `WordSenseOccurrence` 模型
- 不改 `ReviewCard` / `Chapter` 模型
- 不改阅读页跳转逻辑
- 不改复习页弹窗交互
- 不新增 migration

---

## 10. 已测试覆盖清单（26 个现有测试）

| 测试 | 覆盖 |
|------|------|
| `test_source_context_returns_context_tokens_from_occurrence` | chapter source + context_tokens |
| `test_source_context_keeps_sentence_id_zero_valid` | sentence_id=0 边界 |
| `test_source_context_can_match_by_example_sentence_text` | 按例句文本精确匹配 |
| `test_source_context_returns_unavailable_without_source` | unavailable + fallback_message |
| `test_source_context_cannot_access_other_user_sense` | 用户隔离 |
| `test_source_context_does_not_read_other_user_chapter` | 用户隔离 |
| `test_source_context_cannot_read_other_language_chapter` | 语言隔离 |
| `test_source_context_falls_back_to_card_example_without_chapter` | card_example fallback |
| `test_source_context_falls_back_to_card_example_when_chapter_missing` | card_example (chapter 不存在) |
| `test_source_context_recovers_chapter_by_example_sentence_text` | chapter_recovered |
| `test_source_context_recovers_chapter_from_chapter_title` | chapter_title |
| `test_source_context_keeps_card_example_when_recovery_fails` | card_example 兜底 |
| `test_source_context_recovered_chapter_payload_contains_sentence_id` | recovery sentence_id |
| `test_source_context_marks_source_sentence_tokens` | is_source_sentence 标记 |
| `test_source_context_expands_to_surrounding_sentences` | 上下文范围 |
| `test_source_context_fuzzy_recovers_sentence_with_punctuation_differences` | fuzzy recover |
| `test_source_context_fuzzy_recovers_title_when_chapter_name_not_exact` | fuzzy chapter_title |
| `test_source_context_fuzzy_does_not_cross_user_or_language` | fuzzy 隔离 |
| `test_source_context_fuzzy_falls_back_to_card_example_when_score_low` | fuzzy 低分回退 |
| `test_source_context_fuzzy_recovers_walmart_punctuation` | fuzzy 标点差异 |
| `test_source_context_fuzzy_recovers_bricks_from_similar_chapter_title` | fuzzy title |
| `test_source_context_bureau_realistic_sentence_includes_context` | 上文下句 |
| `test_source_context_low_score_unrelated_chapter_falls_back_to_card_example` | 低分回退 |
| `test_source_context_api_still_works` | API endpoint |
| `test_source_context_response_contains_required_keys` | response shape |
| `test_management_page_data_route_does_not_trigger_log` | 管理页不触发日志 |

### 本轮补充测试

| 测试 | 覆盖 |
|------|------|
| `test_chapter_recovered_writes_back_source_fields` | writeBackRecoveredSource 确认写回 |
| `test_card_example_does_not_write_back_to_sense` | card_example 不写回 |
| `test_unavailable_structure_is_stable` | unavailable 字段值精确 |
