# SenseReview Module Boundaries

> **Status**: Current as of 2026-07-10 (report architecture consolidation: Period + DailySeries + ReportCenter).
> **Scope**: Describes the container/sub-component/service boundaries for the SenseReview page (`/reviews/senses`).
> **Related**: `docs/architecture/sense-http-controller-boundaries.md`, `docs/testing/sense-review-understanding-helper-playbook.md`.

## 0. Five-Layer Report Architecture

All SenseReview analytics flows are split into five layers:

```
Period Layer        SenseReviewReportPeriodService     Pure time-window math (zero DB)
      ↓
Query Layer         SenseReviewAnalyticsQueryService   DB reads + user/language/sense/reset isolation
      ↓
Metrics Layer       SenseReviewReportMetricsService    Pure computation, zero DB queries
      ↓
Series Layer        SenseReviewDailySeriesBuilder      Zero-fill daily series (reuses Metrics)
      ↓
Product Service     TodaySummary / DailyReport /       Decides product payload, focus-sense rules,
                    SevenDayTrend / LearningFeedback   recent-count, max-10, day-boundary fill
      ↓
Controller          SenseReviewController              Request coordination only
```

- **Period Layer** (`SenseReviewReportPeriodService`): pure time-window math. `rollingDays(int $days, string $timezone)` returns `start_day`, `end_day`, `start` (inclusive), `end` (exclusive), `day_keys` (ascending). Zero DB queries. No Auth, no config, no .env. Rejects 0/negative/>365.
- **Query Layer** (`SenseReviewAnalyticsQueryService`): only `reviewsForPeriod()`, `sensesReviewedBefore()`, `reviewsForCards()`. No rating labels, no scores, no product copy, no sort/limit.
- **Metrics Layer** (`SenseReviewReportMetricsService`): pure functions — `ratingDistribution`, `forgetRate`, `stabilityRate`, `averageRating`, `distinctSenseCount`, `groupByDay`, `reviewsBySense`, `periodMetrics`. Zero DB queries (locked by test). No Auth, no config, no product copy.
- **Series Layer** (`SenseReviewDailySeriesBuilder`): `build(Collection $logs, array $dayKeys): array` — produces one entry per day key, zero-filled for empty days (null rates, NOT "0%"). Reuses Metrics. Zero DB queries. No product copy.
- **Rating Contract** (`SenseReviewRatingContract`): single source of truth for `allowedRatings()`, `isAllowed()`, `labelFor()`, `scoreFor()`. Pure value object, no DB/Auth/config. Fail-closed for invalid rating.
- **Product Services**: compose Period + Query + Metrics + Series + Contract, keep product rules (focus-sense max-10, recent-count, payload field names, summary block).
- **Controller**: thin — reads user + language, delegates to service, returns JSON.

## 0.1 ReportCenter UI Orchestration

`SenseReviewReportCenter.vue` is the single orchestration component for all report dialogs. It replaces the three previously duplicated dialog/loading/payload/GET patterns in `SenseReview.vue`.

- **v-model / activeReport**: `null` | `'today-summary'` | `'daily-report'` | `'seven-day-trend'` | `'thirty-day-calendar'`.
- **Parent** (`SenseReview.vue`) only sets `activeReport`; ReportCenter handles dialog, loading, GET request, error state, and close (emits `input` null).
- **Only read-only GET requests**. Never POSTs ratings. Never writes ReviewLog. Never touches FSRS.
- **SessionSummary is NOT managed here** (it is page-load scoped, not a backend-report dialog).
- `SenseReview.vue` reduced from 926 → 779 lines after extraction.

## 1. Container Responsibilities

`resources/js/components/Senses/SenseReview.vue` (~779 lines) is the page container. It is responsible for:

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

### 2.8 SenseReviewSevenDayTrend.vue (~200 lines)

**Responsibility**: Presentational display of the fixed rolling 7-day learning trend (近 7 天学习趋势). Distinct from SessionSummary (本次复习总结), TodaySummary (今日复习总结), and DailyReport (今日学习日报).

- **Props**: `trend` (Object from `GET /reviews/senses/seven-day-trend`).
- **Emits**: `close`.
- **Constraints**: pure presentational. No API calls, no ReviewLog writes, no FSRS modifications, no card queue mutations, no chart library. Uses Vuetify v-card/v-row/v-col/v-chip + simple CSS bars.
- **Content**: timezone/start_day/end_day, summary (total_reviews/active_days/distinct_senses/average_per_active_day/distribution/forget_rate/stability_rate), 7 fixed day rows (ascending, zero-filled with null rates for empty days).
- **Empty state**: "近 7 天还没有完成词义卡复习。" with 7 day rows still shown, no misleading percentages.
- **Window definition**: today + previous 6 natural days (NOT natural week, NOT configurable). Backend timezone. Real-time ReviewLog reads, no snapshot.

