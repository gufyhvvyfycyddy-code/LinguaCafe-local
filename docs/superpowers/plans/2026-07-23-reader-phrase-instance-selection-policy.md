# Reader Phrase Instance Selection Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-phrase-instance-selection-policy-design.md`

## Steps

1. Add focused Node tests for the characterized phrase-instance behavior and component boundary.
2. Run RED because `ReaderPhraseInstanceSelectionPolicy.js` does not exist.
3. Implement one pure resolver that returns ordered, unique-word-enriched selection descriptors.
4. Run behavior tests GREEN while the integration guard remains RED.
5. Adapt only `selectPhraseInstanceByWord`:
   - retain component-owned phrase-index choice and cycling;
   - delegate range resolution and enrichment;
   - retain assignment to `ongoingSelection`.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use the official OpenAI Browser to select a stored phrase on an isolated Reader fixture at wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6E.

## Success

- Characterized phrase range, enrichment, ordering, and real vocabulary phrase are unchanged.
- No gesture, phrase cycling, lookup-count, effect, API, store, backend, or learning-data boundary moves.
- `TextBlockGroup.vue` loses only the pure phrase-instance scan and descriptor-building loop.

## Failure

- Any changed phrase membership, nested-phrase order, newline bridge, selected-word order, or missing-word behavior.
- Any moved effectful responsibility or new contract/dependency.
- Any protected write, browser regression, or unexplained test failure.
