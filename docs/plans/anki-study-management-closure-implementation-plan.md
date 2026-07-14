# Anki Study Management Closure — Implementation Plan

**Goal:** Deliver Saved Search V1, today-only limits with cumulative introductions, Review Time, and Study Overview V1 without changing FSRS, undo, lifecycle, Custom Study, or search grammar.

## Global invariants

- Start from `7c77fb142160095228954b4520b5f46644f7dd9a` with local/origin/remote aligned.
- Create exactly three additive reversible migrations; never a fourth.
- Use test-first RED → GREEN → focused regression for each vertical slice.
- Stage only explicit files; one English conventional commit and push per major phase.
- Never touch `.env`, `AGENTS.md`, `.omo`, `.playwright-cli`, `nul`, notification hooks, or prohibited product areas.

## Phase A — Saved Search V1

1. RED: FilterState normalization, stable serialization, invalid input, and query-output parity tests.
2. GREEN: add `ReviewCardManageFilterState`; refactor the query service/controller to delegate to `buildFromFilterState()` while preserving existing wrappers.
3. RED: migration/model/service/controller CRUD, duplicate/cap/isolation/language tests.
4. GREEN: implement transactional Saved Search storage and REST routes.
5. RED/GREEN: add the focused Vue panel and JS state helpers; apply clears selection and manual edits do not mutate saved rows.
6. Verify browser-search/manage/export/protected suites, build, route list, migration rollback/forward, DB doctor, and management Chrome flow.
7. Commit and push Phase A.

## Phase B — Today-only limits

1. RED: override schema/API, study-day/DST, user/language isolation.
2. GREEN: migration/model/service/controller and timezone half-open day bounds.
3. RED: reviewed/introduced counters, first-ever formal rating, reset/undo/source exclusions.
4. GREEN: add the single progress query service and align legacy formal source.
5. RED/GREEN: Effective limits decision table and shared queue integration; expose summary and today-only dialog.
6. Verify both queue entrypoints, settings fingerprints, queue order, FSRS, undo/lifecycle, build, routes, DB doctor, and Chrome flow.
7. Commit and push Phase B.

## Phase C — Review Time and Study Overview

1. RED/GREEN: third migration, ReviewLog field, shared boundary validation, transactional persistence.
2. RED/GREEN: pure browser duration tracker and integration in Sense/legacy review; Custom Study guard.
3. RED: pure metrics fixtures for distributions, due buckets, retrievability availability, duration coverage, and True Retention.
4. GREEN: add Overview query service/builder/controller and reuse canonical Saved Search/effective-limit/timezone seams.
5. RED/GREEN: page, route, nav, period/Saved Search selectors, empty/error/loading states, responsive layout.
6. Verify 1/100/500 query budgets, 30/90/365, all protected suites, full suite, build, routes, DB doctor, and Chrome at 1920×1080 and 900×900.
7. Update master plan, handoff, documentation index, ADR closure evidence; commit and push Phase C/docs.

## Completion gates

- Full PHP/JS suite has zero failures and production development bundle succeeds.
- Three migrations roll back and reapply cleanly; schema and DB doctor are healthy.
- Git local/origin/remote are aligned; only pre-existing prohibited untracked files remain.
- Stop instead of expanding scope if a fourth migration, FSRS math change, undo/lifecycle semantic change, historical log rewrite, or second filter engine becomes necessary.
