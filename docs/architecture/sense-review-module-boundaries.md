# SenseReview Module Boundaries

> **Status**: Current as of 2026-07-10 (daily summary + batch feedback round).
> **Scope**: Describes the container/sub-component/service boundaries for the SenseReview page (`/reviews/senses`).
> **Related**: `docs/architecture/sense-http-controller-boundaries.md`, `docs/testing/sense-review-understanding-helper-playbook.md`.

## 1. Container Responsibilities

`resources/js/components/Senses/SenseReview.vue` (~771 lines) is the page container. It is responsible for:

- Loading the review queue (`GET /reviews/senses`).
- Maintaining the current card index and navigation.
- Calling the rating API (`POST /reviews/senses/{id}/rate`).
- Coordinating dialogs (source context, edit, more menu).
- Maintaining page-session state (session tracker, summary visibility).
- Handling page-level routing and snackbar.
- Delegating rendering to sub-components via props/events.

The container does **not**:
- Directly query ReviewLog (backend service handles this).
- Render large inline template blocks for learning feedback, rating controls, understanding aid, edit dialog, or session summary (all delegated to sub-components).
- Implement FSRS logic (delegated to backend).

## 2. Sub-Components

### 2.1 SenseReviewLearningFeedbackPanel.vue (~226 lines)

**Responsibility**: Read-only display of learning feedback (学习状态 + 遗忘情况).

- **Props**: `learningFeedback` (Object, default null), `fsrsStability` (Number, default null).
- **Emits**: none.
- **Owns**: collapse state (`learningFeedbackOpen`, `forgettingPatternOpen`).
- **Constraints**: no API calls, no ReviewLog writes, no FSRS modifications, no rating logic.

### 2.2 SenseReviewRatingControls.vue (~63 lines)

**Responsibility**: Four rating buttons + hotkey hints.

- **Props**: `disabled` (Boolean, default false).
- **Emits**: `rating` with `'again' | 'hard' | 'good' | 'easy'`.
- **Constraints**: no direct API calls, no FSRS logic, rating values must remain again/hard/good/easy.

### 2.3 SenseReviewSessionSummary.vue (~144 lines)

**Responsibility**: Session summary display (评分分布 + 重点词义).

- **Props**: `stats` (Object), `needsAttention` (Array).
- **Emits**: `continue-review`, `exit`.
- **Constraints**: no API calls, no ReviewLog writes. Pure presentational.

### 2.4 SenseReviewUnderstandingAid.vue (~103 lines)

**Responsibility**: Collapsible "理解这个词义" block.

- **Props**: `aid` (Object, default `{}`).
- **Emits**: none.
- **Owns**: collapse state (`open`, default false).
- **Constraints**: pure presentational, gates rendering on `hasAnyContent` computed.

### 2.5 SenseReviewEditDialog.vue (~207 lines)

**Responsibility**: Edit dialog for sense card fields.

- **Props**: `value` (Boolean, v-model), `card` (Object).
- **Emits**: `input` (v-model close), `saved` (persisted card payload).
- **Owns**: form state, pre-fills on dialog open.
- **Constraints**: calls `PATCH /review-cards/manage/{id}` on save. No direct FSRS/ReviewLog modifications.

### 2.6 SenseReviewTodaySummary.vue (~210 lines)

**Responsibility**: Daily cross-session summary display (今日复习总结). Distinct from `SenseReviewSessionSummary` (本次复习总结) which is page-load-scoped.

- **Props**: `summary` (Object from `GET /reviews/senses/today-summary`).
- **Emits**: `close`.
- **Constraints**: pure presentational. No API calls, no ReviewLog writes, no FSRS modifications, no card queue mutations. Parent loads the data and passes it in. Empty state shows "今天还没有完成词义卡复习。" with no fake charts.
- **Content**: timezone/day/day_start/day_end, total_reviews, distinct_senses, 4-rating distribution, forget_rate (null when empty), focus_senses (max 10, aggregated by sense), recent_reviews (max 10, newest first).

### 2.7 SenseReviewSessionTracker.js (~122 lines)

**Responsibility**: Pure-function session tracker (no Vue dependency).

- **Exports**: `createSession()`, `recordRating()`, `getStats()`, `getNeedsAttention()`, `clearSession()`.
- **Constraints**: immutable updates, requestId dedup, no persistence (page-load scoped).

## 3. Backend Service Boundaries

### 3.1 SenseReviewLearningFeedbackService.php (~181 lines)

**Responsibility**: Single source of truth for ReviewLog aggregation and learning feedback computation.

