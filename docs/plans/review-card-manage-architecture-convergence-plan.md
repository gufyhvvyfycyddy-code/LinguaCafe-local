# ReviewCardManage Architecture Convergence Plan

> **Status**: Authorized Next / Target Prepared / Not Started
>
> **Current first slice**: Phase 3A — Card Info Drawer Extraction
>
> **Baseline**: master `4968d00830ee928f2495dd4046d01c2a5337ffa3`
>
> **Scope**: sense-only ReviewCard management; preserve all current endpoint, payload, access, lifecycle, delete, reset, ReviewLog and FSRS semantics.

## 1. Why this phase is next

Preset V1A–V1D are Production Closed. The next authorized item in the Anki-aligned sequence is Browser/ReviewCardManage convergence. This is not permission to add Card Marker, Custom Study 1B, Reviewer work, Reader work, deck/subdeck, or a new search language.

The current `resources/js/components/ReviewCards/ReviewCardManage.vue` is a working but concentrated page:

- 3,411 lines;
- 24 direct `axios.` references;
- 12 `v-dialog` blocks;
- one large detail drawer;
- search, Saved Search, table, selection, exports, Card Info, lifecycle, leech governance, editing and dangerous mutations are coordinated in one Options API component.

The page already has substantial, accepted product capability. The objective is to reduce change blast radius without changing user behavior.

## 2. Anki official reference and the parts we actually borrow

Reviewed official sources:

- **Anki Manual — Browsing**: the Browser is presented as three visible sections — sidebar, card/note table and editing area. It distinguishes multi-row selection actions from current-item actions, supports Saved Searches in the sidebar, configurable/sortable columns and separate Card Info.
- **Anki Manual — Searching**: Browse and Filtered Decks share one search method; the grammar supports a broader set of operators than LinguaCafe V1.
- `ankitects/anki/qt/aqt/browser/browser.py`: the top-level Browser composes `SidebarTreeView`, `Table`, `BrowserCardInfo` and `BrowserLayout` rather than rendering every responsibility inline.
- `ankitects/anki/qt/aqt/browser/card_info.py` and `layout.py`: Card Info and layout are dedicated modules.
- `ankitects/anki/qt/aqt/browser/table/table.py`, `qt/aqt/browser/table/model.py`, `qt/aqt/browser/table/state.py`: table behavior, data model and table state are separate responsibilities.
- `ankitects/anki/rslib/src/search/parser.rs`, `rslib/src/search/sqlwriter.rs`, `rslib/src/search/service/`: search parsing and execution are separate backend boundaries.

Borrowed principles:

1. The page container coordinates modules; it does not own every rendering and state rule.
2. Card Info is a current-card concern and can be separated before the table is reworked.
3. Multi-selection state and current-card state are different concepts.
4. Search parsing remains server-authoritative and separate from table rendering.
5. Saved Search belongs with the browsing/search surface, not with mutation dialogs.
6. Table state should eventually have a clear owner instead of being spread through template, data and methods.

Deliberate LinguaCafe deviations:

- 不复制 Anki 的 Cards/Notes 双模式；LinguaCafe remains sense ReviewCard-only.
- 不实现 deck/subdeck 树、Note Type、Card Template or tag-tree editing.
- Do not copy Anki's global note deletion semantics; LinguaCafe preserves its existing WordSense/ReviewCard delete contracts and ReviewLog-retention rules.
- Do not expand Browser Search V1 into OR / NOT / parentheses / dates / regular expressions in this convergence phase.
- Do not create Filtered Deck semantics. Custom Study remains the separate temporary-study product boundary.
- Card flags are only a future Card Marker reference. Phase 3 does not add them.

## 3. Subtitle architecture review

The following project subtitle files were selected because they directly address a mature page refactor; all unrelated subtitles were intentionally not loaded.

- `AI可以帮你写代码，但帮不了你成为架构师.srt`
  - Adopted principle: hard-to-test code usually signals unclear inputs, side effects or dependency boundaries. Stable modules and interfaces improve AI implementation quality.
- `AI编程项目为什么总是烂尾？长期项目迭代先给 AI 画边界.srt`
  - Adopted principle: long-running projects need modularity, feature/architecture decoupling and real-machine acceptance after refactoring.
- `10万代码量真实项目，我是如何防止AI把旧功能改坏的？.srt`
  - Adopted principle: natural-language decisions describe why and boundaries; harnesses protect old behavior. Both are required.
