# SenseReview Module Boundaries

> **Status**: Current as of 2026-07-12 (ADR-0010 Task B complete: review card lifecycle state machine — Active/Buried/Suspended/Archived four-state model replaces overloaded `fsrs_enabled` boolean; single additive migration adds `lifecycle_state`/`buried_until`/`lifecycle_version`/`lifecycle_changed_at` to `review_cards` + new `review_card_state_events` audit table; `fsrs_enabled` retained as read-only compatibility mirror; `ReviewCardLifecyclePolicy` pure state machine; `ReviewCardBuryTimeService` user-timezone next-day 00:00; `ReviewCardLifecycleCommandService` single mutation entry point with transaction + lockForUpdate + request_id idempotency + lifecycle_version optimistic lock; `scopeSenseReviewEligible` includes expired buried via OR clause (auto-restore without scheduled job); boundaries with rating/undo/reset/delete/stats/preview/management/export all locked; 133 new backend tests + all regression suites green; ADR-0009 undo ledger and ADR-0008 interval preview remain intact).
> **Scope**: Describes the container/sub-component/service boundaries for the SenseReview page (`/reviews/senses`).
> **Related**: `docs/architecture/sense-http-controller-boundaries.md`, `docs/testing/sense-review-understanding-helper-playbook.md`, `docs/adr/ADR-0006-sense-review-daily-report-consolidation.md`, `docs/adr/ADR-0007-sense-review-report-card-deep-link.md`, `docs/adr/ADR-0008-sense-review-answer-interval-preview.md`, `docs/adr/ADR-0009-review-action-ledger-and-stack-undo.md`, `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`.

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

## 10. Answer Interval Preview (ADR-0008)

### 10.1 Overview

ADR-0008 adds a read-only interval-preview feature so the SenseReview page can show the estimated next-review interval on each of the four rating buttons (Anki Answer Buttons UX). Preview and real rating share the same scheduling core — no second scheduler exists.

- **`FsrsSchedulingService::previewAllRatings(ReviewCard $card, ?Carbon $reviewedAt = null): array`**: pure projection. Calls the existing `schedule()` once per rating (`again` / `hard` / `good` / `easy`, order from `SenseReviewRatingContract::allowedRatings()`). Does NOT save the model, does NOT create a `ReviewLog`, does NOT mutate any field. Both `ReviewCardService::recordReview()` (real rating) and the preview path call the same `schedule()` core.
- **`SenseReviewIntervalPreviewService::preview()`**: handles access control — user + language + `target_type=sense` + confirmed WordSense + `fsrs_enabled` — then delegates to `FsrsSchedulingService::previewAllRatings()`. It is the only service the Controller calls; the Controller does not duplicate access checks.
- **Endpoint**: `GET /reviews/senses/{reviewCard}/interval-preview` — read-only. Returns per-rating projected `due_at` / `interval` / `state` / `stability` / `difficulty` / `lapses`. GET-only; no ReviewLog write; no FSRS mutation; no DB schema change.
- **Constraints**: no FSRS write, no ReviewLog, no WordSense/ReviewCard creation/update/delete, no new migration. The preview helps the user understand the consequence of each rating; it does not make the decision for them.

## 11. Review Action Ledger and Stack Undo (ADR-0009)

### 11.1 Overview

ADR-0009 adds a transaction-based undo ledger for sense review actions. Each rating creates a complete FSRS before/after snapshot in the ReviewLog. Within the current browser tab session, the user can stack-undo the latest active action — restoring the card to its pre-rating state. Undone ratings are excluded from product analytics but retained in audit views.

- **Stack-only undo**: only the latest active action in the session can be undone. After undoing, the previous action becomes undoable. No skipping, no redo, no cross-session undo, no arbitrary history rollback.
- **ReviewLog is NEVER deleted**: undone logs are marked with `undone_at` and retained for audit.
- **Product analytics exclude undone**: daily report, 7-day trend, 30-day calendar, stats, optimization all use `scopeNotUndone`.
- **Audit views retain undone**: management page logs, session action timeline, diagnostics show undone logs with metadata.

