# ADR-0015: Review Queue Order Policy

**Status**: Accepted (architecture research complete; implementation NOT started — awaits separate task `Anki-Queue-Order-Development-2000-9B`)
**Date**: 2026-07-13
**Related**: `docs/adr/ADR-0008-sense-review-answer-interval-preview.md`, `docs/adr/ADR-0009-review-action-ledger-and-stack-undo.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`, `docs/adr/ADR-0014-review-card-info-read-model.md`

## Context

LinguaCafe has two review entry points that share the same backend queue generator (`SenseReviewService::dueCardsWithLimits`) but diverge in ordering semantics:

1. **`/reviews` (POST)** → `ReviewController::getReviewItems` → `ReviewService::getReviewItems` → calls `dueCardsWithLimits` then **`shuffle($reviews)`** (`app/Services/ReviewService.php:41`) — fully randomizes the ordered collection. Frontend `Review.vue` then picks the next card with `Math.floor(Math.random() * this.reviews.length)` (`resources/js/components/Review/Review.vue:822`), so even the shuffled order is not respected.
2. **`/reviews/senses` (GET)** → `SenseReviewController::index` → `dueCardsWithLimits` → `serializeMany` (no shuffle) — preserves the backend order. Frontend `SenseReview.vue` always takes `cards[0]` (`resources/js/components/Senses/SenseReview.vue:668`). After rating, the backend returns a `next_card` field but the frontend ignores it and calls `loadCards()` to reload the whole queue.

So the same user, at the same moment, sees cards in a different order depending on which entry point they use. Neither order is documented, tested, or product-defined.

### Current call chain (`/reviews`, POST)

```
POST /reviews
  → ReviewController::getReviewItems()
  → ReviewService::getReviewItems($userId, $language, $bookId, $chapterId, $practiceMode, ..., $ignoreDailyLimits)
      ├── if bookId!=-1 || chapterId!=-1 → return empty + emptyScoped summary
      ├── SenseReviewService::dueCardsWithLimits($userId, $language, $ignoreDailyLimits)
      │     ├── dueCards($userId, $language)
      │     │     └── dueSenseReviewCardQuery()
      │     │           ├── confirmedSenseCardQuery()  (join word_senses, status=confirmed)
      │     │           ├── scopeSenseReviewEligible() (user+language+target_type=sense+lifecycle active/buried-expired+fsrs_enabled)
      │     │           └── where fsrs_due_at <= now
      │     │     └── orderBy fsrs_due_at ASC, id ASC → get()
      │     ├── reviewedTodayCount() (ReviewLog: source=sense_review, not reset, not undone)
      │     ├── split newCards (fsrs_state=new) / knownCards (fsrs_state!=new, i.e. learning+review+relearning)
      │     ├── slice knownCards to remainingReviewSlots
      │     ├── slice newCards to newLimit (or min(newLimit, remainingAfterKnown) if !newIgnoreReviewLimit)
      │     └── concat knownCards + newCards  (known first, new last)
      ├── serialize each card + type='sense'
      ├── shuffle($reviews)   ← destroys backend order
      └── return { reviews, summary }
```

### Current call chain (`/reviews/senses`, GET)

```
GET /reviews/senses
  → SenseReviewController::index()
  → SenseReviewService::dueCardsWithLimits($userId, $language, $ignoreDailyLimits)
      └── (same as above — known first, new last, fsrs_due_at ASC within each bucket)
  → SenseReviewCardSerializerService::serializeMany()  (preserves order)
  → return { cards, summary }
```

### Current ordering facts (verified from code)

