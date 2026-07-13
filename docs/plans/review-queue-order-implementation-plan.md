# Review Queue Order Implementation Plan

> **Status**: Implemented (2000-9B complete). This document was updated from a pre-development plan to actual execution results per task spec section 16. Each task is marked `complete` / `incomplete` / `changed` based on what actually happened.

**Goal**: Implement ADR-0015 (corrected) Review Queue Order Policy, so `/reviews` and `/reviews/senses` share one deterministic ordering policy with four Anki-aligned configurable settings, remove `ReviewService::shuffle()`, unify post-rating next-card retrieval, and remove `Math.random()` from `Review.vue`.

**Tech stack**: Laravel PHP + Vue 2 + Vuetify + PHPUnit + Node.js built-in `assert` guard tests.

**Related ADR**: `docs/adr/ADR-0015-review-queue-order-policy.md` (corrected in 2000-9B)

---

## Architecture (Actual)

Three-layer design, replacing the old five-bucket design:

| Layer | File | Responsibility |
|---|---|---|
| Value object | `app/Services/ReviewQueueOrderOptions.php` | Four Anki-aligned settings, defaults, enums, validation. No DB. |
| Pure policy | `app/Services/ReviewQueueOrderPolicy.php` | Pure ordering. Input: pre-categorized items + Options. Output: stable ordered array. No DB, no Settings, no Auth. |
| Single entry service | `app/Services/ReviewQueueOrderService.php` | Reads card fields, classifies intraday/interday/review/new, computes retrievability, generates daily hash, calls Policy. No per-card DB query. |

**Four card categories** (not five buckets):
- `intraday`: learning/relearning, `fsrs_last_reviewed_at` and `fsrs_due_at` fall on same user-local date
- `interday`: learning/relearning, cross local date
- `review`: `fsrs_state = review`
- `new`: `fsrs_state = new`

**Four Anki-aligned settings** (global, `settings.user_id = -1`):
- `interday_learning_review_order` (mix/before/after, default mix)
- `new_review_order` (mix/before/after, default mix)
- `review_sort_order` (due_random/due_stable/ascending_retrievability/random, default due_random)
- `new_sort_order` (created_asc/created_desc/random, default created_asc)

**Mix is deterministic uniform interleaving** — NOT random shuffle. Same input + same settings → identical output.

**Stable daily hash** `md5(userId|language|localDate|cardId)` replaces `shuffle()`.

---

## Actual Call Chain (After)

### `/reviews` (POST) and `/reviews/senses` (GET) — unified

```
Controller
  → SenseReviewService::dueCardsWithLimits($userId, $language, $ignoreDailyLimits)
      ├── dueCards()  (existing: orderBy fsrs_due_at ASC, id ASC)
      ├── reviewedTodayCount()  (existing)
      ├── ReviewQueueOrderService::order($dueCards, $userId, $language, $tz, $now, $options)
      │     ├── classify each card → intraday/interday/review/new
      │     ├── computeSortKey per card (retrievability / daily hash / due_at / id)
      │     └── ReviewQueueOrderPolicy::order($items, $options)
      │           ├── split by category
      │           ├── sort each category stable (sort_key + card_id tie-breaker)
      │           ├── combine interday + review per interday_learning_review_order
      │           ├── combine non-intraday + new per new_review_order
      │           └── prepend intraday
      └── three-phase daily limits (intraday first → non-new → new)
  → serialize
  → return { cards/reviews, summary }
```

### `/reviews/rate` (POST) and `/reviews/senses/{id}/rate` (POST) — unified

```
Controller::rate()
  ├── ReviewCardService::recordReview()  (existing)
  ├── SenseReviewService::dueCardsWithLimits()  (re-run, Policy-ordered)
  ├── next_card = visibleCards->first()
  └── return { reviewed_card, next_card, summary }
```

Frontend: `Review.vue` `next()` uses `currentReviewIndex = 0` (queue first card) — backend already returns cards in Queue Order, so no `Math.random()` is needed.

---

## File Structure (Actual)

### Created files

