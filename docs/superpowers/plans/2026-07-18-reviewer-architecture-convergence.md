# Reviewer Architecture Convergence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Converge the two formal Reviewer pages on one frontend API boundary and one request-transaction primitive while extracting Sense-only session-action/undo orchestration from its 1,476-line page container.

**Architecture:** Keep backend endpoints and page-specific success behavior unchanged. A stateless `ReviewApiClient` owns existing HTTP calls; a per-component `ReviewRatingTransaction` owns request identity and delegates ambiguous-result recovery to the already accepted `ReviewRatingRecovery`; a Sense-only surface owns the action timeline and undo request state.

**Tech Stack:** Vue 2, Vuetify 2, Axios, native ES modules, Node built-in test runner/assert, Laravel/PHPUnit protected regressions.

## Global Constraints

- Anki formal-review semantics are the product reference; do not change rating keys, order, labels, scores, hotkeys, interval meaning, or authoritative next-card behavior.
- Do not modify PHP production code, routes, payloads, responses, migrations, database schema, FSRS, ReviewLog, lifecycle, queue-order, Reader, AI, Vuex/store, `.env`, `AGENTS.md`, `.omo/`, `.playwright-cli/`, or unrelated files.
- Preserve the only formal write path: `ReviewController::rateReviewCard` / `SenseReviewController::rate` → `ReviewCardService::recordReview` → `FsrsSchedulingService::schedule`.
- Legacy word/phrase/practice mode and Sense interval/session/undo behavior remain compatible.
- Use TDD for every task; stage only exact task files; commit with English `feat:` / `fix:` / `docs:` prefixes.
- Stop for a new architecture review if any endpoint/payload/FSRS/ReviewLog/lifecycle semantic must change.

---

### Task 1: Formal Review API client

**Files:**
- Create: `resources/js/components/Review/ReviewApiClient.js`
- Create: `tests/js/ReviewApiClient.test.mjs`

**Interfaces:**
- Consumes: Axios-compatible object with `get(url, config)` and `post(url, payload)`.
- Produces: `createReviewApiClient(http = axios)` with the seven frozen methods in the design.

- [ ] **Step 1: Write the failing API-client behavior test**

Create a fake HTTP object that records calls, then assert all exact interfaces:

```js
const calls = [];
const response = { data: { ok: true } };
const http = {
    get(url, config) { calls.push(['get', url, config]); return Promise.resolve(response); },
    post(url, payload) { calls.push(['post', url, payload]); return Promise.resolve(response); },
};
const client = createReviewApiClient(http);

await client.loadLegacyQueue({ bookId: 7 });
await client.rateLegacyCard({ reviewCardId: 9, rating: 'good' });
await client.loadSenseQueue({ ignoreDailyLimits: true });
await client.rateSenseCard(11, { rating: 'hard' });
await client.loadSenseIntervalPreview(11);
await client.loadSenseSessionActions('session-id');
await client.undoSenseReviewAction(13, { review_session_id: 'session-id' });

assert.deepEqual(calls, [
    ['post', '/reviews', { bookId: 7 }],
    ['post', '/reviews/rate', { reviewCardId: 9, rating: 'good' }],
    ['get', '/reviews/senses', { params: { ignoreDailyLimits: true } }],
    ['post', '/reviews/senses/11/rate', { rating: 'hard' }],
    ['get', '/reviews/senses/11/interval-preview', undefined],
    ['get', '/reviews/senses/session-actions', { params: { review_session_id: 'session-id' } }],
    ['post', '/reviews/senses/review-actions/13/undo', { review_session_id: 'session-id' }],
]);
```

Also assert invalid non-positive numeric IDs throw before calling HTTP, and a rejected HTTP promise remains rejected unchanged.

- [ ] **Step 2: Run RED**

Run: `node tests/js/ReviewApiClient.test.mjs`

Expected: FAIL because `ReviewApiClient.js` does not exist.

- [ ] **Step 3: Implement the minimal client**

