# Reader Hover Position Policy Design

Date: 2026-07-18

Status: Approved under the standing Phase 6 goal-mode authorization

## Goal

Move only hover-vocabulary geometry decisions out of `TextBlockGroup.vue` into a pure, directly tested policy without changing the Reader UI, settings, DOM ownership, Vuex state, timing, HTTP, or lookup behavior.

## Existing flow

`TextBlockGroup.vue::updateHoverVocabularyBoxPosition()` is called after the hover box first becomes active and after local/API dictionary responses change its height. It reads the rendered hover-box height, the `.vocab-box-area` rectangle and scroll position, and the first hovered word rectangle. It then calculates `positionLeft`, `arrowPosition`, and `positionTop`, committing each result to the existing Vuex module. `VocabularyHoverBox.vue` only renders those values.

Anki has no continuous-reader hover equivalent. This slice therefore preserves the established LinguaCafe geometry exactly.

## Boundary

The new `HoverVocabularyPositionPolicy.js` owns only deterministic geometry:

- the existing 300px box width, 8px horizontal margin, and 25px vertical gap;
- the current sequential horizontal-boundary correction;
- preferred `top` / `bottom` selection;
- the existing bottom-space and top-space correction rules;
- the final `positionLeft`, `positionTop`, and `arrowPosition` result.

`TextBlockGroup.vue` retains:

- DOM lookup and null handling;
- selection of the rendered word element;
- `getBoundingClientRect()` calls;
- Vuex reads and commits;
- `$nextTick`, timers, requests, and stale-response handling.

The current implementation briefly queries a phrase midpoint and then unconditionally replaces it with the first-word rectangle. The observable first-word behavior is preserved; removing or changing that historical behavior is outside this slice.

## Frozen formulas

The policy receives `{ hoverBoxHeight, areaRect, areaScrollTop, wordRect, preferredPosition, correctionsEnabled }`.

1. Start horizontal position centered on the word using the existing expression.
2. If it is below 8, set 8; otherwise, if it exceeds the existing right bound, set that right bound. This stays sequential rather than becoming a generic clamp so narrow-area edge behavior is unchanged.
3. Start with the preferred arrow position.
4. If correction is enabled and bottom space is insufficient, `bottom` becomes `top`.
5. If correction is enabled, top space is insufficient, and bottom space is sufficient, `top` becomes `bottom`.
6. Calculate vertical position with the existing 25px gap and area scroll offset.

No normalization or validation is added. Inputs come from the same trusted DOM/prop boundary as today.

## Allowed files

- `resources/js/services/HoverVocabularyPositionPolicy.js`
- `resources/js/components/Text/TextBlockGroup.vue`
- `tests/js/HoverVocabularyPositionPolicy.test.mjs`
- this design, implementation plan, and eventual acceptance/current-authority documentation

## Prohibited files and semantics

- `TextReader.vue`, `TextReaderSettings.vue`, Review settings, Vuex modules, `VocabularyHoverBox.vue`
- routes, controllers, services, models, migrations, database fixtures, AI/provider code
- hover timing, request count/payload, surface/lemma, click lookup, selection, completion
- WordSense, ReviewCard, ReviewLog, FSRS, EncounteredWord stage transitions

## Verification

- RED/GREEN Node behavior tests for horizontal bounds, preferred placement, both correction directions, no-correction mode, scroll offsets, and input immutability.
- Source guard proving the component delegates geometry but retains DOM and Vuex ownership.
- Existing hover lookup policy, Reader sizing, and EncounteredWord authority tests.
- Protected Reader PHP tests and frontend build.
- Authenticated wide/narrow browser hover checks, including top/bottom placement and no horizontal overflow.

No ADR is required: public interface, data model, API, setting, and user-flow semantics remain unchanged.
