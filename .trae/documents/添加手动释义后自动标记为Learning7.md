# 添加手动释义后自动标记为 Learning 7 — 实现计划（v3 终版）

> 日期：2026-06-23
> 状态：可执行
> 依赖：无（纯增量，不改现有逻辑）

---

## 一、目标

用户在阅读页点击一个新词，手动添加一个 WordSense 释义后：

1. 继续创建 confirmed WordSense。
2. 继续创建 sense review_card。
3. 同时把当前 EncounteredWord 从 New 变成 Learning 7，即 stage = -7。
4. 确保 target_type = word 的 review_card 存在。
5. 当前阅读页正文 token 立即刷新为绿色。
6. 词汇页中该词显示等级 7。
7. `/senses/review` 不出现这条手动释义 pending。

---

## 二、根因分析

当前 `POST /senses/manual` 接口（`WordSenseService::createManualSense()`）已经：

- ✅ 创建 confirmed WordSense
- ✅ 创建 sense review_card（`createReviewCardForSense()` → `ensureSenseCard()`）
- ✅ 创建 WordSenseOccurrence

但**缺失**以下两步：

- ❌ 没有把 EncounteredWord 的 stage 改成 -7
- ❌ 没有创建 word review_card（`ensureWordCard()`）
- ❌ 前端不知道当前选中的词是哪个 EncounteredWord（Vuex store 中没有 `encounteredWordId`）

**根因**：`createManualSense()` 接口不感知 EncounteredWord，前端也未传递 `encountered_word_id`。

---

## 三、数据流确认

### 3.1 WordSense 表结构

`word_senses` 表已有 `encountered_word_id` 列（nullable），来自 `database/migrations/2026_06_17_000005_create_word_senses_table.php`。`WordSense` 模型 `$fillable` 中已包含 → **可以回填，但必须在归属校验通过之后。**

### 3.2 前端 token 数据流

`TextBlockGroup.vue` 中正文 token 颜色来自两个数组：

| 数组 | 结构 | 匹配方式 |
|---|---|---|
| `uniqueWords[]` | `{ id, word, stage, base_word, ... }` | `normalizeWordKey()` = `word.trim().toLowerCase()` |
| `words[]` | `{ word, stage, wordIndex, ... }` | `normalizeWordKey()` = `word.trim().toLowerCase()` |

`uniqueWordMap` 通过 `normalizeWordKey(word)` 映射 `uniqueWords` 索引。**必须复用 `normalizeWordKey()` 做匹配，不能散写 `.toLowerCase()`**，否则会破坏 `b20f668` 的大小写修复。

### 3.3 组件事件链

```
WordSensesList  →  emit  →  VocabularySideBox  →  emit  →  TextBlockGroup
                           VocabularyBox       →  emit  →  TextBlockGroup
```

`VocabularySideBox` 已有 `setStage(stage)` 方法（第 210 行），它直接 `$emit('setStage', stage)` 给 `TextBlockGroup`。

### 3.4 `createManualSense` 调用点

全局搜索确认：**仅 `SenseOccurrenceController.php:104` 一处调用**。改为返回 `array` 后只需修改 Controller 一处。

---

## 四、实现方案

**核心思路**：

1. 前端通过 Vuex + snapshot 将 `encountered_word_id` 安全传递到 `POST /senses/manual`。
2. 后端**先校验归属**（user_id + language），再决定是否更新 stage、创建 word card、回填 `encountered_word_id`。
3. 后端返回 `updated_word` 结构（含 id、stage、word、base_word、study_base）。
4. 前端收到响应后，通过事件链通知 `TextBlockGroup` 直接更新 `uniqueWords` 和 `words` 中对应 token 的 stage（使用 `normalizeWordKey()` 匹配），同时更新 Vuex store 供右侧面板显示。

### 状态机规则

| 当前 stage | 含义 | 行为 |
|---|---|---|
| 2 | New（未设置） | `setStage(-7)` + `ensureWordCard()` + 返回 `updated_word` |
| < 0 | 已在 Learning | **不改 stage**，但调用 `ensureWordCard()` 补齐历史缺卡（幂等），返回 `updated_word` 但标记 `stage_changed: false` |
| 0 | Known（已知） | 跳过 |
| 1 | Ignored（忽略） | 跳过 |

---

## 五、涉及文件

