# Reader Sentence Context Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-sentence-context-policy-design.md`

## Slice

Phase 6C extracts one characterized responsibility: the pure sentence-context string selected for Reader click lookup.

## Steps

1. Add `ReaderSentenceContextPolicy.test.mjs` first.
   - Cover empty and non-English fallback behavior.
   - Cover identity and `wordIndex` selection resolution.
   - Cover hard boundaries and punctuation.
   - Cover abbreviations, initialisms, decimals, dotted chains, spacing, 120-token, and 600-character rules.
   - Cover frozen inputs and the component integration boundary.
2. Run the focused test and record RED because the policy module does not exist.
3. Add `ReaderSentenceContextPolicy.js` with no Vue, Vuex, DOM, HTTP, or mutation capability.
4. Run the focused policy tests GREEN.
5. Replace the component's sentence-extraction implementation with one delegation call.
   - Keep `isSectionMarker` in the component for rendering.
   - Keep `setSentenceText` and all vocabulary-sidebox orchestration in the component.
6. Run the integration guard GREEN, combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
7. Run authenticated wide/narrow acceptance with the official OpenAI Browser and an isolated testing-MySQL fixture.
8. Verify WordSense, ReviewCard, and ReviewLog snapshots are unchanged; clean the fixture twice.
9. Complete the five-axis review, update authority documents, and close only Phase 6C.

## Success

- Exact characterized output is preserved.
- No endpoint, payload, Vuex contract, tokenizer, backend, or learning-data change.
- Component retains orchestration and loses the sentence policy implementation.
- All scoped automated and browser checks pass.

## Failure

- Any changed context string outside the characterized rules.
- Any source-context or learning-data write.
- Any new backend/store seam, public contract, dependency, or unapproved product choice.
- Any browser regression or unexplained test failure.
