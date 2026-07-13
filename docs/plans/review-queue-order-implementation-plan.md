# Review Queue Order Implementation Plan

> **面向 AI 代理的工作者：** 必需子技能：使用 superpowers:test-driven-development 逐任务实现此计划。步骤使用复选框（`- [ ]`）语法来跟踪进度。

**目标：** 实现 ADR-0015 定义的 Review Queue Order Policy，让 `/reviews` 和 `/reviews/senses` 共用同一确定性排序策略，移除 `ReviewService::shuffle()`，统一评分后取下一张卡的逻辑。

**架构：** 新增 `ReviewQueueOrderPolicy`（纯函数，分桶 + 排序）和 `ReviewQueueOrderService`（单一排序入口）。`SenseReviewService::dueCardsWithLimits` 调用 Policy 分桶后按桶裁剪 daily limits，再 concat。`ReviewService::shuffle()` 删除。`/reviews/rate` 增加 `next_card` 字段。前端 `Review.vue` 改用 `next_card` 替代 `Math.random`。

**技术栈：** Laravel PHP + Vue 2 + Vuetify + PHPUnit + Node.js assert guard tests

**关联 ADR：** `docs/adr/ADR-0015-review-queue-order-policy.md`

**状态：** 本文档是第二阶段（`Anki-Queue-Order-Development-2000-9B`）的实施计划。本文档创建时（`2000-9A`）**开发未开始**。

---

## 当前调用链（Before）

### `/reviews` (POST)

```
POST /reviews
  → ReviewController::getReviewItems()
  → ReviewService::getReviewItems()
      ├── SenseReviewService::dueCardsWithLimits()  (orderBy fsrs_due_at ASC, id ASC; known first, new last)
      ├── serialize each card
      ├── shuffle($reviews)   ← 破坏后端顺序
      └── return { reviews, summary }
```

前端 `Review.vue` 评分后：`Math.floor(Math.random() * this.reviews.length)` 选下一张。

### `/reviews/senses` (GET)

```
GET /reviews/senses
  → SenseReviewController::index()
  → SenseReviewService::dueCardsWithLimits()  (同上，known first, new last)
  → serializeMany()  (保持顺序)
  → return { cards, summary }
```

前端 `SenseReview.vue` 评分后：后端返回 `next_card`，但前端调 `loadCards()` 重载整个队列。

---

## 目标调用链（After）

### `/reviews` (POST) 和 `/reviews/senses` (GET) 共用

```
Controller
  → SenseReviewService::dueCardsWithLimits($userId, $language, $ignoreDailyLimits)
      ├── dueCards()  (existing: orderBy fsrs_due_at ASC, id ASC)
      ├── reviewedTodayCount()  (existing)
      ├── ReviewQueueOrderPolicy::assignBuckets($dueCards)  (new: pure function, 5 buckets)
      ├── apply daily limits per-bucket  (existing slice logic, now bucket-aware)
      └── concat buckets in priority order → visibleCards
  → ReviewQueueOrderService::order($visibleCards)  (new: single sort entry, stable)
  → serialize
  → return { cards/reviews, summary, queue_meta? }
```

### `/reviews/rate` (POST) 和 `/reviews/senses/{id}/rate` (POST) 共用

```
Controller::rate()
  ├── ReviewCardService::recordReview()  (existing)
  ├── SenseReviewService::dueCardsWithLimits()  (re-run, now Policy-ordered)
  ├── next_card = visibleCards->first()
  └── return { reviewed_card, next_card, summary, action? }
```

前端：`Review.vue` 和 `SenseReview.vue` 都用 `response.data.next_card`，不再 `Math.random`，不再冗余 `loadCards()`。

---

## 文件结构

### 新增文件

