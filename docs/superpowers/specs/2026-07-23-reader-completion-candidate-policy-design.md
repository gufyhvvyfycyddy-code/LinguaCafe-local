# Reader Completion Candidate Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6I implementation under goal-mode staged preauthorization

## Goal

Move the pure Reader completion-candidate classification out of `TextBlockGroup.vue` without changing the finish confirmation, candidate order, object tagging, `/chapters/finish` payload, auto-level behavior, or any persistence effect.

## Existing behavior to preserve

- A word is a completion candidate only when `definitions_checked` is falsy and `stage < 0`.
- A phrase uses the same established filter.
- Word candidates remain before phrase candidates, and each group keeps source-array order.
- Candidate IDs are retained exactly as supplied, including duplicate or nullable legacy values.
- Each selected source object receives `type = 'word'` or `type = 'phrase'` before `TextReader.vue` serializes `uniqueWords` and `phrases`.
- `wordIds`, `phraseIds`, and `wordsAndPhrases` remain the public result keys used by `TextReader.vue`.
- The component ref method name `getLeveledUpWordsAndPhrases()` and the parent finish sequence remain unchanged.

## Boundary

New pure owner:

- `ReaderCompletionCandidatePolicy.js`
  - established falsy `definitions_checked` and negative-stage eligibility;
  - word-then-phrase source ordering;
  - candidate descriptors containing type, source index, and unchanged ID.

Existing component owner:

- resolving descriptors back to the original word/phrase objects;
- assigning the established `type` marker to those source objects;
- returning the three-key compatibility shape from `getLeveledUpWordsAndPhrases()`.

Existing `TextReader.vue` owner:

- finish confirmation and loading/error state;
- `/chapters/finish` endpoint and exact payload serialization;
- completion dialog and upgraded-item presentation.

The policy accepts plain arrays and returns plain descriptors. It has no Vue, Vuex, DOM, HTTP, timer, persistence, FSRS, or learning-data capability and does not mutate its inputs.

## Scope

Allowed:

- `resources/js/services/ReaderCompletionCandidatePolicy.js`
- `tests/js/ReaderCompletionCandidatePolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6I design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- changes to `TextReader.vue`, `/chapters/finish`, request fields, serialization, confirmation, or completion UI;
- changes to candidate filters, ordering, ID handling, source-object `type` mutation, or returned compatibility keys;
- Vuex/store, backend, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, AI, or non-English changes.

## Risk, seam, and architecture gate

Reader completion is protected and therefore high risk. This slice crosses one seam only: component-owned source objects into a pure classification result. The parent remains the sole finish-request and persistence owner.

Coupling is reduced because the completion eligibility rule becomes independently characterized while the existing component adapter preserves legacy object tagging and the parent contract. No public contract or accepted architecture decision changes, so no ADR is required.

Fresh-context adversarial review:

- Moving `/chapters/finish` or its payload builder would cross the completion-write seam: rejected.
- Returning cloned display objects would remove the legacy `type` fields from serialized source arrays: rejected.
- “Cleaning up” duplicate/null IDs or strict-boolean checks would change compatibility behavior: rejected.
- Combining completion candidates with Word/Sense FSRS eligibility would create a new rule and wrong owner: rejected.
- Extracting a generic collection framework or changing `TextReader.vue` would exceed the one characterized responsibility: rejected.

The descriptor resolver plus compatibility adapter is the smallest seam that isolates policy without changing observable behavior.

## Verification

- RED before module creation.
- Table tests for word/phrase eligibility, falsy legacy values, negative-stage coercion, source ordering, duplicate/null IDs, excluded items, empty inputs, and frozen-input immutability.
- Integration guard proving the component delegates, preserves source-object tagging, preserves result keys, and leaves `TextReader.vue` plus `/chapters/finish` untouched.
- Combined Reader Node loop, `FinishedReadingSafetyTest`, protected PHP suites, frontend build, and diff checks.
- Official-browser finish confirmation and upgraded-item rendering on an isolated testing-MySQL Reader fixture at wide and narrow viewports.
- Exact finish-request payload observation, protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if candidate IDs/order, source-object tags, result shape, finish request, upgraded-item display, protected learning tables, and all completion effects remain unchanged.