- `你写了一堆文档AI还是不听话？问题不在文档本身.srt`
  - Adopted principle: documents are not a database. Critical boundaries must become executable tests, guards and browser checks.
- `AI编程越写越乱？我用水桶装水，把边界讲透，快速认识spec与harness.srt`
  - Adopted principle: load only task-relevant context; separate the drawing from the enforcement mechanism.
- `答应我，别再和AI一起拉屎了；Vibe Coding如何避免屎山.srt`
  - Adopted principle: split by coherent responsibility, but do not create interface overhead merely to increase file count.

Application to this page:

- no one-shot rewrite;
- one responsibility slice at a time;
- characterize current behavior before moving it;
- each slice must reduce a measurable responsibility from the parent;
- page-level Chrome acceptance remains mandatory;
- critical safety boundaries must be executable, not only documented.

## 4. Frozen architecture direction

The final Browser/ReviewCardManage shape is:

```text
ReviewCardManage.vue
  ├─ browsing/search surface
  │    ├─ Saved Search
  │    ├─ query/filter state
  │    ├─ table/model state
  │    ├─ pagination/columns/selection
  │    └─ exports
  ├─ ReviewCardInfoDrawer.vue
  │    ├─ one canonical detail request
  │    ├─ overview/history/diagnosis presentation
  │    └─ stale-response and close cleanup
  └─ mutation/dialog families
       ├─ lifecycle/archive/restore/bury/suspend
       ├─ due-now/reset
       ├─ delete/bulk delete
       └─ leech governance/rewrite packages
```

The container may coordinate cross-region refresh and snackbar state. It must not re-implement access, lifecycle, leech, delete or FSRS rules.

## 5. Phase sequence

### Phase 3A — Card Info Drawer Extraction

Status: **Authorized Next / Target Prepared / Not Started**.

Create `resources/js/components/ReviewCards/ReviewCardInfoDrawer.vue` and move the existing accepted Card Info responsibility out of the parent.

The child owns:

- drawer open/loading/error/empty states;
- `overview / history / diagnosis` tabs;
- exactly one canonical detail request to `GET /review-cards/manage/{reviewCard}/detail` per opened target;
- the existing monotonic stale-response guard;
- detail cleanup on close;
- Card Info-only formatting and presentation helpers;
- current read-only Card Info data.

The parent owns:

- choosing the current review card;
- deep-link parsing through the existing `ReviewCardManageDeepLink.js` contract;
- source dialog orchestration after the drawer emits a source action;
- list refresh after an external mutation;
- all mutation dialogs and mutation requests.

Frozen interface direction:

- declarative `value`/open state plus positive `reviewCardId` and existing deep-link source metadata;
- emits close/input and semantic actions such as source navigation or report return;
- no generic event bus, no Vuex expansion, no new global state;
- no second Card Info request path in the parent.

Phase 3A measurable closure:

- parent loses the complete Card Info drawer template, Card Info request state and Card Info-only helpers;
- `ReviewCardManage.vue` falls below 2,900 lines or the report explains, with exact diff evidence, why an equally large responsibility reduction does not reach that number;
- direct parent `axios.` references decrease by at least one and detail loading has one owner;
- opening Card Info still sends exactly one detail request;
- deep link, overview/history/diagnosis, undone audit rows, source navigation and close/reopen behavior pass tests and Chrome;
- no mutation button is added inside Card Info.

### Phase 3B — Search and Table Surface

Extract the browsing/search/table surface after Phase 3A is accepted:

- Saved Search UI;
- search query and server error presentation;
- advanced filters;
- table columns, sorting, pagination and compact mode;
- selection state and read-only exports;
- clear distinction between current card and selected cards.

This phase may introduce a dedicated read-only browser client or state module only when it owns a real, testable responsibility. It must reuse `ReviewCardManageFilterState.js`, ADR-0012, ADR-0013 and ADR-0017 instead of creating parallel query rules.

### Phase 3C — Mutation and Dialog Families

Group dangerous actions by domain while keeping their existing backend authorities:

- lifecycle actions continue through Lifecycle services;
- update/due/reset/delete/bulk actions continue through existing Mutation/Access services;
- leech classification remains read-only and suspension remains a lifecycle command;
- dialogs may be extracted only with their full state/action/error boundary, not as empty visual wrappers.

No delete, reset, archive, restore, ReviewLog-retention or FSRS behavior changes are allowed without a separate product/ADR decision.

### Phase 3D — Container Closure