| 层级 | 文件 | 改动类型 |
|---|---|---|
| Vuex Store | `resources/js/vuex/VocabularyBox.js` | 新增字段 + mutation |
| 前端 | `resources/js/components/Text/TextBlockGroup.vue` | 新增 handler + 模板绑定 |
| 前端 | `resources/js/components/Text/WordSensesList.vue` | 新增映射 + snapshot + 请求参数 + 响应处理 + emit |
| 前端 | `resources/js/components/Text/VocabularySideBox.vue` | 新增事件转发 |
| 前端 | `resources/js/components/Text/VocabularyBox.vue` | 新增事件转发 |
| 后端 | `app/Http/Controllers/SenseOccurrenceController.php` | 新增请求参数验证 + 响应结构适配 |
| 后端 | `app/Services/WordSenseService.php` | 核心逻辑（先校验、再写） |
| 测试 | `tests/Feature/WordSenseTest.php` | 新增 9 个测试用例 |

**不需要改动的文件**：
- `app/Services/ReviewCardService.php` — 已有 `ensureWordCard()`，无需修改
- `app/Services/VocabularyService.php` — 已有 `updateWord()` 逻辑，无需修改
- `app/Models/EncounteredWord.php` — `setStage()` 方法无需修改
- `app/Models/ReviewCard.php` — 无需修改
- `routes/web.php` — 无需新增路由

---

## 六、后端改动计划

### 6.1 `SenseOccurrenceController::storeManualSense()`（第 87-111 行）

**改动 1**：在请求验证中新增 `encountered_word_id` 可选字段。

在第 87-99 行的验证数组中新增一行：
```php
'encountered_word_id' => ['nullable', 'integer'],
```

**改动 2**：调用 `createManualSense` 后，从其返回值中取出 `updated_word`，合并到响应中。

将第 104-110 行：
```php
$sense = $this->wordSenseService->createManualSense(
    Auth::user()->id,
    Auth::user()->selected_language,
    $data,
);

return response()->json($this->serializeSense($sense));
```

改为：
```php
$result = $this->wordSenseService->createManualSense(
    Auth::user()->id,
    Auth::user()->selected_language,
    $data,
);

$response = $this->serializeSense($result['sense']);
$response['updated_word'] = $result['updated_word'];

return response()->json($response);
```

### 6.2 `WordSenseService::createManualSense()`（第 109-134 行）

**核心改动**：先校验归属，再写数据。返回结构改为包含 `sense` 和 `updated_word`。

**修改后的完整方法**：

```php
public function createManualSense(int $userId, string $language, array $data): array
{
    return DB::transaction(function () use ($userId, $language, $data) {
        // 0. 先校验 encountered_word_id 归属（在任何写入之前）
        $encounteredWordId = Arr::get($data, 'encountered_word_id');
        $encounteredWord = null;
        if ($encounteredWordId) {
            $encounteredWord = \App\Models\EncounteredWord::where('id', (int) $encounteredWordId)
                ->where('user_id', $userId)
                ->where('language', $language)
                ->first();
        }

        // 1. 创建 sense
        $sense = $this->createSense([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => Arr::get($data, 'lemma'),
            'surface_form' => Arr::get($data, 'surface_form', Arr::get($data, 'lemma')),
            'pos' => Arr::get($data, 'pos'),
            'sense_zh' => Arr::get($data, 'sense_zh'),
            'sense_en' => Arr::get($data, 'sense_en'),
            'aliases_zh' => Arr::get($data, 'aliases_zh', []),
            'collocations' => Arr::get($data, 'collocations', []),
            'example_sentence_en' => Arr::get($data, 'sentence_en'),
            'example_sentence_zh' => Arr::get($data, 'sentence_zh'),
            'source_chapter_id' => Arr::get($data, 'chapter_id'),
            'sentence_id' => Arr::get($data, 'sentence_id'),
            'status' => WordSense::STATUS_CONFIRMED,
            // 仅在归属校验通过后才回填 encountered_word_id
            'encountered_word_id' => $encounteredWord ? $encounteredWord->id : null,
        ]);

        $card = $this->createReviewCardForSense($sense);
        $this->createManualOccurrence($sense, $card, $data);

        // 2. 自动标记 Learning + 确保 word card
        $updatedWord = null;
        if ($encounteredWord) {
            if ($encounteredWord->stage === 2) {
                // New (stage=2) → Learning 7
                $encounteredWord->setStage(-7);
                $encounteredWord->save();
                $this->reviewCardService->ensureWordCard($encounteredWord);

                $updatedWord = [
                    'id' => $encounteredWord->id,
                    'stage' => $encounteredWord->stage,
                    'word' => $encounteredWord->word,
                    'base_word' => $encounteredWord->base_word,
                    'study_base' => $encounteredWord->study_base,
                    'stage_changed' => true,
                ];
            } elseif ($encounteredWord->stage < 0) {
                // 已在 Learning：不改 stage，但利用 ensureWordCard 幂等性补齐历史缺卡
                $this->reviewCardService->ensureWordCard($encounteredWord);

                $updatedWord = [
                    'id' => $encounteredWord->id,
                    'stage' => $encounteredWord->stage,
                    'word' => $encounteredWord->word,
                    'base_word' => $encounteredWord->base_word,
                    'study_base' => $encounteredWord->study_base,
                    'stage_changed' => false,
                ];
            }
            // stage 0 (Known) / stage 1 (Ignored): 不更新 stage，不创建 word card
        }

        return [
            'sense' => $sense->fresh('reviewCard'),
            'updated_word' => $updatedWord,
        ];
    });
}
```