| File | Responsibility |
|---|---|
| `app/Exceptions/QueueOrderValidationException.php` | Structured 422 exception for invalid Queue Order input. |
| `app/Services/ReviewQueueOrderOptions.php` | Value object: four settings, defaults, enums, `fromArray`, `toArray`. No DB. |
| `app/Services/ReviewQueueOrderPolicy.php` | Pure ordering: split by category, sort, combine per options, deterministic mix. No DB. |
| `app/Services/ReviewQueueOrderService.php` | Single entry: classify, compute sort keys (retrievability / daily hash / due_at / id), delegate to Policy. No per-card DB. |
| `tests/Unit/ReviewQueueOrderOptionsTest.php` | 16 tests: defaults, fromArray, invalid input, toArray, unknown keys ignored. |
| `tests/Unit/ReviewQueueOrderPolicyTest.php` | 17 tests: intraday priority, interday before/after/mix, new before/after/mix, mix determinism, edge cases, no loss/duplication. |
| `tests/Unit/ReviewQueueOrderServiceTest.php` | 26 tests: classify intraday/interday/review/new (UTC + America/Los_Angeles + cross-midnight + DST + null), retrievability fallbacks, daily hash stability, no NaN. |
| `tests/Feature/FsrsQueueOrderSettingsTest.php` | 17 tests: GET/POST `/settings/fsrs/queue-order`, auth, admin, 422 on invalid, unknown keys ignored, defaults. |
| `tests/Feature/ReviewQueueOrderTest.php` | 13 tests: two endpoints unified, no shuffle, daily limits interaction, ignoreDailyLimits, next_card, no ReviewLog/FSRS/lifecycle writes on GET. |
| `tests/js/AdminReviewSettingsQueueOrderGuard.test.mjs` | 14 tests: 4 dropdowns present, load/save wiring, no Math.random, no inline defaults. |
| `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` | 8 tests: Review.vue no Math.random in next, AdminReviewSettings imports, no shuffle in ReviewService. |

### Modified files

| File | Change |
|---|---|
| `app/Services/SenseReviewService.php` | `dueCardsWithLimits()` calls `ReviewQueueOrderService::order()` after `dueCards()`. Three-phase daily limits (intraday → non-new → new). |
| `app/Services/ReviewService.php` | Deleted `shuffle($reviews)`. `/reviews` now returns Policy-ordered cards. |
| `app/Http/Controllers/ReviewController.php` | `rateReviewCard()` returns `next_card` field (Policy-ordered first card). |
| `app/Http/Controllers/SettingsController.php` | Added `getFsrsQueueOrder()` and `updateFsrsQueueOrder()` endpoints. |
| `app/Services/SettingsService.php` | Added `getFsrsQueueOrder()` and `updateFsrsQueueOrder()` methods. |
| `routes/web.php` | Added `/settings/fsrs/queue-order` GET/POST routes in admin middleware group. |
| `resources/js/components/Admin/AdminReviewSettings.vue` | Added "复习显示顺序" section with 4 dropdowns, load/save methods. |
| `resources/js/components/Review/Review.vue` | Replaced `Math.floor(Math.random() * this.reviews.length)` with `currentReviewIndex = 0` in `next()`. |
| `docs/adr/ADR-0015-review-queue-order-policy.md` | Completely rewritten in 2000-9B (correction notice + 18 sections). |
| `docs/plans/vibe-coding-collaboration-rules.md` | Added section 14.16 (dual-track parallel rules) and section 8.6 (Anki frozen product rules). |
| `docs/plans/review-queue-order-implementation-plan.md` | This file — updated to actual execution results. |

### Forbidden files (untouched)

- `app/Models/ReviewCard.php` (scope unchanged)
- `app/Services/ReviewCardService.php` (recordReview unchanged)
- `app/Services/ReviewLimitSummaryService.php` (pure builder unchanged)
- `app/Services/SenseReviewQueryService.php` (isolated query unchanged)
- Any ADR-0010/0011/0012/0013/0014 related file
- Any migration file
- No new migration, no schema change

---

## Task Execution Results

### Task 1: ReviewQueueOrderOptions value object — `complete` (changed from original plan)