### 11.2 New Services

- **`ReviewCardFsrsSnapshotService`**: pure capture/restore/matches/fingerprint/validate for 8 FSRS fields (`fsrs_state`, `fsrs_due_at`, `fsrs_stability`, `fsrs_difficulty`, `fsrs_last_reviewed_at`, `fsrs_reps`, `fsrs_lapses`, `fsrs_enabled`). No DB, no save. Datetime normalized via Carbon::toIso8601String(), floats via round($v, 6).
- **`SenseReviewUndoPolicy`**: pure policy service — 10 blocked reasons (`wrong_session`, `not_latest_action`, `already_undone`, `missing_snapshot`, `card_state_changed`, `legacy_target`, `sense_not_confirmed`, `card_archived`, `unsupported_rating`, `unsupported_source`). No DB, no writes.
- **`SenseReviewSessionActionService`**: read-only timeline of the 20 most recent session actions (newest first, includes undone). Evaluates undoable/blocked_reason per action via UndoPolicy. Eager-loads cards + senses to avoid N+1.
- **`SenseReviewUndoService`**: transactional undo. Locks ReviewLog + ReviewCard, evaluates policy, restores before snapshot, marks log undone. Idempotent via `undo_request_id`.

### 11.3 Migration

Single additive migration adds 6 columns to `review_logs`:
- `review_session_id` (nullable string + index)
- `before_card_snapshot` (nullable JSON)
- `after_card_snapshot` (nullable JSON)
- `undone_at` (nullable timestamp + index)
- `undo_request_id` (nullable string + unique)
- `undo_source` (nullable string)

No existing column modified or deleted. No `review_cards` schema change. Legacy logs have null fields — they still participate in stats but cannot be undone.

### 11.4 Endpoints

- `GET /reviews/senses/session-actions?review_session_id=UUID` — read-only session timeline.
- `POST /reviews/senses/review-actions/{reviewLog}/undo` — transactional undo with idempotency.
- `POST /reviews/senses/{reviewCard}/rate` — now accepts optional `review_session_id` and returns `action` metadata.

### 11.5 Analytics Exclusion

`ReviewLog::scopeNotUndone()` is the single exclusion point. Product analytics paths (`SenseReviewQueryService::nonResetSenseReviewLogQuery()`, `nonResetCardReviewLogQuery()`, and `SettingsService` direct queries) all apply `whereNull('undone_at')`. Audit paths (management page logs, session timeline, diagnostics) do NOT apply the scope.

### 11.6 Constraints

No FSRS algorithm/parameter/preview change. No rating key/score/label/hotkey change. No `review_cards` schema change. No second migration. No ReviewLog deletion. No redo. No cross-session undo. Legacy logs (null snapshot) remain in stats but are not undoable.

### 11.7 Frontend Layer (Task A)

- **`SenseReviewSessionIdentity.js`**: pure helper using `sessionStorage` (per-tab, survives refresh, not `localStorage`). Exports `getOrCreateReviewSessionId()`, `isValidReviewSessionId()`, `clearReviewSessionId()`. UUID v4 via `crypto.randomUUID()` with manual fallback. No axios, no Vue.
- **`SenseReview.vue` state**: `reviewSessionId`, `sessionActions`, `sessionActionsLoading`, `sessionActionDrawerOpen`, `undoLoadingReviewLogId`, `undoSnackbar`, `undoConflict`, `sessionActionRequestSequence`. `mounted()` creates/reads session ID and loads timeline. `rate()` sends `review_session_id` and uses `response.data.action`.
- **Unified `requestUndo(action, source)`**: all three entry points (snackbar, drawer, hotkey) converge. POST to `/reviews/senses/review-actions/{id}/undo` with `review_session_id` + `undo_request_id` + `source`. On success: closes snackbar, reloads cards, unshifts restored card to front, sets `showAnswer=false`, clears interval preview, calls `SessionTracker.removeRating`, decrements `reviewedCount`, refreshes timeline + stats, shows "已撤销上一次评分" info snackbar. On 409: conflict message. On 404: session mismatch. Network error: retry message. No optimistic restore, no frontend FSRS calculation, no ReviewLog deletion.
- **Ctrl/Cmd+Z guard**: `handleHotkey` checks `ctrlKey || metaKey` + `z/Z`, then guards: input/textarea/select/contenteditable, edit/archive/reset/delete/source dialogs, `showSessionSummary`, `this.rating`, `this.undoLoadingReviewLogId !== null`, `this.latestUndoableAction` existence.
- **Session summary**: `SessionTracker.removeRating(session, reviewCardId)` excludes undone action from summary (entries filter, requestIds preserved). Summary count/distribution/feedback reflect only active session actions.
- **Management page audit**: `ReviewCardManage.vue` shows `已撤销` chip + `撤销时间` + `撤销来源` for undone logs. Original rating preserved (not changed to "undo"). Logs not hidden. No undo button on management page.
- **Sources**: `sense_review_snackbar`, `sense_review_history`, `sense_review_hotkey` — validated by backend.