**关键设计理由**：

1. **先校验归属**：`EncounteredWord::where('id', ...)->where('user_id', $userId)->where('language', $language)->first()` 在事务**最前面**执行。id 不存在或不属于当前用户 → `$encounteredWord` 为 null → 所有自动操作跳过（sense 仍正常创建）。

2. **`encountered_word_id` 仅在校验通过后写入**：`'encountered_word_id' => $encounteredWord ? $encounteredWord->id : null`。不会把错误的 id 写入 sense。

3. **`stage === 2`**：仅 New 状态自动转 Learning 7。

4. **`stage < 0` 不跳过 ensureWordCard**：利用 `ensureWordCard()` 的 `firstOrCreate()` 幂等性，补齐历史上可能缺失的 word card（如旧数据只有 sense card 没有 word card）。`stage_changed: false` 告诉前端无需更新状态。

5. **返回 `updated_word` 结构**：由 Service 直接返回，Controller 不再需要 `find()`。

6. **在 `DB::transaction` 内执行**：原子性保证。

---

## 七、前端改动计划

### 7.1 Vuex Store：`resources/js/vuex/VocabularyBox.js`

**a) 在 `state` 中新增字段**（约第 22 行，`stage: 0,` 之后）：
```js
encounteredWordId: null,
```

**b) 在 `mutations` 中新增 mutation**（约第 119 行，`setStage` 附近）：
```js
setEncounteredWordId(state, value) {
    state.encounteredWordId = value;
},
```

**c) 在 `reset` 中新增一行**（约第 62 行，`state.stage = 2;` 之后）：
```js
state.encounteredWordId = null;
```

### 7.2 `TextBlockGroup.vue`

**改动 1**：在 `updateVocabBoxDataAfterSelection()` 中设置 `encounteredWordId`。

在第 1323 行 `this.$store.commit('vocabularyBox/setStage', uniqueWord.stage);` 之后新增：
```js
this.$store.commit('vocabularyBox/setEncounteredWordId', uniqueWord.id || null);
```

**改动 2**：在模板中新增事件监听。

`VocabularySideBox` 标签（约第 136 行）和 `VocabularyBox` 标签（约第 163 行）都新增：
```html
@word-learning-updated="onWordLearningUpdated"
```

**改动 3**：新增 `onWordLearningUpdated` 方法。

```js
// 从 WordSensesList 收到手动添加释义后自动学习完成的通知
onWordLearningUpdated(payload) {
    const { encounteredWordId, stage } = payload;
    if (!encounteredWordId || stage === null || stage === undefined) {
        return;
    }

    const targetWord = this.uniqueWords.find(w => w.id === encounteredWordId);
    if (!targetWord) {
        return;
    }

    // 使用 normalizeWordKey 匹配，保持与 b20f668 大小写修复一致
    const targetKey = this.normalizeWordKey(targetWord.word);

    // 更新 uniqueWords 中所有同名词的 stage
    for (let i = 0; i < this.uniqueWords.length; i++) {
        if (this.normalizeWordKey(this.uniqueWords[i].word) === targetKey) {
            this.uniqueWords[i].stage = stage;
        }
    }

    // 更新 words（正文 token）中所有同名词的 stage
    for (let i = 0; i < this.words.length; i++) {
        if (this.normalizeWordKey(this.words[i].word) === targetKey) {
            this.words[i].stage = stage;
        }
    }

    // 同步 Vuex store 供右侧面板显示
    this.$store.commit('vocabularyBox/setStage', stage);
},
```