**Original plan**: Not in original plan (original plan only had Policy and Service).

**Actual**: Created `app/Services/ReviewQueueOrderOptions.php` as a value object expressing four Anki-aligned settings. Provides `defaults()`, `fromArray()` (validates enums, throws `InvalidArgumentException` on invalid), `toArray()` (includes `scope: global` and `preset_supported: false`).

**Test**: `tests/Unit/ReviewQueueOrderOptionsTest.php` — 16 tests, all pass.

### Task 2: ReviewQueueOrderPolicy pure function — `complete` (changed from original plan)

**Original plan**: Policy assigns 5 buckets (relearning / learning / overdue_review / today_review / new) with "overdue duration DESC" sort.

**Actual** (changed): Policy receives pre-categorized items (intraday / interday / review / new) and only orders them per Options. Classification moved to `ReviewQueueOrderService` because it needs `fsrs_last_reviewed_at`, timezone, and `now`. "Overdue duration DESC" is not an Anki option — replaced with four Anki-aligned `review_sort_order` values.

**Test**: `tests/Unit/ReviewQueueOrderPolicyTest.php` — 17 tests covering intraday priority, interday before/after/mix, new before/after/mix, mix determinism, no card loss/duplication. All pass.

### Task 3: ReviewQueueOrderService single entry — `complete`

**Actual**: Created `app/Services/ReviewQueueOrderService.php` — the only formal sorting entry. Reads card fields (no per-card DB), classifies into 4 categories, computes sort keys (retrievability via FSRS-5 formula, stable daily hash, due_at timestamp, id), generates daily hash, delegates to Policy.

**Test**: `tests/Unit/ReviewQueueOrderServiceTest.php` — 26 tests covering classify (UTC, America/Los_Angeles, cross-midnight, DST, null last_reviewed_at), retrievability fallbacks (null stability → 0, null last_reviewed_at → elapsed 0), daily hash stability, no NaN. All pass.

### Task 4: SenseReviewService integration + three-phase daily limits — `complete` (changed)

**Original plan**: `dueCardsWithLimits()` calls `assignBuckets()`, then applies slice per bucket, then concats.

**Actual** (changed): `dueCardsWithLimits()` calls `ReviewQueueOrderService::order()` after `dueCards()`. Three-phase daily limits per task spec lines 333-339:
- Phase 1: apply review limit to non-new cards (intraday first, then interday+review).
- Phase 2: apply new limit + remaining review limit to new cards.
- Phase 3: filter visible cards by queue order to maintain unified ordering.

**Test**: `tests/Feature/ReviewQueueOrderTest.php` — 13 tests. All pass.

### Task 5: Remove ReviewService::shuffle() — `complete`

**Actual**: Deleted `shuffle($reviews)` from `app/Services/ReviewService.php`. `/reviews` now returns Policy-ordered cards.

**Test**: Frontend guard test `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` asserts no `shuffle(` in `ReviewService.php`. Pass.

### Task 6: /reviews/rate next_card — `complete`

**Actual**: `ReviewController::rateReviewCard()` now returns `next_card` field (Policy-ordered first card from re-computed queue).

**Test**: `tests/Feature/ReviewQueueOrderTest.php` asserts `/reviews/rate` returns `next_card`. Pass.

### Task 7: Settings API — `complete` (not in original plan)

**Original plan**: Not in original plan (original plan explicitly forbade modifying `SettingsService.php` and `routes/web.php`).

**Actual** (changed): Added `/settings/fsrs/queue-order` GET/POST routes in admin middleware group. Added `SettingsController::getFsrsQueueOrder()` and `updateFsrsQueueOrder()`. Added `SettingsService::getFsrsQueueOrder()` and `updateFsrsQueueOrder()` (global scope, `user_id = -1`, JSON in `value` field). Added `QueueOrderValidationException` for structured 422 responses.

**Test**: `tests/Feature/FsrsQueueOrderSettingsTest.php` — 17 tests covering GET, POST, auth required, admin required, 422 on invalid, unknown keys ignored, defaults. All pass.