| 文件 | 职责 |
|------|------|
| `app/Services/ReviewQueueOrderPolicy.php` | 纯函数：`assignBuckets(Collection): array`，给每张卡分配 `bucket` (1-5) 和 `rank`。无 DB、无 I/O。 |
| `app/Services/ReviewQueueOrderService.php` | 单一排序入口：`order(Collection): Collection`，调用 Policy 后按 bucket+rank 稳定排序。 |
| `tests/Feature/ReviewQueueOrderTest.php` | 后端 Feature 测试：分桶、排序、daily limits 交互、两入口一致、next_card、不写 ReviewLog/FSRS/lifecycle。 |
| `tests/Unit/ReviewQueueOrderPolicyTest.php` | 后端 Unit 测试：Policy 纯函数的每个桶边界和 tie-breaker。 |
| `tests/js/ReviewQueueOrderGuard.test.mjs` | 前端 source-code guard 测试：无 shuffle、无 Math.random、next_card 使用、stale guard。 |

### 修改文件

| 文件 | 修改内容 |
|------|----------|
| `app/Services/SenseReviewService.php` | `dueCardsWithLimits()` 在 `dueCards()` 后调用 `ReviewQueueOrderPolicy::assignBuckets()`，按桶应用 daily limits，按桶优先级 concat。 |
| `app/Services/ReviewService.php` | 删除 `shuffle($reviews)`（line 41）。`/reviews` 现在返回 Policy 排序后的顺序。 |
| `app/Http/Controllers/ReviewController.php` | `rateReviewCard()` 增加 `next_card` 字段（调用 `dueCardsWithLimits()->first()`）。 |
| `resources/js/components/Review/Review.vue` | 评分后用 `response.data.next_card` 替代 `Math.random`；增加 stale response guard（请求序号）。 |
| `resources/js/components/Senses/SenseReview.vue` | 评分后直接用 `response.data.next_card`，不再冗余调 `loadCards()`（可选优化，不强制）。 |

### 禁止修改文件

- `app/Models/ReviewCard.php`（scope 不改）
- `app/Services/ReviewCardService.php`（recordReview 不改）
- `app/Services/SettingsService.php`（设置不改）
- `app/Services/ReviewLimitSummaryService.php`（纯 builder 不改）
- `app/Services/SenseReviewQueryService.php`（隔离查询不改）
- `routes/web.php`（路由不改）
- 任何 ADR-0010/0011/0012/0013/0014 相关文件
- 任何 migration 文件

---

## 任务分解

### 任务 1：ReviewQueueOrderPolicy 纯函数（TDD）

**文件：**
- 创建：`app/Services/ReviewQueueOrderPolicy.php`
- 测试：`tests/Unit/ReviewQueueOrderPolicyTest.php`

- [ ] **步骤 1：编写失败的 Unit 测试**

