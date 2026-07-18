# Reader Hover Position Policy Implementation Plan

> Execute in the current authorized workspace. Keep every commit scoped and preserve all unrelated worktree assets.

**Goal:** Extract the existing hover-box geometry calculation into a pure policy while preserving rendered behavior and component ownership boundaries.

**Architecture:** A stateless service returns `{ positionLeft, positionTop, arrowPosition }`. `TextBlockGroup.vue` remains the adapter for DOM measurements and Vuex commits. No other component or backend layer changes.

**Tech stack:** Vue 2, Vuex, plain ES modules, Node built-in test runner, Laravel Mix.

---

## Task 1: Characterize and implement the pure geometry policy

**Files:**

- Create: `tests/js/HoverVocabularyPositionPolicy.test.mjs`
- Create: `resources/js/services/HoverVocabularyPositionPolicy.js`

1. Write tests covering centered position, left/right sequential bounds, preferred top/bottom placement, bottom-to-top correction, top-to-bottom correction, insufficient space on both sides, scroll offsets, and frozen inputs.
2. Run the test and observe `ERR_MODULE_NOT_FOUND`.
3. Implement the smallest pure policy preserving the frozen formulas.
4. Run the test and require all cases to pass.
5. Commit only the new policy and test with `refactor:`.

## Task 2: Delegate geometry from TextBlockGroup

**Files:**

- Modify: `resources/js/components/Text/TextBlockGroup.vue`
- Modify: `tests/js/HoverVocabularyPositionPolicy.test.mjs`

1. Add a source-integration guard requiring the policy import/call/output commits while retaining DOM lookup and Vuex ownership.
2. Run it RED before changing the component.
3. Replace only inline calculations with one policy call and three existing Vuex commits.
4. Preserve early return, first-word DOM measurement, `$nextTick`, callers, settings, and constants through policy defaults.
5. Run the focused Node tests GREEN, then existing Reader guards and `npm run development`.
6. Commit the adapter change with `refactor:`.

## Task 3: Verify and close Phase 6B

**Files:**

- Create: `docs/testing/reader-hover-position-policy-browser-acceptance-2026-07-18.md`
- Modify only current-authority routing/status documents needed to mark Phase 6B closed

1. Run testing-DB health, Reader FSRS highlight, phrase indexing, focused Node tests, build, and `git diff --check`.
2. Use an isolated authenticated Reader fixture. Verify wide/narrow no-overflow behavior, horizontal bounds, preferred/corrected vertical placement, click lookup, and zero protected learning-data writes.
3. Remove fixture/auth/scripts, stop local processes, and confirm cleanup twice.
4. Conduct the five-axis code review and fresh completion verification.
5. Record exact evidence, stage exact files, and commit closure documentation with `docs:`.

## Stop conditions

Stop this slice if implementation requires changing a prop/setting, Vuex module, DOM structure, request behavior, surface/lemma behavior, data model, backend endpoint, WordSense/ReviewCard/ReviewLog/FSRS, or any file outside the approved list. Such a discovery becomes a separately gated slice; it is not absorbed here.