```js
import axios from 'axios';

function positiveId(value, name) {
    const id = Number(value);
    if (!Number.isInteger(id) || id <= 0) throw new TypeError(`${name} must be a positive integer`);
    return id;
}

export function createReviewApiClient(http = axios) {
    return Object.freeze({
        loadLegacyQueue: payload => http.post('/reviews', payload),
        rateLegacyCard: payload => http.post('/reviews/rate', payload),
        loadSenseQueue: params => http.get('/reviews/senses', { params }),
        rateSenseCard: (id, payload) => http.post(`/reviews/senses/${positiveId(id, 'reviewCardId')}/rate`, payload),
        loadSenseIntervalPreview: id => http.get(`/reviews/senses/${positiveId(id, 'reviewCardId')}/interval-preview`),
        loadSenseSessionActions: sessionId => http.get('/reviews/senses/session-actions', { params: { review_session_id: sessionId } }),
        undoSenseReviewAction: (id, payload) => http.post(`/reviews/senses/review-actions/${positiveId(id, 'reviewLogId')}/undo`, payload),
    });
}
```

- [ ] **Step 4: Run GREEN**

Run: `node tests/js/ReviewApiClient.test.mjs`

Expected: all API client tests pass with zero real network calls.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/Review/ReviewApiClient.js tests/js/ReviewApiClient.test.mjs
git commit -m "feat: add formal review api client"
```

### Task 2: Per-page rating transaction primitive

**Files:**
- Create: `resources/js/components/Review/ReviewRatingTransaction.js`
- Create: `tests/js/ReviewRatingTransaction.test.mjs`
- Preserve unchanged: `resources/js/components/Review/ReviewRatingRecovery.js`

**Interfaces:**
- Consumes: `runAuthoritativeRatingRecovery(options)` dependency.
- Produces: `createReviewRatingTransaction(recover)` → `{begin, invalidate, isCurrent, recover}`.

- [ ] **Step 1: Write failing transaction tests**

```js
const recoveryCalls = [];
const tx = createReviewRatingTransaction(options => {
    recoveryCalls.push(options);
    return Promise.resolve();
});
const first = tx.begin();
assert.equal(tx.isCurrent(first), true);
const second = tx.begin();
assert.equal(tx.isCurrent(first), false);
assert.equal(tx.isCurrent(second), true);
tx.invalidate();
assert.equal(tx.isCurrent(second), false);
const options = { reloadQueue() {} };
await tx.recover(options);
assert.deepEqual(recoveryCalls, [options]);
```

Create two instances and prove their sequences do not affect each other.

- [ ] **Step 2: Run RED**

Run: `node tests/js/ReviewRatingTransaction.test.mjs`

Expected: FAIL because the module does not exist.

- [ ] **Step 3: Implement the minimal transaction**

```js
import { runAuthoritativeRatingRecovery } from './ReviewRatingRecovery.js';

export function createReviewRatingTransaction(recoverRating = runAuthoritativeRatingRecovery) {
    let sequence = 0;
    return Object.freeze({
        begin() { sequence += 1; return sequence; },
        invalidate() { sequence += 1; },
        isCurrent(candidate) { return Number.isInteger(candidate) && candidate === sequence; },
        recover(options) { return recoverRating(options); },
    });
}
```

- [ ] **Step 4: Run GREEN plus existing recovery behavior**

Run:

```bash
node tests/js/ReviewRatingTransaction.test.mjs
node tests/js/ReviewRatingRecovery.test.mjs
```

Expected: both pass; the accepted recovery helper is unchanged.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/Review/ReviewRatingTransaction.js tests/js/ReviewRatingTransaction.test.mjs
git commit -m "feat: add review rating transaction"
```

### Task 3: Converge legacy Review on the shared request boundary

**Files:**
- Modify: `resources/js/components/Review/Review.vue`
- Modify: `tests/js/ReviewRatingErrorRecoveryGuard.test.mjs`
- Modify: `tests/js/ReviewQueueOrderNextCardGuard.test.mjs`
- Create: `tests/js/ReviewerConvergenceGuard.test.mjs`

**Interfaces:**
- Consumes: `createReviewApiClient()` and `createReviewRatingTransaction()`.
- Preserves: legacy queue payload, rating payload, achievement call, next-card handling, practice mode, counters, animations, and error copy.

- [ ] **Step 1: Write RED structural/behavior guards**

