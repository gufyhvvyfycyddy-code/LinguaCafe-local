# ADR-0008: SenseReview Answer Interval Preview

Date: 2026-07-10

## Status

Accepted

## Context

The SenseReview page shows four rating buttons (忘了 / 勉强记得 / 记得 / 很熟) after the user clicks "显示答案". Currently, the user has no idea what each rating will do to the card's next review schedule until they actually click it.

Anki's Answer Buttons show the estimated next review interval on each rating button, helping users make an informed choice without guessing. We want to bring the same UX to LinguaCafe's SenseReview.

The existing `FsrsSchedulingService::schedule()` method is already a **pure projection**: it takes a `ReviewCard`, a rating, and a `reviewedAt` timestamp, and returns an array with `state`, `due_at`, `stability`, `difficulty`, `lapses`, `reviewed_at`. It does **not** save the model, create a `ReviewLog`, or modify any database state. This means preview and real rating can share the exact same scheduling core without any extraction.

## Anki Reference

- **Anki Manual: Studying → Answer Buttons** — after showing the answer, each of the four buttons displays the estimated next review time.
- **Anki Manual: Deck Options → FSRS** — `desired_retention` affects scheduling; the preview must use the same retention as real scheduling.
- **Anki GitHub: reviewer / rslib** — the scheduling computation is centralized; the UI only renders the result.

### Borrowed
1. Show estimated interval on each of the four buttons after the answer is revealed.
2. All four ratings use the same scheduling rules.
3. The preview helps the user understand the consequence; it does not make the decision for them.
4. The displayed value comes from the real scheduler; the frontend never estimates.

### Not Borrowed
- No new deck, learning steps, card template, or sibling cards.
- No undo, bury, suspend, leech, or custom study.
- No change to `desired_retention`, FSRS parameters, rating keys, scores, labels, or hotkeys.

## Decision

### 1. Single pure projection core

`FsrsSchedulingService::schedule(ReviewCard $card, string $rating, ?Carbon $reviewedAt = null): array` is the **only** scheduling computation. Both preview and real rating call it.

- `ReviewCardService::recordReview()` calls `schedule()` once for the chosen rating, then applies the result to the model and saves.
- `SenseReviewIntervalPreviewService::preview()` calls `schedule()` four times (once per rating) and returns the projections without applying them.

### 2. Batch projection method

A new `FsrsSchedulingService::previewAllRatings(ReviewCard $card, ?Carbon $reviewedAt = null): array` method calls `schedule()` for each of the four ratings (`again`, `hard`, `good`, `easy`) and returns a structured array. The rating order comes from `SenseReviewRatingContract::allowedRatings()` — there is no fifth rating map.

### 3. Read-only endpoint

```
GET /reviews/senses/{reviewCard}/interval-preview
```

Returns:

```json
{
  "review_card_id": 99,
  "generated_at": "2026-07-11T10:00:00Z",
  "timezone": "UTC",
  "engine": "fsrs",
  "ratings": {
    "again": { "due_at": "...", "interval_seconds": 600, "next_state": "relearning" },
    "hard":  { "due_at": "...", "interval_seconds": 86400, "next_state": "review" },
    "good":  { "due_at": "...", "interval_seconds": 345600, "next_state": "review" },
    "easy":  { "due_at": "...", "interval_seconds": 777600, "next_state": "review" }
  }
}
```

- GET-only.
- Access control: current user, current language, `target_type=sense`, WordSense `status=confirmed`, `fsrs_enabled=true`.
- Other user / other language / legacy word card / rejected sense → 404.
- Disabled (`fsrs_enabled=false`) card → 404 (preview is for active review cards only).
- Does not write `ReviewLog`, does not modify any FSRS field, does not change the queue.
- Query budget is constant (one `ReviewCard` fetch + one `WordSense` fetch).

### 4. "预计" wording

The UI uses "预计" (estimated) — not "将在" (will be at) — to avoid implying a guarantee. The `generated_at` timestamp acknowledges that the real click may happen a few seconds later, producing a tiny time delta. This is acceptable and does not affect the interval bucket shown to the user.

### 5. engine field

The `engine` field (`fsrs` or `fallback`) is included in the payload for diagnostics/testing but is **not** displayed to the user. Both paths produce the same payload structure.

### 6. Race protection

The frontend uses a `requestSequence` counter. When the card changes, the sequence increments and stale responses are discarded. This prevents Card A's preview from being written into Card B's state.

### 7. Preview failure does not block rating

If the preview endpoint returns 404/500 or the request fails, the four rating buttons remain fully functional. A single shared hint ("预计时间暂不可用，仍可正常评分。") is shown; the error is not duplicated on each button.

### 8. Fuzz

The FSRS extension may apply small fuzz to intervals. Since preview and real rating both call `schedule()` with the same inputs, and `schedule()` uses `Carbon::now()` internally when `reviewedAt` is null, the preview is computed at request time. If fuzz is non-deterministic, the preview and real rating may differ by a small amount. This is acceptable because:
- The user sees a **bucket** ("预计 1 天") not an exact second.
- The parity test compares the projection result, not the fuzzed display bucket.
- Both paths use the same `schedule()` call, so there is no algorithmic divergence.

### 9. No migration

No database schema changes. No new columns. No new tables. The preview is computed in-memory from existing `ReviewCard` fields.

## Rollback

To roll back:
1. Remove the `GET /reviews/senses/{reviewCard}/interval-preview` route.
2. Remove `SenseReviewIntervalPreviewService`.
3. Remove `previewAllRatings()` from `FsrsSchedulingService`.
4. Remove the frontend interval-preview state from `SenseReview.vue` and the interval display from `SenseReviewRatingControls.vue`.
5. Remove `SenseReviewIntervalPresentation.js`.

The existing rating flow (`POST /reviews/senses/{reviewCard}/rate`) is completely unaffected because it never depended on the preview.