| Aspect | Fact |
|---|---|
| `dueCards()` ORDER BY | `review_cards.fsrs_due_at ASC, review_cards.id ASC` (`app/Services/SenseReviewService.php:50-51`) |
| `dueCardsWithLimits()` bucketing | `new` (fsrs_state=new) vs `known` (fsrs_state != new, i.e. learning+review+relearning all merged); **known first, new last** via `concat` (`SenseReviewService.php:147-194`) |
| Daily limits timing | All limits (review limit, new limit, newIgnoreReviewLimit, ignoreDailyLimits) act **after** `dueCards()` has already sorted — they only slice/concat, they never re-sort (`SenseReviewService.php:121-194`) |
| `ReviewService::shuffle()` | `shuffle($reviews)` at `app/Services/ReviewService.php:41` — PHP built-in `mt_rand`, no seed, not daily-stable, different every request |
| `SenseReviewController` shuffle | None — `serializeMany` preserves input order (`app/Services/SenseReviewCardSerializerService.php:233-238`) |
| `/reviews` frontend next card | `Math.floor(Math.random() * this.reviews.length)` — frontend re-randomizes after backend already shuffled (`Review.vue:822`) |
| `/reviews/senses` frontend next card | Always `cards[0]`; after rating, backend returns `next_card` but frontend calls `loadCards()` to reload whole queue (`SenseReview.vue:668, 952`) |
| Lifecycle eligible scope | Unified in `ReviewCard::scopeSenseReviewEligible` — active + buried-expired + fsrs_enabled mirror + target_type=sense; suspended/archived excluded (`app/Models/ReviewCard.php:87-110`) |
| Random seed / deck / preset | None exist. `shuffle` is the only randomization. "deck" in `Review.vue`/`Review.scss` is a CSS animation name, not an FSRS deck. |
| Existing order abstraction | None. No `ReviewQueueOrderService`, no `ReviewQueueOrderPolicy`, no `queue_bucket`, no `queue_rank`. Ordering is split across `dueCards` (orderBy), `dueCardsWithLimits` (bucket+concat), and `ReviewService` (shuffle). |

### Problems this causes

1. **Two entry points, two orderings.** `/reviews` is random; `/reviews/senses` is deterministic. The same card can appear first in one and last in the other. There is no single source of truth for "what order should cards be reviewed in".
2. **Shuffle destroys backend order.** `dueCardsWithLimits` carefully sorts by `fsrs_due_at ASC` and puts known before new, but `ReviewService::shuffle()` throws all of that away. The backend work is wasted.
3. **No product-defined policy.** The current "order" is an accident of implementation: `fsrs_due_at ASC` was chosen because it's the natural DB index, not because anyone decided "earliest due first" is the right product behavior. Whether overdue cards should come before learning steps, whether new cards should be interleaved, whether same-due-time cards need a tie-breaker — none of this is decided.
4. **No test locks ordering.** Every existing test asserts `count` / `assertContains` / `assertNotContains` / eligibility. **Zero tests assert the relative order of two cards.** Anyone can change `orderBy` or add/remove `shuffle` without any test failing.
5. **Fast card switching has no guard on `/reviews`.** Because `Review.vue` picks a random index from a pre-loaded array, the "next card" is whatever `Math.random` returns, not a deterministic sequence.
6. **`/reviews/rate` does not return `next_card`.** The frontend guesses. `/reviews/senses/{id}/rate` returns `next_card` but the frontend ignores it. Two inconsistent post-rating flows.

## Decision

### 1. Three product approaches analyzed

#### Approach A — Learning-first (recommended)

Buckets, in priority order:

1. Due **relearning** cards (fsrs_state=relearning, fsrs_due_at <= now) — these are cards that were forgotten and are being re-taught; they should come first so the user finishes relearning before new reviews.
2. Due **learning** cards (fsrs_state=learning, fsrs_due_at <= now) — cards in the initial learning steps; short intervals, should not be delayed.
3. **Overdue review** cards (fsrs_state=review, fsrs_due_at < today) — sorted by overdue duration DESC (most overdue first), then by fsrs_due_at ASC.
4. **Today-due review** cards (fsrs_state=review, fsrs_due_at between today 00:00 and now) — sorted by fsrs_due_at ASC.
5. **New** cards (fsrs_state=new) — sorted by id ASC (stable creation order).