```php
// tests/Unit/ReviewQueueOrderPolicyTest.php
<?php

namespace Tests\Unit;

use App\Models\ReviewCard;
use App\Services\ReviewQueueOrderPolicy;
use Carbon\Carbon;
use Tests\TestCase;

class ReviewQueueOrderPolicyTest extends TestCase
{
    private $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ReviewQueueOrderPolicy();
    }

    public function test_assigns_relearning_bucket()
    {
        $now = Carbon::now();
        $card = new ReviewCard(['fsrs_state' => 'relearning', 'fsrs_due_at' => $now->copy()->subHour(), 'id' => 1]);
        $result = $this->policy->assignBuckets(collect([$card]), $now);
        $this->assertEquals(ReviewQueueOrderPolicy::BUCKET_RELEARNING, $result[0]['bucket']);
        $this->assertEquals(0, $result[0]['rank']);
    }

    public function test_assigns_learning_bucket()
    {
        $now = Carbon::now();
        $card = new ReviewCard(['fsrs_state' => 'learning', 'fsrs_due_at' => $now->copy()->subHour(), 'id' => 2]);
        $result = $this->policy->assignBuckets(collect([$card]), $now);
        $this->assertEquals(ReviewQueueOrderPolicy::BUCKET_LEARNING, $result[0]['bucket']);
    }

    public function test_assigns_overdue_review_bucket()
    {
        $now = Carbon::now();
        $card = new ReviewCard(['fsrs_state' => 'review', 'fsrs_due_at' => $now->copy()->subDays(3), 'id' => 3]);
        $result = $this->policy->assignBuckets(collect([$card]), $now, 'UTC');
        $this->assertEquals(ReviewQueueOrderPolicy::BUCKET_OVERDUE_REVIEW, $result[0]['bucket']);
    }

    public function test_assigns_today_review_bucket()
    {
        $now = Carbon::now();
        $card = new ReviewCard(['fsrs_state' => 'review', 'fsrs_due_at' => $now->copy()->subMinutes(30), 'id' => 4]);
        $result = $this->policy->assignBuckets(collect([$card]), $now, 'UTC');
        $this->assertEquals(ReviewQueueOrderPolicy::BUCKET_TODAY_REVIEW, $result[0]['bucket']);
    }

    public function test_assigns_new_bucket()
    {
        $now = Carbon::now();
        $card = new ReviewCard(['fsrs_state' => 'new', 'fsrs_due_at' => $now->copy()->subHour(), 'id' => 5]);
        $result = $this->policy->assignBuckets(collect([$card]), $now);
        $this->assertEquals(ReviewQueueOrderPolicy::BUCKET_NEW, $result[0]['bucket']);
    }

    public function test_relearning_before_learning()
    {
        $now = Carbon::now();
        $learning = new ReviewCard(['fsrs_state' => 'learning', 'fsrs_due_at' => $now->copy()->subHour(), 'id' => 1]);
        $relearning = new ReviewCard(['fsrs_state' => 'relearning', 'fsrs_due_at' => $now->copy()->subHour(), 'id' => 2]);
        $result = $this->policy->assignBuckets(collect([$learning, $relearning]), $now);
        $this->assertEquals(2, $result[0]['card']->id); // relearning first
        $this->assertEquals(1, $result[1]['card']->id); // learning second
    }

    public function test_overdue_sorted_by_duration_desc()
    {
        $now = Carbon::now();
        $lessOverdue = new ReviewCard(['fsrs_state' => 'review', 'fsrs_due_at' => $now->copy()->subDay(), 'id' => 1]);
        $moreOverdue = new ReviewCard(['fsrs_state' => 'review', 'fsrs_due_at' => $now->copy()->subDays(5), 'id' => 2]);
        $result = $this->policy->assignBuckets(collect([$lessOverdue, $moreOverdue]), $now, 'UTC');
        $this->assertEquals(2, $result[0]['card']->id); // more overdue first
    }

    public function test_id_asc_tiebreaker()
    {
        $now = Carbon::now();
        $cardA = new ReviewCard(['fsrs_state' => 'new', 'fsrs_due_at' => $now, 'id' => 10]);
        $cardB = new ReviewCard(['fsrs_state' => 'new', 'fsrs_due_at' => $now, 'id' => 5]);
        $result = $this->policy->assignBuckets(collect([$cardA, $cardB]), $now);
        $this->assertEquals(5, $result[0]['card']->id); // lower id first
    }

    public function test_no_db_queries()
    {
        $now = Carbon::now();
        $cards = collect([
            new ReviewCard(['fsrs_state' => 'new', 'fsrs_due_at' => $now, 'id' => 1]),
        ]);
        $queries = $this->getQueryCount(fn() => $this->policy->assignBuckets($cards, $now));
        $this->assertEquals(0, $queries);
    }
}
```

- [ ] **步骤 2：运行测试验证失败**

运行：`php artisan test --filter=ReviewQueueOrderPolicyTest`
预期：FAIL，`Class App\Services\ReviewQueueOrderPolicy not found`

- [ ] **步骤 3：编写 Policy 实现**