## 12. Review Card Lifecycle State Machine (ADR-0010)

> **Status**: ADR-0010 accepted 2026-07-12 (Task B backend complete; Task A frontend pending).
> **Related**: `docs/adr/ADR-0010-review-card-lifecycle-state-machine.md`.

### 12.1 Problem Solved

ADR-0010 replaces the overloaded `review_cards.fsrs_enabled` boolean with an explicit four-state lifecycle. Previously `fsrs_enabled=false` conflated "user archived", "system disabled", and "reset side-effect"; there was no way to represent temporary bury or long-term suspend, and `resetCard()` silently un-archived cards. The undo ledger (ADR-0009) snapshot/restore path would also overwrite a concurrent lifecycle change because it snapshotted `fsrs_enabled`.

### 12.2 Lifecycle States

| Concept | Persistent? | Queue eligible? | FSRS modified? | ReviewLog written? |
|---|---|---|---|---|
| **Active** | yes | yes | normal rating | yes |
| **Buried** | temporary | no (until user-local next-day 00:00) | no | no |
| **Suspended** | yes (until Resume) | no | retained, no new writes | no |
| **Archived** | yes (until Restore) | no | retained, no new writes | no |
| **Reset** | *not a state* — scheduling operation | depends on lifecycle state | yes (rebuilds FSRS fields) | existing reset log semantics |
| **Delete** | *not a state* — physical removal | n/a | n/a | n/a |

**Buried** auto-reverts to Active at the user's timezone next natural-day 00:00. No timer or scheduled job is required — the queue query (`scopeSenseReviewEligible`) treats `buried_until <= now` as Active via an OR clause. Buried does **not** write a `ReviewLog` and does **not** touch FSRS.

**Suspended** stays out of the queue until the user explicitly resumes. Resume preserves the original `fsrs_due_at` (it does **not** force the card to be due now). Suspended is visible in the management page by default.

**Archived** exits the current learning system but retains all history. It is hidden from the management page's default list and visible under an "Archived" filter. Restore returns the card to Active with its prior FSRS data intact.

**Reset** is a scheduling operation, not a lifecycle state. It rebuilds FSRS fields but does **not** change `lifecycle_state`, `buried_until`, `lifecycle_version`, or `lifecycle_changed_at`. A Suspended card that is reset stays Suspended; an Archived card that is reset stays Archived.

**Delete** is a physical removal operation and is **not** exposed through the unified lifecycle endpoint. It continues to use its own dangerous-confirmation flow and dependency protection.

### 12.3 `fsrs_enabled` Compatibility Mirror

`fsrs_enabled` is retained as a read-only compatibility mirror to avoid touching every queue/stats query in a single round:

- `lifecycle_state IN ('active','buried')` → `fsrs_enabled=true`
- `lifecycle_state IN ('suspended','archived')` → `fsrs_enabled=false`

