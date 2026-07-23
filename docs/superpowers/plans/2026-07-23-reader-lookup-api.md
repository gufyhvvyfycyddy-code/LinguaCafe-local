# Reader Lookup API Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-lookup-api-design.md`

## Steps

1. Add focused Node tests for the four exact axios calls, Promise return identity, call count, and the component boundary.
2. Run RED because `ReaderLookupApi.js` does not exist.
3. Implement four thin named transport functions using global axios, matching the existing project API-module style.
4. Run API contract tests GREEN while the component integration guard remains RED.
5. Adapt only the four dictionary transport expressions in `TextBlockGroup.vue`:
   - retain request location, ordering, guards, conditionals, `.then()`/`.catch()`, response policy calls, Vuex effects, and positioning;
   - leave `/settings/get-anki-settings` inline because it is not lookup transport.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use the official OpenAI Browser connection to verify hover and selected-word lookup continuity on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, audit the remaining Phase 6 backend target, and close only what the evidence supports.

## Success

- The component has no inline dictionary axios expression.
- All four methods, URLs, payloads, call order, conditions, Promise continuations, response/effect behavior, and visible lookup UI are unchanged.
- No unrelated transport, rule, effect, backend, or learning-data ownership moves.

## Failure

- Any changed method, URL, payload, call count/order/timing, condition, catch, response, store effect, or lookup UI.
- Any generic abstraction, new dependency, public contract, retry/cancellation/cache behavior, or adjacent refactor.
- Any protected write, browser regression, or unexplained test failure.