```php
<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReviewQueueOrderPolicy
{
    public const BUCKET_RELEARNING = 1;
    public const BUCKET_LEARNING = 2;
    public const BUCKET_OVERDUE_REVIEW = 3;
    public const BUCKET_TODAY_REVIEW = 4;
    public const BUCKET_NEW = 5;

    public function assignBuckets(Collection $cards, Carbon $now, ?string $timezone = 'UTC'): array
    {
        $todayStart = Carbon::today($timezone);

        $annotated = $cards->map(function (ReviewCard $card) use ($now, $todayStart) {
            return [
                'card' => $card,
                'bucket' => $this->bucketFor($card, $now, $todayStart),
                'overdue_duration' => $card->fsrs_due_at ? $now->diffInSeconds($card->fsrs_due_at) : 0,
            ];
        });

        return $annotated
            ->sortBy([
                ['bucket', 'asc'],
                ['overdue_duration', 'desc'],
                ['card.fsrs_due_at', 'asc'],
                ['card.id', 'asc'],
            ])
            ->values()
            ->map(fn ($item, $index) => [
                'card' => $item['card'],
                'bucket' => $item['bucket'],
                'rank' => $index,
            ])
            ->all();
    }

    private function bucketFor(ReviewCard $card, Carbon $now, Carbon $todayStart): int
    {
        $state = $card->fsrs_state;
        $dueAt = $card->fsrs_due_at;

        if ($state === 'relearning') return self::BUCKET_RELEARNING;
        if ($state === 'learning') return self::BUCKET_LEARNING;
        if ($state === 'new') return self::BUCKET_NEW;

        // review state
        if ($dueAt && $dueAt < $todayStart) return self::BUCKET_OVERDUE_REVIEW;
        return self::BUCKET_TODAY_REVIEW;
    }
}
```

- [ ] **步骤 4：运行测试验证通过**

运行：`php artisan test --filter=ReviewQueueOrderPolicyTest`
预期：PASS

- [ ] **步骤 5：Commit**

```bash
git add app/Services/ReviewQueueOrderPolicy.php tests/Unit/ReviewQueueOrderPolicyTest.php
git commit -m "feat: add review queue order policy pure function"
```

---

### 任务 2：ReviewQueueOrderService 单一排序入口

**文件：**
- 创建：`app/Services/ReviewQueueOrderService.php`
- 测试：`tests/Feature/ReviewQueueOrderTest.php`（部分）

- [ ] **步骤 1-5：TDD 创建 Service**

`ReviewQueueOrderService::order(Collection $cards, Carbon $now, ?string $tz): Collection` — 调用 Policy，返回排序后的 Collection。纯函数，无 DB。

---

### 任务 3：SenseReviewService::dueCardsWithLimits 集成 Policy

**文件：**
- 修改：`app/Services/SenseReviewService.php`
- 测试：`tests/Feature/ReviewQueueOrderTest.php`

- [ ] **步骤 1：编写 Feature 测试（分桶 + daily limits 交互）**

测试覆盖：
- relearning 卡在 learning 卡之前
- learning 卡在 overdue review 卡之前
- overdue review 卡在 today review 卡之前
- today review 卡在 new 卡之前
- overdue 卡按逾期时长 DESC 排序
- daily review limit 裁剪 known 桶（1-4），new 桶不受影响（newIgnoreReviewLimit=true）
- daily new limit 裁剪 new 桶
- ignoreDailyLimits=true 返回全部，顺序仍由 Policy 决定
- 两入口 `/reviews` 和 `/reviews/senses` 返回相同顺序
- 查询次数不随卡片数增长

- [ ] **步骤 2-5：TDD 修改 dueCardsWithLimits**

在 `dueCards()` 后调用 `assignBuckets()`，按桶分组，按桶优先级应用 slice，concat。

---

### 任务 4：移除 ReviewService::shuffle()

**文件：**
- 修改：`app/Services/ReviewService.php`
- 测试：`tests/Feature/ReviewQueueOrderTest.php`

- [ ] **步骤 1：编写测试（/reviews 返回 Policy 顺序，非随机）**

```php
public function test_reviews_endpoint_returns_policy_order_not_shuffled()
{
    // 创建 3 张卡：relearning, learning, new
    // POST /reviews
    // 断言 response.data.reviews[0] 是 relearning 卡
    // 断言 response.data.reviews[1] 是 learning 卡
    // 断言 response.data.reviews[2] 是 new 卡
}
```

- [ ] **步骤 2：运行验证失败**

- [ ] **步骤 3：删除 shuffle($reviews)**

```php
// app/Services/ReviewService.php
// 删除 line 41: shuffle($reviews);
```

- [ ] **步骤 4：运行验证通过**

- [ ] **步骤 5：Commit**

---

### 任务 5：/reviews/rate 增加 next_card

**文件：**
- 修改：`app/Http/Controllers/ReviewController.php`
- 测试：`tests/Feature/ReviewQueueOrderTest.php`

