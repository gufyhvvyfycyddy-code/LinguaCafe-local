# Reader Phrase Instance Selection Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6E implementation under goal-mode staged preauthorization

## Goal

Move the pure phrase-instance range resolution used after a single Reader word selection out of `TextBlockGroup.vue`, without changing phrase cycling, newline bridging, unique-word enrichment, selection order, lookup counts, or the vocabulary-sidebox flow.

## Existing behavior to preserve

- Starting from the selected word, scan backward through the same phrase instance.
- Allow `NEWLINE` tokens to bridge adjacent words that belong to the same phrase instance.
- Scan forward while tokens are `NEWLINE` or belong to the requested phrase index.
- Exclude `NEWLINE` tokens from the returned selection.
- Resolve each token through the existing normalized `uniqueWordMap`.
- Skip tokens whose normalized key has no valid unique-word record.
- Preserve `word`, `reading`, `kanji`, `sentence_index`, `wordIndex`, `uniqueWordIndex`, and `spaceAfter`.
- Return descriptors in source order.

Phase 6E characterizes these rules. It does not redefine phrase identity, phrase cycling, or phrase-length semantics.

## Boundary

New pure owner:

- `ReaderPhraseInstanceSelectionPolicy.js`
  - phrase-instance start resolution
  - newline-aware forward scan
  - unique-word enrichment
  - ordered descriptor creation

Existing component owner:

- mouse/touch events and current selection state
- choosing which nested phrase index is next
- applying `word.selected` flags
- lookup counts, phrase borders, Vuex, HTTP, persistence, and sidebox orchestration

## Scope

Allowed:

- `resources/js/services/ReaderPhraseInstanceSelectionPolicy.js`
- `tests/js/ReaderPhraseInstanceSelectionPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6E design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- template event or gesture changes
- phrase cycling order, phrase identity, phrase length, or lookup-count changes
- Vuex/store, endpoint, payload, backend, tokenizer, migration, or database changes
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, or AI changes

## Risk and seam

Reader selection is protected and therefore high risk. This slice crosses one seam only: component-owned phrase choice into an ordered, enriched list of selected word descriptors. The policy receives plain arrays and a read-only map and has no DOM, Vuex, HTTP, timer, or write capability.

No public contract or stable architecture decision changes, so no ADR is required. Anki has no equivalent continuous-reader phrase-instance selection; LinguaCafe's existing interaction remains authoritative.

## Verification

- RED before module creation.
- Tests for backward/forward range resolution, newline bridges, exact phrase membership, missing unique words, normalized lookup, ordering, descriptors, boundaries, and frozen inputs.
- Integration guard proving the component retains phrase cycling and effects while delegating only range resolution.
- Combined Reader Node loop, testing DB health, Reader FSRS highlight, phrase indexing, frontend build, and diff checks.
- Official-browser phrase selection on an isolated testing-MySQL Reader fixture at wide and narrow viewports, followed by protected-write snapshots and double cleanup.
- Five-axis scoped review.

## Acceptance

Accept only if real single-word selection expands to the same stored phrase text, nested phrase cycling remains component-owned, protected learning tables remain unchanged, and no product or contract behavior changes.