The mirror is maintained inside `ReviewCardLifecycleCommandService` on every transition. `ReviewCardService::resetCard()` no longer force-sets `fsrs_enabled=true` — it leaves the mirror to whatever the current lifecycle state dictates. `ReviewCardFsrsSnapshotService::restore()` (ADR-0009 undo path) no longer restores `fsrs_enabled` — undo must not overwrite a concurrent lifecycle change.

### 12.4 Backend Service Boundaries (Task B)

- **`ReviewCardLifecyclePolicy`** — pure state machine. `describe(ReviewCard $card, Carbon $now, string $timezone): array`, `canTransition(string $from, string $action): bool`, `availableActions(array $descriptor): array`. No DB, no writes. Expired buried is automatically treated as Active. The Vue layer MUST NOT replicate the state machine.
- **`ReviewCardBuryTimeService`** — `nextLocalDayBoundary(string $timezone, Carbon $now): Carbon`. Handles month-end, year-end, DST forward/back, invalid timezone (treated as UTC), and user-vs-server timezone difference. The frontend MUST NOT compute `buried_until`.
- **`ReviewCardLifecycleSnapshotService`** — captures `lifecycle_state` / `buried_until` / `lifecycle_version` / `lifecycle_changed_at` / `fsrs_enabled` for audit.
- **`ReviewCardLifecycleCommandService`** — single mutation entry point. Executes `bury` / `unbury` / `suspend` / `resume` / `archive` / `restore` inside a `DB::transaction` with `lockForUpdate` on `ReviewCard`: validate user/language/sense/confirmed → validate `expected_version` → `Policy::canTransition()` → capture previous state → apply transition → `lifecycle_version + 1` → sync `fsrs_enabled` mirror → save card → create `ReviewCardStateEvent` → commit. Same `request_id` retry returns `already_applied=true`. Stale version returns 409. Illegal transition returns 409. No `ReviewLog` is created. FSRS `due_at` / `stability` / `difficulty` / `reps` / `lapses` are never modified by lifecycle operations.
- **`LifecycleConflictException`** (409) / **`LifecycleValidationException`** (422) — dedicated exceptions.
- **`ReviewCardLifecycleController`** — `GET /review-cards/{reviewCard}/lifecycle`, `POST /review-cards/{reviewCard}/lifecycle-actions`, `GET /review-cards/{reviewCard}/lifecycle-events`. The legacy `enabled` / `archive` / `restore` endpoints are retained for backward compatibility but delegate internally to `CommandService` — no second mutation logic is maintained.

### 12.5 Queue Eligibility Scope

`ReviewCard::scopeSenseReviewEligible(int $userId, string $language, Carbon $now)` is the single entry point for queue eligibility:

- `user_id` + `language_id` + `target_type=sense` + `fsrs_enabled=true`
- AND ((`lifecycle_state=active` AND (`buried_until IS NULL` OR `buried_until <= now`))
      OR (`lifecycle_state=buried` AND `buried_until <= now`))

The OR clause for expired buried is the auto-restore mechanism — no scheduled job is needed. Due conditions are appended by the caller (`SenseReviewService`).

All other consumers (stats, interval preview, reschedule preview, management filters, export) apply the same lifecycle awareness via their own scopes — they MUST NOT duplicate the lifecycle predicate. Stats can now split cards into Active / Buried / Suspended / Archived counts.

### 12.6 Audit Trail — `review_card_state_events`

New additive table records every transition:

```
id, user_id, language_id, review_card_id, action,
previous_state (JSON), new_state (JSON),
request_id (UUID, unique), source, metadata (nullable JSON), created_at
```

`ReviewCardStateEvent` model casts `previous_state` / `new_state` as `array`. The `request_id` unique index is the idempotency key. The management page detail drawer exposes a read-only "状态历史" view; the export includes lifecycle fields but does NOT leak internal audit metadata.

### 12.7 Boundaries with Existing Systems