## 3. Backend Service Boundaries

### 3.1 SenseReviewLearningFeedbackService.php (~280 lines)

**Responsibility**: Per-card ReviewLog aggregation for the `learning_feedback` payload. Supports both single-card and batch paths via a shared `buildFeedbackFromLogs()` algorithm.

- **Public methods**:
  - `buildForCard(int $reviewCardId): array` — delegates to `buildForCards([$id])` (single source of truth).
  - `buildForCards(array $reviewCardIds): array` — batch path: ONE ReviewLog query for all cards, in-memory aggregation by `review_card_id`. Returns `[review_card_id => feedback_payload]`.
- **Shared algorithm**: `buildFeedbackFromLogs(Collection $logs)` — used by both public methods. No duplicated aggregation logic.
- **Uses**: `SenseReviewAnalyticsQueryService::reviewsForCards()` for DB reads, `SenseReviewReportMetricsService` for distribution/forget-rate computation, `SenseReviewRatingContract::labelFor()` for labels (post Task B migration). The `RATING_LABELS` constant has been removed — Contract is now the single source.
- **Computes**: total_reviews, again/hard/good/easy counts, recent_reviews (latest 5), forgetting_pattern (total_forget, forget_rate, last_forget_date, trend).
- **Query profile**: `buildForCards()` issues exactly 1 ReviewLog query regardless of card count (0 for empty input). `buildForCard()` issues 1 query (down from 7 before optimization). Batch path unchanged after Task B migration.
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS fields. Excludes reset-type logs (rating='reset' OR source='reset'). User/card isolation via review_card_id scoping. Duplicate ids in input are de-duplicated.

### 3.2 SenseReviewCardSerializerService.php (~310 lines)

**Responsibility**: Assemble the final card payload for `/reviews/senses` and `rate()`.

- **Public methods**:
  - `serialize(ReviewCard $card, array $options = []): array` — single-card path. Accepts optional `learning_feedback` in `$options` to skip the per-card query.
  - `serializeMany(Collection $cards, array $options = []): array` — batch path: calls `buildForCards()` once, passes the precomputed feedback map to each `serialize()` call. Exactly 1 ReviewLog query regardless of card count.
- **Delegates**: `learning_feedback` computation to `SenseReviewLearningFeedbackService`.
- **Owns**: example selection, occurrence evidence merge, understanding_aid normalization, FSRS field passthrough.
- **Constraints**: does NOT directly query ReviewLog. Does NOT own rating label logic. Payload shape and semantics remain 100% backward-compatible.

### 3.3 SenseReviewTodaySummaryService.php (~200 lines)

**Responsibility**: Read-only cross-session daily aggregate for the "今日复习总结" feature. Distinct from `SenseReviewLearningFeedbackService` (per-card history) — this service aggregates across ALL cards for the current user/language/today.

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewAnalyticsQueryService::reviewsForPeriod()` for DB reads, `SenseReviewReportMetricsService` for distribution/forget-rate/reviewsBySense, `SenseReviewRatingContract::labelFor()` for labels (post Task B migration).
- **Product rules**: timezone/day/day_start/day_end, total_reviews, distinct_senses, 4-rating distribution, forget_rate (null when empty), focus_senses (max 10, aggregated by sense_id), recent_reviews (max 10, newest first).
- **Day boundary**: `Carbon::today($timezone)` (00:00:00) to `Carbon::tomorrow($timezone)` (exclusive). Timezone = `config('app.timezone', 'UTC')`. No user timezone introduced this round.
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table.

### 3.3b SenseReviewDailyReportService.php (~250 lines)

**Responsibility**: Read-only richer four-block daily report for the "今日学习日报" feature. Distinct from TodaySummary (simpler) — DailyReport adds learning-quality, focus-senses, and progress-record sections.

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewAnalyticsQueryService::reviewsForPeriod()` + `sensesReviewedBefore()`, `SenseReviewReportMetricsService` for distribution/forget-rate/stability-rate/reviewsBySense, `SenseReviewRatingContract::scoreFor()` for average-rating computation (post Task B migration — the `RATING_SCORES` private const has been removed).
- **Product rules**: four sections (今日复习概览 / 今日学习质量 / 今日重点词义 / 今日进步记录), focus-sense max-10, first-review vs review-again detection via `sensesReviewedBefore()`.
- **Constraints**: READ-ONLY. Same invariants as TodaySummary.

