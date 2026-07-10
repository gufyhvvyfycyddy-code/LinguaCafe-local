# ADR-0006: Sense Review Daily Report Consolidation

Date: 2026-07-10
Status: Accepted
Task: GLM-SenseReview-DailyReportConsolidation-AndMergedProduct-1000-3

## Context

The SenseReview feature previously had two overlapping "today" reports:

1. **今日复习总结** (Today Summary) — `GET /reviews/senses/today-summary`
   - Service: `SenseReviewTodaySummaryService`
   - Payload: total_reviews, distinct_senses, distribution, forget_rate,
     focus_senses (with last_reviewed_at), recent_reviews
   - Vue: `SenseReviewTodaySummary.vue`

2. **今日学习日报** (Daily Report) — `GET /reviews/senses/daily-report`
   - Service: `SenseReviewDailyReportService`
   - Payload: overview (total/distinct/first/again/average), quality
     (distribution/forget/stability), focus_senses (without
     last_reviewed_at), progress_senses
   - Vue: `SenseReviewDailyReport.vue`

### Why they need to merge

- **Duplicate focus_senses algorithm**: Both services implement identical
  filter rules (again / hard / multi-rating / last-again-or-hard), identical
  sort (again desc, hard desc, total desc), and identical max-10 limit. The
  only difference is the output shape (TodaySummary includes
  `last_reviewed_at`, DailyReport omits it). This is a clear DRY violation.
- **Duplicate date boundary**: Both compute `Carbon::today(tz)` +
  `Carbon::tomorrow(tz)` independently instead of using the shared
  `SenseReviewReportPeriodService::rollingDays(1, tz)`.
- **Duplicate log query**: Both call `analytics->reviewsForPeriod()` for the
  exact same window.
- **Product confusion**: Two "today" reports on the report home page confuse
  users. The product decision is to merge them into one richer daily report.
- **Recent reviews lost**: DailyReport (the richer report) does NOT include
  `recent_reviews`, which is a useful feature only present in TodaySummary.

### Current duplication points

| Concern | TodaySummary | DailyReport |
|---|---|---|
| Day boundary | `Carbon::today/tomorrow` | `Carbon::today/tomorrow` |
| Log fetch | `analytics->reviewsForPeriod()` | `analytics->reviewsForPeriod()` |
| focus_senses filter | identical rules | identical rules |
| focus_senses sort | identical | identical |
| focus_senses shape | includes last_reviewed_at | omits last_reviewed_at |
| rating distribution | `metrics->ratingDistribution()` | `metrics->ratingDistribution()` |
| forget rate | `metrics->forgetRate()` | `metrics->forgetRate()` |

## Decision

### 1. Single formal endpoint

`GET /reviews/senses/daily-report` is the **only** formal "today" report
endpoint. The old `GET /reviews/senses/today-summary` endpoint is **deleted
entirely** (no deprecated alias) because a full-repo scan confirmed the only
production callers were the ReportCatalog frontend config and the test
suite — no other module, controller, or service references it.

### 2. Single Product Service

`SenseReviewDailyReportService` becomes the only today-report Product
Service. It delegates insight generation (focus / progress / recent) to a
new pure-computation layer.

### 3. New pure Daily Insight Builder

`app/Services/SenseReviewDailyInsightBuilder.php` is a pure computation
layer. It:
- Depends only on `SenseReviewReportMetricsService` and
  `SenseReviewRatingContract`.
- NEVER depends on Eloquent, DB, Auth, Request, Controller, config, .env,
  or `SenseReviewAnalyticsQueryService`.
- Exposes `build(Collection $logs): array` returning the unified
  `focus_senses` (superset with `last_reviewed_at`), `progress_senses`
  (again→good, hard→easy, temporal), and `recent_reviews` (max 10, newest
  first, with `rating_label`).
- 0 DB queries by contract.

### 4. Old endpoint deletion strategy

Deleted entirely:
- `routes/web.php` line for `/reviews/senses/today-summary`
- `SenseReviewController::todaySummary()` method
- `SenseReviewController` constructor dependency on
  `SenseReviewTodaySummaryService`
- `app/Services/SenseReviewTodaySummaryService.php` (whole file)
- `resources/js/components/Senses/SenseReviewTodaySummary.vue` (whole file,
  deleted in Task A)
- `SenseReviewReportCatalog.js` entry for `today-summary` (deleted in Task A)
- `SenseReviewReportCenter.vue` import/registration of TodaySummary (deleted
  in Task A)

### 5. Test migration strategy

The old `SenseReviewTodaySummaryTest.php` coverage is migrated to:
- `SenseReviewDailyReportTest.php` — for user/language isolation, reset
  exclusion, legacy word exclusion, date boundary, READ-only, FSRS-unchanged,
  timezone/day, HTTP auth, empty state, recent_reviews newest-first.
- `SenseReviewDailyInsightBuilderTest.php` — for pure-computation focus
  rules, focus sort, focus limit, progress transitions, recent limit,
  rating label, empty collection, input-order independence.

A 1:1 migration table is provided in the final report. The old
`SenseReviewTodaySummaryTest.php` is deleted only after the migration is
verified green.

### 6. Query budget

- Empty daily report: **1 ReviewLog query** (`reviewsForPeriod`).
- Non-empty daily report: **at most 2 ReviewLog queries**:
  1. `reviewsForPeriod` (today's logs)
  2. `sensesReviewedBefore` (for first-review vs review-again — only when
     today has at least one sense)
- `DailyInsightBuilder`: **0 DB queries** (pure computation).
- `MetricsService`: **0 DB queries** (pure computation).
- `recent_reviews` adds **0 additional queries** (computed from the same
  in-memory log collection).
- Query count is constant regardless of 1 / 10 / 50 senses.

### 7. Rollback

To roll back this consolidation:
1. `git revert` the two commits.
2. The old TodaySummaryService, route, controller method, Vue component, and
   Catalog entry are restored.
3. No database migration is needed — this change never touched the schema,
   ReviewLog writes, or FSRS fields.

### 8. Non-goals (explicitly out of scope)

- No FSRS algorithm change.
- No ReviewLog write-semantics change.
- No database migration or schema change.
- No rating API value change (again/hard/good/easy stay).
- No change to due/stability/difficulty/interval/state.
- No legacy word card creation.
- No reading page / AIStudyCard / tokenizer / import-export changes.

## Consequences

- **Positive**: One source of truth for focus_senses algorithm. One today
  report endpoint. One today report Vue component. `recent_reviews` becomes
  part of the richer daily report. Date boundary centralized in
  PeriodService. Insight generation is pure and unit-testable without DB.
- **Negative**: Clients (if any external) that called `/today-summary` must
  switch to `/daily-report`. A full-repo scan confirmed no such external
  clients exist in this codebase. The payload shape differs, so this is a
  breaking change to the today-summary contract — acceptable because the
  only consumer is the frontend Catalog, which is updated in the same task.
- **Neutral**: The `focus_senses` item now always includes
  `last_reviewed_at` (the TodaySummary superset shape), which is a
  backward-compatible addition for the old DailyReport consumers.