- [ ] **步骤 1：编写测试（/reviews/rate 返回 next_card = Policy 排序后第一张）**

- [ ] **步骤 2-5：TDD 修改 rateReviewCard**

```php
// ReviewController::rateReviewCard()
$card = $this->reviewCardService->recordReview(...);
$result = $this->senseReviewService->dueCardsWithLimits($userId, $language, $ignoreDailyLimits);
$nextCard = $result['cards']->first();

return response()->json([
    'card' => $card,
    'next_card' => $nextCard ? $this->senseReviewCardSerializerService->serialize($nextCard) : null,
    'summary' => $result['summary'],
], 200);
```

---

### 任务 6：前端 Review.vue 改用 next_card

**文件：**
- 修改：`resources/js/components/Review/Review.vue`
- 测试：`tests/js/ReviewQueueOrderGuard.test.mjs`

- [ ] **步骤 1：编写前端 guard 测试**

```javascript
// tests/js/ReviewQueueOrderGuard.test.mjs
import assert from 'assert';
import fs from 'fs';
const source = fs.readFileSync('resources/js/components/Review/Review.vue', 'utf-8');

test('no Math.random for next card selection', () => {
    const nextMethod = extractMethod(source, 'next()');
    assert.ok(!nextMethod.includes('Math.random'), 'must not use Math.random for next card');
});

test('uses response.data.next_card', () => {
    assert.ok(source.includes('response.data.next_card'), 'must use next_card from response');
});

test('no shuffle call in ReviewService', () => {
    const svc = fs.readFileSync('app/Services/ReviewService.php', 'utf-8');
    assert.ok(!svc.includes('shuffle('), 'ReviewService must not call shuffle');
});
```

- [ ] **步骤 2-5：TDD 修改 Review.vue**

移除 `Math.floor(Math.random() * this.reviews.length)`，改用 `response.data.next_card`。增加请求序号 stale guard。

---

### 任务 7：回归测试

- [ ] 运行 `php artisan test --filter=ReviewQueueOrderTest`
- [ ] 运行 `php artisan test --filter=ReviewQueueOrderPolicyTest`
- [ ] 运行 `php artisan test --filter=ReviewFsrsTest`
- [ ] 运行 `php artisan test --filter=SenseReviewDailyLimitsTest`
- [ ] 运行 `php artisan test --filter=ReviewCardLifecycleQueueTest`
- [ ] 运行 `php artisan test --filter=ReviewCardManageTest`
- [ ] 运行 `php artisan test --filter=SenseReviewLeech`
- [ ] 运行 `php artisan test --filter=ReviewCardBrowserSearch`
- [ ] 运行 `php artisan test --filter=ReviewCardInfoTest`
- [ ] 运行 `npm run development`
- [ ] 运行 `php artisan db:doctor`
- [ ] 运行前端 guard 测试 `node --test tests/js/ReviewQueueOrderGuard.test.mjs`

---

### 任务 8：MCP Chrome 真实验收

- [ ] 登录（账号 1816529781@qq.com）
- [ ] 打开 `/review/sense`，Network 确认 GET `/reviews/senses` 请求
- [ ] 确认队列顺序：relearning → learning → overdue review → today review → new
- [ ] 评分一张卡，确认 next_card 是 Policy 排序后第一张
- [ ] 打开 `/review`（legacy），确认 POST `/reviews` 返回相同顺序
- [ ] 评分一张卡，确认使用 next_card（无 Math.random）
- [ ] 快速连续评分两张卡，确认 stale response 不覆盖
- [ ] 1920×1080 渲染正常
- [ ] 900×900 渲染正常，无水平溢出
- [ ] Console 无新增错误
- [ ] Network 无外部 AI 请求
- [ ] ReviewLog 数量前后一致（评分会增加，但 detail 请求不增加）
- [ ] FSRS 快照前后一致（评分会改变被评卡，但未评卡不变）
- [ ] lifecycle 快照前后一致

---

## 测试矩阵

### 后端 Unit 测试（ReviewQueueOrderPolicyTest）

