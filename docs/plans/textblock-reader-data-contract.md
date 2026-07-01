# TextBlockService Reader Data 输出契约

> **契约锁定日期**：2026-07-01
> **基准 commit**：`e43861d`
> **契约性质**：本轮只锁定契约和补测试，不提取 ReaderDataService，不改业务逻辑。

---

## 1. 目标

1. 锁定 TextBlockService 中 `prepareTextForReader()` / `getReaderData()` 的输出契约。
2. 标记哪些方法是只读的、可以安全迁移到 ReaderDataService。
3. 标记哪些方法是写入的、不能迁移。
4. 补 characterization tests 确保下一轮提取前后行为不变。
5. 不新增 `app/Services/ReaderDataService.php`。
6. 不改 TextBlockService 业务逻辑。
7. 不改阅读页 Vue 组件。

---

## 2. 当前调用链

### 主调用链

```
POST /chapters/get/reader
  → ChapterController::getReader()
    → ChapterService::getReaderData()
      → new TextBlockService($userId, $language)       # ChapterService:186
      → TextBlockService::setProcessedWords($words)      # ChapterService:187
      → TextBlockService::collectUniqueWords()           # ChapterService:188
      → TextBlockService::prepareTextForReader()         # ChapterService:189
      → TextBlockService::indexPhrases()                 # ChapterService:190
      → (returns $data object directly, not getReaderData) # ChapterService:192-207
```

### 次要调用链（VocabularyService）

```
VocabularyService::getExampleSentenceReaderData()
  → new TextBlockService($userId, $language)            # VocabularyService:616
  → TextBlockService::setProcessedWords()               # VocabularyService:617
  → TextBlockService::uniqueWords = json_decode()       # VocabularyService:618
  → TextBlockService::prepareTextForReader()             # VocabularyService:619
  → TextBlockService::indexPhrases()                     # VocabularyService:620
  → TextBlockService::getReaderData()                    # VocabularyService:622
```

### 关键发现

1. **ChapterService::getReaderData() 不使用 getReaderData()**：它直接读取 `$textBlock->words`、`$textBlock->uniqueWords`、`$textBlock->phrases` 等 public 属性，在外部拼装 `$data` 对象（ChapterService:192-207）。
2. **VocabularyService::getExampleSentenceReaderData() 使用 getReaderData()**：调用 `$textBlock->getReaderData()` 获取标准结构。
3. **两个调用链都依赖 `prepareTextForReader()`** 来填充 `$this->words`、`$this->uniqueWords` 属性。

---

## 3. 当前输出结构

### ChapterService::getReaderData() 返回结构

```json
{
  "type": "text",
  "subtitleTimestamps": [],
  "words": [ /* see below */ ],
  "uniqueWords": [ /* see below */ ],
  "phrases": [],
  "bookName": "...",
  "chapterId": 1,
  "chapterName": "...",
  "bookId": 1,
  "language": "english",
  "languageSpaces": true,
  "chapters": [ /* chapter navigation list */ ],
  "wordCount": 100
}
```

### words[] 单个元素结构（TextBlockService 填充）

| 字段 | 来源 | 说明 |
|------|------|------|
| `word` | tokenizer | 原始词形 |
| `lemma` | tokenizer | 词元 |
| `pos` | tokenizer | 词性 |
| `sentence_index` | tokenizer | 所属句子索引 |
| `phrase_ids` | tokenizer | 所属短语 ID 列表 |
| `selected` | prepareTextForReader | 前端暂存，默认 false |
| `hover` | prepareTextForReader | 前端暂存，默认 false |
| `spaceAfter` | prepareTextForReader | 语言/标点决定的中间空格 |
| `id` | encountered_words | EncounteredWord ID（null 如果不存在） |
| `stage` | `fwLookup` 或 encountered_words | FSRS 熟悉度级别（负数）或旧 SRS stage |
| `lookup_count` | encountered_words | 查词次数 |
| `furigana` | encountered_words | 假名/读音 |
| `phraseStage` | prepareTextForReader | 固定 'learning' |
| `phraseStart` | prepareTextForReader | 短语开始标记 |
| `phraseEnd` | prepareTextForReader | 短语结束标记 |
| `phraseIndexes` | prepareTextForReader | 短语索引列表 |
| `subtitleIndex` | prepareTextForReader | 字幕索引（默认 -1） |
| `fsrs_familiarity_score` | FW lookup | 可选：FSRS 熟悉度得分（0.0-1.0） |
| `fsrs_familiarity_level_10` | FW lookup | 可选：FSRS 10 档等级（1-10） |
| `fsrs_familiarity_percent` | FW lookup | 可选：FSRS 百分比（10-100） |

