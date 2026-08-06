# M6C Article Health Acceptance — 2026-07-28

Status: **Accepted / Closed**
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
Architecture: `docs/adr/ADR-0036-m6-resilience-health-and-isolation-boundaries.md`
Implementation plan: `docs/plans/m6-resilience-health-isolation-implementation-plan.md`

## 1. Accepted scope

M6C closes one bounded, read-only article-health slice for the authenticated
user and selected learning language:

- stable report, check and finding schemas;
- bounded book, chapter and vocabulary scans;
- empty-book, chapter-processing, token, reference, source-fallback and
  vocabulary-pollution checks;
- bounded decompression of processed chapter text;
- optional tokenizer health without turning an unavailable integration into a
  report failure;
- explicit `not_configured` status for chapter positions because the current
  schema has no supported position column;
- a visible user page with loading, healthy, finding and failure states.

The report does not repair, reprocess, import, relink or create learning data.
It does not call source-context recovery and exposes no mutation route.

## 2. Focused verification

`php artisan test tests/Feature/ArticleHealthTest.php` passed:

- authentication and GET-only routing;
- stable healthy response;
- empty, invalid, pending and failed chapter findings;
- processed-text expansion bounds;
- invalid-reference user/language isolation;
- bounded fallback and pollution scans;
- tokenizer not-configured and unavailable behavior;
- scan truncation;
- before/after proof that article, learning and operation-ledger tables are not
  mutated.

Result: **9 passed / 52 assertions**.

PHP syntax checks passed for the service, controller and configuration.
`php artisan route:list --path=article-health` exposed only:

- `GET|HEAD /article-health`;
- `GET|HEAD /article-health/data`.

## 3. Protected regressions and build

The final closeout checks passed:

- tokenizer, Reader, source-context and write-boundary set:
  **110 passed / 605 assertions**;
- `ReviewFsrsTest`: **63 passed / 375 assertions**;
- `FsrsSchedulingServiceTest`: **9 passed / 46 assertions**;
- WordSense filter: **203 passed / 1 skipped / 873 assertions**;
- `npm run development`: compiled successfully with Laravel Mix 6.0.49;
- `git diff --check`: passed.

The build emitted existing Bootstrap/Sass deprecation warnings only. The
WordSense run emitted existing PHPUnit doc-comment metadata deprecations and
one expected skip for the unavailable real tokenizer.

## 4. Real-browser evidence

Channel: official OpenAI Browser plugin, one automation-owned page against the
task-owned testing listener at `127.0.0.1:8092`, with a task-owned tokenizer
health listener at `127.0.0.1:8679`.

The acceptance batch:

1. rendered registration and login and created the first testing-only user
   through visible UI actions;
2. clicked the visible `内容健康` navigation entry;
3. observed the healthy English report with zero findings, tokenizer
   `available`, and chapter positions `not_configured`;
4. inserted one exact testing-only empty book and one URL-like encountered word,
   clicked `刷新`, and observed exactly `ARTICLE_BOOK_EMPTY` and
   `ARTICLE_VOCABULARY_POLLUTION`;
5. stopped the testing backend, clicked `刷新`, and observed the rendered
   `健康报告加载失败` error state.

The batch closed its only page. Closeout stopped the exact testing listeners,
verified ports 8092 and 8679 closed, verified no official-browser tabs
remained, and deleted only the exact task-created user, goal/settings, book,
vocabulary and log fixtures. Before cleanup, database inspection confirmed the
report had created no WordSense, ReviewCard, ReviewLog or operation data.

## 5. Quality review

- **Correctness:** report states and finding codes are stable and covered by
  focused tests and rendered-page evidence.
- **Security/isolation:** every content scan is scoped to authenticated
  user/language ownership; cross-scope fixtures are excluded.
- **Side effects:** routes are GET-only and before/after table snapshots prove
  the service is read-only.
- **Performance:** scans, samples and decompression are bounded; unavailable
  optional integrations degrade to a check state.
- **Architecture:** the controller is a thin adapter, the service owns report
  composition, and Reader/import/source-context/FSRS seams remain untouched.

Verdict: **Approve M6C.** M6 remains in progress until M6D isolation closeout.