| Criterion | Analysis |
|---|---|
| User experience | Predictable: "finish what you're learning, then catch up on overdue, then today's reviews, then new cards". Matches Anki's default display order (Relearning → Learning → Review → New). |
| Backlog behavior | Overdue cards are tackled before today's reviews, preventing "today's easy cards push overdue cards further back". |
| New card starvation | New cards are last but daily_new_limit guarantees they appear once the known quota allows. With default new_limit=20 and review_limit=200, new cards will appear. |
| Learning/relearning buried by old review | No — relearning and learning come **before** review. This is the opposite of the current known-first concat where a learning card and a review card are both "known" and sorted only by fsrs_due_at. |
| Daily predictability | High — same bucket structure every day; only the contents change. |
| FSRS due semantics | Preserved — FSRS still computes fsrs_due_at; we only decide presentation order, not scheduling. |
| Implementation complexity | Medium — 5 buckets, each with a simple sort. Needs a Policy class but no new DB columns. |
| Test complexity | Medium — each bucket boundary and tie-breaker needs a test, but they are independent. |
| Boundary with Custom Study / Saved Search / today-only limits | Clean — Queue Order only decides order within the due set; Custom Study decides *which* cards (a different selection); today-only limits decide *how many* (a different cap). |
| Needs user setting | No — V1 is a single fixed policy. Future V2 could add a setting, but V1 avoids migration. |

#### Approach B — Overdue-first

All non-new cards sorted by overdue duration DESC (most overdue first); learning/relearning are just higher-priority within the same overdue sort.

| Criterion | Analysis |
|---|---|
| User experience | "Clear the oldest debt first." Appeals to users with large backlogs. |
| Backlog behavior | Best for backlog — the most overdue card is always next. |
| New card starvation | Same as A — new cards last, protected by daily_new_limit. |
| Learning/relearning buried | **Risk** — a learning card due 1 hour ago and a review card due 30 days ago: the review card comes first because it's more overdue. This delays learning steps, which have short intervals (minutes/hours). Bad for the learning curve. |
| Daily predictability | Medium — order depends on how overdue each card is, which changes daily. |
| FSRS due semantics | Preserved. |
| Implementation complexity | Low — single sort by overdue duration. |
| Test complexity | Low. |
| Boundary with other tasks | Same as A. |
| Needs user setting | No. |

**Why not B**: learning steps have short intervals (e.g. 10 minutes). If a review card is 30 days overdue, B puts the review card first and the learning card waits. The user finishes a 30-day-overdue card, then the learning card is now 40 minutes overdue — still behind any other 1-day-overdue review card. Learning steps get delayed indefinitely under backlog. Anki explicitly puts Learning before Review for this reason.

#### Approach C — Mixed new cards

Learning/relearning first, then review and new cards interleaved at a fixed ratio (e.g. 3 review : 1 new).

| Criterion | Analysis |
|---|---|
| User experience | "New cards sprinkled in, not all at the end." Reduces fatigue from a long new-card block. |
| Backlog behavior | Overdue review cards are still prioritized within the review portion. |
| New card starvation | No — new cards are guaranteed by the ratio. |
| Learning/relearning buried | No — they come first, same as A. |
| Daily predictability | **Low** — the interleaving ratio depends on how many review vs new cards remain after limits, which changes as the user rates. The user cannot predict "how many more new cards". |
| FSRS due semantics | Preserved. |
| Implementation complexity | **High** — must track remaining review and new counts, interleave, handle edge cases (one bucket empty). |
| Test complexity | **High** — interleaving has many edge cases. |
| Boundary with other tasks | today-only limits interact with the ratio in non-obvious ways (if review limit is hit, does the ratio stop or continue with only new?). |
| Needs user setting | Likely yes — users will want to configure the ratio. Adds a setting, possibly a migration. |

**Why not C for V1**: the complexity of interleaving + the ratio's interaction with daily limits + the need for a user setting (migration) violates the V1 constraint of "no new setting, no migration". C is a candidate for V2.

### 2. Recommended approach: A (Learning-first)

