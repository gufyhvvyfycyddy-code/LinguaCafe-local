# Reader Sentence Context Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6C implementation under goal-mode staged preauthorization

## Goal

Move the already-established Reader token-window sentence extraction rules out of `TextBlockGroup.vue` into one tested pure policy without changing the sentence shown by click lookup or any source-context contract.

## Existing behavior to preserve

- Empty selection returns an empty string.
- Non-English input uses the original `sentence_index` extraction.
- English selection resolves the selected token by object identity first, then by a valid matching `wordIndex`.
- Unresolved selection falls back to `sentence_index`.
- Scanning stops at newline, paragraph, structure, section-marker, sentence, 120-token, or 600-character boundaries.
- Question marks and exclamation marks end sentences.
- Period classification preserves known abbreviations, compound abbreviations, initialisms, decimals, split decimals, and dotted abbreviation chains.
- Token `spaceAfter` controls joining.
- Results longer than 600 characters fall back to the original `sentence_index` extraction.

These rules were introduced by commit `42b60c72` for manual WordSense occurrence context. Phase 6C characterizes and relocates them; it does not redesign them.

## Boundary and responsibility

New owner:

- `ReaderSentenceContextPolicy.js`
  - pure selected-token resolution
  - pure sentence-boundary classification
  - pure token-window scanning and fallback selection

Existing component owner:

- current `words`, `selection`, and `language`
- the canonical section-marker classifier used by rendering
- the Vuex `setSentenceText` commit
- click lookup, vocabulary-sidebox orchestration, HTTP, DOM, and all learning state

The component calls the policy with plain arrays/scalars and its existing section-marker predicate, then commits the returned string exactly where it does today.

## Scope

Allowed implementation files:

- `resources/js/services/ReaderSentenceContextPolicy.js`
- `tests/js/ReaderSentenceContextPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6C design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- tokenizer or processed-text changes
- backend, route, Controller, Service, Model, migration, or database changes
- Vuex/store-module changes
- source-context endpoint or payload changes
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, or AI-provider changes
- UI controls or copy changes
- non-English behavior changes

## Risk and seam

This is high risk because Reader source context is protected. The slice crosses only one seam: selected Reader tokens into the sentence string already committed to `vocabularyBox`.

The policy receives no store, DOM, network, or database capability. It cannot write learning data. No new public API or architectural decision is introduced, so no ADR is required.

Anki has no continuous Reader token-window context equivalent. Per the accepted product direction, LinguaCafe's established behavior is preserved.

## Verification

- Observe RED before the new policy exists.
- Characterize every boundary family, fallback, spacing rule, token/character cap, and immutability.
- Add a source-integration guard proving the component delegates while retaining Vuex ownership.
- Run the combined Reader Node loop.
- Run testing-database health, Reader FSRS highlight, and phrase-indexing suites.
- Build with `npm run development`.
- Use the official OpenAI Browser on an isolated testing-MySQL fixture to click tokens around abbreviation and sentence boundaries, verify the displayed sentence context, wide/narrow layout, and protected-write snapshots.
- Run `git diff --check` and a five-axis scoped review.

## Acceptance

Accept only if browser-visible sentence context and source-context payload semantics remain unchanged, protected learning tables remain unchanged, and `TextBlockGroup.vue` loses the policy implementation while retaining orchestration.