| 测试 | 锁定行为 |
|------|----------|
| assigns_relearning_bucket | fsrs_state=relearning → bucket 1 |
| assigns_learning_bucket | fsrs_state=learning → bucket 2 |
| assigns_overdue_review_bucket | fsrs_state=review + due < today → bucket 3 |
| assigns_today_review_bucket | fsrs_state=review + due today → bucket 4 |
| assigns_new_bucket | fsrs_state=new → bucket 5 |
| relearning_before_learning | bucket 1 卡在 bucket 2 卡之前 |
| learning_before_review | bucket 2 卡在 bucket 3/4 卡之前 |
| overdue_before_today_review | bucket 3 卡在 bucket 4 卡之前 |
| review_before_new | bucket 3/4 卡在 bucket 5 卡之前 |
| overdue_sorted_by_duration_desc | 逾期时长 DESC |
| today_review_sorted_by_due_at_asc | fsrs_due_at ASC |
| new_sorted_by_id_asc | id ASC |
| id_asc_tiebreaker | 同桶同 due_at 时 id ASC |
| no_db_queries | Policy 是纯函数，0 DB 查询 |
| timezone_aware | today 边界用用户时区 |

### 后端 Feature 测试（ReviewQueueOrderTest）

| 测试 | 锁定行为 |
|------|----------|
| /reviews/senses_returns_policy_order | GET 端点返回 relearning→learning→overdue→today→new |
| /reviews_returns_same_order_as_senses | POST 端点与 GET 端点顺序一致 |
| no_shuffle_in_reviews_endpoint | /reviews 不再随机 |
| daily_review_limit_caps_known_buckets | review limit 裁剪 bucket 1-4 |
| daily_new_limit_caps_new_bucket | new limit 裁剪 bucket 5 |
| new_ignore_review_limit | newIgnoreReviewLimit=true 时 new 不受 review limit 约束 |
| ignore_daily_limits_preserves_order | ignoreDailyLimits=true 返回全部，顺序仍由 Policy 决定 |
| rate_returns_next_card_policy_order | /reviews/senses/{id}/rate 的 next_card 是 Policy 第一张 |
| reviews_rate_returns_next_card | /reviews/rate 返回 next_card（新增字段） |
| next_card_is_deterministic | 同一状态两次请求 next_card 相同 |
| no_review_log_written_on_get | GET 队列不写 ReviewLog |
| no_fsrs_change_on_get | GET 队列不改 FSRS |
| no_lifecycle_change_on_get | GET 队列不改 lifecycle |
| user_isolation | 其他用户的卡不出现 |
| language_isolation | 其他语言的卡不出现 |
| sense_only | legacy word card 不出现 |
| archived_excluded | archived 卡不出现 |
| suspended_excluded | suspended 卡不出现 |
| buried_not_expired_excluded | buried 未到期不出现 |
| buried_expired_included | buried 已到期出现 |
| query_count_constant | 查询次数不随卡片数增长 |
| old_logs_endpoint_unchanged | /logs 端点合同不回归 |
| old_leech_endpoint_unchanged | /leech 端点合同不回归 |

### 前端 Guard 测试（ReviewQueueOrderGuard.test.mjs）