Approach A matches Anki's default display order, protects learning steps, handles backlogs sensibly, is predictable, and needs no new setting or migration. It is the recommended V1 policy.

**This ADR recommends A but does not freeze the product choice.** The final decision belongs to the web-end chief designer. If the designer chooses B or C, the implementation plan must be updated accordingly before development starts.

### 3. Target call chain (After)

```
GET /reviews/senses  (and POST /reviews, both)
  → Controller
  → SenseReviewService::dueCardsWithLimits($userId, $language, $ignoreDailyLimits)
      ├── dueCards($userId, $language)   (existing: orderBy fsrs_due_at ASC, id ASC)
      ├── reviewedTodayCount()           (existing)
      ├── ReviewQueueOrderPolicy::assignBuckets(Collection $dueCards): array
      │     returns [{ card, bucket, rank }, ...] sorted by bucket priority then rank
      ├── apply daily limits per-bucket (review limit on known buckets, new limit on new bucket)
      └── concat buckets in priority order → visibleCards
  → ReviewQueueOrderService::order(Collection $visibleCards): Collection
      (final stable sort by bucket, then rank — guarantees Controller-agnostic ordering)
  → serialize
  → return { cards, summary, queue_meta: { buckets, policy_version } }   (additive, optional)
```

**Key invariants**:
- `ReviewQueueOrderPolicy::assignBuckets()` is a **pure function** — no DB, no I/O, no side effects. Input: a Collection of due cards. Output: each card annotated with `bucket` (int 1-5) and `rank` (int, stable within bucket).
- `ReviewQueueOrderService::order()` is the **single** place that produces the final ordered collection. Both `/reviews` and `/reviews/senses` call it. `ReviewService::shuffle()` is **removed**.
- The Policy and Service live in `app/Services/`. The Controller and Vue never replicate bucketing logic.

### 4. Policy input / output

**Input**: `Collection<ReviewCard>` (already filtered to due + eligible + user + language + sense-only by `dueCards()`).

**Output**: `array<array{card: ReviewCard, bucket: int, rank: int}>` where:

| Bucket | Priority | fsrs_state | Due condition | Intra-bucket sort |
|---|---|---|---|---|
| 1 | relearning | relearning | fsrs_due_at <= now | fsrs_due_at ASC, id ASC |
| 2 | learning | learning | fsrs_due_at <= now | fsrs_due_at ASC, id ASC |
| 3 | overdue_review | review | fsrs_due_at < today 00:00 (user tz) | overdue_duration DESC, fsrs_due_at ASC, id ASC |
| 4 | today_review | review | today 00:00 <= fsrs_due_at <= now | fsrs_due_at ASC, id ASC |
| 5 | new | new | fsrs_due_at <= now | id ASC |

- `bucket` is 1-5 (lower = higher priority).
- `rank` is 0-based within each bucket.
- `overdue_duration` = `now - fsrs_due_at` (computed in PHP, not stored).
- `today 00:00` is the user's timezone midnight (reuse `ReviewCardBuryTimeService` or Carbon tz logic from ADR-0010).
- Tie-breaker is always `id ASC` for stability.

### 5. Bucket and tie-breaker rules

1. **Relearning before learning**: relearning cards were already failed once this session/interval; they need immediate reinforcement.
2. **Learning before review**: learning steps have short intervals (minutes); delaying them breaks the learning curve.
3. **Overdue review before today review**: a card due 3 days ago is more urgent than a card due this morning.
4. **Overdue sort by duration DESC**: the most overdue card comes first within the overdue bucket. This prevents "a slightly overdue card keeps pushing a very overdue card back".
5. **Today review sort by fsrs_due_at ASC**: within today, earliest due first (natural FSRS order).
6. **New cards last**: new cards are the lowest priority — they have no overdue debt.
7. **New cards sort by id ASC**: stable creation order. Older new cards are introduced first.
8. **id ASC tie-breaker everywhere**: guarantees deterministic output for tests and for fast card switching.