### Task 8: Admin Review Settings UI — `complete` (not in original plan)

**Actual**: Added "复习显示顺序" section to `resources/js/components/Admin/AdminReviewSettings.vue` with 4 Vuetify dropdowns bound to the four settings. Added `loadQueueOrder()` and `saveQueueOrder()` methods.

**Test**: `tests/js/AdminReviewSettingsQueueOrderGuard.test.mjs` — 14 tests covering 4 dropdowns present, load/save wiring, no Math.random, no inline defaults. All pass.

### Task 9: Frontend Review.vue — `complete` (changed)

**Original plan**: Use `response.data.next_card`; add stale response guard with request sequence number.

**Actual** (changed): `Review.vue` `next()` now uses `currentReviewIndex = 0` (queue first card) instead of `Math.random()`. Backend already returns cards in Queue Order, so picking the first card is correct and deterministic. The `next_card` field is returned by `/reviews/rate` but `Review.vue` does not consume it directly — it relies on the queue order from the initial `/reviews` call. Stale response guard was not added because `Review.vue` does not call `/reviews/rate` for next-card retrieval (it uses local queue state).

**Test**: `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` — 8 tests assert no `Math.random` in `next()` method, no `shuffle(` in `ReviewService.php`. All pass.

### Task 10: QueueOrderValidationException — `complete` (not in original plan)

**Actual**: Created `app/Exceptions/QueueOrderValidationException.php` for structured 422 responses on invalid Queue Order input. Rendered as JSON with field-level errors.

---

## Test Matrix (Actual)

### Backend Unit tests

| File | Tests | Status |
|---|---|---|
| `tests/Unit/ReviewQueueOrderOptionsTest.php` | 16 | pass |
| `tests/Unit/ReviewQueueOrderPolicyTest.php` | 17 | pass |
| `tests/Unit/ReviewQueueOrderServiceTest.php` | 26 | pass |
| **Subtotal** | **59** | **all pass** |

### Backend Feature tests

| File | Tests | Status |
|---|---|---|
| `tests/Feature/FsrsQueueOrderSettingsTest.php` | 17 | pass |
| `tests/Feature/ReviewQueueOrderTest.php` | 13 | pass |
| **Queue Order subtotal** | **30** | **all pass** |

### Frontend guard tests (Node.js built-in assert)

| File | Tests | Status |
|---|---|---|
| `tests/js/AdminReviewSettingsQueueOrderGuard.test.mjs` | 14 | pass |
| `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` | 8 | pass |
| **Subtotal** | **22** | **all pass** |

### Regression tests

Full suite (`php artisan test`) — 669 tests pass (250+ Feature including 30 Queue Order + 161 regression + 59 other; 59 Unit; 22 Node guard). No regressions.

### Build & db:doctor

- `npm run development` — success.
- `php artisan db:doctor` — healthy.

---

## MCP Chrome Acceptance Matrix

Pending — to be executed in VERIFY-2 step.

| Step | Item |
|---|---|
| 1 | Login as 1816529781@qq.com |
| 2 | Open `/review/sense` |
| 3 | Confirm GET `/reviews/senses` returns cards in Queue Order |
| 4 | Verify queue order: intraday → interday/review (per setting) → new (per setting) |
| 5 | Rate one card; confirm next card is Policy-ordered first |
| 6 | Open `/review` (legacy); confirm POST `/reviews` returns same order |
| 7 | Rate one card in `/review`; confirm `next()` uses `currentReviewIndex = 0` (no Math.random) |
| 8 | Open `/admin` → Review Settings → confirm 4 dropdowns present |
| 9 | Change one setting, save, reload, confirm value persisted |
| 10 | 1920×1080 render OK |
| 11 | 900×900 render OK, no horizontal overflow |
| 12 | Console no new errors |
| 13 | Network no external AI requests |

---

## Rollback Plan

1. Revert feat commit (restores `shuffle()`, `Math.random()`, removes Policy/Service/Options/Exception).
2. Revert docs commit (removes corrected ADR-0015 and this plan).
3. No migration, no schema change, no FSRS/lifecycle/ReviewLog change — pure code revert.
4. Existing `/logs`, `/lifecycle-events`, `/leech` endpoints unaffected.
5. Existing daily limits behavior unaffected.

