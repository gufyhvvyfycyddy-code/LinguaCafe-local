# Reader Hover Lookup Policy Design

**Date:** 2026-07-18

**Status:** Accepted for the persistent goal under ADR-0028 stage preauthorization
**Milestone:** Phase 6 — Reader UI and architecture governance, Slice 6A

## 1. Outcome

Phase 6A does not add another hover auto-lookup switch. LinguaCafe already provides the setting `vocabularyHoverBoxSearch`, persists it under `vocabulary-hover-box-search`, and routes it from `TextReaderSettings.vue` through `TextReader.vue` into `TextBlockGroup.vue`. When disabled, hover still shows locally available vocabulary data after the configured delay and sends no dictionary request. When enabled, the existing delayed dictionary lookup runs.

The slice instead extracts the decision “close, show local data only, or search this term” from `TextBlockGroup.vue` into a pure `HoverVocabularyLookupPolicy` module. This is the smallest architecture seam that improves locality without changing the user flow.

## 2. Architecture Gate

### Goal and explicit non-goals

Goal:

- Give hover lookup mode and term selection one testable owner.
- Preserve the existing switch, delay, display, request and stale-response behavior.
- Reduce `TextBlockGroup.vue` responsibility without crossing another Reader seam.

Non-goals:

- No new toolbar control or settings UI.
- No change to the existing localStorage key or default.
- No extraction of timer, Axios, Vuex, DOM positioning, mouse/touch selection or reader completion.
- No backend, route, payload, database, tokenizer, import, source-context or AI change.
- No WordSense, ReviewCard, ReviewLog, FSRS, EncounteredWord or lifecycle mutation.

### Module responsibility and seam

`HoverVocabularyLookupPolicy.js` is a pure module. Its single public interface accepts the current settings and hovered-word snapshot, then returns one of three decisions:

```js
resolveHoverVocabularyLookup({
    hoverBoxEnabled,
    searchEnabled,
    plainTextMode,
    hoveredWords,
    normalizeLemma,
})

// { mode: 'closed', term: '' }
// { mode: 'local-only', term: '' }
// { mode: 'search', term: 'canonical term' } // term may be empty
```

The module hides:

- the close conditions;
- the distinction between local-only and network-search modes;
- single-word preference when the raw lemma is non-empty;
- surface-word fallback only when the raw lemma is empty;
- phrase reconstruction using each non-final token's existing `spaceAfter` contract, never adding a trailing space after the final token;
- phrase reconstruction and raw term selection while leaving final lower-casing and empty-term rejection in the existing request adapter.

The policy is immutable: it must not write, normalize in place, reorder or otherwise mutate `hoveredWords` or its token objects. The result is a new decision object.

`normalizeLemma` is the existing caller-owned `trimSearchTerm` adapter. Passing it in preserves the current language-specific normalization without moving the general click-lookup method or duplicating its behavior.

`TextBlockGroup.vue` remains the adapter for:

- mouse/touch and phrase-hover selection;
- local vocabulary projection into Vuex;
- delay timer lifecycle;
- existing Axios endpoints and payloads;
- stale-response guards;
- DOM measurement and hover-box positioning.

### Deletion test, depth and locality

Deleting the policy forces the caller to reintroduce all close/local/search branching and word-or-phrase term construction. The interface is smaller than the hidden decision implementation and supports both word and phrase lookup, so the module has useful depth and leverage. It does not become a shallow Axios wrapper.

### Coupling and risk

The allowed seam is one-way: `TextBlockGroup.vue` imports the pure policy. The policy imports no Vue, Vuex, Axios, DOM, localStorage or project data model.

Primary risks:

1. Changing which term is sent for a lemma or phrase, especially by falling back after a non-empty raw lemma normalizes to an empty string.
2. Accidentally issuing a request while search is disabled.
3. Changing the delay, local-only display, stale response or positioning behavior while adapting the caller.
4. Expanding the slice into the adjacent timeout-reset anomaly.

Mitigations:

- Characterization-first Node tests assert current word, raw-lemma, normalized-empty lemma, phrase, disabled and immutable-input behavior.
- The caller changes only the decision/term branch and retains the existing timer/request/store/DOM code.
- The adjacent `clearHoverVocabularyBoxTimeout()` local-field anomaly is recorded but excluded unless a separate failing test proves a user-visible defect.

### Allowed and prohibited files

Allowed implementation files:

- `resources/js/services/HoverVocabularyLookupPolicy.js` (new)
- `resources/js/components/Text/TextBlockGroup.vue`
- `tests/js/HoverVocabularyLookupPolicy.test.mjs` (new)

Allowed closure files:

- this design and its implementation plan;
- a Phase 6 browser acceptance report;
- current roadmap, handoff, master plan, documentation index and the relevant Reader module contract.

