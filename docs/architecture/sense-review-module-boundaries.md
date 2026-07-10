# SenseReview Module Boundaries

> **Status**: Current as of 2026-07-10 (ADR-0007 complete: SenseReview report card deep link navigation contract added — `ReviewCardManageAccessService` is the single source of truth for sense-card access control; new read-only `GET /review-cards/manage/{reviewCard}/detail` endpoint; `ReviewCardManageDeepLink.js` pure-function helper; `SenseReviewDailyInsightBuilder` outputs `review_card_id` + `word_sense_id` on focus/progress/recent with 0 DB queries via in-memory map; ADR-0006 daily report consolidation remains intact).
> **Scope**: Describes the container/sub-component/service boundaries for the SenseReview page (`/reviews/senses`).
> **Related**: `docs/architecture/sense-http-controller-boundaries.md`, `docs/testing/sense-review-understanding-helper-playbook.md`, `docs/adr/ADR-0006-sense-review-daily-report-consolidation.md`, `docs/adr/ADR-0007-sense-review-report-card-deep-link.md`.

## 0. Six-Layer Report Architecture

All SenseReview analytics flows are split into six layers:

```
Period Layer        SenseReviewReportPeriodService     Pure time-window math (zero DB)
      ↓
Query Layer         SenseReviewAnalyticsQueryService   DB reads + user/language/sense/reset isolation
      ↓
Metrics Layer       SenseReviewReportMetricsService    Pure computation, zero DB queries
      ↓
Insight Layer       SenseReviewDailyInsightBuilder     Pure computation: focus/progress/recent (zero DB)
      ↓
Series Layer        SenseReviewDailySeriesBuilder      Zero-fill daily series (reuses Metrics)
      ↓
Product Service     DailyReport / SevenDayTrend /      Decides product payload, section orchestration,
                    ThirtyDayCalendar /                day-boundary fill, endpoint shape
                    LearningFeedback
      ↓
Controller          SenseReviewController              Request coordination only
```

- **Period Layer** (`SenseReviewReportPeriodService`): pure time-window math. `rollingDays(int $days, string $timezone)` returns `start_day`, `end_day`, `start` (inclusive), `end` (exclusive), `day_keys` (ascending). Zero DB queries. No Auth, no config, no .env. Rejects 0/negative/>365.
- **Query Layer** (`SenseReviewAnalyticsQueryService`): only `reviewsForPeriod()`, `sensesReviewedBefore()`, `reviewsForCards()`. No rating labels, no scores, no product copy, no sort/limit.
- **Metrics Layer** (`SenseReviewReportMetricsService`): pure functions — `ratingDistribution`, `forgetRate`, `stabilityRate`, `averageRating`, `distinctSenseCount`, `groupByDay`, `reviewsBySense`, `periodMetrics`. Zero DB queries (locked by test). No Auth, no config, no product copy.
- **Insight Layer** (`SenseReviewDailyInsightBuilder`): pure computation — `build(Collection $logs): array` returning `focus_senses` / `progress_senses` / `recent_reviews`. Zero DB queries (locked by `SenseReviewDailyInsightBuilderTest`). No Eloquent, no Auth, no config, no .env. Single source of truth for focus/progress/recent algorithms (consolidated from former TodaySummary + DailyReport duplication).
- **Series Layer** (`SenseReviewDailySeriesBuilder`): `build(Collection $logs, array $dayKeys): array` — produces one entry per day key, zero-filled for empty days (null rates, NOT "0%"). Reuses Metrics. Zero DB queries. No product copy.
- **Rating Contract** (`SenseReviewRatingContract`): single source of truth for `allowedRatings()`, `isAllowed()`, `labelFor()`, `scoreFor()`. Pure value object, no DB/Auth/config. Fail-closed for invalid rating.
- **Product Services**: compose Period + Query + Metrics + Insight + Series + Contract, keep product rules (section orchestration, payload field names, summary block).
- **Controller**: thin — reads user + language, delegates to service, returns JSON.

## 0.1 ReportCenter UI Orchestration

`SenseReviewReportCenter.vue` is the single orchestration component for all report dialogs. It owns the entire report-dialog lifecycle internally; the parent only controls open/close.

