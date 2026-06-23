# Task C：合并复习入口 — 实现计划（v3 终版）

> 日期：2026-06-23 | 状态：可执行 | 依赖：Task A + Task B 已完成

---

## 一、源码现状

### 1.1 两个复习入口对比

| 维度 | 单词复习 | 词义复习 |
|---|---|---|
| 前端路由 | `/review/:practiceMode?/:bookId?/:chapterId?` | `/reviews/senses` |
| 前端组件 | `Review.vue`（665行）| `SenseReview.vue`（146行） |
| 侧边栏 | "单词复习"→打开StartReviewDialog | "词义复习"→直接导航 |
| 后端接口 | `POST /reviews` | `GET /reviews/senses` |
| 评分接口 | `POST /reviews/rate` | `POST /reviews/senses/{id}/rate` |
| Controller | `ReviewController` | `SenseReviewController` |
| Service | `ReviewService::getReviewItems()` | `SenseReviewService::dueCards()` |
| target_type | `word` | `sense` |
| 排序 | `inRandomOrder()` 随机 | `fsrs_due_at` 升序 |
| 卡片结构 | EncounteredWord全字段+review_card_id+type | serializeCard() 18个字段 |

### 1.2 Word card payload（`ReviewService::getReviewItems()`）

`EncounteredWord` 全字段通过 `select('encountered_words.*')` 返回，关键字段：`id, word, base_word, reading, stage, translation, review_card_id, type='word'`

### 1.3 Sense card payload（`SenseReviewService::serializeCard()`）

`serializeCard()` 是 **public** 方法，可直接复用。返回字段：

```
review_card_id, word_sense_id, lemma, surface_form, pos,
sense_zh, sense_en, aliases_zh, collocations,
example_sentence_en, example_sentence_zh,
fsrs_state, fsrs_due_at, fsrs_stability, fsrs_difficulty,
fsrs_reps, fsrs_lapses
```

### 1.4 共用部分

ReviewCard 模型、ReviewLog 模型、`ReviewCardService::recordReview()`、`FsrsSchedulingService::schedule()`、评分按钮（again/hard/good/easy）全部共用。前端评分组件各自独立。

### 1.5 前端 Review.vue 当前分支

- `type == 'word'`：正面 base_word→word+例句，背面 word+translation+例句
- `type == 'phrase'`：正面短语词列表
- **没有** `type == 'sense'` 分支

### 1.6 StartReviewDialog 参数传递

`bookId=-1` 表示全部书籍，`chapterId=-1` 表示全部章节。`ReviewService::getReviewItems()` 仅在 `bookId !== -1 || chapterId !== -1` 时按章节过滤 word cards（第 65-96 行）。

### 1.7 侧边栏入口

`Layout.vue` 第 144-161 行：

```js
{ name: '单词复习', url: '', click: this.openStartReviewDialog, icon: 'mdi-playlist-check' },
{ name: '词义确认', url: '/senses/review', icon: 'mdi-check-decagram' },
{ name: '词义复习', url: '/reviews/senses', icon: 'mdi-brain' },
```

---

## 二、实现方案

### 2.1 核心思路

改造 `ReviewService::getReviewItems()` 在全局复习模式（`bookId=-1 && chapterId=-1`）下同时拉取 sense due cards。复用 `SenseReviewService::serializeCard()` 序列化 sense card。前端 `Review.vue` 新增 `type == 'sense'` 分支渲染。

### 2.2 关键设计决策

| 决策 | 理由 |
|---|---|
| 仅在全局模式混入 sense | 限定 book/chapter 时 sense 无法可靠绑定章节，混入会误导用户 |
| 复用 `serializeCard()` | 不重复字段定义，保证 `/reviews` 和 `/reviews/senses` 返回结构一致 |
| 在查询层过滤坏 sense | 只查 confirmed、user_id+language 匹配的 sense，不在前端遇错 |
| 整体 shuffle | 保持现有随机策略，不引入复杂优先级 |

### 2.3 后端改动

**`ReviewService::getReviewItems()`**

在方法开头先做类型转换（前端可能传字符串 `"-1"`）：

```php
$bookId = (int) $bookId;
$chapterId = (int) $chapterId;
```

在 `return $reviews` 之前（第 105 行后）新增：

```php
// 仅在全局普通到期复习模式混入 sense cards
// 约束1：save SenseReviewService 到局部变量，不反复 app()
// 约束2：bookId/chapterId 已转为 int，严格判断
// 约束3：仅 !$practiceMode 时混入（练习模式不混入）
if (!$practiceMode && $bookId === -1 && $chapterId === -1) {
    $senseReviewService = app(SenseReviewService::class);
    $senseCards = $senseReviewService->dueCards($userId, $language);

    foreach ($senseCards as $card) {
        $serialized = $senseReviewService->serializeCard($card);
        $serialized['type'] = 'sense';
        $reviews[] = (object) $serialized;
    }

    // 统一随机
    shuffle($reviews);
}
```