- **Rating (`ReviewCardService::recordReview`)**: only queue-eligible cards can be rated. The query now filters `lifecycle_state=active` AND `buried_until` expiry AND `fsrs_enabled=true`. Returns `ReviewCard` (not `ReviewLog`).
- **Undo (`SenseReviewUndoService` / `SenseReviewUndoPolicy`)**: `ReviewCardFsrsSnapshotService::restore()` no longer restores `fsrs_enabled`. `SenseReviewUndoPolicy` returns `card_suspended` or `card_archived` blocked reason — undo is blocked on Suspended/Archived cards to avoid overwriting a concurrent lifecycle change. Undo must operate while the card is still Active.
- **Reset (`ReviewCardService::resetCard`)**: only rebuilds FSRS scheduling fields. Does NOT change `lifecycle_state` / `buried_until` / `lifecycle_version` / `lifecycle_changed_at` / `fsrs_enabled`. A Suspended card stays Suspended after reset; an Archived card stays Archived.
- **Delete**: NOT exposed through the unified lifecycle endpoint. Continues to use its own dangerous-confirmation flow. The lifecycle state machine never transitions to a "deleted" state.
- **Stats / Interval Preview / Reschedule Preview / Management / Export**: all lifecycle-aware. Interval preview and reschedule preview only operate on Active cards (they intentionally exclude buried cards even if expired, because they are explicit user-facing operations, not queue consumption).
- **Daily report / 7-day trend / 30-day calendar**: unaffected — they read ReviewLog, not ReviewCard lifecycle. Lifecycle operations write no ReviewLog, so historical reports are unchanged.

### 12.8 Single Additive Migration

`database/migrations/2026_07_12_000001_add_review_card_lifecycle_state_machine.php`:

- Adds to `review_cards`: `lifecycle_state` (string, default 'active', indexed), `buried_until` (nullable datetime, indexed), `lifecycle_version` (unsigned int, default 0), `lifecycle_changed_at` (nullable datetime).
- Creates `review_card_state_events` table.
- Backfill: `fsrs_enabled=false` → `lifecycle_state='archived'`; otherwise `lifecycle_state='active'`. No FSRS scheduling fields are modified. No existing column is dropped.
- Reversible (down drops the new columns and the new table).
- No second migration is allowed. No ReviewLog schema change. No fresh/wipe/drop/truncate.

### 12.9 Backend Test Coverage (Task B-9)

Seven new test files, 133 tests, 291 assertions, all green:

- `tests/Unit/ReviewCardLifecyclePolicyTest.php` (39 tests)
- `tests/Unit/ReviewCardBuryTimeServiceTest.php` (16 tests)
- `tests/Feature/ReviewCardLifecycleMigrationTest.php` (10 tests)
- `tests/Feature/ReviewCardLifecycleCommandTest.php` (24 tests)
- `tests/Feature/ReviewCardLifecycleQueueTest.php` (13 tests)
- `tests/Feature/ReviewCardLifecycleConcurrencyTest.php` (8 tests)
- `tests/Feature/ReviewCardLifecycleCompatibilityTest.php` (23 tests)

Covers: all legal/illegal transitions, bury to next-day local 00:00, DST, expired bury auto-restore, suspend/resume, archive/restore, reset preserves lifecycle, delete not in unified endpoint, request ID idempotency, version conflict, two-tab concurrency, rating concurrency, undo concurrency, other user/language, legacy word card, rejected sense, migration backfill, `fsrs_enabled` mirror, 0 ReviewLog writes, FSRS field invariance, state event correctness, queue/stats/preview/manager consistency, no N+1.

Regression suites all green: ReviewFsrsTest 63, SenseReviewStackUndoTest 15, SenseReviewIntervalPreviewTest 25, ReviewCardManageTest 258+2 skipped, WordSense 197+1 skipped, FsrsSchedulingServiceTest 9.

### 12.10 Frontend Layer (Task A — pending)