- **v-model (open)**: `boolean` — `false` = closed (resets all internal state); `true` = open.
- **Parent** (`SenseReview.vue`) only sets `reportCenterOpen = true|false`; ReportCenter handles dialog, loading, GET request, error state, report selection, back-to-list, and close (emits `input` false).
- **Internal state owned by ReportCenter**: `selectedReportKey` (`null` on home page | `'daily-report'` | `'seven-day-trend'` | `'thirty-day-calendar'`), `loading`, `error`, `payload`, `requestSequence` (monotonic counter for async-race protection).
- **Home page**: when `selectedReportKey === null`, shows the report catalog selection view. NO GET request is sent on the home page. A GET is sent only after the user selects a specific report.
- **Back to list**: resets `selectedReportKey` to `null`, clears `error`/`payload`, keeps the dialog open. The user can then choose another report.
- **Async-race protection**: each report selection increments `requestSequence`; stale responses whose sequence no longer matches the current request are discarded (guard against user switching reports or closing the dialog while a request is in flight).
- **No duplicate requests**: if the same report is already loading, a second selection does not re-trigger the GET.
- **Only read-only GET requests**. Never POSTs ratings. Never writes ReviewLog. Never touches FSRS.
- **SessionSummary is NOT managed here** (it is page-load scoped, not a backend-report dialog).

## 0.2 SenseReviewReportCatalog.js (Frontend Report Metadata)

`resources/js/components/Senses/SenseReviewReportCatalog.js` is the **single source of truth** for the three report entries on the frontend. Pure configuration — no API calls, no Vuex, no state writes.

- **Exports**: `REPORT_CATALOG` (array), `REPORT_KEYS`, `getReportByKey(key)`, `isReportKey(key)`.
- **Each catalog entry** has: `key`, `title`, `description`, `icon`, `color`, `endpoint`, `component` (registered component name), `payloadProp` (prop name the rendered component expects), `maxWidth`, `loadingText`.
- **Fixed order**: `daily-report`, `seven-day-trend`, `thirty-day-calendar` (3 items post ADR-0006).
- **Consumed by**: `SenseReviewReportCenter.vue` (drives home-page cards, endpoint selection, component lookup, payload prop binding, dialog width, loading text).
- **Constraint**: `SenseReview.vue` does NOT know the three endpoints or report keys. `SenseReviewReportCenter.vue` does NOT maintain a parallel copy of endpoint/width/loading-text maps — all metadata comes from the Catalog.
- **Component mapping**: ReportCenter keeps a local `COMPONENT_MAP` from component name → imported Vue component. The catalog itself never imports Vue components (keeps it pure/testable).

## 0.3 SenseReviewRatingPresentation.js (Frontend Rating Display Contract)

`resources/js/components/Senses/SenseReviewRatingPresentation.js` is the **single source of truth** for the four rating labels, colors, hotkeys, and scores on the frontend. Pure configuration — no FSRS, no API, no Vuex.

- **Exports**: `RATING_PRESENTATION` (array), `RATING_VALUES`, `getRatingPresentation(value)`, `labelForRating(value)`, `colorForRating(value)`, `hotkeyHintText()`.
- **Fixed content** (must match backend `SenseReviewRatingContract`):

  | value | label | color | hotkey | score |
  |-------|-------|-------|--------|-------|
  | again | 忘了 | error | 1 | 1 |
  | hard | 勉强记得 | warning | 2 | 2 |
  | good | 记得 | primary | 3 | 3 |
  | easy | 很熟 | success | 4 | 4 |

- **Consumed by**: `SenseReviewRatingControls.vue` (renders buttons + hotkey hint from this config), and report components reference `labelForRating()` for rating chips to avoid hardcoded labels.
- **Cross-stack guard**: `tests/js/SenseReviewRatingPresentationGuard.test.mjs` verifies PHP `SenseReviewRatingContract` and JS `RATING_PRESENTATION` agree on values, labels, scores, and that `hard` → label `勉强记得`, score `2`. Also guards against isolated "勉强" (without "记得") in SenseReview Vue templates.
- **Never changes**: rating API values (`again`/`hard`/`good`/`easy`), numeric scores (1/2/3/4), hotkey numbers (1/2/3/4), FSRS semantics.

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