### uniqueWords[] 单个元素结构

| 字段 | 来源 | 说明 |
|------|------|------|
| `id` | encountered_words | EncounteredWord ID |
| `word` | encountered_words | 词形 |
| `stage` | encountered_words | 原始 stage（负数表示学习系统） |
| `lookup_count` | encountered_words | 查词次数 |
| `read_count` | encountered_words | 阅读次数 |
| `definitions_checked` | prepareTextForReader | 前端暂存标记 |
| `fsrs_familiarity_score` | FW lookup | 可选 |
| `fsrs_familiarity_level_10` | FW lookup | 可选 |
| `fsrs_familiarity_percent` | FW lookup | 可选 |
| `fsrs_familiarity_has_data` | prepareTextForReader | bool：是否有 FSRS 数据 |

---

## 4. 只读 / 写入边界

### 只读方法（可安全迁移到 ReaderDataService）

| 方法 | 说明 | 是否只读 |
|------|------|----------|
| `collectUniqueWords()` | 从 `$this->processedWords` 提取唯一词（内存操作） | ✅ 只读（不写 DB） |
| `prepareTextForReader()` | 填充 `$this->words`、查询 encountered_words、查询 FSRS familiarity | ✅ 只读（不写 DB） |
| `getReaderData()` | 返回 `{words, uniqueWords, phrases}` 对象 | ✅ 只读（不写 DB） |
| `loadFsrsFamiliarityLookup()` | private 只读查询 word_senses + review_cards | ✅ 只读 |
| `indexPhrases()` | 给 words 添加短语标记（内存操作 + 查询 phrases 表） | ✅ 只读 |

### 写入方法（不能迁移到 ReaderDataService）

| 方法 | 说明 | 是否写入 |
|------|------|----------|
| `tokenizeRawText()` | 调用 python tokenizer HTTP 服务 | ⚠️ 外部调用 |
| `createNewEncounteredWords()` | 批量创建 EncounteredWord 记录 | ❌ 写 DB |
| `processTokenizedWords()` | 处理 token 数据 | ⚠️ 内存操作+外部依赖 |
| `updateAllPhraseIds()` / `updatePhraseIds()` | 更新短语 ID | ❌ 写 DB |

### ReaderDataService 下一轮只能迁移的纯只读方法

- `prepareTextForReader()` → 移到 ReaderDataService
- `loadFsrsFamiliarityLookup()` → 移到 ReaderDataService（private 或 protected）
- `collectUniqueWords()` → 移到 ReaderDataService
- `getReaderData()` → 移到 ReaderDataService

---

## 5. FSRS 熟悉度契约

### loadFsrsFamiliarityLookup

**读取的表**：
- `word_senses`（JOIN `encountered_words`）
- `encountered_words`
- `review_cards`（JOIN `word_senses.id = review_cards.target_id`）

**筛选条件**：
- `word_senses.user_id` = 当前用户
- `word_senses.language` = 当前语言
- `word_senses.status` = CONFIRMED
- `encountered_words.stage` < 0（学习系统的词）
- `review_cards.target_type` = TARGET_SENSE

**不筛选**：
- 不检查 `fsrs_enabled`（已归档的卡片仍然计算熟悉度）
- 不检查 `fsrs_state`（new/review/learning 状态的处理在 PHP 计算中）

**输出结构**（keyed by encountered_word_id）：

```php
[
  $encounteredWordId => [
    'level_10' => int (1-10),  // 10-tier familiarity
    'level'    => int (1-7),   // backward-compat 7-tier
    'score'    => float (0.0-1.0), // normalised stability
  ]
]
```

**契约**：
- 不修改 `review_cards`（只读查询）。
- 不修改 `word_senses`。
- 不改变 `due_at` / `state` / `stability` / `difficulty`。
- 不创建 `review_logs`。
- 不创建 `EncounteredWord`。

---

## 6. 前端兼容契约

1. **下一轮提取 ReaderDataService 后，前端看到的 JSON shape 必须不变**。
2. **ChapterService 构建的 `$data` 对象字段不能改名**（words/uniqueWords/phrases/bookName/chapterId/chapterName/bookId/language/languageSpaces/chapters/wordCount）。
3. **words[] 元素字段不能改名**（尤其 stage/spaceAfter/selected/hover/fsrs_familiarity_* 等 TextBlockGroup.vue 直接读取的字段）。
4. **uniqueWords[] 元素字段不能改名**。
5. **Phrases 数据不能改变格式**。
6. TextReader / TextBlockGroup Vue 组件完全不动。
7. API route `/chapters/get/reader` 的输入输出完全不动。
8. tokenizer 输出字段（word/lemma/pos/sentence_index/phrase_ids）完全不动。

