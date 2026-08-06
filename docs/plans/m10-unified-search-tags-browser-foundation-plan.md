# M10 Unified Search, Tags, and Browser Foundation Plan

> Status: Accepted / Closed
> Date: 2026-07-28
> Architecture: ADR-0038

## Goal and non-goals

Close M10 by extending the existing canonical Browser criteria into a shared,
versioned search foundation; add isolated WordSense tags; expose tag-aware
Browser/Saved Search/export behavior; and add a bounded mobile read-only search.

Do not add Deck/Subdeck, Note Type, sibling cards, full Anki Boolean grammar,
tag color/alias/merge, duplicate-sense merging, formal Custom Study scoring,
Statistics V2, `.apkg`, FSRS writes or lifecycle changes.

## Architecture review

### Existing owners to retain

- `ReviewCardManageFilterState` owns canonical request/persisted criteria.
- `ReviewCardBrowserSearchParser` and its criteria/applier own the existing
  free-text and advanced-token grammar.
- `ReviewCardManageQueryService::buildFromFilterState()` owns the scoped
  current-membership query used by management, export and overview.
- `ReviewCardSavedSearchService` owns versioned criteria persistence.
- `ReviewCardManageItemSerializerService` owns Browser/export row projection.
- `ReviewCardTableSurface.vue` owns selection, configurable columns and bulk
  action presentation; `ReviewCardSearchSurface.vue` owns filter/Saved Search
  presentation.

### New owners and seams

- `WordSenseTagService` owns tag normalization, scoped CRUD and bounded,
  idempotent assignment changes.
- `WordSenseTagController` is the authenticated web adapter.
- `MobileReviewCardSearchController` adapts mobile requests to the same
  FilterState/query/serializer pipeline and never mutates.
- `WordSenseTagManager.vue` owns tag catalog CRUD; a focused tag picker owns
  bulk assignment. Parent surfaces exchange selected IDs and refresh events,
  not request internals.

### Data and compatibility

`authenticated user/language + allowlisted criteria → canonical FilterState →
single parsed criteria → shared scoped ReviewCard query → shared row serializer
→ Browser/export/overview/mobile consumer`

`authenticated user/language + validated tag/card/sense IDs →
WordSenseTagService transaction → tag/assignment tables only`

Saved Search v1 remains readable as v2 with `tag_ids=[]`; new or edited rows are
v2. Tag deletion may make an old Saved Search reference disappear from the
resolved filter, but cannot broaden it silently: unknown scoped tag IDs produce
an empty result until the user edits that search.

## Ordered slices

### M10A — Tag domain and canonical criteria

- additive tag and assignment schema;
- models/relations and scoped service CRUD/bulk operations;
- `tag_ids` FilterState v2 and query semantics;
- serializer tag projection;
- focused isolation, zero-learning-write and Saved Search compatibility tests.

### M10B — Browser Tag and Saved Search surface

- web routes/adapters;
- tag filter and catalog management;
- bulk add/remove from selected Browser rows;
- configurable Tags column and stable visible states;
- JSON/CSV/TSV parity.

### M10C — Consumer parity and query links

- Saved Search deep-link restoration with tags;
- Study Overview current-membership parity;
- stable shareable Browser query serialization;
- focused cross-consumer parity harness.

### M10D — Mobile read-only search and closeout

- device-bound GET endpoint with maximum 100 rows/page;
- shared criteria/query/serialization semantics;
- API isolation/pagination/error contract;
- protected regression, build and real-browser closeout.

## Exact allowed files

M10 may modify only:

- new M10 migrations under `database/migrations/`;
- new `app/Models/WordSenseTag.php`;
- `app/Models/WordSense.php`;
- new `app/Services/WordSenseTagService.php`;
- `app/Services/ReviewCardManageFilterState.php`;
- `app/Services/ReviewCardManageQueryService.php`;
- `app/Services/ReviewCardSavedSearchService.php`;
- `app/Services/StudyOverviewQueryService.php`;
- `app/Services/ReviewCardManageItemSerializerService.php`;
- `app/Services/ReviewCardExportService.php`;
- existing Browser parser/criteria/applier only if the frozen `tag:` grammar is
  implemented without changing existing token semantics;
- new `app/Http/Controllers/WordSenseTagController.php`;
- new `app/Http/Controllers/Mobile/MobileReviewCardSearchController.php`;
- `app/Http/Controllers/ReviewCardManageController.php` only for additive
  query-link metadata if required;
- `app/Http/Controllers/Mobile/MobileBootstrapController.php`;
- `routes/web.php` and `routes/api.php`;
- `resources/js/components/ReviewCards/ReviewCardManage.vue`;
- `resources/js/components/ReviewCards/ReviewCardSearchSurface.vue`;
- `resources/js/components/ReviewCards/ReviewCardTableSurface.vue`;
- `resources/js/services/ReviewCardManageFilterState.js`;
- new focused tag components under that same directory;
- directly affected ReviewCard/Saved Search/Study Overview/export/Mobile tests;
- new focused M10 tests and testing-only browser fixture/evidence;
- ADR-0038, this plan, roadmap, current context/handoff, documentation index and
  directly related documentation guards.

Files outside this list remain forbidden. Existing unrelated working-tree
changes remain user assets.

## Adversarial pre-implementation review

- **Cross-scope IDs:** fixed by resolving every tag and target from the same
  user/language transaction before writing.
- **Partial bulk changes:** fixed by validation-before-mutation and one
  transaction; bounded request sizes prevent unbounded SQL.
- **Saved Search broadening after deletion:** unknown referenced tag IDs must
  yield no matches, not be discarded.
- **N+1 tag projection:** eager-load tag relations in the shared query.
- **Parser regression:** M10A uses structured `tag_ids`; any later `tag:` token
  is additive and cannot reinterpret existing text/prefixes.
- **Marker conflation:** separate schema, service, endpoints and UI labels.
- **Mobile drift:** controller must call the same FilterState/query owner rather
  than copy SQL.
- **Migration safety:** testing database only; no development/user migration,
  backfill or destructive command.

## Minimum validation

- Focused tag/criteria/API/UI tests and migration rollback-on-empty-schema test.
- Browser/data/JSON/CSV/TSV/Saved Search/Overview/mobile ID-set parity.
- WordSense, ReviewCard management/search/export, Mobile and FSRS protected
  filters.
- `npm run development`, PHP syntax and `git diff --check`.
- Real browser: create/rename tag, bulk add/remove, filter, save/reopen search,
  show/hide Tags column, delete tag, observe rejection/error state, and clean
  only task-owned testing data.

## Stop conditions

Continue autonomously inside the frozen slices. Pause only for an authority
conflict, scope outside M10, non-testing migration/data operation, secret or
paid-provider access, deployment, or irreversible third-party action.

## Closeout

M10A–M10D are Accepted / Closed. Focused and protected automation, the frontend
build, migration rollback/restore and official real-browser acceptance passed.
Evidence: `docs/testing/m10-unified-search-tags-browser-foundation-acceptance-2026-07-28.md`.