### 2.6 ~~SenseReviewTodaySummary.vue~~ (DELETED in ADR-0006)

**Status**: DELETED. The component, its Catalog entry, and the ReportCenter import/registration were all removed. The `recent_reviews` capability was migrated to `SenseReviewDailyReport.vue` as its fifth section. Frontend guard coverage migrated to `SenseReviewDailyReportGuard.test.mjs` and `SenseReviewReportCenterGuard.test.mjs`.

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

### 2.9 SenseReviewThirtyDayCalendar.vue (~303 lines)

**Responsibility**: Presentational display of the fixed rolling 30-day review calendar (近 30 天复习日历). Distinct from SevenDayTrend (short-term continuous change) — this surface shows historical date distribution across 30 cells.

- **Props**: `calendar` (Object from `GET /reviews/senses/thirty-day-calendar`).
- **Emits**: `close`.
- **Constraints**: pure presentational. No API calls, no ReviewLog writes, no FSRS modifications, no card queue mutations, no chart library. Uses Vuetify v-card/v-row/v-col/v-chip + simple CSS grid.
- **Content**: timezone/start_day/end_day, summary (total_reviews/active_days/distinct_senses/average_per_active_day/distribution/forget_rate/stability_rate), 30 fixed day cells (ascending, zero-filled with null rates for empty days).
- **Empty state**: "近 30 天还没有完成词义卡复习。" with 30 cells still shown, no misleading percentages.
- **Window definition**: today + previous 29 natural days (NOT natural month, NOT configurable). Backend timezone. Real-time ReviewLog reads, no snapshot.
- **Calendar grid**: 10-column CSS grid (~960px+), 7-column on narrow screens (<960px). Intensity coloring by `total_reviews` relative to max-day total (empty/verylow/low/mid/high).
- **Day detail**: clicking a day cell selects it locally (`selectedIndex`) and renders detail below the grid (no API call). Empty days show null rates (not "0%").

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

### 3.3 ~~SenseReviewTodaySummaryService.php~~ (REMOVED in ADR-0006)

**Status**: DELETED. Consolidated into `SenseReviewDailyReportService` + `SenseReviewDailyInsightBuilder`. The `GET /reviews/senses/today-summary` endpoint, the Controller method, and the Service class were all removed. All test coverage migrated to `SenseReviewDailyReportTest` + `SenseReviewDailyInsightBuilderTest` (1:1 mapping, see ADR-0006).

### 3.3b SenseReviewDailyReportService.php (~200 lines)