Assert `Review.vue` imports both factories, creates one transaction per component instance, invalidates it in `beforeDestroy()` and at authoritative queue reload, uses `reviewApi.loadLegacyQueue()` / `reviewApi.rateLegacyCard()`, calls `ratingTransaction.recover()`, and contains no direct `axios.post('/reviews'` or `axios.post('/reviews/rate'`.

Update recovery/next-card guards to recognize the client call as the request boundary while retaining assertions that counters and `next_card` handling occur only in confirmed success.

- [ ] **Step 2: Run RED**

Run:

```bash
node tests/js/ReviewerConvergenceGuard.test.mjs
node tests/js/ReviewRatingErrorRecoveryGuard.test.mjs
node tests/js/ReviewQueueOrderNextCardGuard.test.mjs
```

Expected: convergence guard fails on missing imports/client calls; existing guards may fail only where their request-boundary string still expects raw Axios.

- [ ] **Step 3: Replace only legacy formal queue/rating orchestration**

At module scope create the stateless client:

```js
const reviewApi = createReviewApiClient();
```

In `data()` create a per-page transaction and remove `ratingRequestSequence`:

```js
ratingTransaction: createReviewRatingTransaction(),
```

At `loadReviews()` start, call `this.ratingTransaction.invalidate()` and return `reviewApi.loadLegacyQueue(data)`. In `rateReview()`, replace sequence arithmetic with:

```js
const seq = this.ratingTransaction.begin();
reviewApi.rateLegacyCard(payload).then((response) => {
    if (!this.ratingTransaction.isCurrent(seq)) return;
    // keep the complete existing confirmed-success body unchanged
}).catch(() => {
    if (!this.ratingTransaction.isCurrent(seq)) return;
    // keep existing animation reset
    this.ratingTransaction.recover(existingRecoveryOptions);
}).finally(() => {
    if (this.ratingTransaction.isCurrent(seq)) this.ratingLoading = false;
});
```

Call `this.ratingTransaction.invalidate()` in `beforeDestroy()`.

- [ ] **Step 4: Run GREEN and legacy guards**

Run the three Node commands from Step 2 plus `node tests/js/ReviewQueueOrderFrontendGuard.test.mjs`.

Expected: all pass; `Review.vue` has at most four remaining direct Axios references and no direct formal queue/rating URL.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/Review/Review.vue tests/js/ReviewRatingErrorRecoveryGuard.test.mjs tests/js/ReviewQueueOrderNextCardGuard.test.mjs tests/js/ReviewerConvergenceGuard.test.mjs
git commit -m "refactor: converge legacy review requests"
```

### Task 4: Converge SenseReview formal queue, rating, and preview requests

**Files:**
- Modify: `resources/js/components/Senses/SenseReview.vue`
- Modify: `tests/js/ReviewRatingErrorRecoveryGuard.test.mjs`
- Modify: `tests/js/SenseReviewIntervalPreviewGuard.test.mjs`
- Modify: `tests/js/ReviewerConvergenceGuard.test.mjs`

**Interfaces:**
- Consumes: shared client/transaction.
- Preserves: `loadCardsRequestSequence`, session tracker, review-session ID, duration, summary, stats refresh, undo metadata, interval normalization, and existing error copy.

- [ ] **Step 1: Extend RED guards**

Require `reviewApi.loadSenseQueue`, `rateSenseCard`, and `loadSenseIntervalPreview`; require a per-instance transaction with destroy invalidation; reject direct formal Sense queue/rate/preview Axios URLs. Retain checks that preview errors do not disable rating and only confirmed rating increments `reviewedCount`.

- [ ] **Step 2: Run RED**

Run:

```bash
node tests/js/ReviewerConvergenceGuard.test.mjs
node tests/js/ReviewRatingErrorRecoveryGuard.test.mjs
node tests/js/SenseReviewIntervalPreviewGuard.test.mjs
```

Expected: new Sense convergence assertions fail.

- [ ] **Step 3: Replace the three request calls**

Use the shared client in `loadCards()` and `loadIntervalPreview()`. In `rate()`:

```js
const seq = this.ratingTransaction.begin();
reviewApi.rateSenseCard(this.currentCard.review_card_id, payload).then((response) => {
    if (!this.ratingTransaction.isCurrent(seq)) return;
    // keep the complete existing Sense confirmed-success body
}).catch(() => {
    if (!this.ratingTransaction.isCurrent(seq)) return;
    this.ratingTransaction.recover(existingRecoveryOptions);
});
```

Invalidate the transaction before authoritative reload and on destroy. Do not move session/undo logic in this task.

- [ ] **Step 4: Run GREEN plus Sense protected frontend guards**

Run Step 2 plus:

```bash
node tests/js/SenseReviewRatingPresentationGuard.test.mjs
node tests/js/SenseReviewSessionTracker.test.mjs
node tests/js/SenseReviewStackUndoGuard.test.mjs
```

Expected: all pass; direct Sense Axios count falls from 11 to at most 8 before the session surface extraction.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/Senses/SenseReview.vue tests/js/ReviewRatingErrorRecoveryGuard.test.mjs tests/js/SenseReviewIntervalPreviewGuard.test.mjs tests/js/ReviewerConvergenceGuard.test.mjs
git commit -m "refactor: converge sense review requests"
```