**注意**：此方法**不调用 `saveWord()`**，因为后端在 `createManualSense` 中已经完成持久化。匹配使用 `normalizeWordKey()` 而非散写 `.toLowerCase()`，与 `b20f668` 保持一致。

### 7.3 `WordSensesList.vue`

**改动 1**：在 `data()` 的 `snapshot` 中新增 `encounteredWordId`。

```js
snapshot: {
    chapterId: null,
    sentenceIndex: null,
    sentenceText: '',
    encounteredWordId: null,  // 新增
},
```

**改动 2**：在 `computed` 的 `mapState` 中新增映射。

```js
computed: {
    ...mapState({
        chapterId: state => state.vocabularyBox.chapterId,
        sentenceIndex: state => state.vocabularyBox.sentenceIndex,
        sentenceText: state => state.vocabularyBox.sentenceText,
        encounteredWordId: state => state.vocabularyBox.encounteredWordId,  // 新增
    }),
    // ...
},
```

**改动 3**：在 `openAddForm()` 中保存 snapshot。

```js
this.snapshot = {
    chapterId: this.chapterId,
    sentenceIndex: this.sentenceIndex,
    sentenceText: this.sentenceText,
    encounteredWordId: this.encounteredWordId,  // 新增
};
```

**改动 4**：在 `createPayload()` 中使用 snapshot 优先。

```js
return {
    // ... 现有字段 ...
    encountered_word_id: this.snapshot?.encounteredWordId ?? this.encounteredWordId ?? null,
};
```

**改动 5**：在 `createSense()` 成功回调中处理响应并 emit 事件。

```js
axios.post('/senses/manual', this.createPayload(this.newForm))
    .then((response) => {
        this.message = '已保存新词义，并已创建词义复习卡。';

        // 处理自动标记 Learning 的结果
        const updatedWord = response.data.updated_word;
        if (updatedWord && updatedWord.id && updatedWord.stage !== null) {
            // 同步 Vuex store 供右侧面板
            this.$store.commit('vocabularyBox/setStage', updatedWord.stage);
            // 通知父组件更新正文 token 颜色
            this.$emit('word-learning-updated', {
                encounteredWordId: updatedWord.id,
                stage: updatedWord.stage,
            });
        }

        const pos = this.newForm.pos;
        this.closeAddForm();
        this.fetchSenses();
        // ... 其余不变
    })
```

### 7.4 `VocabularySideBox.vue`

**改动**：在模板中 `WordSensesList` 标签上新增事件监听，并转发。

在第 106 行：
```html
<word-senses-list
    ref="wordSensesList"
    v-if="type === 'word'"
    :study-base="studyBase"
    :base-word="baseWord"
    :lemma="baseWord || word"
    :surface="word"
    :word="word"
    :language="$props.language"
    :legacy-translation="translationText"
    @word-learning-updated="$emit('word-learning-updated', $event)"
/>
```

### 7.5 `VocabularyBox.vue`

**改动**：同样在 `WordSensesList` 标签上新增事件转发。

找到 `VocabularyBox.vue` 中 `WordSensesList` 的引用处，新增：
```html
@word-learning-updated="$emit('word-learning-updated', $event)"
```

---

## 八、测试计划

### 8.1 后端测试：`tests/Feature/WordSenseTest.php`

新增 9 个测试用例：

**用例 1**：`test_manual_sense_auto_sets_new_word_to_learning_7`
- 前置条件：创建 EncounteredWord（stage=2, New）
- 操作：`POST /senses/manual` 传入 `encountered_word_id`
- 断言：`EncounteredWord.stage` 变为 `-7`
- 断言：`ReviewCard` 表中存在 `target_type='word'` 且 `target_id` 匹配的 word card
- 断言：`WordSense.encountered_word_id` 被正确填充
- 断言：响应中 `updated_word.stage` 为 `-7`，`updated_word.stage_changed` 为 `true`
- 断言：手动创建的 sense 对应的 occurrence 不处于 pending（`/senses/review` 不出现）