**Responsibility**: Read-only five-block daily report for the "今日学习日报" feature. The **single** today-report Product Service after ADR-0006 consolidation (formerly shared with the now-deleted TodaySummaryService).

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewReportPeriodService::rollingDays(1, $timezone)` (window math), `SenseReviewAnalyticsQueryService::reviewsForPeriod()` + `sensesReviewedBefore()`, `SenseReviewReportMetricsService` for distribution/forget-rate/stability-rate/reviewsBySense, `SenseReviewDailyInsightBuilder` for focus/progress/recent (single source of truth), `SenseReviewRatingContract::scoreFor()` for average-rating computation.
- **Product rules**: five sections (今日复习概览 / 今日学习质量 / 今日重点词义 / 今日进步记录 / 今日最近复习), focus-sense max-10, first-review vs review-again detection via `sensesReviewedBefore()`.
- **Payload**: timezone / day / day_start / day_end, overview (total_reviews / distinct_senses / first_review_senses / review_again_senses / average_rating), quality (distribution / forget_rate / stability_rate), focus_senses (max 10, unified algorithm from InsightBuilder), progress_senses (again→good / hard→easy transitions), recent_reviews (max 10, newest first, additive field migrated from former TodaySummary).
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table. Does NOT re-implement focus/progress/recent algorithms (delegates to InsightBuilder). Date boundary comes ONLY from PeriodService.

### 3.3b-insight SenseReviewDailyInsightBuilder.php (Insight Layer)

**Responsibility**: Pure computation layer — single source of truth for focus_senses, progress_senses, and recent_reviews algorithms. Consolidated from the former duplication between TodaySummaryService and DailyReportService.

- **Public method**: `build(Collection $logs): array` — returns `['focus_senses' => ..., 'progress_senses' => ..., 'recent_reviews' => ...]`.
- **Uses**: `SenseReviewReportMetricsService::reviewsBySense()` (grouping), `SenseReviewRatingContract::labelFor()` (rating labels).
- **Forbidden dependencies**: Eloquent, DB, Auth, Request, Controller, config, .env, `SenseReviewAnalyticsQueryService`. Pure in-memory computation only.
- **Navigation IDs (ADR-0007)**: each item in `focus_senses` / `progress_senses` / `recent_reviews` includes `review_card_id` (int|null) and `word_sense_id` (int). The builder constructs an in-memory `word_sense_id → review_card_id` map from the same `$logs` Collection passed in — 0 DB queries, 0 extra QueryService calls. `recent_reviews` uses each log row's own `review_card_id` directly; `focus_senses` and `progress_senses` use the latest `review_card_id` seen for that `word_sense_id` in the log collection. Invalid/missing IDs become `null` (never fabricated). This is the contract that enables the DailyReport → ReviewCardManage deep link flow without adding DB queries.
- **focus_senses rules**: includes senses with again>0 OR hard>0 OR same-sense reviewed multiple times today OR last rating is again/hard. Unified superset output: word_sense_id, lemma, sense_zh, total, again, hard, last_rating, last_reviewed_at, review_card_id (from in-memory map, latest log for that sense). Sorted by again desc, hard desc, total desc. Max 10.
- **progress_senses rules**: again→good or hard→easy transitions detected by real temporal order. Same sense max one entry. Output includes word_sense_id, lemma, sense_zh, from_rating, to_rating, reviewed_at, review_card_id (from in-memory map, the card on which the transition occurred).
- **recent_reviews rules**: newest first, max 10. Each item: lemma, sense_zh, rating, rating_label (from Contract, hard→"勉强记得"), reviewed_at, review_card_id (from the log row directly), word_sense_id (from the log row directly).
- **Zero DB queries**: locked by `SenseReviewDailyInsightBuilderTest::test_builder_does_not_access_database` and `test_navigation_ids_do_not_increase_db_queries`.

### 3.3c SenseReviewSevenDayTrendService.php (~190 lines)

**Responsibility**: Read-only fixed rolling 7-day trend for the "近 7 天学习趋势" feature.

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewAnalyticsQueryService::reviewsForPeriod()` (single query for the whole 7-day window), `SenseReviewReportMetricsService::groupByDay()` + `ratingDistribution()` + `forgetRate()` + `stabilityRate()` + `distinctSenseCount()`.
- **Window**: today + previous 6 natural days (NOT natural week). `Carbon::today($timezone)` back 6 days. Backend timezone. Fixed 7-day array, ascending order, zero-filled for empty days (empty days have null rates, NOT "0%").
- **Summary**: total_reviews, active_days, distinct_senses, average_per_active_day (null when 0 active days), distribution, forget_rate, stability_rate.
- **Query budget**: exactly 1 ReviewLog query for the entire 7-day window regardless of sense count (1/10/50 senses → 1 query, locked by `SenseReviewSevenDayTrendTest`).
- **Today-row consistency**: the "today" row must match `GET /reviews/senses/daily-report` for total_reviews / distinct_senses / distribution / forget_rate / stability_rate (locked by contract test `test_today_row_matches_daily_report`).
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table. No snapshot persistence.

### 3.3d SenseReviewThirtyDayCalendarService.php (~155 lines)

**Responsibility**: Read-only fixed rolling 30-day calendar for the "近 30 天复习日历" feature. Distinct from SevenDayTrend (7-day short-term trend) — this surface shows 30-day historical date distribution.