**设计要点**：
- `(int)` 类型转换：前端可能传字符串 `"-1"`，严格 `===` 比较前先转 int
- `!$practiceMode`：练习模式不混入 sense（`practiceMode=true` 时忽略到期时间，语义不符）
- `$senseReviewService` 局部变量：不在 foreach 里反复 `app()`
- 复用 `SenseReviewService::dueCards()` — 已过滤 confirmed、user_id、language、fsrs_enabled、due_at
- 复用 `SenseReviewService::serializeCard()` — 与 `/reviews/senses` 返回结构完全一致
- `(object)` 转换：让 `$reviews` 中 word 和 sense 都是对象，前端 `reviews[i].type` 统一访问
- `shuffle()` 保持随机策略
- 限定 book/chapter 或练习模式时**不混入** sense

**评分接口**：`POST /reviews/rate` 不改动。`ReviewCardService::recordReview()` 已根据 `target_type` 校验可复习性。

**保留路由**：`GET /reviews/senses` 和 `POST /reviews/senses/{id}/rate` 不做任何修改。

### 2.4 前端改动

**`Review.vue` — 正面（sense 卡片，不显示答案）**

```html
<template v-if="reviews[currentReviewIndex].type == 'sense'">
    <div class="selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
        <div class="text-h6 mb-2">{{ reviews[currentReviewIndex].lemma }}</div>
        <div class="text--secondary mb-3">
            {{ reviews[currentReviewIndex].surface_form || reviews[currentReviewIndex].lemma }}
            <span v-if="reviews[currentReviewIndex].pos"> / {{ reviews[currentReviewIndex].pos }}</span>
        </div>
        <v-sheet outlined rounded class="pa-3 mt-2">
            <div class="default-font">
                {{ reviews[currentReviewIndex].example_sentence_en || '（回忆这个词义）' }}
            </div>
        </v-sheet>
        <div class="text-caption text--secondary mt-2">
            这里的 {{ reviews[currentReviewIndex].lemma }} 是什么意思？
        </div>
    </div>
</template>
```

**`Review.vue` — 背面（sense 卡片，显示答案）**

```html
<template v-if="reviews[currentReviewIndex].type == 'sense'">
    <div class="selected-font" :style="{'font-size': (settings.fontSize) + 'px'}">
        <div class="text-h6">{{ reviews[currentReviewIndex].lemma }}</div>
        <div class="text--secondary mb-2">
            <span v-if="reviews[currentReviewIndex].pos">{{ reviews[currentReviewIndex].pos }}</span>
        </div>
        <hr>
        <!-- 中文释义优先，降级到英文释义，再降级到"暂无释义" -->
        <div v-if="reviews[currentReviewIndex].sense_zh" class="mb-3" style="font-size: 24px; font-weight: 600;">
            {{ reviews[currentReviewIndex].sense_zh }}
        </div>
        <div v-else-if="reviews[currentReviewIndex].sense_en" class="mb-3" style="font-size: 24px; font-weight: 600;">
            {{ reviews[currentReviewIndex].sense_en }}
        </div>
        <div v-else class="mb-3" style="font-size: 20px; color: #999;">
            暂无释义
        </div>
        <!-- 英文释义：中文释义存在时作为补充 -->
        <div v-if="reviews[currentReviewIndex].sense_zh && reviews[currentReviewIndex].sense_en" class="mb-2">
            {{ reviews[currentReviewIndex].sense_en }}
        </div>
        <v-sheet outlined rounded class="pa-3 mb-3">
            <div class="default-font">
                {{ reviews[currentReviewIndex].example_sentence_en || '（无例句）' }}
            </div>
            <div v-if="reviews[currentReviewIndex].example_sentence_zh" class="text--secondary mt-1">
                {{ reviews[currentReviewIndex].example_sentence_zh }}
            </div>
        </v-sheet>
    </div>
</template>
```

**辅助方法适配**：
- `next()`：`type !== 'sense'` 时跳过例句API加载
- `countReadWords()`：`type == 'sense'` 时 return
- `textToSpeech()`：`type == 'sense'` 时用 `lemma`

`rateReview()` 方法**不改**——`POST /reviews/rate` 接受 `reviewCardId` + `rating`，不区分 target_type。

### 2.5 侧边栏改动