### 6. Daily limits interaction

Daily limits continue to act **after** bucketing, exactly as today:

- `daily_review_limit` caps the total of buckets 1-4 (relearning + learning + overdue_review + today_review). The cap is applied **across** these buckets in priority order: if the limit is hit during bucket 2, buckets 3 and 4 are hidden entirely.
- `daily_new_limit` caps bucket 5 (new).
- `new_cards_ignore_review_limit=true` → new cards are capped only by `daily_new_limit`, not by the remaining review slots. (Same as current behavior.)
- `new_cards_ignore_review_limit=false` → new cards are capped by `min(daily_new_limit, remainingReviewSlotsAfterKnown)`. (Same as current behavior.)
- `ignoreDailyLimits=true` → no caps; all 5 buckets returned in full. **Order is still applied** — ignoreDailyLimits bypasses quantity limits, not ordering.

**Important**: daily limits never re-sort. They only slice. The order is decided by the Policy; the limits decide how many.

### 7. Lifecycle interaction

The Policy does **not** re-implement lifecycle eligibility. It receives cards already filtered by `scopeSenseReviewEligible` (ADR-0010):

- Active cards: included (subject to due filter).
- Buried-not-expired: excluded by scope.
- Buried-expired: included by scope (treated as active).
- Suspended: excluded by scope.
- Archived: excluded by scope.
- fsrs_enabled=false: excluded by scope (mirror invariant).

The Policy only decides order among eligible cards. It never changes lifecycle state, never writes ReviewLog, never modifies FSRS fields.

### 8. Query budget

Per `dueCardsWithLimits` request (after access check):

| Query | Count | Notes |
|---|---|---|
| `dueCards()` (existing) | 1 | `WHERE user+language+sense+eligible+due ORDER BY fsrs_due_at, id` — unchanged |
| `reviewedTodayCount()` (existing) | 1 | ReviewLog count — unchanged |
| `assignBuckets()` (new) | 0 | pure function on the already-loaded Collection |
| `order()` (new) | 0 | pure function on the already-loaded Collection |

**Total: 2 DB queries per request** — same as today. The Policy and Service add zero DB queries. No N+1.

### 9. `/reviews` and `/reviews/senses` share the same ordered collection

Both endpoints call `dueCardsWithLimits` (which now includes the Policy + Service). Both get the same ordered collection. `ReviewService::shuffle()` is **removed**. The two entry points produce identical order.

### 10. `ReviewService::shuffle()` removal

`shuffle($reviews)` at `app/Services/ReviewService.php:41` is removed. The `/reviews` endpoint now returns cards in the same order as `/reviews/senses`. This is a behavior change for `/reviews` users — but since the order was random before, no user could have relied on it, and no test asserts it.

### 11. Post-rating next card

- `/reviews/senses/{id}/rate` already returns `next_card` = `dueCardsWithLimits()->first()`. After this ADR, that `first()` is the Policy-ordered first card. The frontend should **use** `response.data.next_card` instead of calling `loadCards()` again, to avoid a redundant request. (This is a frontend optimization, not a backend change.)
- `/reviews/rate` currently returns only the rated card. It should be updated to also return `next_card` (same as `/reviews/senses/{id}/rate`), so the `Review.vue` frontend can stop using `Math.random()`. This is a **backend behavior change** but additive (new field in response).

**V1 decision**: The backend `next_card` field is added to `/reviews/rate` response. The frontend `Review.vue` is updated to use `response.data.next_card` instead of `Math.random()`. This makes both entry points deterministic and consistent.

### 12. Stale response guard (fast card switching)

Same pattern as ADR-0014: a monotonic request sequence number. The frontend increments a counter before each queue request; the `.then()` handler checks if the counter still matches before applying the response. This prevents a slow `/reviews/senses` response from overwriting a newer one.

### 13. Loading / empty / error states

- **Loading**: frontend shows a loading state while the queue request is in flight.
- **Empty**: if `cards` is `[]`, show "今日没有需要复习的卡片。" (same as current).
- **Error**: if the queue request fails, show an error state with retry.

