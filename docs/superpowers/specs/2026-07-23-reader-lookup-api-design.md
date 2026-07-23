# Reader Lookup API Design

Date: 2026-07-23

Status: Accepted for Phase 6L implementation under goal-mode staged preauthorization

## Goal

Give the four Reader dictionary requests one transport owner without changing request timing, method, URL, payload shape, Promise behavior, response handling, Vuex effects, error behavior, or visible lookup results.

## Existing contract to preserve

| Operation | Method | URL | Payload |
|---|---|---|---|
| API dictionary availability | GET | `/dictionaries/api/is-enabled` | none |
| inflection lookup | POST | `/dictionaries/search/inflections` | `{ term }` |
| local hover dictionary | POST | `/dictionaries/search-for-hover-vocabulary` | `{ language, term }` |
| API dictionary lookup | POST | `/dictionaries/api/search` | `{ language, term }` |

- Each function must return the original axios Promise unchanged.
- The availability request remains in `mounted()` after the existing Anki-settings request.
- The Japanese guard and inflection reset remain before the inflection transport call.
- Local and API hover calls retain their current order and conditional gating.
- Response normalization remains owned by `ReaderLookupResponsePolicy.js`.
- The API failure catch remains in `TextBlockGroup.vue` and still writes `['error']`.
- No retry, cancellation, timeout, batching, caching, deduplication, dependency injection, or response transformation is added.

## Boundary

New transport owner:

- `ReaderLookupApi.js`
  - `getApiDictionaryEnabled()`;
  - `searchReaderInflections(term)`;
  - `searchReaderHoverDictionary(language, term)`;
  - `searchReaderApiDictionary(language, term)`.

Existing component owner:

- lifecycle timing, language and selection guards, term lowercasing, and API-enabled gating;
- all `.then()` and `.catch()` behavior;
- all response-policy calls, Vuex commits, key increments, `$nextTick`, hover positioning, timers, selection, DOM, and persistence effects.

The client only constructs the established axios calls. It accepts primitive arguments, returns axios Promises, and has no Vue, Vuex, DOM, timer, persistence, learning-data, provider, or secret capability.

## Scope

Allowed:

- `resources/js/services/ReaderLookupApi.js`
- `tests/js/ReaderLookupApi.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6L design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- changing any method, URL, payload key/value, request order/count/timing, condition, catch, or response behavior;
- moving the Anki settings request or unrelated HTTP;
- moving response normalization, store effects, timers, positioning, or selection orchestration;
- adding a generic HTTP abstraction, injected client, configuration, retry, cancellation, cache, schema, or dependency;
- Vuex/store module, backend, route, controller, service, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, AI provider, secret, external request, or non-English product expansion.

## Risk, seam, and architecture gate

Reader lookup is protected and therefore high risk. This slice crosses one seam only: four existing dictionary axios expressions move from the Reader component to a purpose-specific transport module. Their callers, ordering, conditions, Promise continuations, and effects remain in place.

The public backend contract is unchanged. The client mirrors the established project-level API-module style and owns no domain rule, so no ADR is required.

Fresh-context adversarial review:

- Combining the four requests into one orchestration function would move conditions and sequencing: rejected.
- Accepting arbitrary payload objects would weaken the exact contract: rejected.
- Injecting axios or adding a generic request wrapper would add abstraction without a current consumer need: rejected.
- Moving `.then()`/`.catch()` blocks would cross the response/effect seam: rejected.
- Including `/settings/get-anki-settings` would mix an unrelated settings capability: rejected.
- Adding retries, cancellation, stale-response handling, normalization, or logging would change behavior: rejected.

Four thin named functions are the smallest safe transport owner.

## Verification

- RED before `ReaderLookupApi.js` exists.
- Focused tests with a stubbed global axios proving exact method, URL, payload, return identity, and absence of extra calls.
- Component integration guard proving all four dictionary transports delegate while lifecycle position, guards, conditions, response policies, Vuex effects, catch behavior, and the unrelated Anki settings request remain component-owned.
- Direct-source guard proving the component has no inline `/dictionaries/` axios expressions.
- Combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
- Official-browser hover and selected-word lookup continuity at wide and narrow viewports on an isolated testing-MySQL English Reader fixture.
- Protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if the four axios contracts and Promise identities are exact, lookup request timing and effects remain unchanged, the real lookup UI works at both viewports, and protected learning data remains unchanged.
