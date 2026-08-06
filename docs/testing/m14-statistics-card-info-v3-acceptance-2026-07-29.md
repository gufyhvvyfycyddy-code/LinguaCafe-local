# M14 Statistics and Card Info V3 Acceptance

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0046

## Accepted scope

M14 now has one server-defined, read-only report for:

- Future Due 7/30/90/365 and a 365-day series;
- review calendar, current card states and special-study rating count;
- total/average review time with a 60-second per-answer analytics cap;
- interval, stability, difficulty and retrievability distributions;
- Again/Hard/Good/Easy use and first-review-per-card-per-day True Retention;
- most failed, most difficult and least stable WordSenses;
- user/language reading baselines plus unified-query-scoped Sense conversion;
- responsive/mobile summary cards and native CSS charts;
- CSV/PDF downloads from the same report;
- additive Card Info V3 current descriptors, rating counts and review time.

The report uses authenticated user, selected language, confirmed Sense-card and
M10 unified-query scope. Formal analytics exclude undone logs. Audit history in
Card Info still retains undone rows.

## Automated and build evidence

- M14 + Card Info focused run: 32 tests passed, 150 assertions.
- Protected M10 search, ReviewFsrs, Card Info and analytics run: 137 tests
  passed, 685 assertions.
- M14 frontend contract guard passed.
- `npm run development` compiled successfully; only existing Sass deprecation
  warnings remained.
- PHP syntax and `git diff --check` passed.

## Real-browser evidence

The official Browser/Chrome localhost channels had already been genuinely
attempted and finalized during the M14 local-browser batch. The authorized
Playwright fallback used one page/context/browser and closed all three.

Before any write, `127.0.0.1:8784` returned:

```json
{"environment":"testing","database_is_testing":true,"sentinel_present":true}
```

Through rendered UI and ordinary user events, the browser:

1. created and logged into a task-only testing administrator;
2. loaded the full statistics page at 1280px;
3. changed the period to 7 days and applied `rated:again` through the M10 query
   field;
4. verified the truthful empty state and updated 7-day summary;
5. clicked CSV and PDF buttons and captured real downloads;
6. reloaded at 390×844 and verified the M14 surface was 374px wide, wholly
   inside the viewport, while the document width remained exactly 390px.

Only the existing unauthenticated font request returned 401 during setup/login.
The local, gitignored screenshots and downloads were written under
`output/m14-acceptance/`; that path records the inspection location and is not
versioned repository evidence.

## PDF verification

The downloaded/generated PDF had a valid `%PDF-1.4` signature, one extractable
text page, a 918×1188 rendered PNG at 1.5× scale, readable headers/metrics,
explicit `N/A` empty values and a page footer. Visual inspection found no
clipping, overlap, black squares or unreadable glyphs. The locally inspected,
non-versioned sample was `output/pdf/m14-statistics-sample.pdf`.

## Cleanup

The testing administrator, legacy testing-only `reviewIntervals` fixture and
acceptance sentinel were removed. Follow-up counts were all zero, and the
testing server was stopped.
