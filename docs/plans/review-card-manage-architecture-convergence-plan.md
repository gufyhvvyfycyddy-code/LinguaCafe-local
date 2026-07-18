# ReviewCardManage Architecture Convergence Plan

> **Status**: Phase 3C-4 — Leech Governance Mutation Family — Accepted / Production Closed
>
> **Current next slice**: Phase 3D — Container Closure — Planned / Not Authorized; no implementation phase is currently authorized
>
> **Architecture baseline**: master `0b293874412458bf0bc8badd3e0d018471c47f85`
>
> **Phase 3B-1 implementation baseline**: master `666f76a4829034123d275d9ec6a295d8e22dc20a`
>
> **Phase 3B-2 execution baseline**: master `666f76a4829034123d275d9ec6a295d8e22dc20a`
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

Current measured snapshot after Phase 3C-4:

- 28 production files exceed 500 lines;
- 10 production files exceed 1,000 lines;
- only 1 production file now exceeds 1,500 lines (`TextBlockGroup.vue`, 2,514 lines);
- `ReviewCardManage.vue` is 767 lines with 7 direct `axios.` references and 2 dialogs;
- `ReviewCardDeleteMutationSurface.vue` is 196 lines with one DELETE request, one bulk-delete POST request and two dialogs;
- `ReviewCardLeechGovernanceMutationSurface.vue` is 366 lines with two direct `axios.` references and two dialogs;
- current debt assessment is **6.0/10, localized medium-high burden**: the management module has materially converged, while Reader/Reviewer hotspots and residual compatibility code still require staged governance.

## 2. Anki official reference and the parts LinguaCafe borrows

Reviewed official sources and code boundaries:

- **Anki Manual — Browsing** describes the Browser as sidebar, card/note table and editing area. Saved Searches belong to the sidebar/search region. The manual distinguishes multi-row selection from the current card used by Card Info, and lists Set Due, Reset, Toggle Suspend and Info as separate card actions.
- **Anki Manual — Studying** keeps Bury, Suspend, Reset and Set Due semantically separate: Bury hides until the next day, Suspend lasts until manual unsuspend, Reset returns a card to the new queue while preserving review history, and Set Due changes scheduling without pretending a review occurred.
- **Anki Manual — Searching** states that Browser and Filtered Deck use one common search method. LinguaCafe therefore keeps one server-authoritative search contract instead of adding a second frontend grammar.
- `ankitects/anki/qt/aqt/browser/` contains dedicated `sidebar/`, `table/`, `browser.py`, `card_info.py`, `layout.py` and `previewer.py` boundaries.
- `qt/aqt/browser/browser.py` normalizes the query through `build_search_string` and delegates result loading to `table.search`; the top-level Browser coordinates instead of parsing search syntax itself.
- `ankitects/anki/rslib/src/search/` separates search service code from `builder.rs`, `parser.rs`, `sqlwriter.rs` and `writer.rs`.
- `qt/aqt/operations/scheduling.py` and the `qt/aqt/operations/` package keep scheduling/card mutations outside the top-level Browser coordinator. The Browser selects current/selected cards and invokes operation-specific boundaries.
- Exact paths used as references include `qt/aqt/browser/sidebar`, `qt/aqt/browser/table`, `qt/aqt/browser/card_info.py`, `rslib/src/search/parser.rs` and `rslib/src/search/sqlwriter.rs`.

Borrowed principles:

1. The page container coordinates regions.
2. Search parsing and result presentation have separate owners.
3. Saved Search belongs with the search/filter surface.
4. Current-card state and selected-row state stay distinct.
5. Card Info has a dedicated read-only owner.
6. Table ownership is handled after search ownership, in a separate phase.
7. Bury, Suspend, Reset, Set Due and Delete remain different product commands with different confirmations and side effects; a generic mutation abstraction must not erase those distinctions.

Deliberate LinguaCafe deviations:

- 不复制 Anki 的 Cards/Notes 双模式；LinguaCafe remains sense ReviewCard-only.
- 不实现 deck/subdeck 树、Note Type、Card Template or tag-tree editing.
- Do not copy Anki note-deletion semantics; existing WordSense/ReviewCard and ReviewLog-retention contracts remain authoritative.
- Do not expand Browser Search V1 into OR, NOT, parentheses, dates or regular expressions during architecture convergence.
- Do not create Filtered Deck semantics. Custom Study remains the temporary-study boundary.
- Card flags remain a future Card Marker reference.

