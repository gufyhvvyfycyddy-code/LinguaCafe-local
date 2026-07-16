# ReviewCardManage Architecture Convergence Plan

> **Status**: Phase 3B-1 Accepted / Production Closed
>
> **Current next slice**: Phase 3B-2 — Table / Columns / Pagination / Selection / Export — Authorized Next / Not Started
>
> **Architecture baseline**: master `291a5a8676f5ade2625a51c4305fb1ce2714a3fd`
>
> **Phase 3B-1 implementation baseline**: master `83605d5885bcd9b8b583e9ab5647d2b12fb572e4`
>
> **Scope**: sense-only ReviewCard management; preserve current endpoint, payload, access, lifecycle, delete, reset, ReviewLog and FSRS semantics.

## 1. Why this architecture line exists

The management page already has useful Browser-style capabilities. Its original container mixed search, Saved Search, table, selection, exports, Card Info, lifecycle, leech governance, editing and dangerous mutations in one Options API component.

Original measured baseline:

- `ReviewCardManage.vue`: 3,411 lines;
- 24 direct `axios.` references;
- 12 `v-dialog` blocks;
- Card Info, search and table concerns all lived in the parent.

The goal is incremental responsibility separation. Each phase must move one real responsibility, preserve behavior and pass real browser acceptance.

## 2. Anki official reference and the parts LinguaCafe borrows

Reviewed official sources and code boundaries:

- **Anki Manual — Browsing** describes the Browser as sidebar, card/note table and editing area. Saved Searches belong to the sidebar/search region. The manual distinguishes multi-row selection from the current card used by Card Info.
- **Anki Manual — Searching** states that Browser and Filtered Deck use one common search method. LinguaCafe therefore keeps one server-authoritative search contract instead of adding a second frontend grammar.
- `ankitects/anki/qt/aqt/browser/` contains dedicated `sidebar/`, `table/`, `browser.py`, `card_info.py`, `layout.py` and `previewer.py` boundaries.
- `qt/aqt/browser/browser.py` normalizes the query through `build_search_string` and delegates result loading to `table.search`; the top-level Browser coordinates instead of parsing search syntax itself.
- `ankitects/anki/rslib/src/search/` separates search service code from `builder.rs`, `parser.rs`, `sqlwriter.rs` and `writer.rs`.
- Exact paths used as references include `qt/aqt/browser/sidebar`, `qt/aqt/browser/table`, `qt/aqt/browser/card_info.py`, `rslib/src/search/parser.rs` and `rslib/src/search/sqlwriter.rs`.

Borrowed principles:

1. The page container coordinates regions.
2. Search parsing and result presentation have separate owners.
3. Saved Search belongs with the search/filter surface.
4. Current-card state and selected-row state stay distinct.
5. Card Info has a dedicated read-only owner.
6. Table ownership is handled after search ownership, in a separate phase.

Deliberate LinguaCafe deviations:

- 不复制 Anki 的 Cards/Notes 双模式；LinguaCafe remains sense ReviewCard-only.
- 不实现 deck/subdeck 树、Note Type、Card Template or tag-tree editing.
- Do not copy Anki note-deletion semantics; existing WordSense/ReviewCard and ReviewLog-retention contracts remain authoritative.
- Do not expand Browser Search V1 into OR, NOT, parentheses, dates or regular expressions during architecture convergence.
- Do not create Filtered Deck semantics. Custom Study remains the temporary-study boundary.
- Card flags remain a future Card Marker reference.

## 3. Subtitle-derived long-project rules

The following project subtitle files were used because they directly address architecture work on a mature codebase:

- `AI可以帮你写代码，但帮不了你成为架构师.srt`
- `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
- `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
- `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
- `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
- `AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt`
- `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`

Rules frozen into this plan:

- 只把稳定决定写进长期文档；探索过程留在任务报告。
- 文档负责说明边界，可执行 guard、测试和 Chrome smoke 负责阻止越界。
- 每轮只移动一个真实职责，并保留旧功能回归证据。
- 按数据流和副作用分模块，不按行数机械拆文件。
- 优先复用既有组件和状态工具，不新增没有独立职责的抽象层。
- 当前权威入口优先于历史长叙述，旧状态不得重新进入有效文档。

## 4. Frozen target shape