### 3.3c SenseReviewSevenDayTrendService.php (~190 lines)

**Responsibility**: Read-only fixed rolling 7-day trend for the "近 7 天学习趋势" feature.

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewAnalyticsQueryService::reviewsForPeriod()` (single query for the whole 7-day window), `SenseReviewReportMetricsService::groupByDay()` + `ratingDistribution()` + `forgetRate()` + `stabilityRate()` + `distinctSenseCount()`.
- **Window**: today + previous 6 natural days (NOT natural week). `Carbon::today($timezone)` back 6 days. Backend timezone. Fixed 7-day array, ascending order, zero-filled for empty days (empty days have null rates, NOT "0%").
- **Summary**: total_reviews, active_days, distinct_senses, average_per_active_day (null when 0 active days), distribution, forget_rate, stability_rate.
- **Query budget**: exactly 1 ReviewLog query for the entire 7-day window regardless of sense count (1/10/50 senses → 1 query, locked by `SenseReviewSevenDayTrendTest`).
- **Today-row consistency**: the "today" row must match `GET /reviews/senses/daily-report` for total_reviews / distinct_senses / distribution / forget_rate / stability_rate (locked by contract test `test_today_row_matches_daily_report`).
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table. No snapshot persistence.

### 3.3d SenseReviewAnalyticsQueryService.php (Query Layer)

**Responsibility**: Centralized read-only ReviewLog statistics query layer. Single entry point for sense-review analytics DB reads.

- **Public methods** (Query Layer only — no pure computation):
  - `reviewsForPeriod(int $userId, string $language, Carbon $start, Carbon $end): Collection` — non-reset sense logs in [start, end), newest-first, with sense metadata.
  - `sensesReviewedBefore(int $userId, string $language, Carbon $before): array` — sense ids with any non-reset review before a time.
  - `reviewsForCards(array $cardIds): Collection` — non-reset logs for given card ids, newest-first. 1 query regardless of card count.
- **Removed in Task B**: `ratingDistribution`, `forgetRate`, `stabilityRate`, `reviewsBySense` (moved to `SenseReviewReportMetricsService`).
- **Delegates**: user/language/sense-only/reset-exclusion isolation to `SenseReviewQueryService::nonResetSenseReviewLogQuery()` / `nonResetCardReviewLogQuery()`.
- **Constraints**: READ-ONLY. No rating labels, no scores, no product copy, no sort/limit. No new database table.

### 3.3e SenseReviewRatingContract.php (Rating Contract)

**Responsibility**: Single source of truth for SenseReview rating metadata. Pure value object.

- **Methods**: `allowedRatings()`, `isAllowed($rating)`, `labelFor($rating)`, `scoreFor($rating)`.
- **Allowed ratings**: again / hard / good / easy.
- **Labels**: again→忘了, hard→勉强, good→记得, easy→很熟.
- **Scores**: again=1, hard=2, good=3, easy=4.
- **Invalid handling**: fail-closed — `labelFor`/`scoreFor` return null for invalid/null/case-mismatched ratings. Never silently treats invalid as `good`.
- **Constraints**: no DB, no Auth, no config, no state writes, no product copy.

### 3.3f SenseReviewReportMetricsService.php (Metrics Layer)

**Responsibility**: Pure computation layer for report metrics. Zero DB queries.

- **Methods**: `ratingDistribution(Collection)`, `forgetRate(Collection)`, `stabilityRate(Collection)`, `averageRating(Collection)`, `distinctSenseCount(Collection)`, `groupByDay(Collection)`, `reviewsBySense(Collection)`, `periodMetrics(Collection)`.
- **Uses**: `SenseReviewRatingContract::scoreFor()` for average-rating computation (single source of truth for scores).
- **groupByDay**: returns ONLY days with data (associative array `Y-m-d => Collection`). Zero-fill for empty days is the Product Service's responsibility, NOT Metrics'.
- **Constraints**: zero DB queries (locked by `SenseReviewReportMetricsServiceTest::test_metrics_service_does_not_access_database`). No Eloquent, no Auth, no config, no product copy, no sort/limit decisions.

### 3.4 SenseReviewTodaySummary endpoint

- **Route**: `GET /reviews/senses/today-summary`.
- **Controller**: `SenseReviewController::todaySummary()` — thin: reads user + language, delegates to service, returns JSON.
- **Auth**: required (middleware). Strict user + language isolation (enforced by service via `SenseReviewQueryService`).
- **No writes**: does not write ReviewLog, does not change ReviewCard/FSRS, does not create WordSense/ReviewCard.

## 4. Four Coexisting Summary / Report Concepts

The SenseReview page now has four clearly distinct analytics surfaces. They MUST NOT be conflated in wording or payload:

1. **本次复习总结 (Session Summary)** — `SenseReviewSessionSummary.vue` + `SenseReviewSessionTracker.js`. Frontend, page-load scoped. Resets on refresh. Tracks only ratings after page open. No backend call, no persistence.
2. **今日复习总结 (Today Summary)** — `SenseReviewTodaySummary.vue` + `SenseReviewTodaySummaryService` + `GET /reviews/senses/today-summary`. Simpler cross-session backend aggregate for today.
3. **今日学习日报 (Daily Report)** — `SenseReviewDailyReport.vue` + `SenseReviewDailyReportService` + `GET /reviews/senses/daily-report`. Richer four-block backend aggregate for today (概览 / 学习质量 / 重点词义 / 进步记录).
4. **近 7 天学习趋势 (7-Day Trend)** — `SenseReviewSevenDayTrend.vue` + `SenseReviewSevenDayTrendService` + `GET /reviews/senses/seven-day-trend`. Fixed rolling 7-day window (today + previous 6 natural days, NOT natural week).

The "today" row of the 7-day trend MUST match the DailyReport for total_reviews / distinct_senses / distribution / forget_rate / stability_rate (contract test enforced).

## 5. Props / Events Contract Summary

| Component | Props | Emits |
|-----------|-------|-------|
| LearningFeedbackPanel | learningFeedback, fsrsStability | — |
| RatingControls | disabled | rating(again\|hard\|good\|easy) |
| SessionSummary | stats, needsAttention | continue-review, exit |
| TodaySummary | summary | close |
| UnderstandingAid | aid | — |
| EditDialog | value (v-model), card | input, saved |
| SevenDayTrend | trend | close |
| SessionTracker | (pure functions, not a component) | — |

## 6. N+1 Risk Assessment

**Status**: RESOLVED (SenseReview-BatchFeedback-1000-1).

**Before optimization**: `SenseReviewLearningFeedbackService::buildForCard()` issued ~7 ReviewLog queries per card (count + 4 rating counts + recent + last_forget + trend). When the `/reviews/senses` endpoint serialized N due cards, this produced ~7N ReviewLog queries.

**After optimization**: `SenseReviewCardSerializerService::serializeMany()` calls `SenseReviewLearningFeedbackService::buildForCards()` which issues exactly 1 ReviewLog query for all cards. `buildForCard()` also delegates to `buildForCards()`, so the single-card path now issues 1 query (down from 7).

**Query count verification** (locked by `SenseReviewBatchFeedbackTest`):
- 1 card → 1 review_logs query
- 5 cards → 1 review_logs query
- 20 cards → 1 review_logs query
- Constant regardless of card count.

**Controller integration**: `SenseReviewController::index()` uses `serializeMany()` for the initial queue load. `rate()` uses `serialize()` for reviewed_card + next_card (2 single-card calls = 2 queries, acceptable per spec).

**Shared algorithm**: Both `buildForCard()` and `buildForCards()` delegate to the private `buildFeedbackFromLogs(Collection)` method — single source of truth, no duplicated aggregation logic.

## 7. Query Budget Summary

All SenseReview analytics paths are constant-query regardless of card/sense count:

| Path | ReviewLog queries | Locked by |
|------|-------------------|-----------|
| `LearningFeedbackService::buildForCards` (1/5/20 cards) | 1 | `SenseReviewBatchFeedbackTest` |
| `SevenDayTrendService::build` (1/10/50 senses, 7 days) | 1 | `SenseReviewSevenDayTrendTest` |
| `ReportMetricsService` (any method) | 0 | `SenseReviewReportMetricsServiceTest::test_metrics_service_does_not_access_database` |
| `AnalyticsQueryService::reviewsForPeriod` (1/10/50 senses) | 1 | `SenseReviewAnalyticsQueryServiceTest` |
| `AnalyticsQueryService::reviewsForCards` (1/10/50 cards) | 1 | `SenseReviewAnalyticsQueryServiceTest` |

The 7-day trend does NOT issue 7 separate queries (one per day). It issues a single `reviewsForPeriod` for the whole [start, end) window, then `groupByDay` in memory via the Metrics layer.

## 8. Not Implemented This Round

- Natural-week switching (the 7-day window is a fixed rolling window, NOT a calendar week).
- Date picker / arbitrary-date query.
- Monthly / yearly reports.
- Reading-time / lookup-count / AI-usage statistics.
- Report export / push / badges / streak / auto-generated study advice.
- Historical daily calendar.
- Report snapshot persistence (all reports are real-time ReviewLog reads).
