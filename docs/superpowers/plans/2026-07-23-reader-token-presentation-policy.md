# Reader Token Presentation Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-token-presentation-policy-design.md`

## Steps

1. Add focused Node tests for language spacing, marker formats, sentence-end scans, strict translation lookup, token classes, both furigana predicates, frozen inputs, and the component boundary.
2. Run RED because `ReaderTokenPresentationPolicy.js` does not exist.
3. Implement small pure presentation functions with the existing loose/strict comparisons.
4. Run behavior tests GREEN while the component integration guard remains RED.
5. Adapt only presentation expressions and the three existing presentation methods:
   - delegate root spacing, token classes, sentence-end and translation lookup;
   - delegate each existing `<rt>` predicate separately;
   - retain every template node, attribute, key, style, event, and whitespace comment.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use an official OpenAI browser connection to verify token rendering, real selection, plain-text spacing, and overflow on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6J.

## Success

- Presentation decisions and DOM structure remain unchanged.
- No DOM, event, lookup, store, API, backend, or learning-data ownership moves into the policy.
- `TextBlockGroup.vue` delegates all characterized token-presentation rules through thin adapters.

## Failure

- Any changed node, whitespace, key, attribute, class, text, furigana, translation placement, or interaction.
- Any new capability, dependency, public contract, or adjacent refactor.
- Any protected write, browser regression, or unexplained test failure.