`Layout.vue`：注释"词义复习"入口，保留路由可直访：

```js
// 词义复习入口已合并到单词复习，保留注释供日后恢复
// { name: '词义复习', url: '/reviews/senses', icon: 'mdi-brain', bottomNav: false },
```

---

## 三、排序策略

- 全局普通到期模式（`!practiceMode && bookId=-1 && chapterId=-1`）：word + sense 混合后统一 `shuffle()` 随机
- 练习模式（`practiceMode=true`）：不混入 sense，word 保持原逻辑
- 限定模式（book/chapter 指定）：不混入 sense，word 保持原 `inRandomOrder()`
- 不做复杂优先级，不改变 FSRS 算法

---

## 四、兼容与降级策略

| 场景 | 策略 |
|---|---|
| 已有 word review | 不受影响，卡片渲染逻辑不变 |
| 已有 sense review | `/reviews/senses` 保留，SenseReview.vue 保留，可直访 |
| review_cards / logs | 不迁移 |
| book/chapter 限定 | 不混入 sense（避免全局 sense 出现在章节复习中） |
| FSRS 评分 | word+sense 走同一 `recordReview()` |
| sense 缺少 sentence_en | 正面："（回忆这个词义）"；背面："（无例句）" |
| sense 缺少 sense_zh | 背面用 sense_en 替代 |
| sense 两者都缺 | 背面显示"暂无释义" |
| sense 缺少 sense_en | 背面隐藏英文释义行 |
| sense 缺少 pos | 隐藏词性行 |
| sense 被归档/不存在 | 查询层已过滤 confirmed，不会进入队列 |

---

## 五、涉及文件

| 层级 | 文件 | 改动 |
|---|---|---|
| 后端 | `app/Services/ReviewService.php` | 全局模式混入 sense + shuffle |
| 前端 | `resources/js/components/Review/Review.vue` | 正面+背面 sense 分支 + 跳过例句/字数/TTS |
| 前端 | `resources/js/components/Layout.vue` | 注释"词义复习"入口 |
| 测试 | `tests/Feature/ReviewFsrsTest.php` | 新增 9 个测试用例 |

**不改动**：SenseReviewService、SenseReviewController、ReviewCardService、FsrsSchedulingService、SenseReview.vue、routes/web.php、app.js。

---

## 六、测试计划

### 6.1 新增测试：`tests/Feature/ReviewFsrsTest.php`

**用例 1**：`test_review_queue_returns_due_sense_cards_in_global_mode`
- 创建 confirmed WordSense + sense review_card（due）
- `POST /reviews`（bookId=-1, chapterId=-1, practiceMode=false）
- 断言：reviews 包含 `type='sense'` 的卡片
- 断言：payload 包含 `review_card_id, lemma, sense_zh, sense_en, example_sentence_en`

**用例 2**：`test_review_queue_returns_word_and_sense_cards_mixed`
- 创建 1 个 due word card + 1 个 due sense card
- `POST /reviews`（bookId=-1, chapterId=-1）
- 断言：reviews 包含 2 张卡片，一张 `type='word'` 一张 `type='sense'`

**用例 3**：`test_review_queue_does_not_return_sense_in_chapter_mode`
- 创建 due sense card
- `POST /reviews`（bookId=1, chapterId=1）
- 断言：reviews 中无 `type='sense'` 卡片

**用例 4**：`test_review_queue_filters_out_archived_sense`
- 创建 archived/rejected sense + due review_card
- `POST /reviews`（bookId=-1, chapterId=-1）
- 断言：reviews 中无该 sense

**用例 5**：`test_review_queue_filters_out_other_user_sense`
- 以 userA 创建 sense，以 userB 身份请求
- 断言：reviews 中无 userA 的 sense

**用例 6**：`test_review_queue_filters_out_future_sense`
- sense card `fsrs_due_at > now()`
- 断言：reviews 中无该 sense

**用例 7**：`test_rate_sense_card_updates_fsrs_and_writes_log`
- 创建 sense card
- `POST /reviews/rate`（reviewCardId + rating='good'）
- 断言：`fsrs_reps=1`, `fsrs_due_at` 更新
- 断言：`ReviewLog` 写入，`source='review'`

**用例 8**：`test_rate_sense_card_cannot_cross_user`
- 以 userA 创建 sense card，userB 调用评分
- 断言：请求不成功（按现有系统可能是 500）
- 断言：`review_card` 没有被 userB 更新
- 断言：没有写入 userB 的 `review_log`
- **注意**：不修改现有错误码体系，按现状断言

