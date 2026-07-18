# Reviewer Architecture Convergence Design

## Status and decision source

Approved for roadmap Phase 5 under the user's goal-mode standing decision: follow Anki's formal review semantics first; where Anki does not define LinguaCafe's code boundary, use the smallest recommended convergence that preserves behavior. This is a high-risk architecture task because it touches both formal review pages, but it changes no backend endpoint, payload, FSRS rule, ReviewLog rule, or rating meaning.

## Goal

Reduce duplicated formal-rating request orchestration in `SenseReview.vue` and legacy `Review.vue` without pretending their product state is identical. The result must make the shared transaction boundary explicit while leaving Sense-only session actions/undo/interval preview and legacy word/phrase/practice/animation behavior with their current owners.

## Non-goals

- No new backend route, Controller, Service, migration, database write, or response field.
- No changes to `ReviewController::rateReviewCard`, `SenseReviewController::rate`, `ReviewCardService::recordReview`, or `FsrsSchedulingService::schedule`.
- No changes to rating keys, labels, scores, hotkeys, queue order, daily limits, next-card semantics, ReviewLog creation, undo policy, lifecycle, reset, delete, source context, text-to-speech, or achievement behavior.
- No removal of legacy word/phrase/practice review compatibility.
- No unification of the page-level Sense session summary with the legacy page's counters and animations.
- No new dependency, Vuex module, public API version, generic repository, DTO family, or cross-application event bus.

## Alternatives considered

### A. One universal Reviewer component

Rejected. The pages share four ratings and failure recovery, but their card types, session/undo state, preview behavior, animations, and compatibility responsibilities differ. A universal component would turn those differences into conditionals and increase coupling.

### B. Shared transaction kernel plus page adapters — selected

Share only the formal request boundary: endpoint calls, one-in-flight sequencing, stale-response rejection, and authoritative failure recovery. Keep canonical success application in each page because the server responses and product side effects are intentionally different. Extract the Sense-only session-action drawer as its own owner.

### C. Independent page extractions only

Lower immediate risk, but rejected as the final design because it would leave the same formal rating transaction duplicated and would not achieve roadmap convergence. Page-specific extraction is still used where the behavior is not genuinely shared.

## Current seams that remain authoritative

- `ReviewRatingRecovery.js`: the existing shared failure-recovery orchestrator. It remains the only helper that locks rating, reloads the authoritative queue, preserves load errors, and unlocks after recovery. Its public behavior and tests remain unchanged.
- `ReviewDurationTracker.js`: existing shared review-duration measurement.
- `SenseReviewSessionTracker.js` and `SenseReviewSessionIdentity.js`: Sense-only page-session summary and per-tab undo identity.
- `SenseReviewIntervalPresentation.js`: Sense-only interval response normalization.
- Backend formal writes: `ReviewController::rateReviewCard` / `SenseReviewController::rate` → `ReviewCardService::recordReview` → `FsrsSchedulingService::schedule`.

## New and changed owners

### `ReviewApiClient.js`

A narrow frontend client in `resources/js/components/Review/` owns only existing formal-review HTTP interfaces:

```js
loadLegacyQueue(payload): Promise<AxiosResponse>
rateLegacyCard(payload): Promise<AxiosResponse>
loadSenseQueue(params): Promise<AxiosResponse>
rateSenseCard(reviewCardId, payload): Promise<AxiosResponse>
loadSenseIntervalPreview(reviewCardId): Promise<AxiosResponse>
loadSenseSessionActions(reviewSessionId): Promise<AxiosResponse>
undoSenseReviewAction(reviewLogId, payload): Promise<AxiosResponse>
```

The client performs no state mutation, retry, recovery, FSRS calculation, payload translation, or response fallback. It preserves the exact existing URL, HTTP method, query/body field names, Axios response, and rejection behavior. Other page requests—stats, lifecycle, reset, delete, source context, examples, settings, and achievements—remain outside this client.

### `ReviewRatingTransaction.js`

An instance factory owns request identity for one mounted page:

```js
const transaction = createReviewRatingTransaction();
transaction.begin(): number
transaction.invalidate(): void
transaction.isCurrent(sequence): boolean
transaction.recover(options): Promise<void>
```

`begin()` increments and returns the sequence. `invalidate()` makes all earlier responses stale. `isCurrent()` is the only comparison used by both pages. `recover()` delegates unchanged options to `runAuthoritativeRatingRecovery()`; it does not apply a successful rating, mutate counters, choose a next card, or know FSRS/ReviewLog/lifecycle. Each component keeps its reactive loading boolean so templates do not depend on a non-reactive service object.

This deliberately small interface replaces duplicated sequence arithmetic and recovery imports without moving page-specific success logic into callback-heavy shared code.

### `SenseReviewSessionActionsSurface.vue`

This Sense-only surface owns:

- the “本次操作” dialog and its loading/error/empty states;
- loading the current per-tab action list through `ReviewApiClient`;
- selecting the latest undoable action;
- issuing the existing undo request and showing conflict/loading state;
- emitting canonical `undone` output and a refresh request after success.

