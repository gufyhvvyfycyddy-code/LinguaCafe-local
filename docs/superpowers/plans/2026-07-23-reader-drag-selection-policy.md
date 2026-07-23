# Reader Drag Selection Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-drag-selection-policy-design.md`

## Steps

1. Add focused Node tests for the characterized drag-range behavior and component boundary.
2. Run RED because `ReaderDragSelectionPolicy.js` does not exist.
3. Implement one pure resolver that returns `null` for no change or ordered selected-word descriptors.
4. Run behavior tests GREEN while the integration guard remains RED.
5. Adapt only `updateSelection`:
   - retain the touch-timer guard;
   - delegate range calculation;
   - retain component-owned `selected` flags and assignment.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use the official OpenAI Browser to perform a real mouse drag on an isolated Reader fixture at wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6D.

## Success

- Characterized outputs and real phrase selection are unchanged.
- No gesture, effect, API, store, backend, or learning-data boundary moves.
- `TextBlockGroup.vue` loses only the pure range-building loop.

## Failure

- Any changed selected range, order, phrase length, or newline behavior.
- Any moved effectful responsibility or new contract/dependency.
- Any protected write, browser regression, or unexplained test failure.
