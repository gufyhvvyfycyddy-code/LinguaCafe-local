# Reader Completion Candidate Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-completion-candidate-policy-design.md`

## Steps

1. Add focused Node tests for candidate filters, legacy coercion, order, IDs, frozen inputs, and the component/parent boundary.
2. Run RED because `ReaderCompletionCandidatePolicy.js` does not exist.
3. Implement one pure resolver from word and phrase arrays to ordered candidate descriptors.
4. Run behavior tests GREEN while the component integration guard remains RED.
5. Adapt only `getLeveledUpWordsAndPhrases()`:
   - resolve candidate descriptors;
   - map each descriptor to the original source object;
   - preserve `type` mutation and the existing three-key return shape.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run `FinishedReadingSafetyTest` and the protected PHP suites.
8. Use an official OpenAI browser connection to verify the finish confirmation, exact request payload, and upgraded-item display on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6I.

## Success

- Eligibility, coercion, order, IDs, source-object tags, public result shape, and finish payload are unchanged.
- No finish request, persistence, UI, API, store, backend, or learning-data ownership moves into the policy.
- `TextBlockGroup.vue` loses the inline candidate filters and retains a short compatibility adapter.

## Failure

- Any changed candidate, ordering, ID, source tag, result key, finish payload, or completion effect.
- Any new capability, dependency, public contract, or adjacent refactor.
- Any protected write outside the isolated fixture, browser regression, or unexplained test failure.