```text
ReviewCardManage.vue
  ├─ ReviewCardSearchSurface.vue
  │    ├─ ReviewCardSavedSearchPanel.vue
  │    ├─ search input / token chips / server errors
  │    ├─ preset filters / advanced filters
  │    └─ normalized filter-state emission
  ├─ future table surface (Phase 3B-2)
  │    ├─ columns / sorting / pagination / compact mode
  │    ├─ current card / selected cards
  │    └─ read-only exports
  ├─ ReviewCardInfoDrawer.vue
  │    ├─ one canonical detail request
  │    ├─ overview / history / diagnosis
  │    └─ stale-response and close cleanup
  └─ future mutation/dialog families (Phase 3C)
       ├─ lifecycle/archive/restore/bury/suspend
       ├─ due-now/reset
       ├─ delete/bulk delete
       └─ leech governance/rewrite packages
```

The parent may coordinate list requests, cross-region refresh and snackbar state. It must not re-implement access, lifecycle, leech, delete, search grammar or FSRS rules.

## 5. Phase sequence and evidence

### Phase 3A — Card Info Drawer Extraction

Status: **Accepted / Production Closed**.

Implemented boundary:

- `ReviewCardInfoDrawer.vue` owns the canonical detail request, loading/error/detail state, stale-response sequence guard, tabs, formatting and close cleanup.
- `ReviewCardManage.vue` retains deep-link parsing, source-dialog orchestration, report return, list refresh and mutation workflows.
- The parent decreased from 3,411 to 2,792 lines and from 24 to 22 direct `axios.` references; 12 `v-dialog` blocks remained.
- Exactly one canonical detail request owner remains.
- Authenticated Chrome acceptance covered 1920×1080, 900×900, Slow 3G switching, report deep link/return, source context, clean Console and unchanged ReviewLog/FSRS data.

### Phase 3B-1 — Search / Filter / Saved Search Surface — Accepted / Production Closed

Implemented boundary:

- Created `resources/js/components/ReviewCards/ReviewCardSearchSurface.vue`.
- The child owns search input, token chips, server error presentation, preset filters, advanced filters, search help and canonical filter-state emission.
- Existing `ReviewCardSavedSearchPanel.vue` remains the Saved Search GET/POST/PATCH/DELETE owner; no duplicate CRUD implementation was created.
- Existing `ReviewCardManageFilterState.js` remains the canonical normalization/apply helper.
- `ReviewCardManage.vue` owns list/data requests, sorting, pagination, table, selection, exports, mutation dialogs and Card Info orchestration.
- Search remains server-authoritative. The child performs only simple token detection/removal needed for UI behavior and contains no request client or full parser.

Measured result:

- parent decreased from 2,792 lines to 2,462 lines;
- parent direct `axios.` references remain 22 because request ownership intentionally stayed in the parent until later phases;
- parent now has 11 `v-dialog` blocks, decreased from 12 because search help moved with the search surface;
- `ReviewCardSearchSurface.vue` contains no `axios.` call;
- table, export, selection, lifecycle, reset, delete and Card Info behavior were not moved.

TDD and browser evidence on 2026-07-16:

- `ReviewCardSearchSurfaceGuard.test.mjs` first failed because the component did not exist, then passed after the smallest extraction.
- A second RED caught the 900×900 advanced-filter action overflow; adding a wrapping action row turned the guard GREEN and removed page-level horizontal overflow.
- focused search and Saved Search tests passed; the development build compiled successfully.
- authenticated Chrome confirmed one data request per explicit search/filter action.
- `bigger` returned its matching card; `zzznomatch` displayed the no-match state.
- invalid `is:unknown` displayed the server 422 grammar error and preserved existing table data.
- clicking a preset filter removed conflicting `is:` state and issued the expected filter request.
- advanced filter `reps_min=1` produced zero results; clearing it restored the two-card list.
- temporary Saved Search creation, automatic selection, delete confirmation and cleanup completed through the page; no test row remained.
- at 900×900, document `scrollWidth === clientWidth`; table overflow remains inside its intended scroll container.
- Console contained no error or warning.

### Phase 3B-2 — Table / Columns / Pagination / Selection / Export — Authorized Next / Not Started

Next owned surface:

- table columns, sorting, pagination and compact mode;
- current-card and selected-card state kept distinct;
- selection state without dangerous-action ownership;
- read-only JSON/CSV/TSV export presentation;
- reuse current endpoints, serializers and `ReviewCardManageFilterState.js`.