- **Public method**: `build(int $userId, string $language): array`.
- **Uses**: `SenseReviewReportPeriodService::rollingDays(30, $timezone)` (window math), `SenseReviewAnalyticsQueryService::reviewsForPeriod()` (single query for the whole 30-day window), `SenseReviewDailySeriesBuilder::build()` (zero-fill daily series, reuses Metrics), `SenseReviewReportMetricsService::periodMetrics()` for summary block.
- **Window**: today + previous 29 natural days (NOT natural month). `Carbon::today($timezone)` back 29 days. Backend timezone. Fixed 30-day array, ascending order, zero-filled for empty days (empty days have null rates, NOT "0%").
- **Summary**: total_reviews, active_days, distinct_senses, average_per_active_day (null when 0 active days), distribution, forget_rate, stability_rate.
- **Query budget**: exactly 1 ReviewLog query for the entire 30-day window regardless of sense count (1/10/50 senses → 1 query, locked by `SenseReviewThirtyDayCalendarTest`).
- **Today-row consistency**: the "today" row (days[29]) must match `GET /reviews/senses/daily-report` for total_reviews / distinct_senses / distribution / forget_rate / stability_rate (locked by contract test `test_today_row_matches_daily_report`).
- **Last-7-days consistency**: the last 7 days of the 30-day calendar must match `GET /reviews/senses/seven-day-trend` for day/day total_reviews/distinct_senses/distribution/forget_rate/stability_rate (locked by `test_last_seven_days_match_seven_day_trend`).
- **Constraints**: READ-ONLY. Never writes ReviewLog. Never touches FSRS. Never creates WordSense/ReviewCard. No new database table. No snapshot persistence. No date picker, no natural-week/natural-month switching.

### 3.3e SenseReviewAnalyticsQueryService.php (Query Layer)

**Responsibility**: Centralized read-only ReviewLog statistics query layer. Single entry point for sense-review analytics DB reads.

- **Public methods** (Query Layer only — no pure computation):
  - `reviewsForPeriod(int $userId, string $language, Carbon $start, Carbon $end): Collection` — non-reset sense logs in [start, end), newest-first, with sense metadata.
  - `sensesReviewedBefore(int $userId, string $language, Carbon $before): array` — sense ids with any non-reset review before a time.
  - `reviewsForCards(array $cardIds): Collection` — non-reset logs for given card ids, newest-first. 1 query regardless of card count.
- **Removed in Task B**: `ratingDistribution`, `forgetRate`, `stabilityRate`, `reviewsBySense` (moved to `SenseReviewReportMetricsService`).
- **Delegates**: user/language/sense-only/reset-exclusion isolation to `SenseReviewQueryService::nonResetSenseReviewLogQuery()` / `nonResetCardReviewLogQuery()`.
- **Constraints**: READ-ONLY. No rating labels, no scores, no product copy, no sort/limit. No new database table.

### 3.3f SenseReviewRatingContract.php (Rating Contract)

**Responsibility**: Single source of truth for SenseReview rating metadata. Pure value object.

- **Methods**: `allowedRatings()`, `isAllowed($rating)`, `labelFor($rating)`, `scoreFor($rating)`.
- **Allowed ratings**: again / hard / good / easy.
- **Labels**: again→忘了, hard→勉强, good→记得, easy→很熟.
- **Scores**: again=1, hard=2, good=3, easy=4.
- **Invalid handling**: fail-closed — `labelFor`/`scoreFor` return null for invalid/null/case-mismatched ratings. Never silently treats invalid as `good`.
- **Constraints**: no DB, no Auth, no config, no state writes, no product copy.

### 3.3g SenseReviewReportMetricsService.php (Metrics Layer)

**Responsibility**: Pure computation layer for report metrics. Zero DB queries.

- **Methods**: `ratingDistribution(Collection)`, `forgetRate(Collection)`, `stabilityRate(Collection)`, `averageRating(Collection)`, `distinctSenseCount(Collection)`, `groupByDay(Collection)`, `reviewsBySense(Collection)`, `periodMetrics(Collection)`.
- **Uses**: `SenseReviewRatingContract::scoreFor()` for average-rating computation (single source of truth for scores).
- **groupByDay**: returns ONLY days with data (associative array `Y-m-d => Collection`). Zero-fill for empty days is the Product Service's responsibility, NOT Metrics'.
- **Constraints**: zero DB queries (locked by `SenseReviewReportMetricsServiceTest::test_metrics_service_does_not_access_database`). No Eloquent, no Auth, no config, no product copy, no sort/limit decisions.

### 3.4 ~~SenseReviewTodaySummary endpoint~~ (REMOVED in ADR-0006)

**Status**: DELETED. The `GET /reviews/senses/today-summary` route, `SenseReviewController::todaySummary()` method, and `SenseReviewTodaySummaryService` were all removed. The consolidated endpoint is `GET /reviews/senses/daily-report` (see 3.3b).

