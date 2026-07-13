# ADR-0015: Review Queue Order Policy

**Status**: Implemented (code + tests + npm build + db:doctor pass; MCP Chrome acceptance pending)
**Date**: 2026-07-13
**Related**: `docs/adr/ADR-0008-sense-review-answer-interval-preview.md`, `docs/adr/ADR-0009-review-action-ledger-and-stack-undo.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`, `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`, `docs/adr/ADR-0014-review-card-info-read-model.md`

## Correction Notice

> **This ADR was corrected in 2000-9B.** The original 2000-9A version proposed a fixed five-bucket policy (relearning / learning / overdue_review / today_review / new) and claimed it "matches Anki default". That was wrong:
> 1. Anki does not have a fixed five-bucket display order. Anki has configurable display options with defaults.
> 2. The original ADR did not distinguish intraday learning from interday learning, which Anki explicitly separates.
> 3. The original ADR did not provide any configurable settings, while Anki provides multiple display order settings.
> 4. The original ADR used "overdue duration DESC" as the review sort, which is not an Anki option.
>
> The corrected ADR (below) replaces the fixed five-bucket design with four Anki-aligned configurable settings and four card categories (intraday learning, interday learning, review, new). The implementation is complete; this ADR documents the actual implementation.

## Context

LinguaCafe has two review entry points that share the same backend queue generator (`SenseReviewService::dueCardsWithLimits`) but diverge in ordering semantics:

1. **`/reviews` (POST)** → `ReviewController::getReviewItems` → `ReviewService::getReviewItems` → calls `dueCardsWithLimits` then **`shuffle($reviews)`** (`app/Services/ReviewService.php`) — fully randomizes the ordered collection. Frontend `Review.vue` then picks the next card with `Math.floor(Math.random() * this.reviews.length)`, so even the shuffled order is not respected.
2. **`/reviews/senses` (GET)** → `SenseReviewController::index` → `dueCardsWithLimits` → `serializeMany` (no shuffle) — preserves the backend order. Frontend `SenseReview.vue` always takes `cards[0]`. After rating, the backend returns a `next_card` field but the frontend calls `loadCards()` to reload the whole queue.

So the same user, at the same moment, sees cards in a different order depending on which entry point they use. Neither order is documented, tested, or product-defined. The `shuffle()` uses PHP built-in `mt_rand` with no seed — different every request. The `Math.random()` in `Review.vue` re-randomizes after the backend already shuffled.

### Problems this causes

1. **Two entry points, two orderings.** `/reviews` is random; `/reviews/senses` is deterministic. There is no single source of truth for "what order should cards be reviewed in".
2. **Shuffle destroys backend order.** `dueCardsWithLimits` carefully sorts by `fsrs_due_at ASC` and puts known before new, but `ReviewService::shuffle()` throws all of that away.
3. **No product-defined policy.** The current "order" is an accident of implementation, not a product decision.
4. **No test locks ordering.** Every existing test asserts `count` / `assertContains` / eligibility. Zero tests assert relative order.
5. **No intraday/interday distinction.** All `fsrs_state != new` cards are merged into one "known" bucket, ignoring that Anki explicitly separates intraday learning (same-day steps) from interday learning (cross-day steps).
6. **No configurable settings.** Users cannot choose how new cards interleave with reviews, or how reviews are sorted.

## Decision

### 1. Four Anki-aligned configurable settings

LinguaCafe V1 implements four Queue Order settings, aligned with Anki's display options:

| Setting | Allowed values | Default | Anki counterpart |
|---|---|---|---|
| `interday_learning_review_order` | `mix` / `before` / `after` | `mix` | Interday learning order relative to reviews |
| `new_review_order` | `mix` / `before` / `after` | `mix` | New card order relative to reviews |
| `review_sort_order` | `due_random` / `due_stable` / `ascending_retrievability` / `random` | `due_random` | Review sort order |
| `new_sort_order` | `created_asc` / `created_desc` / `random` | `created_asc` | New card sort order |

**Anki defaults are used as LinguaCafe defaults.** No user product question is needed for these — Anki already has clear defaults.

### 2. Four card categories (not five buckets)

The Policy classifies each due card into one of four categories:

| Category | fsrs_state | Intraday condition | Notes |
|---|---|---|---|
| `intraday` | learning or relearning | `fsrs_last_reviewed_at` and `fsrs_due_at` fall on same user-local date | Same-day learning steps; always shown first |
| `interday` | learning or relearning | `fsrs_last_reviewed_at` and `fsrs_due_at` fall on different user-local dates | Cross-day learning steps |
| `review` | review | N/A | Scheduled review cards |
| `new` | new | N/A | Never-reviewed cards |

**Intraday always comes first** — Anki puts same-day learning steps before everything else. This is not configurable in V1.

**Interday + review are combined** per `interday_learning_review_order`:
- `mix` (default): deterministic uniform interleaving
- `before`: interday before review
- `after`: interday after review

**New + non-intraday are combined** per `new_review_order`:
- `mix` (default): deterministic uniform interleaving
- `before`: new before non-intraday
- `after`: new after non-intraday

### 3. Intraday/interday classification

A learning/relearning card is **intraday** if:
1. `fsrs_state` is `learning` or `relearning`.
2. `fsrs_last_reviewed_at` is non-null.
3. `fsrs_last_reviewed_at` and `fsrs_due_at` are converted to user timezone.
4. Both fall on the same user-local date → intraday.
5. Different user-local dates → interday.