**用例 2**：`test_manual_sense_does_not_overwrite_known_word`
- 前置条件：创建 EncounteredWord（stage=0, Known）
- 操作：`POST /senses/manual` 传入 `encountered_word_id`
- 断言：`EncounteredWord.stage` 仍为 `0`
- 断言：`ReviewCard` 表中 word card 数量不变
- 断言：响应中 `updated_word` 为 `null`

**用例 3**：`test_manual_sense_does_not_overwrite_ignored_word`
- 前置条件：创建 EncounteredWord（stage=1, Ignored）
- 操作：`POST /senses/manual` 传入 `encountered_word_id`
- 断言：`EncounteredWord.stage` 仍为 `1`
- 断言：`ReviewCard` 表中 word card 数量不变
- 断言：响应中 `updated_word` 为 `null`

**用例 4**：`test_manual_sense_does_not_duplicate_card_for_learning_word`
- 前置条件：创建 EncounteredWord（stage=-7, 已有 word card）
- 操作：`POST /senses/manual` 传入 `encountered_word_id`
- 断言：`ReviewCard` 表中 word card 数量不变（仍为 1，`ensureWordCard` 幂等）
- 断言：`EncounteredWord.stage` 仍为 `-7`
- 断言：响应中 `updated_word.stage` 为 `-7`，`updated_word.stage_changed` 为 `false`

**用例 5**：`test_manual_sense_ensures_word_card_for_learning_word_without_card`
- 前置条件：创建 EncounteredWord（stage=-5, **没有** word card）
- 操作：`POST /senses/manual` 传入 `encountered_word_id`
- 断言：`EncounteredWord.stage` 仍为 `-5`
- 断言：`ReviewCard` 表中现在存在 1 条 `target_type='word'` 的 card（补齐历史缺卡）
- 断言：响应中 `updated_word.stage` 为 `-5`，`updated_word.stage_changed` 为 `false`

**用例 6**：`test_manual_sense_without_encountered_word_id_does_not_error`
- 操作：`POST /senses/manual` 不传 `encountered_word_id`
- 断言：sense 正常创建，不报错（向后兼容）
- 断言：响应中 `updated_word` 为 `null`

**用例 7**：`test_manual_sense_with_other_user_encountered_word_id_graceful`
- 前置条件：创建用户 A 的 EncounteredWord（stage=2）
- 操作：以用户 B 身份调用 `POST /senses/manual`，传入用户 A 的 `encountered_word_id`
- 断言：sense 正常创建
- 断言：用户 A 的 word stage 不变
- 断言：`WordSense.encountered_word_id` 不指向用户 A 的 word（应为 null）
- 断言：响应中 `updated_word` 为 `null`

**用例 8**：`test_manual_sense_with_nonexistent_encountered_word_id_graceful`
- 操作：`POST /senses/manual` 传入不存在的 `encountered_word_id`（如 999999）
- 断言：sense 正常创建，不报错
- 断言：响应中 `updated_word` 为 `null`

**用例 9**：`test_manual_sense_null_encountered_word_id_graceful`
- 操作：`POST /senses/manual` 传入 `encountered_word_id = null`
- 断言：sense 正常创建
- 断言：响应中 `updated_word` 为 `null`

### 8.2 浏览器验收步骤

1. 导入一篇英文材料（含未学过的词）。
2. 打开阅读页，点击一个 New 词（白色/无高亮，stage=2）。
3. 右侧面板 → 词元释义 → 点击 "+ 添加新释义"。
4. 填写词性（如 verb）、中文释义（如"落下"）→ 点击"保存新释义"。
5. **验收点 1**：该词在阅读页正文中立即变为绿色（Learning 7 对应颜色），无需刷新页面。
6. **验收点 2**：右侧面板"普通词汇状态"中等级 7 按钮高亮。
7. **验收点 3**：右侧面板"词元释义"区域出现新保存的释义，显示"已保存"标签。
8. **验收点 4**：打开 `/vocabulary/search`，搜索该词，显示等级为 7。
9. **验收点 5**：数据库验证 `review_cards` 表中存在 `target_type='word'` 且 `target_id` 匹配的 word card。
10. **验收点 6**：数据库验证 `review_cards` 表中存在 `target_type='sense'` 的 sense card。
11. **验收点 7**：打开 `/senses/review`，没有出现新的 pending occurrence。
12. 对一个 Known 词（stage=0）添加释义 → 验证 stage 不变，颜色不变。
13. 对一个 Ignored 词（stage=1）添加释义 → 验证 stage 不变，颜色不变。
14. 对一个已在 Learning 的词（stage=-5）添加释义 → 验证 stage 不变，颜色不变，不创建重复 card。

