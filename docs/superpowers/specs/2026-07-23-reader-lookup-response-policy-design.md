# Reader Lookup Response Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6K implementation under goal-mode staged preauthorization

## Goal

Move the pure Reader lookup-response acceptance and normalization rules out of `TextBlockGroup.vue` without changing endpoints, payloads, request timing, stale-response behavior, store commits, hover positioning, errors, or visible results.

## Existing behavior to preserve

- A local dictionary response applies only when the current dictionary term strictly equals `response.data.term` and the current term is not an empty string.
- Local dictionary definitions remain joined with `;` and no extra spacing.
- API dictionary responses remain flattened in response-item order by repeated `concat(item.definitions)`.
- API request failures still produce `['error']` in the component.
- Inflections are reset to `[]` before each Japanese request.
- Inflection response `'[]'` or a loosely empty string remains an early no-result case.
- Other inflection strings are parsed with `JSON.parse`; parse errors retain existing rejection behavior.
- Only the established ten display names are retained, in first-seen name order.
- Repeated forms merge into one item per name, and later values overwrite the same `affPlain`, `affFormal`, `negPlain`, or `negFormal` field.
- Non-Japanese calls still return before any request or store reset.

## Boundary

New pure owner:

- `ReaderLookupResponsePolicy.js`
  - strict current/response-term acceptance;
  - local definition joining;
  - API definition flattening;
  - displayed-inflection parsing, filtering, grouping, field mapping, and no-result decision.

Existing component owner:

- language guard and initial inflection reset;
- every axios request, endpoint, and payload;
- current-term reads and stale-response return;
- all Vuex commits, error state, key increments, `$nextTick`, and position effects;
- timers, hover lifecycle, selection, persistence, and all other effects.

The policy accepts plain response values and returns booleans, strings, arrays, or `null`. It has no Vue, Vuex, DOM, HTTP, timer, persistence, or learning-data capability and does not mutate inputs.

## Scope

Allowed:

- `resources/js/services/ReaderLookupResponsePolicy.js`
- `tests/js/ReaderLookupResponsePolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6K design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- moving axios or changing any endpoint, method, payload, request count, timer, or error handler;
- changing store mutation names/order, hover activation/positioning, or response key increments;
- making malformed responses fail-open or redesigning legacy loose/strict comparisons;
- Vuex/store module, backend, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, AI, or non-English product expansion.

## Risk, seam, and architecture gate

Reader lookup is protected and therefore high risk. This slice crosses one seam only: transport response values into pure normalized lookup data. The component remains the sole request, store, timer, DOM, and effect owner.

Coupling is reduced because four response rules and the inflection parser become independently characterized. HTTP transport remains a distinct, unreviewed seam for a later slice. No public contract or accepted architecture decision changes, so no ADR is required.

Fresh-context adversarial review:

- Moving axios together with response rules would cross a second seam: rejected.
- Trimming, deduplicating, sorting, or validating API definitions would change established output: rejected.
- Replacing loose empty-string handling or strict term equality would change compatibility: rejected.
- Catching JSON parse errors would change failure behavior: rejected.
- Generalizing all dictionary formats into a registry or schema would exceed current Reader responses: rejected.

Small pure normalizers plus component-owned effects are the smallest safe response boundary.

## Verification

- RED before module creation.
- Table tests for stale/empty/current terms, definition joining, API concatenation order, inflection no-result cases, parsing, filtering, grouping, overwrite behavior, malformed JSON, and frozen inputs.
- Integration guard proving all response branches delegate while endpoints, payloads, store commits, errors, next-tick positioning, and language guard remain component-owned.
- Combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
- Official-browser hover lookup and selected-word lookup continuity at wide and narrow viewports on an isolated testing-MySQL English Reader fixture.
- Protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if response acceptance, output ordering/content, inflection fields, failure behavior, store effects, lookup UI, and protected learning data remain unchanged.