---

## 7. 下一轮 ReaderDataService 提取边界

下一轮正式提取时**只允许**：

| 操作 | 允许 |
|------|------|
| 新增 `app/Services/ReaderDataService.php` | ✅ |
| 从 TextBlockService 迁移 `prepareTextForReader` | ✅ |
| 迁移 `loadFsrsFamiliarityLookup` | ✅ |
| 迁移 `collectUniqueWords` | ✅ |
| 迁移 `getReaderData` | ✅ |
| TextBlockService 保留原 public 方法做委托 | ✅（直到确认无外部调用） |
| 修改 ChapterService 改用 ReaderDataService | ✅ |
| 修改 VocabularyService 改用 ReaderDataService 做 reader data 生成 | ✅ |
| 补 MCP Chrome 验收阅读页无变化 | ✅ |

**下一轮禁止**：

| 操作 | 禁止 |
|------|------|
| 修改 `getReaderData` 输出结构 | ❌ |
| 修改 tokenizer 输出字段 | ❌ |
| 修改 import 链路（`createNewEncounteredWords`、`tokenizeRawText`） | ❌ |
| 修改 Vue 组件 | ❌ |
| 修改 API route | ❌ |
| 修改 `loadFsrsFamiliarityLookup` 的 FSRS 计算逻辑 | ❌ |

---

## 8. 已有测试清单

### ReaderFsrsHighlightTest 已有覆盖（本轮之前）

| 测试 | 覆盖点 |
|------|--------|
| `test_reader_returns_fsrs_familiarity_for_learning_word` | FSRS familiarity 基础输出 |
| `test_fsrs_familiarity_level_10_range` | level_10 范围 1-10 |
| `test_high_stability_cards_get_higher_highlight_level` | 高稳定性 |
| `test_low_stability_cards_get_lower_highlight_level` | 低稳定性 |
| `test_overdue_cards_get_penalty` | 过期扣减 |
| `test_new_state_cards_get_level_1` | new 状态 |
| `test_word_without_word_sense_uses_old_stage` | 无 WordSense |
| `test_word_without_review_card_keeps_old_stage` | 无 ReviewCard |
| `test_new_word_stage_2_is_not_affected` | 新词 stage>=0 不受影响 |
| `test_known_word_stage_0_is_not_affected` | 已知词 stage=0 不受影响 |
| `test_word_sense_without_review_card_keeps_old_stage` | WordSense 但无 ReviewCard |
| `test_reader_does_not_create_review_log` | 只读不创建日志 |
| `test_reader_does_not_modify_encountered_word_stage` | 不修改 encountered_word |
| `test_reader_does_not_modify_review_card_due_at` | 不修改 review_card |
| `test_words_array_contains_fsrs_familiarity_fields` | 字段完整性 |
| `test_language_isolation` | 语言隔离 |
| `test_ai_reading_assist_endpoints_are_untouched` | AI assist 不受影响 |

### 本轮新增测试

| 测试 | 覆盖点 |
|------|--------|
| `test_reader_data_contains_core_top_level_fields` | 顶层 fields（words/uniqueWords/phrases/bookName 等） |
| `test_reader_words_object_has_expected_fields` | words[] 元素字段 |
| `test_reader_unique_words_has_expected_fields` | uniqueWords[] 元素字段 |
| `test_reader_does_not_create_review_cards_or_senses` | 不创建 review_cards 或 senses |
| `test_reader_does_not_modify_chapters` | 不修改 chapters |
| `test_archived_card_behavior` | `fsrs_enabled=false` 时依然有熟悉度 |
| `test_legacy_word_card_does_not_affect_fsrs_familiarity` | legacy word card 不影响 FSRS |
| `test_textblock_get_reader_data_returns_stdclass_with_core_properties` | 直接调用 getReaderData |
| `test_textblock_prepare_text_for_reader_is_read_only` | prepareTextForReader 只读确认 |

### 下一轮提取前必须全部通过的契约

1. **Reader data 顶层字段不变**：test_reader_data_contains_core_top_level_fields
2. **words 元素字段不变**：test_reader_words_object_has_expected_fields
3. **uniqueWords 元素字段不变**：test_reader_unique_words_has_expected_fields
4. **FSRS familiarity 计算不变**：ReaderFsrsHighlightTest 全部已有测试
5. **只读副作用不变**：不创建 review_cards/senses/review_logs，不修改 chapters
6. **legacy word card 仍被排除**：test_legacy_word_card_does_not_affect_fsrs_familiarity
7. **archived card 仍贡献熟悉度**：test_archived_card_behavior
8. **直接 getReaderData 返回结构不变**：test_textblock_get_reader_data_returns_stdclass_with_core_properties