### 3.4b SenseReviewDailyReport endpoint

- **Route**: `GET /reviews/senses/daily-report`.
- **Controller**: `SenseReviewController::dailyReport()` — thin: reads user + language, delegates to `SenseReviewDailyReportService`, returns JSON.
- **Auth**: required (middleware). Strict user + language isolation (enforced by service via `SenseReviewQueryService`).
- **No writes**: does not write ReviewLog, does not change ReviewCard/FSRS, does not create WordSense/ReviewCard.
- **Payload**: five blocks (overview / quality / focus_senses / progress_senses / recent_reviews). See 3.3b for full shape.

## 4. Four Coexisting Summary / Report Concepts (post ADR-0006)

The SenseReview page has four clearly distinct analytics surfaces. They MUST NOT be conflated in wording or payload:

1. **本次复习总结 (Session Summary)** — `SenseReviewSessionSummary.vue` + `SenseReviewSessionTracker.js`. Frontend, page-load scoped. Resets on refresh. Tracks only ratings after page open. No backend call, no persistence.
2. **今日学习日报 (Daily Report)** — `SenseReviewDailyReport.vue` + `SenseReviewDailyReportService` + `SenseReviewDailyInsightBuilder` + `GET /reviews/senses/daily-report`. Five-block backend aggregate for today (概览 / 学习质量 / 重点词义 / 进步记录 / 最近复习). Former "今日复习总结" (TodaySummary) has been consolidated into this single daily report (ADR-0006).
3. **近 7 天学习趋势 (7-Day Trend)** — `SenseReviewSevenDayTrend.vue` + `SenseReviewSevenDayTrendService` + `GET /reviews/senses/seven-day-trend`. Fixed rolling 7-day window (today + previous 6 natural days, NOT natural week). Short-term continuous change view.
4. **近 30 天复习日历 (30-Day Calendar)** — `SenseReviewThirtyDayCalendar.vue` + `SenseReviewThirtyDayCalendarService` + `GET /reviews/senses/thirty-day-calendar`. Fixed rolling 30-day window (today + previous 29 natural days, NOT natural month). Historical date distribution view across 30 cells.

The "today" row of the 7-day trend and the 30-day calendar (days[29]) MUST match the DailyReport for total_reviews / distinct_senses / distribution / forget_rate / stability_rate (contract tests enforced). The last 7 days of the 30-day calendar MUST match the 7-day trend day-by-day (locked by `test_last_seven_days_match_seven_day_trend`).

The report home page (ReportCenter catalog) shows only items 2–4. Session Summary (item 1) is page-load scoped and NOT part of the report catalog.

## 5. Props / Events Contract Summary

| Component | Props | Emits |
|-----------|-------|-------|
| ReportCenter | open (v-model boolean) | input(false) |
| LearningFeedbackPanel | learningFeedback, fsrsStability | — |
| RatingControls | disabled | rating(again\|hard\|good\|easy) |
| SessionSummary | stats, needsAttention | continue-review, exit |
| UnderstandingAid | aid | — |
| EditDialog | value (v-model), card | input, saved |
| SevenDayTrend | trend | close, back |
| ThirtyDayCalendar | calendar | close, back |
| DailyReport | report | close, back |
| SessionTracker | (pure functions, not a component) | — |

> Report sub-components emit `back` to return to the report list (kept inside ReportCenter); `close` closes the whole ReportCenter.

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
| `DailyReportService::build` empty day (1/10/50 senses) | 1 | `SenseReviewDailyReportTest::test_query_budget_constant` |
| `DailyReportService::build` non-empty day (1/10/50 senses) | ≤2 (reviewsForPeriod + sensesReviewedBefore) | `SenseReviewDailyReportTest::test_query_budget_constant` |
| `DailyInsightBuilder::build` (any input) | 0 | `SenseReviewDailyInsightBuilderTest::test_builder_does_not_access_database` |
| `SevenDayTrendService::build` (1/10/50 senses, 7 days) | 1 | `SenseReviewSevenDayTrendTest` |
| `ThirtyDayCalendarService::build` (1/10/50 senses, 30 days) | 1 | `SenseReviewThirtyDayCalendarTest` |
| `ReportMetricsService` (any method) | 0 | `SenseReviewReportMetricsServiceTest::test_metrics_service_does_not_access_database` |
| `AnalyticsQueryService::reviewsForPeriod` (1/10/50 senses) | 1 | `SenseReviewAnalyticsQueryServiceTest` |
| `AnalyticsQueryService::reviewsForCards` (1/10/50 cards) | 1 | `SenseReviewAnalyticsQueryServiceTest` |

