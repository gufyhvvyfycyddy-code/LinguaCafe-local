# M12 Special Study Sessions Plan

> Status: Accepted / Closed
> Date: 2026-07-29
> Architecture: ADR-0040

## Goal and non-goals

Upgrade preview-only Custom Study into saved, rebuildable LinguaCafe Special
Study sessions with preview, formal and early-review outcomes, unified
Tag/Marker/source/lifecycle/FSRS filtering, and deterministic ordering.

Do not add deck movement, arbitrary deck trees/search expressions, a second
scheduler, custom scheduling, sibling bury, permanent limit changes or
client-authored FSRS values.

## Responsibilities and seams

- Special Study criteria/query/order services normalize the request and reuse
  the M10 scoped ReviewCard query vocabulary.
- The session aggregate owns definition, candidate snapshot, progress,
  revision, save/rebuild/end and answer idempotency.
- Preview policy owns only session-local progress.
- Formal/early answers delegate to `ReviewCardService` and the existing
  operation ledger inside the session transaction.
- Controllers own auth, selected language, validation mapping and response
  envelopes only.
- The UI renders explicit queue-impact language and calls the existing today
  limit API for temporary limit changes.

Data flow:

`UI → normalized definition → scoped M10 query + M12 scenario predicate →
complete-set order → persisted candidate/progress snapshot`.

Preview answer:

`session/action lock → eligibility recheck → local progress → action response`.

Formal/early answer:

`session/action lock → eligibility recheck → ReviewCardService →
FsrsSchedulingService + ReviewLog → shared operation ledger → local progress →
action response`.

## Ordered slices

### M12A — Aggregate, criteria and query foundation

- additive session/action tables and models;
- normalized modes, filters, orders and complete-set candidate query;
- persisted create/show/list/save/rebuild/end;
- strict scope, revision, state and migration tests.

### M12B — Preview and formal execution

- preview zero-write answer path;
- formal and early-review unique rating path;
- shared web rating operation registration;
- action replay/conflict, rollback, eligibility and queue-impact tests.

### M12C — Web experience and today limits

- Special Study setup, active session and saved-session views;
- explicit preview/formal/early warnings and recent-new restriction;
- Tag, Marker, source, chapter, lifecycle, FSRS and order controls;
- save/rebuild/end and existing today-limit override controls;
- loading, empty, validation, stale, completed and ended states.

### M12D — Compatibility and closeout

- old Custom Study token route and coordinator regression;
- shared search/tag/marker/source, review-limit and operation-ledger regressions;
- protected FSRS/WordSense tests, build and server-bound real-browser
  acceptance;
- roadmap, context, handoff, documentation index and acceptance evidence.

## Exact allowed files

M12 may modify only:

- one additive M12 migration under `database/migrations/`;
- new `app/Models/SpecialStudySession.php` and
  `SpecialStudySessionAction.php`;
- new focused exception and services under
  `app/Services/SpecialStudy/`;
- `app/Services/ReviewCardManageFilterState.php` and
  `ReviewCardManageQueryService.php` only for a reusable scoped-query seam;
- `app/Models/ReviewLog.php`, `app/Services/SenseReviewQueryService.php`,
  `ReviewDailyProgressQueryService.php`, `StudyOverviewQueryService.php`,
  `ReviewCardBrowserSearchQueryApplier.php` and the two existing
  today-forgotten Custom Study query/order files only to make
  `special_study` a canonical formal-rating source;
- `app/Services/MobileOperationLedgerService.php` and `app/Models/Operation.php`
  only for device-optional web rating registration metadata;
- new `app/Http/Controllers/SpecialStudySessionController.php`;
- `app/Http/Controllers/Mobile/MobileBootstrapController.php` only if the
  read-only capability map requires it;
- `routes/web.php` and `routes/api.php` only for M12 routes/capabilities;
- `resources/js/components/CustomStudy/CustomStudy.vue`,
  `CustomStudySession.vue`, `CustomStudySessionCoordinator.js` and at most two
  focused sibling components/helpers;
- direct Custom Study, M10 query/filter, today-limit, rating-ledger and new M12
  tests;
- ADR-0040, this plan, roadmap, current context/handoff, documentation index
  and M12 acceptance evidence.

Files outside this list remain forbidden. Existing unrelated worktree changes
remain user assets.

## Risk controls

- client input is normalized through enums and bounded integer/ID lists;
- user/language/confirmed-sense scope is part of every candidate and answer
  query;
- ordering precedes truncation and random order is persisted per build;
- preview cannot resolve or inject any writing service;
- formal/early cannot bypass `ReviewCardService` or
  `FsrsSchedulingService`;
- action reservation, rating, ledger registration and session progress commit
  or roll back together;
- stale revision and reused request identifiers fail before mutation;
- rebuild/end never undo or rewrite rating history;
- existing daily override expires by study date and remains outside session
  ownership.

## Minimum validation

- focused M12 migration/domain/API/UI guards;
- legacy Custom Study full focused suite;
- M10 query/tag/marker/source and today-limit suites;
- M2/M11 operation and Card Info suites;
- `ReviewFsrsTest`, `FsrsSchedulingServiceTest` and WordSense tests;
- `npm run development`;
- official-plugin-first real-browser preview/formal/save/rebuild/end/filter/
  ordering/today-limit acceptance;
- route listing, PHP/JS syntax, allowlist and `git diff --check`.

## Closeout

M12A–M12D are closed. The final protected matrix passed 1312 tests with 4264
assertions, all focused JS guards passed, the frontend build succeeded, and the
official OpenAI Chrome plugin completed server-bound testing-database
acceptance for preview/formal/early warnings, filtering, today limits,
save/rename/rebuild/end and formal rating.

The preview checkpoint proved zero ReviewLog/operation writes. The formal
checkpoint proved exactly one `special_study` ReviewLog and one applied Web
`sense_review.rating` operation. Task data, sentinel and server process were
fully cleaned. Evidence:
`docs/testing/m12-special-study-sessions-acceptance-2026-07-29.md`.
