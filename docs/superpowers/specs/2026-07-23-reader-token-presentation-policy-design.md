# Reader Token Presentation Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6J implementation under goal-mode staged preauthorization

## Goal

Move the pure Reader token-presentation decisions out of `TextBlockGroup.vue` without changing rendered nodes, whitespace, keys, attributes, classes, furigana, AI translation placement, reactive behavior, or input events.

## Existing behavior to preserve

- Chinese, Japanese, and Thai apply the root `spaceless-language` class.
- `[A]` through `[Z]` and legacy `_SECT_X_` values are section markers; other strings and non-strings are not.
- A non-structure token is the last word of its sentence when the next non-structure token has a different `sentence_index`, or when no later non-structure token exists.
- AI translation lookup uses strict `sentence_index` equality and returns an empty string for missing/empty input.
- `no-highlight` remains true when all highlights are hidden or when new-word highlights are hidden and stage loosely equals `2`.
- `highlighted`, `source-highlight`, phrase, spacing, and phrase-boundary classes retain their existing truthy values.
- New-word furigana requires loose stage `2`, its setting, a non-empty distinct furigana value, and non-plain-text mode.
- Highlighted-word furigana uses the same conditions except `stage < 0` and its own setting.
- The two existing `<rt>` nodes, token DOM order, comment-based whitespace suppression, and AI translation node remain structurally unchanged.

## Boundary

New pure owner:

- `ReaderTokenPresentationPolicy.js`
  - spaceless-language decision;
  - section-marker recognition;
  - sentence-end scan;
  - AI-translation lookup;
  - ordinary token class map;
  - new and highlighted furigana visibility.

Existing component owner:

- passing reactive props, words, and indexes to policy functions;
- all `<template>`, `<br>`, `<div>`, `<span>`, `<ruby>`, and `<rt>` nodes;
- keys, DOM attributes, styles, whitespace comments, events, and Vue rendering;
- selection, hover, lookup, store, HTTP, timers, persistence, and every effect.

The policy accepts plain values/arrays/objects and returns booleans, strings, or a plain class map. It has no Vue, Vuex, DOM, HTTP, timer, persistence, or learning-data capability and does not mutate inputs.

## Scope

Allowed:

- `resources/js/services/ReaderTokenPresentationPolicy.js`
- `tests/js/ReaderTokenPresentationPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6J design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- extracting a child Vue component or changing template node count/order;
- changing keys, attributes, class names, styles, whitespace comments, event handlers, or reactive timing;
- correcting loose stage comparisons or expanding section-marker/AI-translation behavior;
- Vuex/store, endpoint, payload, backend, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, AI write, or non-English product expansion.

## Risk, seam, and architecture gate

`TextBlockGroup.vue` and Reader rendering are protected and therefore high risk. This slice crosses one seam only: reactive component facts into pure token-presentation decisions. The component remains the sole DOM and event owner.

Coupling is reduced because presentation rules become independently characterized without introducing a child component or changing DOM structure. No public contract or accepted architecture decision changes, so no ADR is required.

Fresh-context adversarial review:

- Extracting a Vue child now would risk wrappers, whitespace, event targets, and keyed-node identity: rejected.
- Precomputing presentation onto word objects would add mutation and stale reactive state: rejected.
- Merging the two `<rt>` nodes would change template structure even though their predicates are mutually exclusive: rejected.
- Normalizing stage or sentence indexes would change loose/strict compatibility behavior: rejected.
- Combining lookup response or selection rules with presentation would cross a second seam: rejected.

Direct pure predicates plus thin component adapters are the smallest safe token-rendering boundary.

## Verification

- RED before module creation.
- Table tests for all language, marker, sentence-end, translation, class, and furigana boundaries, including coercion and frozen inputs.
- Integration guard proving the template delegates while retaining exact node counts, class names, attributes, whitespace comments, and event handlers.
- Combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
- Official-browser token rendering, real selection highlight, plain-text spacing, and wide/narrow overflow checks on an isolated testing-MySQL English Reader fixture.
- Protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if token text, structure nodes, section markers, class behavior, furigana predicates, sentence translation placement, selection interaction, and protected learning data remain unchanged.