**用例 9**：`test_sense_card_payload_matches_serialize_card`
- 调用 `serializeCard()` 和 `POST /reviews` 返回的 sense card
- 断言：字段一致（review_card_id, lemma, sense_zh, sense_en, example_sentence_en 等）

### 6.2 浏览器验收

1. 准备 New 词 → 添加手动释义 → 确认 stage=-7
2. DB 验证 word review_card 和 sense review_card 存在
3. 进入"单词复习"（全局模式）→ 看到 word card 正常
4. 翻到 sense card → 正面：lemma + pos + 例句，**无中文释义**
5. 正面显示"这里的 X 是什么意思？"
6. 点击"显示答案" → 背面：中文释义（大号）+ 英文释义（补充）+ 例句
7. 缺少 sentence_en 的 sense → 正面"（回忆这个词义）"
8. 缺少 sense_zh 的 sense → 背面显示 sense_en
9. 两者都缺 → 背面显示"暂无释义"
10. 对 sense card 评分 → 下一张正常
11. 侧边栏无"词义复习"入口 → `/reviews/senses` 可直访
12. Task A/B 不受影响

### 6.3 回归测试

```bash
php artisan test --filter=ReviewFsrsTest
php artisan test --filter=FsrsSchedulingServiceTest
php artisan test --filter=WordSense
php artisan test --filter=Vocabulary
npm run development
php artisan db:doctor
php artisan tokenizer:doctor --language=english
```

---

## 七、风险点

| 风险 | 等级 | 缓解 |
|---|---|---|
| sense 上 `word` 字段 undefined | 高 | `v-if type=='sense'` 分支不访问 word 字段 |
| `next()` 调不存在的例句API | 高 | `type !== 'sense'` 跳过 |
| `countReadWords()` 无 words 字段 | 中 | `type == 'sense'` 时 return |
| 限定章节时混入全局 sense | 中 | `bookId=-1 && chapterId=-1` 严格判断，已转 int |
| 练习模式混入 sense | 中 | `!$practiceMode` 严格判断，练习模式不混入 |
| `app()` 反复调用 | 低 | `$senseReviewService` 局部变量保存 |
| `(object)` 转换导致数组方法丢失 | 低 | 前端只读属性，不调数组方法 |
| 隐藏侧边栏时误删"词义确认" | 低 | 只注释"词义复习" |

---

## 八、不应在本任务中做

1. ❌ 不删除 `/reviews/senses` 路由或 SenseReview.vue
2. ❌ 不修改 SenseReviewController、ReviewCardService、FsrsSchedulingService
3. ❌ 不修改 word card 渲染逻辑
4. ❌ 不修改侧边栏"词义确认"入口
5. ❌ 不做阅读中点词前弹出到期 sense card（Task D）
6. ❌ 不重构 Review.vue 组件结构
7. ❌ 不引入复杂排序优先级
8. ❌ 不修改 FSRS 算法

---

## 九、实现顺序

1. `ReviewService.php`：注入 SenseReviewService + 全局模式混入 sense + shuffle
2. `Review.vue`：正面 sense 分支（lemma + 例句 + 提示问题，无答案）
3. `Review.vue`：背面 sense 分支（中文释义 + 英文释义 + 例句 + 降级）
4. `Review.vue`：next/countReadWords/textToSpeech 适配
5. `Layout.vue`：注释"词义复习"入口
6. `ReviewFsrsTest.php`：新增 9 个测试用例
7. 运行测试 → `npm run development` → 浏览器验收

---

## 十、commit 建议

```bash
git add -A
git commit -m "feat: merge sense cards into word review flow

- Backend: ReviewService mixes sense cards in global review mode
- Reuse SenseReviewService::serializeCard() for consistent payload
- Only mix in sense when bookId=-1 && chapterId=-1 (global mode)
- Query-level filtering: confirmed, due, user/language scope
- Frontend: Review.vue adds type='sense' branch for front/back
- Sense card front: lemma + sentence + question, hides answer
- Sense card back: sense_zh > sense_en > '暂无释义' degrade
- Sidebar: hide '词义复习' entry, keep /reviews/senses accessible
- Tests: 9 new cases covering sense queue, rate, archive, cross-user
- No router changes, no FSRS changes, no old data migration"
```

---

## 十一、保护清单

- Word Review 原有逻辑不受影响
- `/reviews/senses` 保留可直访
- 不破坏 Task A（添加释义后自动标记 Learning 7）
- 不破坏 Task B（例句断句修复）
- 不改 FSRS 算法、tokenizer、study_base、lemma、ECDICT
- 英文导入、阅读页、点词侧栏、词典不受影响
- GPT sense-mapping workflow 不受影响
- `php artisan test` 独立测试数据库