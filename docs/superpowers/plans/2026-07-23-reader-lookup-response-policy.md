# Reader Lookup Response Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-lookup-response-policy-design.md`

## Steps

1. Add focused Node tests for term acceptance, dictionary/API normalization, inflection parsing/grouping/overwrites, malformed input, frozen inputs, and the component boundary.
2. Run RED because `ReaderLookupResponsePolicy.js` does not exist.
3. Implement small pure response functions with the established comparison and concatenation behavior.
4. Run behavior tests GREEN while the component integration guard remains RED.
5. Adapt only response branches in `requestInflections()` and `makeHoverVocabularyBoxSearchRequest()`:
   - retain language guard, reset, requests, endpoints, payloads, commits, catches, key increments, and positioning;
   - delegate response acceptance and normalized values.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use an official OpenAI browser connection to verify hover and selected-word lookup continuity on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6K.

## Success

- Response acceptance, ordering, fields, failure behavior, and all effects remain unchanged.
- No HTTP, store, DOM, timer, backend, or learning-data ownership moves into the policy.
- `TextBlockGroup.vue` loses the inline response normalizers and retains readable effect adapters.

## Failure

- Any changed accepted response, definition text/order, inflection item/field/order, exception, store effect, or lookup UI.
- Any new capability, dependency, public contract, or adjacent transport refactor.
- Any protected write, browser regression, or unexplained test failure.
