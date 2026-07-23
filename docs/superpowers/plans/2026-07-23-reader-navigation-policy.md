# Reader Navigation Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-navigation-policy-design.md`

## Steps

1. Add focused Node tests for both directions, anchors, legacy no-selection boundaries, render skipping, stage filters, exhaustion, frozen inputs, and the component boundary.
2. Run RED because `ReaderNavigationPolicy.js` does not exist.
3. Implement one pure resolver from words, selection, direction, filters, and rendered indexes to a candidate index.
4. Run behavior tests GREEN while the component integration guard remains RED.
5. Adapt only `selectPreviousWord` and `selectNextWord`:
   - collect rendered word indexes from the existing DOM selector;
   - resolve one candidate;
   - preserve the no-candidate return;
   - preserve unselection, next-tick scheduling, selection start, and selection finish.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use the official OpenAI Chrome connection to verify previous/next keyboard navigation and focused-input suppression on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6H.

## Success

- Direction, boundary, render skipping, stage filters, and selection-effect order are unchanged.
- No DOM, effect, API, store, backend, or learning-data ownership moves into the policy.
- `TextBlockGroup.vue` loses both duplicated scans and retains a readable effect adapter.

## Failure

- Any changed candidate, boundary, string-stage compatibility, keyboard behavior, or effect order.
- Any new capability, dependency, public contract, or adjacent refactor.
- Any protected write, browser regression, or unexplained test failure.