## 3. Subtitle-derived long-project rules

The repository checkout itself does not track subtitle files, but this task had direct access to and searched all **9 个原始字幕文件** supplied with the project context (11,156 subtitle lines total), with focused close reading of the four most relevant architecture/spec/harness videos. This plan therefore records a fresh evidence-based synthesis instead of merely inheriting an earlier summary:

- `AI可以帮你写代码，但帮不了你成为架构师.srt`
- `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
- `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
- `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
- `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
- `AI 编程的 spec 到底该什么时候写？和先写文档完全相反.srt`
- `AI编程别一开始就写太多spec，MVP阶段放开抡.srt`
- `Vibe Coding 第二讲：像架构师一样用 AI 做复杂产品.srt`
- `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`

Rules frozen into this plan:

- 只把已经稳定、下一轮不应重新争论的决定写进长期 spec；探索中的判断保留在任务证据里。
- MVP 只冻结产品身份、反向边界和不可破坏项；进入长期迭代后，再把反复验证过的决定逐步固化。
- 文档负责解释边界，可执行 guard、测试和 Chrome smoke 才负责阻止越界；Agent 自报完成不等于验收。
- 每轮只移动一个真实职责，并保留旧功能回归证据。
- 按数据流、副作用和权威所有者分模块，不按行数机械拆文件。
- 接口本身也有复杂度成本；只有形成独立职责时才抽取，禁止空壳包装和重复 DTO。
- 优先复用既有组件、纯函数和状态工具，避免全局状态与隐式跨模块副作用。
- 当前权威入口优先于历史长叙述，旧状态不得重新进入有效文档。

## 4. Frozen target shape