**Fallback**: if `fsrs_last_reviewed_at` is null, the card is treated as interday (conservative — don't assume same-day without evidence).

Tests cover: UTC, America/Los_Angeles, cross-midnight, DST boundary, null `fsrs_last_reviewed_at`.

### 4. Review sort order

| Value | Semantics |
|---|---|
| `due_random` (default) | Earlier due date first; same-date cards ordered by stable daily hash. Stable within same user+language+local date+card. May differ across days. |
| `due_stable` | `fsrs_due_at ASC`, then `review_card_id ASC` as tie-breaker. |
| `ascending_retrievability` | Lower retrievability first (most easily forgotten). Uses FSRS-5 formula. |
| `random` | Stable daily hash (same as `due_random` same-date portion, but ignores due date). |

**FSRS-5 retrievability formula** (from the project's installed FSRS implementation):

```
R = (1 + FACTOR * elapsed / stability) ^ DECAY
```

Where:
- `FACTOR = 19/81`
- `DECAY = -0.5`
- `elapsed` = seconds between `fsrs_last_reviewed_at` and now
- `stability` = `fsrs_stability`

**Fallback**: if `fsrs_stability` is null or zero, retrievability returns 0 (lowest priority — needs immediate review). If `fsrs_last_reviewed_at` is null, retrievability returns 1 (highest — don't prioritize unknown state). These fallbacks are tested and produce no NaN.

### 5. Stable daily hash

```
hash = md5(userId | language | localDate | cardId)
float = first 8 hex chars of hash / 0xffffffff
```

- Stable within the same user + language + local date + card.
- May differ across days (different `localDate`).
- Same day, same card → same float → same order on page refresh.
- Different user / language / card → different float.
- Float is in `[0, 1)`.

**This replaces `shuffle()`.** No unseeded PHP `shuffle()` is used for queue ordering. The daily hash is deterministic and testable.

### 6. Stable Mix algorithm

`mix` is **not** a random shuffle. It is deterministic uniform interleaving:

1. Get ordered main sequence A (e.g., interday + review sorted).
2. Get ordered secondary sequence B (e.g., new sorted).
3. Interleave B into A by ratio: `step = ceil(|A| / (|B| + 1))`, insert one B card every `step` cards of A.
4. Same input + same settings → identical output.
5. Either side empty → return the other.
6. B longer than A → still distributes evenly.
7. No cards lost, no cards duplicated.
8. Internal relative order of A preserved.
9. Internal relative order of B preserved.

### 7. Execution hierarchy (daily limits interaction)

Per task spec lines 333-339:

1. Intraday learning/relearning — always first, not affected by new limit.
2. Combine interday + review per `interday_learning_review_order`.
3. Apply review daily limit to non-new cards (intraday + interday + review).
4. Sort and trim new cards (subject to new limit, and when `new_cards_ignore_review_limit=false`, also subject to remaining review limit).
5. Combine new + non-intraday per `new_review_order`.
6. Concatenate intraday at the front.

**Two-phase daily limits**:
- **Phase 1**: Apply review limit to non-new cards (intraday first, then interday+review).
- **Phase 2**: Apply new limit + remaining review limit to new cards.
- **Phase 3**: Filter visible cards by queue order to maintain unified ordering.

`ignoreDailyLimits=true` bypasses quantity limits only — Queue Order settings still apply. No re-shuffle after trimming.

### 8. Global settings scope

V1 uses **global settings** (`settings.user_id = -1`), consistent with existing daily limits and FSRS management settings.

- No Preset (deferred to FSRS-Anki-Mgmt-9 per master plan).
- No new migration.
- Settings stored as JSON in `settings.value` field.
- `scope: "global"` and `preset_supported: false` returned in API response.

### 9. Two entry points unified

Both `/reviews` and `/reviews/senses`:
- Read the same Queue Order configuration from `SettingsService::getFsrsQueueOrder()`.
- Call the same `ReviewQueueOrderService` for classification and sorting.
- Produce identical card ID order for the same user + language + time + card set + settings.

`ReviewService::shuffle()` is **deleted**. `Review.vue` `Math.random()` is **deleted** — `next()` now uses `currentReviewIndex = 0` (queue first card) because the backend already returns cards in Queue Order.

### 10. Three-layer architecture

| Layer | File | Responsibility |
|---|---|---|
| **Value object** | `app/Services/ReviewQueueOrderOptions.php` | Expresses validated Queue Order config. Provides defaults, allowed enums. No DB, no Auth, no Request. |
| **Pure policy** | `app/Services/ReviewQueueOrderPolicy.php` | Pure sorting. Input: standardized queue items + Options. Output: stable ordered collection. Implements intraday priority, interday/review mix, new/review mix, review sort, new sort. No DB, no Settings, no Auth, no lifecycle eligibility, no writes. |
| **Single entry service** | `app/Services/ReviewQueueOrderService.php` | Reads card fields, classifies intraday/interday/review/new, computes retrievability, generates stable daily hash, calls Policy. The only formal sorting entry. No per-card DB query, no ReviewCard mutation, no ReviewLog write, no FSRS due_at change. |

### 11. Settings API

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/settings/fsrs/queue-order` | GET | admin | Read Queue Order config |
| `/settings/fsrs/queue-order` | POST | admin | Update Queue Order config |

**Response shape**:
```json
{
  "interday_learning_review_order": "mix",
  "new_review_order": "mix",
  "review_sort_order": "due_random",
  "new_sort_order": "created_asc",
  "scope": "global",
  "preset_supported": false
}
```

**422 on invalid input**: structured errors, no partial save. Unknown keys are ignored (tested). Auth required (tested). Admin required (tested).

### 12. Post-rating next_card

Both `/reviews/rate` and `/reviews/senses/{id}/rate`:
- Return `next_card` from the re-computed unified Queue Order.
- Current card (if no longer due) does not reappear as `next_card`.
- Daily limits and `ignoreDailyLimits` continue to apply.
- Queue Order settings continue to apply.

### 13. Lifecycle interaction

The Policy does **not** re-implement lifecycle eligibility. It receives cards already filtered by `scopeSenseReviewEligible` (ADR-0010):
- Active cards: included.
- Buried-not-expired: excluded by scope.
- Buried-expired: included by scope.
- Suspended: excluded by scope.
- Archived: excluded by scope.
- `fsrs_enabled=false`: excluded by scope.

The Policy only decides order among eligible cards. It never changes lifecycle state, never writes ReviewLog, never modifies FSRS fields.

### 14. Query budget

Per `dueCardsWithLimits` request:

| Query | Count | Notes |
|---|---|---|
| `dueCards()` | 1 | Existing — unchanged |
| `reviewedTodayCount()` | 1 | Existing — unchanged |
| `classify()` / `computeSortKey()` | 0 | Pure functions on already-loaded Collection |
| Settings read | 1 | Cached — same as existing daily limits settings read |

**Total: 2-3 DB queries per request** — no N+1. The Policy and Service add zero DB queries.

### 15. Inapplicable deck-specific options

The following Anki options are **not implemented** in LinguaCafe V1 because LinguaCafe has no deck/subdeck/preset/sibling/card-type model:

- due date then deck
- deck then due date
- deck gather
- subdeck priority
- note sibling position

### 16. LinguaCafe deviations from Anki

| Anki concept | LinguaCafe V1 mapping | Deviation reason |
|---|---|---|
| `created_asc` (new card position) | `review_card_id ASC` | LinguaCafe has no deck position/note sibling model; `id ASC` is the stable creation order proxy |
| `created_desc` | `review_card_id DESC` | Same as above |
| Deck-specific sorting | Not implemented | LinguaCafe is single-queue per user+language |
| Preset | Not implemented | Deferred to FSRS-Anki-Mgmt-9 |

### 17. Actual implementation results

**Files created**:
- `app/Exceptions/QueueOrderValidationException.php`
- `app/Services/ReviewQueueOrderOptions.php`
- `app/Services/ReviewQueueOrderPolicy.php`
- `app/Services/ReviewQueueOrderService.php`
- `tests/Unit/ReviewQueueOrderOptionsTest.php` (16 tests)
- `tests/Unit/ReviewQueueOrderPolicyTest.php` (17 tests)
- `tests/Unit/ReviewQueueOrderServiceTest.php` (26 tests)
- `tests/Feature/FsrsQueueOrderSettingsTest.php` (17 tests)
- `tests/Feature/ReviewQueueOrderTest.php` (13 tests)
- `tests/js/AdminReviewSettingsQueueOrderGuard.test.mjs` (14 tests)
- `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` (8 tests)

**Files modified**:
- `app/Services/SettingsService.php` — added `getFsrsQueueOrder()` / `updateFsrsQueueOrder()`
- `app/Http/Controllers/SettingsController.php` — added queue-order endpoints
- `app/Http/Controllers/ReviewController.php` — `rateReviewCard` returns `next_card`
- `app/Services/ReviewService.php` — **deleted** `shuffle($reviews)`
- `app/Services/SenseReviewService.php` — integrated QueueOrderService, two-phase daily limits
- `routes/web.php` — added queue-order routes
- `resources/js/components/Admin/AdminReviewSettings.vue` — added "复习显示顺序" section with 4 dropdowns
- `resources/js/components/Review/Review.vue` — **deleted** `Math.random()`, uses `currentReviewIndex = 0`

**Test results** (all pass):
- Unit: 16 + 17 + 26 = 59 tests
- Feature: 17 + 13 = 30 tests (Queue Order specific)
- Feature regression: 63 (ReviewFsrsTest) + 17 (SenseReviewDailyLimitsTest) + 13 (ReviewCardLifecycleQueueTest) + 28 (ReviewCardInfoTest) + 25 (SenseReviewIntervalPreviewTest) + 15 (SenseReviewStackUndoTest) = 161 tests
- Node guard: 14 + 8 + 21 + 35 = 78 tests
- npm build: success
- db:doctor: healthy
- git diff --check: clean

### 18. Rollback

Revert the implementation commit. The old `shuffle()` behavior is restored by reverting `ReviewService.php`. The `ReviewQueueOrderOptions`, `ReviewQueueOrderPolicy`, `ReviewQueueOrderService` are new files — removing them is safe. The `next_card` field in `/reviews/rate` is additive. The settings endpoints and admin UI are additive. No migration, no schema change, no FSRS / lifecycle / ReviewLog change — rollback is a pure code revert.

## Prohibited scope

- No new migration.
- No FSRS algorithm / parameter / scheduling change.
- No ReviewLog schema or historical log change.
- No lifecycle state machine change (ADR-0010).
- No Leech Policy change (ADR-0011).
- No Browser Search syntax change (ADR-0012/0013 frozen).
- No Card Info read model change (ADR-0014).
- No external AI provider call.
- No deck / preset system.
- No Custom Study / Saved Search / today-only limits / Study Overview.
- No duplication of ordering logic in Controller or Vue.
- No per-card DB query inside the Policy.
- No ReviewLog write.
- No lifecycle state mutation.
- No FSRS field mutation.
- No `Math.random()` for next-card selection in the frontend.
- No `shuffle()` for queue ordering in the backend.