- **Public method**: `buildForCard(int $reviewCardId): array`.
- **Computes**: total_reviews, again/hard/good/easy counts, recent_reviews, forgetting_pattern (total_forget, forget_rate, last_forget_date, trend).
- **Constants**: `RATING_LABELS` (again→忘了, hard→勉强, good→记得, easy→很熟).
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS fields. Excludes reset-type logs (rating='reset' OR source='reset'). User/card isolation via review_card_id scoping.

### 3.2 SenseReviewCardSerializerService.php (~266 lines)

**Responsibility**: Assemble the final card payload for `/reviews/senses` and `rate()`.

- **Delegates**: `learning_feedback` computation to `SenseReviewLearningFeedbackService::buildForCard()`.
- **Owns**: example selection, occurrence evidence merge, understanding_aid normalization, FSRS field passthrough.
- **Constraints**: does NOT directly query ReviewLog. Does NOT own rating label logic. Payload shape and semantics remain 100% backward-compatible.

### 3.3 SenseReviewTodaySummaryService.php (~200 lines)

**Responsibility**: Read-only cross-session daily aggregate for the "今日复习总结" feature. Distinct from `SenseReviewLearningFeedbackService` (per-card history) — this service aggregates across ALL cards for the current user/language/today.

- **Public method**: `build(int $userId, string $language): array`.
- **Reuses**: `SenseReviewQueryService::nonResetSenseReviewLogQuery()` for the shared sense-only / user-isolated / language-isolated / reset-excluded log base. This guarantees the reset exclusion rule is identical to `ReviewStatsService::reviewActivity()` and `SenseReviewLearningFeedbackService`.
- **Computes**: timezone/day/day_start/day_end, total_reviews, distinct_senses, 4-rating distribution, forget_rate (null when empty), focus_senses (max 10, aggregated by sense_id), recent_reviews (max 10, newest first).
- **Day boundary**: `Carbon::today($timezone)` (00:00:00) to `Carbon::tomorrow($timezone)` (exclusive). Timezone = `config('app.timezone', 'UTC')`. No user timezone introduced this round.
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table.

### 3.4 SenseReviewTodaySummary endpoint

- **Route**: `GET /reviews/senses/today-summary`.
- **Controller**: `SenseReviewController::todaySummary()` — thin: reads user + language, delegates to service, returns JSON.
- **Auth**: required (middleware). Strict user + language isolation (enforced by service via `SenseReviewQueryService`).
- **No writes**: does not write ReviewLog, does not change ReviewCard/FSRS, does not create WordSense/ReviewCard.

## 4. Session Summary vs Today Summary

**Session summary (本次复习总结)**:
- Source: `SenseReviewSessionTracker.js` (pure frontend, page-load scoped).
- Resets on page refresh.
- Tracks only ratings that happened AFTER the page was opened.
- No backend call, no persistence.

**Today summary (今日复习总结)**:
- Source: backend `ReviewLog` via `GET /reviews/senses/today-summary`.
- Cross-session: merges ALL of today's real ratings across multiple page sessions.
- Persists across page refreshes (uses ReviewLog as source of truth).
- Read-only: never writes ReviewLog, never touches FSRS.

Both coexist on the SenseReview page with clearly distinct wording.

## 5. Props / Events Contract Summary

| Component | Props | Emits |
|-----------|-------|-------|
| LearningFeedbackPanel | learningFeedback, fsrsStability | — |
| RatingControls | disabled | rating(again\|hard\|good\|easy) |
| SessionSummary | stats, needsAttention | continue-review, exit |
| TodaySummary | summary | close |
| UnderstandingAid | aid | — |
| EditDialog | value (v-model), card | input, saved |
| SessionTracker | (pure functions, not a component) | — |

## 6. N+1 Risk Assessment

**Current state**: `SenseReviewLearningFeedbackService::buildForCard()` queries ReviewLog per card (one query per serialized card). When the `/reviews/senses` endpoint serializes N due cards, this produces N ReviewLog queries.

**Risk level**: P1 (potential N+1 for large review queues).

**Current mitigation**: Review queues are typically small (daily due cards, capped by `daily_review_limit`). The per-card query is indexed by `review_card_id` and returns a small result set (latest 6 non-reset logs).

**Batch optimization (Task B, this round)**: A `buildForCards(array $reviewCardIds): array` method batch-loads ReviewLogs for all due cards in a single query. See section 7 for details.

## 7. Next-Round Architecture Candidates

1. **Batch ReviewLog aggregation** — eliminate per-card N+1 in queue serialization.
2. **Source context batch loading** — `SenseSourceContextService::sourceContextList` currently loads chapter data per source; could batch for multi-source cards.
3. **Session summary persistence** — if users request cross-refresh session continuity, consider sessionStorage (still no DB writes).
4. **Understanding aid occurrence-level evidence** — further merge occurrence-level `explanation` and `meaning_boundary` (currently sense-level only for these two fields).