Prohibited implementation files:

- `resources/js/components/TextReader/TextReader.vue`
- `resources/js/components/TextReader/TextReaderSettings.vue`
- `resources/js/vuex/HoverVocabularyBox.js`
- `VocabularyHoverBox.vue`, `VocabularySideBox.vue`, `WordSensesList.vue`
- all PHP routes, controllers, services and models;
- migrations and database configuration;
- AI, source-context, import/export and review code.

Any implementation need outside the allowed list is a new architecture seam and must stop this slice.

### ADR decision

No new ADR is required. The slice implements the existing roadmap instruction to split Reader responsibilities one at a time and does not change a stable public or data decision. This design plus executable characterization is the scoped contract.

## 3. Existing data flow preserved

```text
TextReaderSettings vocabularyHoverBoxSearch
  → localStorage vocabulary-hover-box-search
  → TextReader settings
  → TextBlockGroup prop
  → hover word/phrase snapshot
  → HoverVocabularyLookupPolicy decision
     closed     → existing close path
     local-only → existing delayed local display, no request
     search     → existing delayed request and stale-response guard
  → existing HoverVocabularyBox Vuex presentation
```

The following endpoints remain unchanged:

- `POST /dictionaries/search-for-hover-vocabulary`
- optional `POST /dictionaries/api/search`

The request continues to send the current `language` and lower-case `term`. No response interpretation changes.

## 4. Product behavior

The existing settings switch is the canonical UI. Phase 6A makes no visual change.

- Hover box disabled or plain-text mode: close, no request.
- `hoveredWords === null`: close, no request. An empty array preserves the existing branch: `local-only` when search is disabled, or `search` with `term: ''` when search is enabled.
- Hover box enabled and dictionary search disabled: show the existing local vocabulary content after the configured delay, no dictionary/API request.
- Search enabled, one word: if the raw lemma is non-empty, pass it through the existing caller-owned `normalizeLemma` adapter and use the result even if it is empty; fall back to the surface word only when the raw lemma itself is empty.
- Search enabled, phrase: join tokens according to `spaceAfter` only between tokens; the final token never contributes a trailing space.
- An empty term remains a `search` decision with `term: ''`. The adapter keeps the existing active/loading hover path, and the existing request method performs final lower-casing and rejects the empty term without sending a request. It must not be converted to `closed` or `local-only`, because that would change visible state.

This remains a LinguaCafe Reader adaptation. Anki has no equivalent continuous-reading hover lookup design, so the existing LinguaCafe interaction is preserved rather than inventing an Anki claim.

## 5. Error and compatibility behavior

- Existing dictionary endpoint failures keep their current presentation behavior.
- Existing optional API-dictionary error state remains unchanged.
- Existing stale local-dictionary response guard remains unchanged.
- No new error copy, loading state, retry or cancellation mechanism is introduced.
- Existing localStorage users retain their selected setting and default behavior.
- `/chapters/get/reader` and its JSON shape remain untouched.

## 6. Test and acceptance contract

TDD sequence:

1. Add a Node test that fails because the policy module does not exist.
2. Characterize closed, local-only, word lemma, raw-empty lemma fallback, normalized-empty non-fallback, phrase spacing, empty-term and immutable-input decisions.
3. Add the minimal pure module.
4. Adapt `TextBlockGroup.vue` without moving request, timer, store or DOM code.
5. Run the focused Node test and Reader guards.

Automated verification:

```powershell
node tests/js/HoverVocabularyLookupPolicy.test.mjs
node tests/js/ReaderWorkspaceSizingService.test.mjs
node tests/js/EncounteredWordStageAuthorityGuard.test.mjs
php artisan test --filter=TestingDatabaseHealthTest
php artisan test --filter=ReaderFsrsHighlightTest
php artisan test --filter=TextBlockPhraseIndexingTest
npm run development
git diff --check
```

Reader smoke and authenticated browser acceptance must then prove:

- search enabled: a delayed hover produces the same target term and normal result;
- search disabled: local hover data remains visible and no hover dictionary/API request is sent;
- moving to a second word does not let the old local-dictionary response overwrite the current word;
- click lookup, token colors, selection and completion still work;
- wide and 900×900 viewports have no horizontal overflow;
- no WordSense, ReviewCard, ReviewLog or FSRS write occurs from hovering.

## 7. Success and failure

Success requires all of the following:

- the existing product switch is recognized as the canonical implementation rather than duplicated;
- the pure policy owns all documented decision and term cases;
- the adapter retains current timer, HTTP, Vuex and DOM behavior;
- focused tests, build, Reader smoke and real browser acceptance pass;
- implementation stays inside the allowed file list.

The slice fails if it changes endpoint/payload semantics, localStorage defaults, Reader data shape, click lookup, selection, colors, data writes, or requires another protected module.