- **`resources/js/services/ReviewCardLifecyclePresentation.js`** — pure presentation helper (state Chinese label, color, blocked reason, buried remaining time, available actions, danger level). No axios, no Vue, no DOM, no FSRS calculation.
- **SenseReview "更多" menu**: adds 埋藏到明天 / 暂停复习 / 归档 / 重置学习进度 / 删除. Active can bury/suspend/archive/reset; Buried can unbury; Suspended can resume/archive/reset; Archived is not visible on the review page; Delete keeps its own dangerous confirmation.
- **ReviewCardManage**: new filters (学习中 / 已埋藏 / 已暂停 / 已归档 / 全部), per-row state badge, lifecycle history drawer, per-row actions (bury/unbury/suspend/resume/archive/restore/reset/delete). No "启用/禁用" toggle.
- **Batch operations**: bulk suspend/resume/archive/restore/unbury (NOT bulk bury/reset/delete). Per-item results (`success` / `already_applied` / `conflict` / `forbidden` / `not_found`); partial failure is NOT masked as full success.
- **State explanation UI**: a "状态说明" entry on the management page that explains 埋藏 / 暂停 / 归档 / 重置 / 删除 in plain language. Does NOT expose `lifecycle_version`, `fsrs_enabled` mirror, or database column names.
- **Concurrency / error UX**: 409 → "卡片状态已在其他页面发生变化，已刷新最新状态。"; network failure → "状态修改失败，请检查网络后重试。"; illegal transition → "当前状态不能执行此操作。". No optimistic state mutation on the frontend.
- **Frontend guard tests**: `ReviewCardLifecyclePresentationGuard.test.mjs`, `SenseReviewLifecycleActionsGuard.test.mjs`, `ReviewCardManageLifecycleGuard.test.mjs`, `ReviewCardLifecycleBulkGuard.test.mjs`.

### 12.11 Frozen Boundaries

- **Policy**: pure, no DB, no writes. Vue MUST NOT replicate the state machine.
- **CommandService**: single mutation entry point. No other service may write `lifecycle_state` / `buried_until` / `lifecycle_version` / `lifecycle_changed_at` directly.
- **Bury time**: computed only by `ReviewCardBuryTimeService`. Frontend MUST NOT compute `buried_until`.
- **Mirror invariant**: `lifecycle_state IN ('active','buried')` → `fsrs_enabled=true`; `lifecycle_state IN ('suspended','archived')` → `fsrs_enabled=false`. Maintained only inside `CommandService`.
- **ReviewLog**: never written by lifecycle operations. Never deleted by lifecycle operations.
- **FSRS**: `due_at` / `stability` / `difficulty` / `reps` / `lapses` never modified by lifecycle operations.
- **Reset**: does NOT change lifecycle state. Does NOT force `fsrs_enabled=true`.
- **Delete**: NOT exposed through the unified lifecycle endpoint.
- **Migration**: exactly one additive migration. No second migration. No ReviewLog schema change.

## 13. Sense Leech Governance and Rewrite Package (ADR-0011)

> **Status**: ADR-0011 accepted 2026-07-12 (Task B backend complete; Task A frontend pending).
> **Related**: `docs/adr/ADR-0011-sense-leech-governance-and-rewrite-package.md`.

### 13.1 Problem Solved

ADR-0011 adds an Anki-like "leech" governance layer for sense review cards that are repeatedly forgotten. The system classifies each sense card as `stable` / `struggling` / `leech` based on its ReviewLog history, suggests governance actions (continue review, rewrite example, edit sense, suspend temporarily, view history), and can generate a "rewrite prompt package" (JSON + Markdown) for the user to copy to an external AI. The package is read-only — LinguaCafe does NOT call any AI provider, does NOT create WordSense / ReviewCard / ReviewLog, and does NOT auto-suspend cards.

### 13.2 Classification States

| Status | Meaning | Queue eligible? | ReviewLog written? |
|---|---|---|---|
| **stable** | Normal learning progress | yes (per lifecycle) | no (leech is read-only) |
| **struggling** | Recent difficulty, not yet leech | yes (per lifecycle) | no |
| **leech** | Repeatedly forgotten, suggests governance | yes (per lifecycle) | no |

Leech is a **computed classification**, NOT a lifecycle state. It does not affect queue eligibility directly — that remains the responsibility of `ReviewCardLifecyclePolicy`. Suspended / archived cards still have a computable leech status (shown on the management page) but do not appear in the review queue.