### 8.3 回归测试

```bash
php artisan test --filter=ReviewFsrsTest
php artisan test --filter=FsrsSchedulingServiceTest
php artisan test --filter=WordSense
npm run development
```

---

## 九、风险点

| 风险 | 等级 | 缓解措施 |
|---|---|---|
| 前端传了别的用户的 `encountered_word_id` | 中 | 后端 `where('user_id', $userId)->where('language', $language)` 三重校验，归属不通过则 `$encounteredWord` 为 null，全部跳过 |
| `encounteredWordId` 在 Vuex 中为 null（如短语选择、新短语） | 低 | 后端 `encountered_word_id` 为可选参数，为 null 时跳过所有 auto-stage 逻辑 |
| 下拉框/菜单导致 Vuex store 被重置 | 中 | `WordSensesList` 已有 snapshot 机制，将 `encounteredWordId` 也放入 snapshot，提交 payload 时优先用 snapshot |
| 只更新 Vuex store 不更新正文 token | 高 | 通过 `$emit('word-learning-updated')` 事件链通知 `TextBlockGroup`，用 `normalizeWordKey()` 匹配更新 `uniqueWords[]` 和 `words[]` |
| 散写 `.toLowerCase()` 破坏大小写修复 | 中 | `onWordLearningUpdated()` 使用 `this.normalizeWordKey()` 而非手写 `.toLowerCase()` |
| `EncounteredWord.setStage()` 依赖 `reviewIntervals` 设置 | 低 | 该方法已有 fallback 逻辑（自动 seed 默认设置） |
| `WordSensesList` 在 `VocabularyBox` 和 `VocabularySideBox` 两个父组件中使用 | 低 | 两者都通过 Vuex 共享状态，且都在模板上绑定 `@word-learning-updated` |
| `createManualSense` 返回值从 `WordSense` 变为 `array` | 低 | 全局搜索确认仅 `SenseOccurrenceController` 一处调用 |
| `stage < 0` 但无 word card 的历史数据 | 中 | `ensureWordCard()` 幂等补齐，测试用例 5 覆盖 |

---

## 十、实现顺序

1. **Vuex Store**：`VocabularyBox.js` — 新增 `encounteredWordId` 字段和 mutation
2. **TextBlockGroup.vue**：新增 `setEncounteredWordId` 赋值 + 模板绑定 + `onWordLearningUpdated` 方法（使用 `normalizeWordKey()`）
3. **WordSensesList.vue**：snapshot 扩展 + `createPayload` 修改（snapshot 优先）+ `createSense` 响应处理 + emit
4. **VocabularySideBox.vue**：模板新增事件转发
5. **VocabularyBox.vue**：模板新增事件转发
6. **SenseOccurrenceController.php**：新增 `encountered_word_id` 验证 + 响应适配
7. **WordSenseService.php**：核心逻辑（先校验归属、再写数据、stage < 0 也调用 ensureWordCard、返回 `updated_word`）
8. **WordSenseTest.php**：编写 9 个测试用例
9. 运行测试，验证全部通过
10. 手动端到端测试（浏览器验收）

---

## 十一、commit 建议

```bash
git add -A
git commit -m "feat: auto-mark word as Learning 7 when adding manual sense

- Backend: validate encountered_word_id ownership before writing
- Backend: set stage 2→-7, ensureWordCard, fill missing historical cards
- Frontend: snapshot encounteredWordId to prevent dropdown reset
- Frontend: notify TextBlockGroup via event chain to update token colors
- Frontend: use normalizeWordKey() for consistent case matching
- Tests: 9 cases covering New/Learning/Known/Ignored/other-user/null"
```

---

## 十二、保护清单（不可破坏）

- 英文导入、tokenizer English fallback
- 阅读页 + 点词侧栏 + 词典
- Word Review / Sense Review 及对应测试
- GPT sense-mapping workflow
- 注册、登录、用户创建
- Pusher 本地降级
- `php artisan test` 必须使用独立测试数据库
- 保存 Learning 词不得因 settings/goals 缺失而崩溃
- 不破坏点击单词、拖选词组、右侧面板下拉框、添加释义、Ctrl+F 修复
- 不破坏 `b20f668` 大小写修复（`normalizeWordKey()` 统一匹配）