# ADR-0007: SenseReview Report Card Deep Link

**Date**: 2026-07-10
**Status**: Accepted

## Context

The SenseReview daily report (`GET /reviews/senses/daily-report`) shows three
clickable insight lists: `focus_senses`, `progress_senses`, and `recent_reviews`.
Users want to click any item in these lists and jump directly to the exact
sense ReviewCard management detail — not just the management page home, and not
a fuzzy lemma search that may miss the target card due to pagination or filters.

This is inspired by Anki's Browser / Card Info design, where a user can open
the exact card from the statistics or study screen using a precise card ID.
Anki separates content objects (notes) from scheduling objects (cards) and
provides a central Browser for searching, card details, and review history.

### Current state

- `SenseReviewAnalyticsQueryService::reviewsForPeriod()` already selects
  `review_logs.review_card_id` and `review_cards.target_id as word_sense_id`
  in every log row.
- `SenseReviewDailyInsightBuilder` builds the three insight lists but does NOT
  output `review_card_id` or `word_sense_id` — the IDs are available in the
  in-memory logs but discarded during shaping.
- `ReviewCardManageController::findManageableSenseCard()` is a private method
  that validates card ownership (user + language + target_type=sense) and
  WordSense status (confirmed). It is duplicated implicitly by every controller
  action that calls it.
- `ReviewCardManage.vue` does not read route query parameters and has no way
  to deep-link to a specific card detail.
- The `review_cards` table has a unique index on
  `(user_id, language_id, target_type, target_id)`, so one confirmed WordSense
  maps to exactly one sense ReviewCard — there is no multi-card ambiguity.

### Anki references consulted

- Anki Manual: Studying — review screen can open the current card in Browser.
- Anki Manual: Browsing — Browser is the central search/detail/history entry.
- Anki Manual: Statistics / Card Info — Card Info uses exact card ID, not text
  search.
- Anki Manual: Deck Options — scheduling options are separate from content.
- Anki GitHub: rslib / proto / ts / Qt layering — content vs scheduling
  separation, access control not duplicated in UI components.

### Anki designs adopted

1. From statistics/review screen, open the exact card in Browser (card ID
   precision, not fuzzy text search).
2. Browser is the central entry for search, card detail, and review history.
3. Content objects and scheduling objects are separated.
4. Page components do not duplicate scheduling or access-control logic.
5. Card Info uses the exact card ID.

### Anki designs NOT copied

- No new deck, note type, card template, or sibling cards.
- No predicted intervals, undo rating, bury/suspend, leech, or custom study.
- No FSRS changes.

## Decision

### 1. review_card_id is the primary navigation key

Every clickable item in `focus_senses`, `progress_senses`, and `recent_reviews`
will carry both `review_card_id` (primary navigation key) and `word_sense_id`
(diagnostic field). The frontend deep link uses `review_card_id` to open the
exact card; `word_sense_id` is carried for diagnostics only and never replaces
the card ID.

**Why review_card_id, not word_sense_id**: The management page operates on
ReviewCard objects (edit, archive, reset, delete, logs). Using the card ID
ensures the exact scheduling object is opened, matching Anki's Card Info
precision.

**Why word_sense_id is still in the payload**: It allows the frontend to
display a diagnostic chip and helps debugging if the card was deleted but the
sense still exists. It never replaces card ID for navigation.

### 2. ID source: in-memory map in InsightBuilder (0 DB queries)

`SenseReviewDailyInsightBuilder` will build a `word_sense_id → review_card_id`
map from the same in-memory log collection it already receives. This adds zero
database queries. The Metrics layer (`reviewsBySense()`) stays unchanged — it
remains focused on counts/rates/aggregation, and navigation IDs are a product
output concern that belongs in the Insight layer.

Because the `review_cards` table has a unique constraint on
`(user_id, language_id, target_type, target_id)`, all logs for the same
`word_sense_id` share the same `review_card_id`. The map is deterministic.

### 3. Route query contract

```
/review-cards/manage?review_card_id={positive-int}&from={source-whitelist}
```

- `review_card_id`: positive integer, required for deep link.
- `from`: source whitelist (`daily-report`, `seven-day-trend`,
  `thirty-day-calendar`). Used for the "return" button label.
- Invalid/missing `review_card_id` → no deep link, management page loads
  normally.

### 4. Detail endpoint contract

```
GET /review-cards/manage/{reviewCard}/detail
```

- Read-only GET.
- Returns the serialized card item (same shape as
  `ReviewCardManageItemSerializerService::serializeCard()`).
- Access control via `ReviewCardManageAccessService::findManageableSenseCardOrFail()`.
- 404 for: not found, other user, other language, legacy word card, rejected
  sense, deleted sense.
- Archived sense cards are allowed (fsrs_enabled=false is not a 404).
- Does not write ReviewLog, does not modify FSRS.

### 5. ReviewCardManageAccessService

Extracts `findManageableSenseCard()` from the Controller into a dedicated
service. All controller actions (update, enabled, dueNow, reset, destroy,
logs, detail) reuse the same access check. The service validates:

- review_card_id exists
- current user owns the card
- current language matches
- target_type = sense
- WordSense belongs to current user
- WordSense belongs to current language
- WordSense status = confirmed

Returns `[$card, $sense]` or aborts 404.

### 6. Frontend DeepLink Helper

`resources/js/services/ReviewCardManageDeepLink.js` — pure functions:
- `buildReviewCardManageLocation(target, source)` → router location object
- `parseReviewCardManageLocation(query)` → `{review_card_id, from}` or null

No axios, no Vue, no DOM, no state writes.

### 7. No FSRS / ReviewLog / DB schema changes

This ADR does not touch FSRS scheduling, ReviewLog writes, or database
structure. The daily report remains read-only. The detail endpoint is
read-only. No migration needed.

## Consequences

- The daily report payload grows by two additive fields per clickable item
  (`review_card_id`, `word_sense_id`). Existing fields are unchanged.
- The query budget for the daily report stays at 1 (empty) / 2 (non-empty)
  ReviewLog queries — no new queries for navigation IDs.
- The management page gains a new mount-time route-query parsing path and a
  "返回学习报告" button. Existing list/filter/pagination behavior is
  unchanged.
- `ReviewCardManageAccessService` becomes the single source of truth for
  sense-card access control. The Controller becomes thinner.

## Rollback

Revert both commits. The changes are additive (new service, new endpoint, new
helper, new fields) with no schema changes, so rollback is safe.
