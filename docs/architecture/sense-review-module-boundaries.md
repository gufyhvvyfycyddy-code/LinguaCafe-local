# SenseReview Module Boundaries

> **Status**: Current as of 2026-07-10 (SenseReview modularization round).
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

### 2.6 SenseReviewSessionTracker.js (~122 lines)

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

## 4. Props / Events Contract Summary

| Component | Props | Emits |
|-----------|-------|-------|
| LearningFeedbackPanel | learningFeedback, fsrsStability | — |
| RatingControls | disabled | rating(again\|hard\|good\|easy) |
| SessionSummary | stats, needsAttention | continue-review, exit |
| UnderstandingAid | aid | — |
| EditDialog | value (v-model), card | input, saved |
| SessionTracker | (pure functions, not a component) | — |

## 5. N+1 Risk Assessment

**Current state**: `SenseReviewLearningFeedbackService::buildForCard()` queries ReviewLog per card (one query per serialized card). When the `/reviews/senses` endpoint serializes N due cards, this produces N ReviewLog queries.

**Risk level**: P1 (potential N+1 for large review queues).

**Current mitigation**: Review queues are typically small (daily due cards, capped by `daily_review_limit`). The per-card query is indexed by `review_card_id` and returns a small result set (latest 6 non-reset logs).

**Batch optimization (NOT implemented this round)**: A future round could add a `buildForCards(array $reviewCardIds): array` method that batch-loads ReviewLogs for all due cards in a single query. This would require:
1. No change to public payload shape.
2. No change to Controller routes.
3. No change to ReviewLog/FSRS semantics.
4. A query-count regression test.
5. Architecture review approval.

**Recorded as**: Next-round P1 architecture candidate.

## 6. Next-Round Architecture Candidates

1. **Batch ReviewLog aggregation** — eliminate per-card N+1 in queue serialization.
2. **Source context batch loading** — `SenseSourceContextService::sourceContextList` currently loads chapter data per source; could batch for multi-source cards.
3. **Session summary persistence** — if users request cross-refresh session continuity, consider sessionStorage (still no DB writes).
4. **Understanding aid occurrence-level evidence** — further merge occurrence-level `explanation` and `meaning_boundary` (currently sense-level only for these two fields).