It consumes `reviewSessionId` and `value` through props. It exposes `reload()` and `requestUndo(action, source)` to the parent and emits `input`, `state-change`, and `undone`. `state-change` is a read-only projection `{latestUndoableAction, activeCount, undoLoadingReviewLogId}` used by the parent's existing toolbar, hotkey, and short undo snackbar; the parent does not copy the child action list or request state. It does not create or rate cards, apply FSRS, restore a card locally without a server result, own the page session summary, or decide queue eligibility. `SenseReview.vue` remains responsible for reconciling the canonical restored card into its current queue/session tracker, reloading queue/stats, and displaying the short undo snackbar.

## Data flow

### Successful rating

1. Page guard rejects an absent card or an already-loading rating.
2. Page sets its reactive lock and calls `transaction.begin()`.
3. Page builds the exact existing payload, including duration, daily-limit override, and Sense `review_session_id` where applicable.
4. `ReviewApiClient` sends the existing request.
5. The page checks `transaction.isCurrent(sequence)` before any mutation.
6. The page applies its existing canonical success path: counters/session, summary, next card or queue reload, undo metadata, and UI reset.
7. The page unlocks only for the current transaction.

### Failed or ambiguous rating

1. The page checks the sequence and resets only its page-specific visual transition state.
2. `transaction.recover()` delegates to `ReviewRatingRecovery.js` with the existing authoritative queue reload and error-message callbacks.
3. No optimistic counter, ReviewLog, FSRS, queue, or session mutation occurs.
4. The rating remains locked until the reload settles.

### Sense undo

1. `SenseReviewSessionActionsSurface` submits the existing review-log ID, review-session ID, and current lifecycle/version fields.
2. On success it emits the server response; the parent reconciles the restored card and updates its page-session tracker.
3. On conflict/error the surface preserves the current queue and reports the existing user-visible error.

## Compatibility and error contract

- All seven client methods return raw Axios promises; callers keep current response parsing and user copy.
- Invalid/stale responses are ignored exactly as today; there is no synthetic success or fallback card.
- Recovery remains fail-safe and never repeats the rating request.
- Interval-preview failure remains non-blocking and never disables rating.
- Legacy practice mode remains local-only and never enters the formal transaction/client rating method.
- Browser refresh/destroy invalidates the page transaction; late responses cannot mutate a replacement page instance.

## Allowed files

- Create `resources/js/components/Review/ReviewApiClient.js`.
- Create `resources/js/components/Review/ReviewRatingTransaction.js`.
- Create `resources/js/components/Senses/SenseReviewSessionActionsSurface.vue`.
- Modify `resources/js/components/Review/Review.vue`.
- Modify `resources/js/components/Senses/SenseReview.vue`.
- Add focused Node tests/guards for the three owners and update existing recovery/queue/interval/session guards only where imports move.
- Update this design, the Phase 5 implementation plan, the relevant module-boundary document, roadmap/handoff/master plan, and a browser acceptance report.

## Forbidden files and seams

- All PHP production code, routes, migrations, models, and database schema.
- `ReviewRatingRecovery.js` semantics, backend rating/undo endpoints, rating contracts, FSRS services, ReviewLog models/services, lifecycle command services, queue-order services, Vuex/store, Reader, AI, `.env`, `AGENTS.md`, `.omo/`, `.playwright-cli/`, and unrelated generated assets.

Expanding beyond these files or changing an endpoint/payload/FSRS/ReviewLog/lifecycle semantic requires a new architecture review and explicit stop.

## Measurable completion criteria

- Zero direct formal queue/rating Axios calls remain in either page container; all use `ReviewApiClient`.
- `SenseReview.vue` has at most 1,250 lines and at most six remaining direct `axios.` references (stats, source, lifecycle, reset, delete).
- `Review.vue` has at most 1,030 lines and at most four remaining direct `axios.` references (achievement, example, update, source).
- Exactly one shared recovery implementation remains, with both pages using it through `ReviewRatingTransaction`.
- The Sense session-action dialog and its request state exist only in `SenseReviewSessionActionsSurface.vue`.
- No endpoint, payload, rating label/key/score/hotkey, formal write path, or protected database delta changes.

## Verification

- RED/GREEN Node contract tests for `ReviewApiClient`, `ReviewRatingTransaction`, and the session-actions surface.
- Existing `ReviewRatingRecovery.test.mjs`, rating error-recovery guards, Queue Order frontend/next-card guards, Sense interval/session/undo/rating-presentation guards.
- PHP protected suites: `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, `SenseReviewIntervalPreviewTest`, `SenseReviewStackUndoTest`, `SenseReviewSessionActionsTest`, and `WordSense`.
- `npm run development`.
- Authenticated browser acceptance at 1920×1080 and 900×900: Sense reveal/preview/rate/next/undo/session actions and legacy reveal/rate/next/failure-operability, with Console/Network inspection and testing-database ReviewLog/FSRS delta limited to intentional formal ratings and undo.

## ADR decision

No new ADR is required because public API and data semantics do not change. This design refines the frontend ownership described by ADR-0008, ADR-0009, and the current SenseReview module-boundary document. Any later attempt to merge the page state models or change rating/undo payloads requires a new or superseding ADR.