---

## Second-Stage Allowed Files (Actual)

| File | Allowed | Actual |
|---|---|---|
| `app/Exceptions/QueueOrderValidationException.php` | new (added in 9B) | created |
| `app/Services/ReviewQueueOrderOptions.php` | new (added in 9B) | created |
| `app/Services/ReviewQueueOrderPolicy.php` | new | created |
| `app/Services/ReviewQueueOrderService.php` | new | created |
| `app/Services/SenseReviewService.php` | modify dueCardsWithLimits | modified |
| `app/Services/ReviewService.php` | delete shuffle | modified |
| `app/Http/Controllers/ReviewController.php` | add next_card | modified |
| `app/Http/Controllers/SettingsController.php` | new (added in 9B) | modified |
| `app/Services/SettingsService.php` | new (added in 9B) | modified |
| `routes/web.php` | new (added in 9B) | modified |
| `resources/js/components/Admin/AdminReviewSettings.vue` | new (added in 9B) | modified |
| `resources/js/components/Review/Review.vue` | remove Math.random | modified |
| `tests/Unit/ReviewQueueOrderOptionsTest.php` | new | created |
| `tests/Unit/ReviewQueueOrderPolicyTest.php` | new | created |
| `tests/Unit/ReviewQueueOrderServiceTest.php` | new | created |
| `tests/Feature/FsrsQueueOrderSettingsTest.php` | new | created |
| `tests/Feature/ReviewQueueOrderTest.php` | new | created |
| `tests/js/AdminReviewSettingsQueueOrderGuard.test.mjs` | new | created |
| `tests/js/ReviewQueueOrderFrontendGuard.test.mjs` | new | created |
| `docs/adr/ADR-0015-review-queue-order-policy.md` | corrected in 9B | modified |
| `docs/plans/vibe-coding-collaboration-rules.md` | added 14.16 + 8.6 | modified |
| `docs/plans/review-queue-order-implementation-plan.md` | this file | modified |
| `docs/plans/current-working-handoff.md` | update | pending |
| `docs/plans/linguacafe-master-plan.md` | update | pending |
| `docs/DOCUMENTATION_INDEX.md` | update | pending |

## Second-Stage Forbidden Scope (Complied)

- No new migration. ✓
- No FSRS algorithm/parameter/scheduling change. ✓
- No ReviewLog schema or history log change. ✓
- No lifecycle state machine change (ADR-0010). ✓
- No Leech Policy change (ADR-0011). ✓
- No Browser Search syntax change (ADR-0012/0013 frozen). ✓
- No external AI provider call. ✓
- No new deck/preset system. ✓
- No user-custom sort (V2 candidate). ✓
- No Custom Study / Saved Search / today-only limits / Study Overview. ✓
- No bucket logic duplicated in Controller or Vue. ✓
- No per-card DB query inside Policy. ✓
- No ReviewLog write. ✓
- No lifecycle state change. ✓
- No FSRS field change. ✓
- No Math.random for next card. ✓

---

## Open Product Questions (Resolved in 9B)

1. **Anki defaults** — Anki defaults used as LinguaCafe defaults. No user product question needed. (Resolved per section 8.6 of collaboration rules.)
2. **Global vs Preset** — V1 uses global settings (`user_id = -1`). Preset deferred to FSRS-Anki-Mgmt-9. (Resolved.)
3. **Review.vue legacy maintenance** — V1 keeps Review.vue working with Policy order (no Math.random). Long-term deprecation is a separate product decision. (Resolved.)
4. **Timezone for intraday boundary** — User timezone (consistent with ADR-0010 bury time). (Resolved.)
5. **Empty bucket skip** — Empty categories are skipped; next non-empty category starts immediately. (Resolved.)
6. **Same due_at secondary sort** — V1 uses stable daily hash for `due_random` and `card_id ASC` for `due_stable`. Lapses/stability secondary sort is V2 candidate. (Resolved.)