| 测试 | 锁定行为 |
|------|----------|
| no_math_random_in_next | Review.vue next() 无 Math.random |
| uses_next_card_from_response | Review.vue 用 response.data.next_card |
| no_shuffle_in_review_service | ReviewService.php 无 shuffle( |
| stale_guard_exists | 请求序号守卫存在 |
| sense_review_uses_cards_zero | SenseReview.vue 仍取 cards[0] |

---

## MCP Chrome 验收矩阵

| 步骤 | 项目 |
|------|------|
| 1 | 登录成功 |
| 2 | 打开 /review/sense |
| 3 | GET /reviews/senses 请求发出 |
| 4 | 队列顺序正确（relearning→learning→overdue→today→new） |
| 5 | 评分一张卡 |
| 6 | next_card 是 Policy 第一张 |
| 7 | 打开 /review（legacy） |
| 8 | POST /reviews 返回相同顺序 |
| 9 | 评分一张卡，使用 next_card（无 Math.random） |
| 10 | 快速连续评分两张卡，stale guard 生效 |
| 11 | 1920×1080 渲染正常 |
| 12 | 900×900 渲染正常，无水平溢出 |
| 13 | Console 无新增错误 |
| 14 | Network 无外部 AI 请求 |
| 15 | ReviewLog 前后一致（除评分新增外） |
| 16 | FSRS 快照前后一致（除评分卡外） |
| 17 | lifecycle 快照前后一致 |

---

## 回滚方案

1. Revert feat commit（恢复 shuffle，移除 Policy/Service）
2. Revert docs commit（移除 ADR-0015 和本计划）
3. 无 migration、无 schema change、无 FSRS/lifecycle/ReviewLog change — 纯代码 revert
4. 旧 `/logs`、`/lifecycle-events`、`/leech` 端点不受影响
5. 旧 daily limits 行为不受影响

---

## 第二阶段允许修改文件

| 文件 | 允许 |
|------|------|
| `app/Services/ReviewQueueOrderPolicy.php` | 新增 |
| `app/Services/ReviewQueueOrderService.php` | 新增 |
| `app/Services/SenseReviewService.php` | 修改 dueCardsWithLimits |
| `app/Services/ReviewService.php` | 删除 shuffle |
| `app/Http/Controllers/ReviewController.php` | rateReviewCard 增加 next_card |
| `resources/js/components/Review/Review.vue` | 改用 next_card + stale guard |
| `resources/js/components/Senses/SenseReview.vue` | 可选优化（用 next_card 替代 loadCards） |
| `tests/Unit/ReviewQueueOrderPolicyTest.php` | 新增 |
| `tests/Feature/ReviewQueueOrderTest.php` | 新增 |
| `tests/js/ReviewQueueOrderGuard.test.mjs` | 新增 |
| `docs/adr/ADR-0015-review-queue-order-policy.md` | 已在 2000-9A 创建 |
| `docs/plans/review-queue-order-implementation-plan.md` | 本文档 |
| `docs/plans/current-working-handoff.md` | 更新 |
| `docs/plans/linguacafe-master-plan.md` | 更新 |
| `docs/DOCUMENTATION_INDEX.md` | 更新 |

## 第二阶段禁止范围

- 不新增 migration
- 不改 FSRS 算法/参数/调度
- 不改 ReviewLog schema 或历史日志
- 不改 lifecycle 状态机（ADR-0010）
- 不改 Leech Policy（ADR-0011）
- 不改 Browser Search 语法（ADR-0012/0013 frozen）
- 不调外部 AI provider
- 不新增 deck/preset 系统
- 不实现用户自定义排序（V2 候选）
- 不实现 Custom Study / Saved Search / today-only limits / Study Overview
- 不在 Controller 或 Vue 中复制分桶逻辑
- 不在 Policy 内做 per-card DB 查询
- 不写 ReviewLog
- 不改 lifecycle 状态
- 不改 FSRS 字段
- 不用 Math.random 选下一张卡

---

## 未决产品问题

1. **`queue_meta` 响应字段是否在 V1 返回？** ADR-0015 标记为 optional。如果设计师希望前端显示 "5 张学习中, 10 张逾期" 汇总，则 V1 包含；否则延后。
2. **`SenseReview.vue` 评分后是否停止冗余 `loadCards()`？** 后端已返回 `next_card`，前端可直接用。但停止 `loadCards()` 会改变 summary 刷新时机。设计师需确认是否接受。
3. **`Review.vue`（legacy）是否继续维护？** ADR-0015 让它共用 Policy，但 legacy 页面长期可能被废弃。设计师需确认 V1 是否投入精力改 Review.vue，还是只改 SenseReview.vue。
4. **overdue 边界用用户时区还是 UTC？** ADR-0015 建议用户时区（与 ADR-0010 bury time 一致）。确认。
5. **如果用户没有 due 的 relearning/learning 卡，overdue review 是否立即开始？** 是 — 空桶跳过，下一个非空桶立即生效。确认。
6. **同一 fsrs_due_at 的多张 review 卡，是否需要基于 lapses/stability 二次排序？** V1 不做 — id ASC 已足够稳定。V2 候选。