The daily report issues 1 query when empty (reviewsForPeriod returns empty, sensesReviewedBefore is skipped) and at most 2 queries when non-empty (reviewsForPeriod + sensesReviewedBefore). The InsightBuilder and Metrics layers add 0 additional queries — they operate purely on the in-memory Collection passed in. The `recent_reviews` field is additive and does NOT issue its own query.

The 7-day trend and 30-day calendar do NOT issue per-day queries. Each issues a single `reviewsForPeriod` for the whole [start, end) window, then `groupByDay` in memory via the Metrics layer (and `DailySeriesBuilder` for zero-fill).

## 8. Not Implemented This Round

- Natural-week switching (the 7-day window is a fixed rolling window, NOT a calendar week).
- Natural-month switching (the 30-day window is a fixed rolling window, NOT a calendar month).
- Date picker / arbitrary-date query.
- Monthly / yearly reports.
- Reading-time / lookup-count / AI-usage statistics.
- Report export / push / badges / streak / auto-generated study advice.
- Report snapshot persistence (all reports are real-time ReviewLog reads).

## 9. Report Card Deep Link Navigation (ADR-0007)

### 9.1 Navigation Contract Overview

ADR-0007 freezes the cross-page deep link contract that lets a user click an entry in the DailyReport (focus_senses / progress_senses / recent_reviews) and land on the exact `ReviewCardManage` detail for that sense card — without relying on lemma fuzzy search, without depending on the target card being in the current page/filter, and without any DB writes.

```
Daily Report Query (reviewsForPeriod, 1 query)
    ↓
DailyInsightBuilder (pure in-memory)
    ↓ outputs review_card_id + word_sense_id on each item
DailyReport.vue (presentational)
    ↓ emit open-review-card { review_card_id, word_sense_id, source_section }
ReportCenter (orchestration)
    ↓ ReviewCardManageDeepLink.buildReviewCardManageLocation(target, source)
/review-cards/manage?review_card_id=123&from=daily-report
    ↓
ReviewCardManage.vue (parses route query on mount)
    ↓
GET /review-cards/manage/{reviewCard}/detail   (read-only)
    ↓
ReviewCardManageAccessService::findManageableSenseCardOrFail()
    ↓ user + language + target_type=sense + confirmed WordSense
ReviewCardManageItemSerializerService::serializeCard()
    ↓
Exact card detail + logs loaded, no list pagination dependency
```

### 9.2 ReviewCardManageAccessService (Single Source of Truth for Access Control)

`app/Services/ReviewCardManageAccessService.php` is the **only** place that decides whether a given `review_card_id` is manageable as a sense card by the current user. The Controller's former private `findManageableSenseCard()` method was migrated here so that `update`, `enabled`, `dueNow`, `reset`, `destroy`, `logs`, and the new `detail` endpoint all share one access path.

- **Public method**: `findManageableSenseCardOrFail(int $reviewCardId, int $userId, string $language): array{0: ReviewCard, 1: WordSense}`.
- **Checks (all must pass, else abort 404)**:
  1. `review_cards.id` exists.
  2. `review_cards.user_id === $userId`.
  3. `review_cards.language_id === $language`.
  4. `review_cards.target_type === ReviewCard::TARGET_SENSE`.
  5. A `WordSense` exists with `id === review_cards.target_id`.
  6. `word_senses.user_id === $userId`.
  7. `word_senses.language_id === $language`.
  8. `word_senses.status === WordSense::STATUS_CONFIRMED`.
- **Archived cards**: allowed (`fsrs_enabled=false` is not a 404 reason — the user can still open the detail).
- **Legacy word cards**: 404 (target_type mismatch).
- **Rejected / pending / deleted senses**: 404 (status mismatch).
- **Other user / other language**: 404 (never reveals existence — uniform 404 to avoid leaking card existence).
- **Never writes**: no ReviewLog, no FSRS mutation, no ReviewCard/WordSense creation/update/delete.