### 13.3 Thresholds

- **leech**: `again_count >= 3 AND total_reviews >= 5` OR last 7 reviews `(again + hard) >= 4`
- **struggling**: last 5 reviews `(again + hard) >= 3` OR `fsrs_lapses >= 2 AND forgetting_pattern.trend = 'declining'`
- **stable**: all other cards

### 13.4 Backend Service Boundaries (Task B)

- **`SenseReviewLeechPolicy`** — pure classification function. `classify(ReviewCard $card, array $feedback, array $lifecycleDescriptor, ?Carbon $now): array`. Returns `{status, severity (0-100), reasons[], suggestions[], blocked_actions[]}`. No DB, no writes, no AI, no lifecycle mutation, no FSRS modification, no Request/Auth access. `blocked_actions` blocks `suspend_temporarily` when `effective_state != 'active'`.
- **`SenseReviewLeechQueryService`** — batch query service. `describeForCard()` / `describeForCards()` / `summary()` / `filterCardIdsByLeechStatus()`. Reuses `SenseReviewLearningFeedbackService::buildForCards()` (1 ReviewLog query for all cards — no N+1). Excludes reset and undone ReviewLog rows via the shared `SenseReviewQueryService` exclusion. Does NOT write, does NOT call AI, does NOT mutate lifecycle.
- **`SenseReviewLeechRewritePackageService`** — generates `sense-leech-rewrite-package-v1` JSON + Markdown. `buildPackage()` / `buildPackagesBatch()`. Returns `{schema_version, package, markdown, json, provider_called: false, card_created: false, review_log_created: false}`. Does NOT call any AI provider. Does NOT create WordSense / ReviewCard / ReviewLog. The package is a read-only output that the user copies to an external AI manually.
- **`SenseReviewLeechController`** — 4 endpoints (see 13.5). Uses `ReviewCardManageAccessService::findManageableSenseCardOrFail` for access control. Validates `ids` array (max 50) on bulk endpoint.

### 13.5 Endpoints

- `GET /reviews/senses/{reviewCard}/leech` — single-card leech descriptor.
- `POST /reviews/senses/{reviewCard}/leech/rewrite-package` — generate rewrite package (read-only).
- `GET /review-cards/manage/leech-summary` — counts + leech/struggling card ID lists.
- `POST /review-cards/manage/bulk-leech-rewrite-packages` — batch generate packages (`{ids: int[]}`, max 50).

The management page `data()` endpoint injects `leech_status` / `leech_severity` / `leech_reasons` / `leech_suggestions` into each item when `include_leech=true` or `filter=leech|struggling`.

### 13.6 ReviewLog Exclusion

Leech computation uses the same exclusion as all product analytics: `undone_at IS NULL` AND `source != 'reset'` AND `rating != 'reset'`. This is delegated to `SenseReviewLearningFeedbackService`, which uses `SenseReviewQueryService::nonResetCardReviewLogQuery()`. Reset logs and undone logs do not participate in leech classification.

### 13.7 Lifecycle Boundary

- Leech is NOT a lifecycle state. `SenseReviewLeechPolicy` never writes `lifecycle_state` / `buried_until` / `lifecycle_version`.
- Leech can SUGGEST `suspend_temporarily`, but the actual suspend must go through `ReviewCardLifecycleCommandService::act($card, 'suspend', ...)`.
- Suspended / archived cards still show `leech_status` on the management page (read-only classification).
- Suspended / archived cards do NOT appear in the review queue (enforced by `scopeSenseReviewEligible`).
- Resume preserves leech history (the ReviewLog data is unchanged by lifecycle transitions).
- `blocked_actions` blocks `suspend_temporarily` when `effective_state != 'active'`.

### 13.8 Rewrite Package Safety

The rewrite package is a read-only output. The response explicitly includes:
- `provider_called: false` — no AI provider was contacted.
- `card_created: false` — no WordSense / ReviewCard was created.
- `review_log_created: false` — no ReviewLog was written.