```text
ReviewCardManage.vue
  ├─ ReviewCardSearchSurface.vue
  │    ├─ ReviewCardSavedSearchPanel.vue
  │    ├─ search input / token chips / server errors
  │    ├─ preset filters / advanced filters
  │    └─ normalized filter-state emission
  ├─ ReviewCardTableSurface.vue
  │    ├─ columns / sorting / pagination / compact mode
  │    ├─ current card / selected cards
  │    └─ read-only exports
  ├─ ReviewCardInfoDrawer.vue
  │    ├─ one canonical detail request
  │    ├─ overview / history / diagnosis
  │    └─ stale-response and close cleanup
  ├─ ReviewCardSchedulingMutationSurface.vue
  │    └─ due-now / reset requests, locks and dialogs
  ├─ ReviewCardLifecycleMutationSurface.vue
  │    ├─ lifecycle descriptor and stale-response protection
  │    ├─ single / bulk lifecycle requests and locks
  │    └─ lifecycle confirmations and state help
  ├─ ReviewCardDeleteMutationSurface.vue
  │    └─ single / bulk delete requests, locks and confirmations
  └─ ReviewCardLeechGovernanceMutationSurface.vue
       ├─ Leech summary and rewrite-package requests
       ├─ rewrite-package / bulk suspend dialogs and request locks
       └─ lifecycle writes delegated to ReviewCardLifecycleMutationSurface.vue
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

### Phase 3B-2 — Table / Columns / Pagination / Selection / Export — Accepted / Production Closed

Implemented boundary:

- Created `resources/js/components/ReviewCards/ReviewCardTableSurface.vue` as the complete owner of table rendering, column visibility, compact mode, sorting controls, pagination controls, current-card state, selected-card state and read-only JSON/CSV/TSV exports.
- `currentCardId` and `selectedIds` are separate child-owned states. Opening Card Info marks the current row without changing the checkbox selection.
- The child emits row and bulk mutation intents with an explicit selection snapshot. It contains no mutation request and does not own lifecycle, reset, delete, archive, restore or leech semantics.
- `ReviewCardManage.vue` remains the canonical `/review-cards/manage/data` request owner and continues to own every mutation request, dangerous confirmation dialog, Card Info orchestration, cross-region refresh and snackbar state.
- Existing endpoints, payloads, serializers, server search grammar and `ReviewCardManageFilterState.js` remain unchanged.
- No Vuex module, event bus, backend route, migration or speculative abstraction was introduced.

Measured result:

- the initial extraction decreased the parent from 2,462 lines to 1,532 lines; the corrective deep-link synchronization leaves the current parent at 1,540 lines;
- the initial `ReviewCardTableSurface.vue` was 866 lines; the current component is 872 lines and owns one responsibility-complete table region;
- parent direct `axios.` references decreased from 22 to 19 because the three existing read-only export GET requests moved with their presentation;
- the child contains exactly three `axios.` references, all read-only export GET requests, and no POST/PATCH/DELETE request;
- parent keeps 11 `v-dialog` blocks because mutation/dialog extraction belongs to Phase 3C;
- `ReviewCardSearchSurface.vue` remains 325 lines and unchanged in responsibility.

TDD, regression and browser evidence on 2026-07-16:

- `ReviewCardTableSurfaceGuard.test.mjs` first failed because the component did not exist, then passed after the frozen owner boundary was implemented.
- Testing database health passed: 12 tests, 97 assertions.
- Browser/Search/Saved Search/Manage/Card Info/Deep Link regression passed: 380 tests, 1,298 assertions, with two existing slow tests skipped.
- Lifecycle/Danger/Leech regression passed: 147 tests, 402 assertions.
- Unit suite passed: 652 tests, 1,518 assertions.
- all Node guards passed after historical parent-owned selection and line-count assumptions were updated to the new executable contract.
- `npm run development`, `php artisan db:doctor` and `git diff --check` passed.
- authenticated Chrome at 1920×1080 confirmed one list request for sort and per-page changes, one detail request when opening Card Info, separate current/selected state, working column and compact preferences without list reload, three read-only export requests and a bulk confirmation dialog without mutation.
- authenticated Chrome at 900×900 confirmed no page-level horizontal overflow; wide table overflow remains inside `.table-wrapper`.
- Console contained no error or warning and no external request was observed.

Corrective follow-up on 2026-07-16:

- A second independent acceptance pass found one missed cross-region case: a valid learning-report deep link opened Card Info for review card 156 while the table child still held `currentCardId` as `null`, so no current row was marked.
- The guard was extended first and failed on the missing parent-to-table synchronization contract.
- `ReviewCardTableSurface.vue` now exposes the narrow `markCurrentCardById()` method while remaining the sole current-card state owner. `ReviewCardManage.vue` coordinates only after the canonical drawer emits `detail-loaded`.
- This deep-link current-row synchronization does not change checkbox selection, list requests, Card Info request ownership, mutation behavior, endpoints or payloads.
- After rebuilding, authenticated Chrome at 1920×1080 opened the valid `daily-report` deep link for review card 156 with exactly one detail request, `currentCardId=156`, no checkbox selection and the matching row marked. Selecting card 157 then left card 156 as the current row, proving current and selected states remain independent.
- At 900×900, card 156 remained current while card 157 remained selected; the page had no horizontal overflow and only `.table-wrapper` scrolled horizontally. Console remained clean and all observed requests stayed on `127.0.0.1:8000`.
- The current measured sizes are 1,540 lines for `ReviewCardManage.vue` and 872 lines for `ReviewCardTableSurface.vue`; request and dialog counts remain 19 parent `axios.` references, three child read-only export GET requests and 11 parent `v-dialog` blocks.

### Phase 3C — Mutation and Dialog Families — Accepted / Production Closed

Group write operations by real domain boundaries while retaining current backend authorities. No mutation semantics change is authorized by this plan.

Frozen subphase order:

1. **Phase 3C-1 — Due-now / Reset Scheduling Mutation Family — Accepted / Production Closed**. `ReviewCardSchedulingMutationSurface.vue` is the sole owner of the two scheduling POST requests, their targets, request locks and confirmation dialogs. The parent only forwards table intents and consumes semantic card-update/refresh/notify/error events.
2. **Phase 3C-2 — Lifecycle Mutation Family — Accepted / Production Closed**. `ReviewCardLifecycleMutationSurface.vue` is the sole descriptor, single/bulk lifecycle request, request-lock, confirmation and state-help owner. Leech governance keeps its own product UI but delegates lifecycle writes to this owner. Authenticated dual-viewport Chrome acceptance completed on 2026-07-17.
3. **Phase 3C-3 — Delete Mutation Family — Accepted / Production Closed**. `ReviewCardDeleteMutationSurface.vue` owns the single/bulk delete confirmations, request locks and the two existing delete requests while preserving ReviewLog, occurrence and last-confirmed-sense semantics. Authenticated dual-viewport browser acceptance completed on 2026-07-17.
4. **Phase 3C-4 — Leech Governance Mutation Family — Accepted / Production Closed**. `ReviewCardLeechGovernanceMutationSurface.vue` owns the Leech summary, rewrite-package requests, dialogs, loading/error state and selected-card suspend orchestration while preserving the no-provider/no-auto-create boundary. Lifecycle HTTP writes still delegate to `ReviewCardLifecycleMutationSurface.vue`.

Anki alignment for 3C-1:

- the Browser coordinates the selected/current card and delegates scheduling operations such as Set Due Date and Reset to operation-specific code;
- LinguaCafe borrows the coordinator-versus-operation boundary only;
- LinguaCafe does not copy deck movement, note deletion, filtered deck behavior, Anki's full reset option matrix or its Cards/Notes dual mode;
- existing LinguaCafe due-now and reset endpoints, ReviewLog behavior and FSRS semantics remain authoritative.

Phase 3C-1 measured result:

- created `resources/js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue` at 117 lines;
- the scheduling child owns exactly two `axios.post` requests and two `v-dialog` blocks;
- `ReviewCardManage.vue` decreased from 1,540 to 1,489 lines;
- parent direct `axios.` references decreased from 19 to 16 because the two live scheduling requests moved and one unused duplicate `setDueNow()` request path was removed;
- parent `v-dialog` blocks decreased from 11 to 9;
- the parent contains no due-now/reset endpoint, target, loading or dialog state;
- lifecycle, delete, leech, search, Card Info, backend routes and payloads remain unchanged.

Phase 3C-1 TDD and browser evidence on 2026-07-16:

- `ReviewCardSchedulingMutationSurfaceGuard.test.mjs` first failed because the component did not exist, then passed after the responsibility-complete extraction;
- `ReviewCardManageUiGuardTest.php` was updated to read the management safety surface across the parent and the new scheduling owner; the safety copy remains locked;
- authenticated Chrome at 1920×1080 confirmed that opening the due-now confirmation creates no write request; one deliberate confirmation for review card 157 produced exactly one `POST /review-cards/manage/157/due-now`, one stats refresh and the semantic row-update event;
- a separate deliberate reset confirmation for review card 156 produced exactly one `POST /review-cards/manage/156/reset`; the dialog closed, target/loading state cleared, list and stats refresh events fired, and the success notice was shown. The resulting `ReviewLog` row was preserved as required;
- Chrome accessibility snapshot IDs drifted during acceptance, so later actions used real DOM events and the loaded Vue component's existing event method inside the authenticated page rather than direct API/fetch calls;
- the same acceptance pass closed a stale deep-link context bug: closing a report-opened Card Info drawer now removes only `review_card_id` and `from` from the route, preserves unrelated query keys such as `saved_search_id`, and prevents a subsequently opened normal card from showing report-only copy;
- deep-link parsing now rejects mixed numeric garbage such as `123abc` and decimal strings such as `1.5` instead of truncating them with `parseInt`;
- at 900×900 the page had no document-level horizontal overflow, table overflow remained inside `.table-wrapper`, the scheduling dialog remained contained, Console was clean and no external resource was observed.

#### Phase 3C-2 — Lifecycle Mutation Family — Accepted / Production Closed

Implemented boundary:

- created `resources/js/components/ReviewCards/ReviewCardLifecycleMutationSurface.vue` at 414 lines;
- the lifecycle child owns exactly one descriptor GET, one single-card lifecycle POST, one bulk lifecycle POST and three dialogs: single confirmation, bulk confirmation and state help;
- `ReviewCardManage.vue` decreased from 1,489 to 1,210 lines;
- parent direct `axios.` references decreased from 16 to 11 and parent `v-dialog` blocks decreased from 9 to 6;
- the parent contains no `/lifecycle-actions`, `/review-cards/manage/bulk-lifecycle`, lifecycle target, request lock, conflict or lifecycle dialog state;
- the parent stores only a read-only lifecycle view snapshot for `ReviewCardTableSurface.vue` and forwards table intents through the child ref;
- table current/selected state remains owned by `ReviewCardTableSurface.vue`; the table still contains no mutation request;
- Leech-specific single and bulk suspend UI remains in the parent for Phase 3C-4, but its lifecycle POSTs now delegate to the lifecycle child, making it the **ReviewCardManage 域内唯一生命周期请求所有者**;
- `SenseReview.vue` 是独立产品入口，仍拥有自己的 lifecycle client；这不影响管理页收敛，但不得把该子组件夸大为全前端唯一 lifecycle client；
- 遗留 `/enabled` archive/restore 兼容方法和对话框无可达表格入口，属于 dormant compatibility debt，而不是第二套活跃状态机；删除代码或退休端点属于后续兼容/Phase 3D 任务，不属于本次验收。

Architecture risks closed:

- the old moderate-action flow could clear the live descriptor 200 ms after the row menu closed, so the later confirmation could silently lose `expected_version`; the child now freezes `expectedVersion` in `lifecycleDialogContext` before menu cleanup;
- descriptor requests now use `descriptorRequestSeq`, card identity checks and destroy invalidation, so a slower response from an earlier row cannot overwrite the active menu;
- shared single/bulk request methods enforce one in-flight request and are reused by Leech suspend delegation, avoiding a hidden second lifecycle client inside ReviewCardManage;
- successful single actions explicitly clear the menu descriptor snapshot, including the fast-confirm path where the delayed menu-close cleanup was skipped by the request lock;
- 409/422 refresh the authoritative descriptor; network and general errors never report false success.

TDD and verification evidence on 2026-07-16:

- `ReviewCardLifecycleMutationSurfaceGuard.test.mjs` first failed because the component did not exist, then passed after the new owner was created;
- the former parent-owned `ReviewCardManageLifecycleGuard.test.mjs` and `ReviewCardLifecycleBulkGuard.test.mjs` failed after extraction, then were rewritten to enforce child ownership, delegation, frozen `expected_version`, stale-response protection and unchanged table intent boundaries;
- testing database health passed before Feature execution;
- lifecycle command, compatibility, concurrency, queue, danger and UI safety regression passed: 98 tests / 229 assertions;
- the development build compiled successfully with only existing Sass deprecation warnings;
- grouped Browser regression passed 393 tests / 1,366 assertions with two existing slow export cases skipped; lifecycle/Leech regression passed 134 tests / 341 assertions; Unit passed 652 tests / 1,518 assertions; all 57 Node guards passed;
- the original implementation pass could not complete authenticated Chrome acceptance because the platform connector returned 502 and the available session had expired;
- a later authenticated localhost browser pass on 2026-07-17 closed that blocker: at 1920×1080, one single-card bury and unbury each produced exactly one lifecycle-action POST, and a two-card bulk suspend and restore each produced exactly one bulk-lifecycle POST; every confirmed write was followed by one list refresh and one stats refresh;
- opening single and bulk confirmations produced no write before confirmation, selection cleared after the successful bulk operations, and the visible lifecycle baseline was restored to total 4, active 4, buried 0, suspended 0, archived 0 and due now 4;
- at 900×900, the state-help and single-card confirmation dialogs remained contained, cancelling created no request and document width matched client width after close;
- Console contained no error, warning or issue, and every observed resource stayed on `127.0.0.1:8000`; full evidence is recorded in `docs/testing/review-card-lifecycle-mutation-browser-acceptance-2026-07-17.md`.

Anki alignment for 3C-2:

- Anki Browser distinguishes the current card from multi-row selection and applies card actions to the selected card set;
- Anki exposes Suspend/Unsuspend, Bury/Unbury, Reset and Set Due as separate actions with different semantics, and its Qt code keeps operation implementations under `qt/aqt/operations/` rather than in the Browser table/controller;
- LinguaCafe borrows this coordinator-versus-operation separation while retaining its own four-state lifecycle policy, optimistic version, idempotent request ID, audit event and sense-only access contracts;
- LinguaCafe does not copy Anki deck movement, sibling bury rules, Cards/Notes mode, note deletion or filtered decks.

#### Phase 3C-3 — Delete Mutation Family — Accepted / Production Closed

Implemented boundary:

- created `resources/js/components/ReviewCards/ReviewCardDeleteMutationSurface.vue` at 196 lines;
- the delete child owns exactly one single-card DELETE request, one selected-card bulk-delete POST request and two confirmation dialogs;
- `ReviewCardManage.vue` decreased from 1,210 to 1,098 lines;
- parent direct `axios.` references decreased from 11 to 9 and parent `v-dialog` blocks decreased from 6 to 4;
- the parent contains no delete request, delete target, request lock, delete confirmation or bulk-delete selection snapshot;
- the parent only forwards row/selection intents and consumes clear-selection, list refresh, stats refresh, notification and error events;
- `ReviewCardTableSurface.vue` remains the current-card, selected-card and intent owner and still contains no mutation request;
- existing delete endpoints, payloads, access checks, ReviewLog preservation, occurrence preservation and last-confirmed-sense behavior remain unchanged;
- no backend file, route, migration, FSRS logic or ReviewLog write rule changed.

TDD and verification evidence on 2026-07-17:

- `ReviewCardDeleteMutationSurfaceGuard.test.mjs` first failed because the component did not exist, then passed after the responsibility-complete extraction;
- `ReviewCardManageUiGuardTest.php` now reads safety copy across the parent and delete owner and passed 17 tests / 22 assertions;
- focused backend delete regression passed 24 tests / 86 assertions;
- the development build completed with only existing Sass deprecation warnings;
- authenticated browser acceptance verified the single and two-card bulk flows at 1920×1080 and 900×900: opening either confirmation produced no write, confirming produced exactly one matching DELETE or bulk-delete POST with HTTP 200, selection cleared after bulk success, and all three acceptance rows left the active list;
- both dialogs remained contained at 900×900, the document had no horizontal overflow, every application request stayed on localhost and the only Console errors were the established local WebSocket fallback failures;
- full evidence is recorded in `docs/testing/review-card-delete-mutation-browser-acceptance-2026-07-17.md`.

Anki alignment for 3C-3:

- the Browser coordinates the current row and selected card set while operation-specific code owns the dangerous action and confirmation;
- LinguaCafe retains its existing WordSense/ReviewCard delete contract instead of copying Anki note deletion;
- ReviewLog and reading-source history remain preserved, and removing the last confirmed sense may return the EncounteredWord to New according to the established backend authority.

#### Phase 3C-4 — Leech Governance Mutation Family — Accepted / Production Closed

Implemented boundary:

- created `resources/js/components/ReviewCards/ReviewCardLeechGovernanceMutationSurface.vue` at 366 lines;
- the Leech child owns exactly one summary GET, one bulk rewrite-package POST and two dialogs: bulk rewrite package and selected-card bulk suspend;
- the existing `SenseReviewLeechRewritePackageDialog.vue` remains the single-card rewrite-package request/dialog owner and is reused inside the new surface;
- `ReviewCardManage.vue` decreased from 1,098 to 767 lines;
- parent direct `axios.` references decreased from 9 to 7 and parent `v-dialog` blocks decreased from 4 to 2;
- the parent contains no Leech endpoint, rewrite-package dialog, bulk Leech selection snapshot or Leech request/loading state;
- the parent only forwards table intents, projects the child loading state and bridges lifecycle actions to `ReviewCardLifecycleMutationSurface.vue`;
- `ReviewCardLifecycleMutationSurface.vue` remains the sole ReviewCardManage-domain lifecycle HTTP owner; the Leech surface contains no lifecycle endpoint;
- no backend route, payload, Leech Policy, lifecycle state machine, ReviewLog rule, FSRS field, migration or provider boundary changed.

TDD and verification evidence on 2026-07-18:

- `ReviewCardLeechGovernanceMutationSurfaceGuard.test.mjs` first failed because the owner did not exist, then passed after the responsibility-complete extraction;
- the prior Leech/lifecycle guards were updated from parent-ownership assumptions to the new singular Leech owner and lifecycle delegation contract;
- testing database health and `ReviewCardManageUiGuardTest` passed after the local MariaDB process was started with its existing data config; the Windows service itself remains an environment issue because it points to a missing top-level config file;
- the development build completed successfully with only existing Sass deprecation warnings;
- authenticated MCP Chrome verified single rewrite-package, two-card bulk rewrite-package and two-card bulk suspend flows. Safety flags remained `provider_called=false`, `card_created=false` and `review_log_created=false`;
- the two deliberately suspended cards were restored through the existing lifecycle UI before acceptance ended, returning the visible baseline to active 4 and suspended 0;
- dialog bounds remained inside the viewport, the document had no horizontal overflow, Console contained no error or warning and every observed application request stayed on `127.0.0.1:8000`;
- full evidence is recorded in `docs/testing/review-card-leech-governance-browser-acceptance-2026-07-18.md`.

Anki alignment for 3C-4:

- the Browser remains a coordinator while operation-specific code owns request, lock, dialog and error state;
- Leech remains a read-only diagnosis and governance recommendation, not a lifecycle state;
- suspending a Leech card still goes through the established lifecycle authority;
- LinguaCafe keeps its manual external-AI rewrite-package boundary and does not add automatic provider calls or automatic card creation.

### Phase 3D — Container Closure — Planned / Not Authorized

Evaluate the final coordinator only after earlier slices are separately accepted. The stretch target is about 1,000 lines; 1,200 is acceptable when further extraction would create meaningless pass-through components.

## 6. Formal target pairing required by the hard rules

Completed Phase 3B target pairs:

- `ARCH-ReviewCardManage-3B-1`: verify search/Saved Search data flow, keep the server as parser authority, freeze parent/child ownership, reuse `ReviewCardManageFilterState.js`, and reject duplicate request owners or speculative state frameworks.
- `DEV-ReviewCardManage-3B-1`: create `ReviewCardSearchSurface.vue`, move the complete search/filter/help presentation, retain `ReviewCardSavedSearchPanel.vue`, add executable guards, run grouped regression and perform authenticated dual-viewport Chrome acceptance.
- `ARCH-ReviewCardManage-3B-2`: freeze the table-region owner, keep list and mutation requests in the parent, separate current-card from selected-card state and move only the three existing read-only export requests.
- `DEV-ReviewCardManage-3B-2`: create `ReviewCardTableSurface.vue`, migrate the complete table/columns/pagination/selection/export region, update executable guards, run grouped regression and complete authenticated dual-viewport Chrome acceptance.

Each ARCH and DEV pair closed in one task. The architecture record preceded implementation, the new guard demonstrated RED, and implementation proceeded only inside the frozen boundary.

Completed Phase 3C target pairs:

- `ARCH-ReviewCardManage-3C-1`: freeze due-now/reset as one scheduling mutation family, make request and dialog ownership singular, remove duplicate due-now implementation, and preserve backend scheduling authorities.
- `DEV-ReviewCardManage-3C-1`: create `ReviewCardSchedulingMutationSurface.vue`, migrate the complete due-now/reset request and confirmation workflow, add executable guards, run grouped regressions and complete authenticated dual-viewport Chrome acceptance.
- `ARCH-ReviewCardManage-3C-2`: freeze descriptor, single/bulk lifecycle writes, conflicts, request locks and state help as one lifecycle mutation family; retain the backend state machine as sole authority; preserve table intent ownership and Leech product boundaries.
- `DEV-ReviewCardManage-3C-2`: create `ReviewCardLifecycleMutationSurface.vue`, migrate all lifecycle request/dialog ownership, freeze `expected_version`, add stale-response protection, delegate Leech lifecycle writes, update guards, run grouped regressions and complete authenticated dual-viewport Chrome acceptance.
- `ARCH-ReviewCardManage-3C-3`: freeze single and selected-card bulk delete as one dangerous mutation family, preserve backend delete authorities and keep ReviewLog, occurrence and last-confirmed-sense semantics unchanged.
- `DEV-ReviewCardManage-3C-3`: create `ReviewCardDeleteMutationSurface.vue`, migrate both delete requests and confirmations, preserve table intent ownership, add executable guards, run focused regressions and complete authenticated dual-viewport browser acceptance.
- `ARCH-ReviewCardManage-3C-4`: freeze Leech summary/rewrite-package orchestration as one owner, preserve ADR-0011 no-provider/no-auto-create semantics and retain lifecycle writes under the existing lifecycle owner.
- `DEV-ReviewCardManage-3C-4`: create `ReviewCardLeechGovernanceMutationSurface.vue`, migrate Leech request/dialog/loading/selection ownership, delegate suspend writes to the lifecycle child, add executable guards and complete authenticated browser acceptance.

## 7. Phase 3B changed files

Production:

- `resources/js/components/ReviewCards/ReviewCardManage.vue`
- `resources/js/components/ReviewCards/ReviewCardSearchSurface.vue`
- `resources/js/components/ReviewCards/ReviewCardTableSurface.vue`

Tests:

- `tests/js/ReviewCardSearchSurfaceGuard.test.mjs`
- `tests/js/ReviewCardTableSurfaceGuard.test.mjs`
- `tests/js/ReviewCardLifecycleBulkGuard.test.mjs`
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
- do not enter Phase 3D, Card Marker or Custom Study 1B without a separate task;
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

Phase 3C-4 is **Accepted / Production Closed** after its authenticated browser acceptance pass. Phase 3D is **Planned / Not Authorized**. Do not enter Phase 3D, Card Marker, Custom Study 1B or any later phase automatically.