Forbidden in Phase 3B-2:

- backend route, payload or search grammar changes;
- lifecycle, reset, delete, archive, restore or leech mutation changes;
- Card Info or Search Surface redesign;
- new global state, event bus or speculative browser framework;
- entering Phase 3C, Card Marker or Custom Study 1B.

### Phase 3C — Mutation and Dialog Families — Planned / Not Started

Group dangerous actions by real domain boundaries while retaining current backend authorities. No mutation semantics change is authorized by this plan.

### Phase 3D — Container Closure — Planned / Not Started

Evaluate the final coordinator only after earlier slices are separately accepted. The stretch target is about 1,000 lines; 1,200 is acceptable when further extraction would create meaningless pass-through components.

## 6. Formal target pairing required by the hard rules

Completed Phase 3B-1 target pair:

- `ARCH-ReviewCardManage-3B-1`: verify search/Saved Search data flow, keep the server as parser authority, freeze parent/child ownership, reuse `ReviewCardManageFilterState.js`, and reject duplicate request owners or speculative state frameworks.
- `DEV-ReviewCardManage-3B-1`: create `ReviewCardSearchSurface.vue`, move the complete search/filter/help presentation, retain `ReviewCardSavedSearchPanel.vue`, add executable guards, run grouped regression and perform authenticated dual-viewport Chrome acceptance.

ARCH and DEV closed in the same task. The architecture record preceded implementation, the new guard demonstrated RED, and implementation proceeded only inside the frozen boundary.

## 7. Phase 3B-1 changed files

Production:

- `resources/js/components/ReviewCards/ReviewCardManage.vue`
- `resources/js/components/ReviewCards/ReviewCardSearchSurface.vue`

Tests:

- `tests/js/ReviewCardSearchSurfaceGuard.test.mjs`
- `tests/Feature/ReviewCardBrowserSearchUiGuardTest.php`
- existing architecture/master-plan guards

Docs:

- this plan;
- Anki-aligned roadmap;
- master plan;
- current handoff;
- Documentation Index.

## 8. Permanent safety boundaries

- no backend Controller/Service/route/payload changes during a frontend ownership extraction;
- no database schema or migration change;
- no FSRS, due, rating or ReviewLog write change;
- no lifecycle, archive, restore, reset or delete semantic change;
- no frontend reimplementation of Browser Search grammar;
- 不进入 Phase 3B-2、Card Marker 或 Custom Study 1B without a separate task;
- no deck/subdeck, Note mode, tag tree or Filtered Deck;
- no new dependency, Vuex module or event bus without proven need;
- no `.env`, `AGENTS.md`, `.omo/`, `.playwright-cli/` or `nul` changes;
- no notification script, DCP, force or destructive DB command.

## 9. Required verification for future Browser phases

1. Write or update a guard first and show the expected RED result.
2. Implement the smallest responsibility-complete change that turns it GREEN.
3. Run focused Node guards and related PHP tests.
4. Run database health before Feature tests.
5. Feature tests must be grouped. Never run the ungrouped full Feature suite.
6. Run Unit suite, all Node guards, frontend build, `php artisan db:doctor` and `git diff --check`.
7. Use authenticated MCP Chrome at 1920×1080 and 900×900.
8. Verify request ownership, no external domains and no duplicate requests.
9. Verify Console has no new error or warning.
10. Verify protected learning/scheduling data is unchanged when the task is read-only.

## 10. Accept / Refuse conditions

Accept a phase only when:

- the moved region has one complete owner;
- the parent no longer keeps a parallel implementation;
- existing requests and user behavior remain compatible;
- tests, build and dual-viewport Chrome pass;
- the diff stays inside the allowed boundary.

Refuse when:

- extraction is only a template wrapper while state or requests remain duplicated;
- generic DTO/service/event layers lack an independent responsibility;
- backend behavior is changed to make frontend extraction easier;
- request count increases without an approved product reason;
- destructive, rating, lifecycle or FSRS behavior changes;
- API/fetch evidence is used instead of real browser acceptance;
- ungrouped Feature execution is presented as valid evidence.

## 11. Stop rule

Phase 3B-1 closes after its commit, normal push and final report. Phase 3B-2 is only **Authorized Next / Not Started**. Do not enter Phase 3B-2, Phase 3C, Card Marker, Custom Study 1B or any later phase automatically.