The package JSON includes `output_contract` and `safety_rules` that instruct the external AI to NOT create new senses, NOT create review cards, and to focus on improving the example sentence / Chinese definition / disambiguation clues.

### 13.9 No Migration

ADR-0011 does NOT add any database migration. All leech classification is computed from existing `ReviewLog` / `ReviewCard` / `WordSense` data at query time. The `SenseReviewLearningFeedbackService` already aggregates the needed metrics (`total_reviews`, `forget_count`, `hard_count`, `recent_reviews`, `forgetting_pattern`).

### 13.10 Backend Test Coverage (Task B-8)

Five new test files, 56 tests, all green:

- `tests/Unit/SenseReviewLeechPolicyTest.php` (17 tests) — stable / struggling / leech classification, severity, suggestions, blocked actions, reasons.
- `tests/Feature/SenseReviewLeechQueryTest.php` (9 tests) — single/batch describe, summary, filter, undone/reset exclusion, user isolation, no N+1.
- `tests/Feature/SenseReviewLeechRewritePackageTest.php` (11 tests) — schema version, provider_called=false, no WordSense/ReviewCard/ReviewLog creation, required fields, markdown, safety rules, batch.
- `tests/Feature/ReviewCardManageLeechTest.php` (9 tests) — leech/struggling filter, include_leech injection, summary endpoint, bulk endpoint, single card endpoints, 404 for other user.
- `tests/Feature/SenseReviewLeechLifecycleBoundaryTest.php` (10 tests) — leech doesn't modify lifecycle, suspended/archived still shows leech, blocked actions, queue exclusion, resume preserves leech, no state events / review logs created, suspend via lifecycle endpoint.

Regression suites all green: ReviewFsrsTest 63, SenseReviewStackUndoTest 15, ReviewCardLifecycleCommandTest 24, ReviewCardLifecycleQueueTest 13, SenseReviewIntervalPreviewTest 25, WordSense 197+1 skipped.

### 13.11 Frontend Layer (Task A — pending)

- **`resources/js/services/SenseReviewLeechPresentation.js`** — pure helper (status label, color, severity text, reason text, suggestion text, copy filename). No axios, no Vue, no DOM, no FSRS calculation.
- **`SenseReviewLeechPanel.vue`** — review page panel. Stable: hidden. Struggling: light hint. Leech: governance card on answer face with buttons (生成重写提示包 / 编辑词义 / 查看历史 / 暂停复习). Does NOT block rating. Does NOT change hotkeys. Suspend goes through lifecycle endpoint.
- **`SenseReviewLeechRewritePackageDialog.vue`** — shows JSON + Markdown, copy buttons, explicit "不调用 AI" notice. No provider-preview. No auto-creation.
- **`ReviewCardManage.vue`** — leech filter (全部 / 正常 / 需关注 / 高遗忘), leech badge + severity + reasons, per-row actions, batch generate rewrite packages, batch suspend leech cards, detail drawer diagnostics.
- **Frontend guard tests**: `SenseReviewLeechPresentationGuard.test.mjs`, `SenseReviewLeechPanelGuard.test.mjs`, `ReviewCardManageLeechGuard.test.mjs`, `SenseReviewLeechRewritePackageGuard.test.mjs`.

### 13.12 Frozen Boundaries

- **Policy**: pure, no DB, no writes, no AI, no lifecycle mutation, no FSRS modification.
- **QueryService**: read-only, reuses `SenseReviewLearningFeedbackService`, no N+1, excludes reset/undone ReviewLog.
- **RewritePackageService**: read-only output, `provider_called=false`, `card_created=false`, `review_log_created=false`.
- **Leech is NOT a lifecycle state**: suspend must go through `ReviewCardLifecycleCommandService`.
- **No migration**: all computation from existing data.
- **No auto-AI**: the rewrite package is for the user to copy to an external AI manually.
- **No auto-creation**: no WordSense, no ReviewCard, no ReviewLog created by leech services.
- **No FSRS change**: leech classification does not modify any FSRS field.
- **No rating change**: leech does not block rating or change hotkeys.
