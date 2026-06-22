# NEXT_TASK — 阅读页点词显示已有词义

> 创建时间：2026-06-22
> 状态：待开始
> 依赖：无（纯增量，不改现有逻辑）

## 目标

点击阅读页单词时，侧栏在**词典结果之外**显示该 lemma 已保存的 word_senses。

- 有已有词义：显示列表（中文释义、英文释义、词性、状态）。
- 没有已有词义：显示“暂无已保存词义”。
- **只读**：不保存，不绑定 occurrence，不创建 review card。
- **不影响**词典结果、导入、tokenizer、Pusher、FSRS、注册。

## 不做什么

- 不保存、更新、删除 word_senses。
- 不创建 word_sense_occurrences。
- 不创建 review_cards。
- 不修改词典搜索逻辑。
- 不在阅读页高亮或标注 occurrence。
- 不提供“从词典保存为词义”（那是后续任务）。

## 已有基础设施

- `GET /senses/candidates?lemma=<lemma>` — 已存在，返回 lemma 的所有 word_senses（`routes/web.php:180`，`SenseOccurrenceController@candidates`，`WordSenseOccurrenceService::candidates()`）。
- Vuex `vocabularyBox.baseWord` — 已存储点击单词的 lemma/词元。
- `VocabularySideBox.vue` — 侧栏组件（约 196 行），含单词信息、释义输入框、词典搜索框、VocabularySearchBox 子组件。
- `VocabularyBox.vue` — 浮动弹窗组件（逻辑同侧栏）。

## 实现计划

### 1. 新增只读 API 路由（可选）

现有 `/senses/candidates` 已可用，默认使用 `Auth::user()->selected_language`。如果需要更明确的"阅读页词义"语义，可新增路由：

```php
// routes/web.php
Route::get('/reading/word-senses', [App\Http\Controllers\SenseOccurrenceController::class, 'candidates']);
```

但 `SenseOccurrenceController@candidates` 已经做了隔离检查和参数验证，可以直接复用。**建议直接使用现有路由**，避免新增冗余。

### 2. 侧栏组件改动

**`VocabularySideBox.vue`**（侧栏变体）：
- 在 `VocabularySearchBox` 之后新增 `<div class="word-senses-section">`。
- 监听 `baseWord` 变化，调用 `GET /senses/candidates?lemma=<baseWord>`。
- 显示 loading → 结果列表 / "暂无已保存词义"。
- 每行显示：`sense_zh`（中文释义）、`sense_en`（英文释义）、`pos`（词性标签）、`status` 标签（已确认 / AI 建议）。

**`VocabularyBox.vue`**（浮动弹窗变体）：
- 相同修改，或抽取共用小组件 `WordSensesList.vue`。

### 3. 抽取小组件（推荐）

新建 `resources/js/components/Text/WordSensesList.vue`：
- Props: `lemma` (String), `language` (String)
- Data: `senses` (Array), `loading` (Boolean), `loaded` (Boolean)
- Template: loading spinner → 结果列表 / 空态提示
- 两个父组件（SideBox / Box）各引入一个 `<word-senses-list>` 实例。

### 4. 对应测试

- Feature test：调用 `GET /senses/candidates?lemma=<test_lemma>`，验证返回结构。
- 已有 `WordSenseTest` 中 `candidates returns current user language senses for lemma` 已覆盖，**不需要新增后端测试**。
- 如时间允许，可加浏览器 smoke test（Playwright）。

## 预计改动文件

| 文件 | 改动类型 | 说明 |
|------|---------|------|
| `resources/js/components/Text/WordSensesList.vue` | **新增** | 抽取的 word_senses 只读列表组件 |
| `resources/js/components/Text/VocabularySideBox.vue` | 修改 | 引入 WordSensesList，放在 VocabularySearchBox 之后 |
| `resources/js/components/Text/VocabularyBox.vue` | 修改 | 同上 |
| `routes/web.php` | 可能不变 | 如复用 `/senses/candidates` 则不改 |

不需要改：
- `SenseOccurrenceController` — 不变。
- `WordSenseOccurrenceService` — 不变。
- 数据库 migration — 不变。
- `VocabularySearchBox.vue` — 不变。
- Vuex store — 不变。

## 验收标准

1. 点有已有词义的词 → 侧栏显示已有词义列表（中文释义、英文释义、词性、状态）。
2. 点没有已有词义的词 → 侧栏显示“暂无已保存词义”。
3. 词典结果区域仍正常显示。
4. 侧栏不无限 loading。
5. 不写入 `word_senses`、`word_sense_occurrences`、`review_cards`。
6. `php artisan test --filter=ReviewFsrsTest` 全绿。
7. `php artisan test --filter=WordSense` 全绿（50 个测试）。
8. `npm run development` 编译成功。

## 预估工作量

约 30-60 分钟：1 个新小组件 + 2 个已有组件各加 ~15 行引入代码。