These are largely already implemented in `SenseReview.vue`. `Review.vue` may need minor adjustments.

### 14. `queue_meta` response field (additive, optional)

The response may include an additive `queue_meta` field:

```json
{
  "cards": [...],
  "summary": {...},
  "queue_meta": {
    "policy_version": "v1",
    "buckets": [
      { "name": "relearning", "count": 2 },
      { "name": "learning", "count": 5 },
      { "name": "overdue_review", "count": 10 },
      { "name": "today_review", "count": 20 },
      { "name": "new", "count": 5 }
    ]
  }
}
```

This is **optional for V1** — the frontend does not need it to render the queue. It is included so the frontend *can* show "5 张学习中, 10 张逾期, 20 张今日到期, 5 张新卡" if desired. If the designer prefers V1 without it, it can be deferred.

### 15. Scope exclusion (V1)

This ADR does **not** introduce:

- Custom Study sessions (separate task `Anki-CustomStudy-1`).
- Saved Search (separate task).
- today-only limits / temporary cap overrides (separate task).
- Study Overview / Stats changes (separate task `Anki-Stats-1`).
- Deck / preset system (explicitly out of scope; LinguaCafe is single-queue per user+language).
- User-configurable ordering (V2 candidate).
- Random seed / daily-stable shuffle (not needed — V1 is deterministic).
- Any new migration.
- Any FSRS algorithm / parameter / scheduling change.
- Any ReviewLog schema or historical log change.
- Any lifecycle state machine change (ADR-0010).
- Any Leech Policy change (ADR-0011).
- Any Browser Search syntax change (ADR-0012/0013 frozen).
- Any external AI provider call.
- Any edit / suspend / resume / archive / reset / delete action triggered from the queue.

### 16. V1 boundary summary

| Item | In V1? |
|---|---|
| sense card only | Yes |
| active + due cards only | Yes (via existing scope) |
| 5 fixed buckets (relearning/learning/overdue_review/today_review/new) | Yes |
| Stable intra-bucket sort | Yes |
| Both `/reviews` and `/reviews/senses` share policy | Yes |
| Daily limits still apply | Yes |
| ignoreDailyLimits bypasses quantity, not order | Yes |
| Remove `ReviewService::shuffle()` | Yes |
| `/reviews/rate` returns `next_card` | Yes (additive) |
| Frontend uses `next_card` instead of `Math.random` | Yes |
| Stale response guard | Yes |
| `queue_meta` response field | Optional (designer choice) |
| New setting page | No |
| User-configurable order | No |
| Deck / preset | No |
| Custom Study | No |
| Saved Search | No |
| today-only temporary override | No |
| Study Overview / Stats | No |
| New migration | No |
| Random seed | No |

## Rollback

Revert the implementation commit. The old `shuffle()` behavior is restored by reverting `ReviewService.php`. The `ReviewQueueOrderPolicy` and `ReviewQueueOrderService` are new files — removing them is safe. The `next_card` field in `/reviews/rate` is additive — removing it does not break old frontend (the frontend would fall back to... nothing, so the frontend revert must happen together with the backend revert). No migration, no schema change, no FSRS / lifecycle / ReviewLog change — rollback is a pure code revert.

## Prohibited scope

- No new migration.
- No FSRS algorithm / parameter / scheduling change.
- No ReviewLog schema or historical log change.
- No lifecycle state machine change (ADR-0010).
- No Leech Policy change (ADR-0011).
- No Browser Search syntax change (ADR-0012/0013 frozen).
- No external AI provider call.
- No deck / preset system.
- No user-configurable ordering in V1.
- No Custom Study / Saved Search / today-only limits / Study Overview.
- No duplication of bucketing logic in Controller or Vue.
- No per-card DB query inside the Policy.
- No ReviewLog write.
- No lifecycle state mutation.
- No FSRS field mutation.
- No `Math.random()` for next-card selection in the frontend.
