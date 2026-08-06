# M14 Statistics and Card Info V3 Plan

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0046

## Goal and non-goals

Deliver one server-defined statistics report covering Future Due, activity,
card states, review time, intervals, FSRS distributions, rating use, True
Retention, difficult WordSenses and reading conversion. Reuse the same report
for responsive/mobile summaries and CSV/PDF exports, and extend Card Info
additively.

Do not add arbitrary analytics SQL, a second search grammar, a chart framework,
write-capable analytics, invented mature-retention claims or cross-user data.

## Ordered slices

1. M14A - metric definitions, scoped query and focused tests.
2. M14B - Card Info V3 additive read model.
3. M14C - responsive web charts and mobile summary cards.
4. M14D - CSV/PDF renderers and download endpoints.
5. M14E - protected tests, build, PDF render inspection, server-bound browser
   acceptance and documentation closeout.

Closed on 2026-07-29. Evidence is recorded in
`docs/testing/m14-statistics-card-info-v3-acceptance-2026-07-29.md`.

## Allowed files

- `app/Services/StatisticsService.php`;
- one focused `app/Services/StatisticsExportService.php`;
- `app/Services/ReviewCardInfoQueryService.php`;
- `app/Http/Controllers/HomeController.php`, `routes/web.php`;
- `resources/js/components/Home/Statistics.vue` and at most one focused chart
  component/helper;
- direct M14 PHP/JS tests;
- ADR-0046, this plan, M14 acceptance evidence, roadmap/master plan/current
  context/handoff/documentation index.

All other files are forbidden. Existing unrelated worktree changes remain user
assets.

## Minimum verification

- M14 metric/export/Card Info/UI tests;
- existing `ReviewCardInfoTest`, M10 unified-search tests, ReviewLog analytics
  tests and formal-rating regressions;
- `npm run development`;
- server-bound testing-database browser acceptance at desktop and 390px widths;
- download and PDF signature/text/render inspection;
- PHP syntax, route list, allowlist and `git diff --check`.