### Task 5: Extract Sense session-actions and undo surface

**Files:**
- Create: `resources/js/components/Senses/SenseReviewSessionActionsSurface.vue`
- Modify: `resources/js/components/Senses/SenseReview.vue`
- Rewrite: `tests/js/SenseReviewSessionActionsGuard.test.mjs`
- Modify: `tests/js/SenseReviewStackUndoGuard.test.mjs`
- Modify: `tests/js/ReviewerConvergenceGuard.test.mjs`

**Interfaces:**
- Props: `value: Boolean`, `reviewSessionId: String`.
- Emits: `input(Boolean)`, `state-change({latestUndoableAction, activeCount, undoLoadingReviewLogId})`, `undone(responseData)`.
- Public methods via ref: `reload(): Promise<void>|undefined`, `requestUndo(action, source): void`.

- [ ] **Step 1: Write RED owner guards**

Assert the new surface owns the unchanged dialog text/list/chips, sequence-protected `loadSenseSessionActions`, undo UUID payload and 409/404/general errors, and that only the surface calls `undoSenseReviewAction`. Assert `SenseReview.vue` embeds the surface, stores only the read-only projection, delegates snackbar/hotkey undo through `$refs.sessionActionsSurface`, and contains no session-action list/loading/request-sequence fields or direct session/undo URLs.

- [ ] **Step 2: Run RED**

Run:

```bash
node tests/js/SenseReviewSessionActionsGuard.test.mjs
node tests/js/SenseReviewStackUndoGuard.test.mjs
node tests/js/ReviewerConvergenceGuard.test.mjs
```

Expected: FAIL because the surface does not exist and the parent still owns the list/dialog/request state.

- [ ] **Step 3: Create the surface with the exact existing UI and request semantics**

The surface state is:

```js
data: () => ({
    actions: [],
    loading: false,
    error: '',
    conflict: '',
    undoLoadingReviewLogId: null,
    requestSequence: 0,
}),
computed: {
    drawerOpen: {
        get() { return this.value; },
        set(next) { this.$emit('input', next); },
    },
    latestUndoableAction() { return this.actions.find(action => action.undoable) || null; },
    activeCount() { return this.actions.filter(action => !action.undone).length; },
},
```

`reload()` increments `requestSequence`, calls `reviewApi.loadSenseSessionActions(this.reviewSessionId)`, discards stale results, applies `actions || []`, preserves the existing load error, and emits the projection in success/finally. `requestUndo()` keeps the existing guards, UUID generation, payload fields and conflict copy; on success it hides conflict, calls `reload()`, and emits `undone(response.data)`. It never reloads the queue or mutates parent cards/session counters itself.

Move the existing dialog template without changing visible copy, rating colors, time formatting, blocked-reason labels, or the `action.undoable` gate.

- [ ] **Step 4: Integrate the surface and canonical undo reconciliation**

Replace the parent dialog with:

```vue
<sense-review-session-actions-surface
    ref="sessionActionsSurface"
    v-model="sessionActionDrawerOpen"
    :review-session-id="reviewSessionId"
    @state-change="onSessionActionStateChange"
    @undone="onSessionActionUndone"
/>
```

Keep `sessionActionDrawerOpen` and a projection object only. After rating, call `this.$refs.sessionActionsSurface?.reload()`. Delegate snackbar/history/hotkey undo to the child. `onSessionActionUndone(data)` performs the exact current parent reconciliation: hide snackbar, reload queue and promote restored card, clear answer/preview, remove the rating from `SessionTracker`, decrement `reviewedCount`, reload stats, and show the success snackbar.

- [ ] **Step 5: Run GREEN and line/request budgets**

Run Step 2 and:

```powershell
(Get-Content resources/js/components/Senses/SenseReview.vue).Count
rg -n "axios\." resources/js/components/Senses/SenseReview.vue
```

Expected: guards pass; `SenseReview.vue` ≤1,250 lines; at most six direct Axios references; no session-action/undo endpoint appears in the parent.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/Senses/SenseReviewSessionActionsSurface.vue resources/js/components/Senses/SenseReview.vue tests/js/SenseReviewSessionActionsGuard.test.mjs tests/js/SenseReviewStackUndoGuard.test.mjs tests/js/ReviewerConvergenceGuard.test.mjs
git commit -m "refactor: extract sense review session actions"
```

### Task 6: Phase 5 protected regression and production closure

**Files:**
- Modify: `docs/architecture/sense-review-module-boundaries.md`
- Modify: `docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- Modify: `docs/plans/current-working-handoff.md`
- Modify: `docs/plans/linguacafe-master-plan.md`
- Modify: `docs/DOCUMENTATION_INDEX.md`
- Create: `docs/testing/reviewer-architecture-convergence-browser-acceptance-2026-07-18.md`

**Interfaces:**
- Consumes all completed Phase 5 code and tests.
- Produces reproducible closure evidence and advances the roadmap to Phase 6 only after all checks pass.

- [ ] **Step 1: Run the full frontend contract set**

Run all Reviewer guards, including API client, transaction, recovery, Queue Order, interval preview, rating presentation, session tracker/actions/undo, lifecycle, leech, and convergence guards.

Expected: all pass; no guard is weakened merely to match moved code.

- [ ] **Step 2: Run protected PHP suites**

```bash
php artisan test --filter=ReviewFsrsTest
php artisan test --filter=FsrsSchedulingServiceTest
php artisan test --filter=SenseReviewIntervalPreviewTest
php artisan test --filter=SenseReviewStackUndoTest
php artisan test --filter=SenseReviewSessionActionsTest
php artisan test --filter=WordSense
```

Expected: all pass with testing MySQL healthy; no development-database substitution.

- [ ] **Step 3: Build**

Run: `npm run development`

Expected: compiled successfully; only existing Sass/module-type warnings are acceptable.

- [ ] **Step 4: Authenticated two-viewport browser acceptance**

Use isolated testing fixtures and the real UI at 1920×1080 and 900×900. Verify Sense reveal → interval preview → each enabled rating path → authoritative next card → action history → undo → restored card; verify legacy reveal → rating → server next card and operable error recovery. Inspect Console/Network. Snapshot ReviewLog/ReviewCard FSRS/lifecycle/WordSense before and after; only intentional formal ratings and the tested undo may differ. Clean disposable fixtures by exact ID.

- [ ] **Step 5: Record closure and commit**

Update module ownership and quantitative facts, create the acceptance report, mark Phase 5 Accepted / Production Closed, and point Current Phase to Phase 6 Reader. Run `git diff --check`, link/status searches, exact staging, then:

```bash
git commit -m "docs: close reviewer architecture milestone"
```

## Plan self-review

- Spec coverage: every new owner, compatibility boundary, metric, protected test, and browser requirement maps to Tasks 1–6.
- Placeholder scan: no TBD/TODO/“implement later” or unspecified error-handling steps remain.
- Type consistency: API client, transaction, surface props/events/methods and parent integration names match the design and all consuming tasks.
- Scope: one frontend architecture seam; no backend or data-model work is mixed in.