Final targets, evaluated after the earlier slices:

- `ReviewCardManage.vue` is a coordinator, preferably at or below 1,200 lines; 1,000 remains the stretch target, not a reason to create meaningless pass-through components;
- parent direct `axios.` references at or below 5;
- no duplicate Card Info, search, selection, lifecycle or dialog state owners;
- every region has executable guards and real Chrome acceptance;
- endpoint and payload compatibility remain intact.

## 6. Formal target pairing required by the hard rules

The Codex target must execute both tracks in the same task:

- `ARCH-ReviewCardManage-3A`: verify the current Card Info data flow, freeze the child/parent ownership boundary, reject duplicate state owners and meaningless pass-through abstractions, and preserve ADR-0014 plus the existing deep-link contract.
- `DEV-ReviewCardManage-3A`: implement the verified boundary by creating `ReviewCardInfoDrawer.vue`, moving the complete Card Info responsibility, adding executable guards, running grouped regression and performing real dual-viewport Chrome acceptance.

ARCH is not a separate planning-only round. DEV may start only after the target has recorded the verified boundary and the failing characterization guard, then both tracks close together in one commit/push/report cycle.

## 7. Phase 3A allowed scope

Allowed production files:

- `resources/js/components/ReviewCards/ReviewCardManage.vue`
- create `resources/js/components/ReviewCards/ReviewCardInfoDrawer.vue`
- `resources/js/services/ReviewCardManageDeepLink.js` only if a proven compatibility seam is required

Allowed tests:

- `tests/js/ReviewCardInfoGuard.test.mjs`
- `tests/js/ReviewCardManageDeepLinkGuard.test.mjs`
- create focused Card Info drawer tests/guards as needed
- related existing backend Feature files for regression only; backend behavior changes are out of scope

Allowed docs:

- this plan;
- current roadmap, master plan, handoff, Documentation Index;
- ADR-0014 only for additive factual implementation notes after code exists.

## 8. Phase 3A forbidden scope

- backend Controller/Service/route/payload changes;
- database schema or migrations;
- FSRS algorithm, parameters, due fields, rating or ReviewLog writes;
- lifecycle, archive, restore, reset or delete semantics;
- Search V1 grammar or Saved Search behavior;
- 不进入 Card Marker 或 Custom Study 1B；也不进入 table redesign、Reviewer or Reader work;
- deck/subdeck, Note mode, tag tree or Filtered Deck;
- new dependency, Vuex module or event bus;
- `.env`, `AGENTS.md`, `.omo/`, `.playwright-cli/`, `nul`;
- notification scripts, DCP, force, destructive DB commands.

## 9. Required verification for the Codex target

TDD and executable evidence are mandatory:

1. Write or update a guard first and show the expected RED result.
2. Implement the smallest extraction that turns it GREEN.
3. Run focused Node guards for Card Info, deep links and management UI.
4. Run DB health checks before Feature tests.
5. Feature tests must be grouped. Never run `php artisan test --testsuite=Feature`; group all related files by module and record each exit code and summary.
6. Run Unit suite as allowed by the current hard rule, all Node guards, frontend build, `php artisan db:doctor`, and `git diff --check`.
7. MCP Chrome real acceptance at 1920×1080 and 900×900 using the task-provided local account if needed.
8. Verify Network: one detail GET on open, no extra `/logs`, `/lifecycle-events` or `/leech` request, no external domains.
9. Verify Console has no new errors.
10. Verify no learning-data or scheduling change from read-only opening/closing.

## 10. Accept / Refuse conditions

Accept only when:

- the child owns the whole Card Info responsibility;
- the parent no longer keeps a parallel Card Info request/state implementation;
- deep links and one-request Card Info behavior are preserved;
- existing management actions remain accessible and unchanged;
- tests, build and two-viewport Chrome acceptance pass;
- the diff stays inside the allowed boundary.

Refuse when:

- extraction is only a template wrapper while state and requests remain duplicated;
- new generic DTO/service/event layers have no independent responsibility;
- the task changes backend behavior to simplify the frontend;
- detail opening sends duplicate requests;
- any destructive, rating, lifecycle or FSRS behavior changes;
- the report uses API/fetch in place of real browser acceptance;
- Feature coverage is claimed from an ungrouped full-suite command.

## 11. Stop rule

Phase 3A ends after commit, normal push and final report. Do not enter Phase 3B, Card Marker, Custom Study 1B or any later phase automatically.