### 9.3 Detail Endpoint Contract

- **Route**: `GET /review-cards/manage/{reviewCard}/detail` (registered in `routes/web.php`, inside the auth middleware group).
- **Controller**: `ReviewCardManageController::detail(int $reviewCard): JsonResponse`.
- **Behavior**:
  1. Delegates to `ReviewCardManageAccessService::findManageableSenseCardOrFail()`.
  2. On success, returns `ReviewCardManageItemSerializerService::serializeCard($card, $sense)` — **byte-identical payload shape** to the list endpoint's `serializeCard()`, so the management page can reuse the same item renderer for both list rows and the deep-link detail.
  3. On failure (any of the 8 checks above): 404.
- **GET-only**: POST / PATCH / DELETE → 405 (locked by `ReviewCardManageDeepLinkTest::test_detail_endpoint_is_get_only`).
- **No writes**: no ReviewLog write, no FSRS change, no mutation of any field (locked by `test_detail_endpoint_does_not_write_review_log` + `test_detail_endpoint_does_not_change_fsrs`).
- **Does NOT touch list pagination / filters / queue**: this endpoint returns a single card payload; the management page list query is independent.

### 9.4 ReviewCardManageDeepLink.js (Frontend Pure-Function Helper)

`resources/js/services/ReviewCardManageDeepLink.js` is a pure ES module — no Vue, no Vuex, no axios, no DOM access, no state writes.

- **Exports**:
  - `DEEP_LINK_SOURCES` — frozen whitelist `['daily-report', 'seven-day-trend', 'thirty-day-calendar']`.
  - `buildReviewCardManageLocation(target, source)` — returns `{ path: '/review-cards/manage', query: { review_card_id, from } }` or `null` on invalid input. `target.review_card_id` must be a positive integer; `source` must be in the whitelist; `word_sense_id` is accepted as a diagnostic field but never replaces `review_card_id`.
  - `parseReviewCardManageLocation(query)` — returns `{ review_card_id, from, word_sense_id }` or `null` on invalid input. Coerces numeric strings, rejects 0 / negative / NaN / non-numeric strings / missing keys.
- **Route contract**: `/review-cards/manage?review_card_id={positive-int}&from={source-whitelist}` (optional `word_sense_id` diagnostic).
- **Guard tests**: `tests/js/ReviewCardManageDeepLinkGuard.test.mjs` (24 tests covering build/parse/invalid/whitelist/no-axios/no-Vue/no-DOM).

### 9.5 Why review_card_id (not lemma search)

The unique constraint on `review_cards` `(user_id, language_id, target_type, target_id)` means one confirmed WordSense maps to exactly one sense ReviewCard. Using `review_card_id` as the primary key:

- Eliminates the pagination/filter dependency that a lemma search would introduce (the target card may not be on the current page, and forcing it into the list would corrupt the user's filters).
- Matches the Anki Browser / Card Info model: Anki opens Card Info by exact card ID, not by searching the note text.
- Allows the deep link to survive a page refresh (the route query stays in the URL).

### 9.6 Frozen Boundaries

- **InsightBuilder**: pure in-memory, 0 DB queries. The navigation IDs are a product-output concern, so they live in the InsightBuilder (not in `SenseReviewReportMetricsService::reviewsBySense()`, which stays focused on metrics aggregation).
- **DailyReportService**: query budget unchanged (still 1 query empty / ≤2 non-empty). The navigation IDs add 0 queries.
- **AccessService**: the only place that performs sense-card access control. The Controller must not duplicate user/language/sense-only/confirmed checks.
- **DeepLink Helper**: pure functions only. No API calls, no Vue imports, no DOM access.
- **Seven-day trend window**: unchanged (today + previous 6 natural days, NOT natural week). ADR-0007 explicitly does NOT touch `SenseReviewSevenDayTrendService` / `SenseReviewReportPeriodService` / `SenseReviewSevenDayTrend.vue`.
- **No FSRS / ReviewLog / DB schema change**: the entire deep link flow is read-only.
