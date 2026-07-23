# Reader Drag Selection Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6D implementation under goal-mode staged preauthorization

## Goal

Move the pure range calculation used while dragging across Reader words out of `TextBlockGroup.vue`, without changing mouse/touch behavior, phrase length, newline handling, selection styling, or the vocabulary-sidebox flow.

## Existing behavior to preserve

- Do nothing when the target is the first or last currently selected word.
- At the phrase-length limit, do nothing when dragging farther outside the current range.
- Normalize reverse drags into ascending array indexes.
- Apply the existing left and right length formulas exactly, including their current boundary behavior.
- Exclude `NEWLINE` tokens from returned selected-word descriptors.
- Preserve `word`, `wordIndex`, `sentence_index`, and `spaceAfter`.
- Return descriptors in source order.

Phase 6D characterizes these rules. It does not redefine the product's phrase-length semantics.

## Boundary

New pure owner:

- `ReaderDragSelectionPolicy.js`
  - early no-change decision
  - start/end normalization and existing length bounds
  - selected-word descriptor creation

Existing component owner:

- mouse and touch target resolution
- long-press timer and event cancellation
- current selection state
- applying `word.selected` flags
- phrase auto-selection, unique-word enrichment, Vuex, HTTP, persistence, and sidebox orchestration

## Scope

Allowed:

- `resources/js/services/ReaderDragSelectionPolicy.js`
- `tests/js/ReaderDragSelectionPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6D design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- template event or gesture changes
- touch delay changes
- phrase auto-selection or lookup-count changes
- Vuex/store, endpoint, payload, backend, tokenizer, migration, or database changes
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, or AI changes

## Risk and seam

Reader selection is protected and therefore high risk. This slice crosses one seam only: component-owned drag state into an ordered list of selected word descriptors. The policy receives plain data and has no DOM, Vuex, HTTP, timer, or write capability.

No public contract or stable architecture decision changes, so no ADR is required. Anki has no equivalent continuous-reader drag selection; LinguaCafe's existing interaction remains authoritative.

## Verification

- RED before module creation.
- Tests for forward/reverse drag, both early-return branches, both existing length formulas, newline skipping, ordering, descriptors, string target indexes, and frozen inputs.
- Integration guard proving component-owned flags/events and policy delegation.
- Combined Reader Node loop, testing DB health, Reader FSRS highlight, phrase indexing, frontend build, and diff checks.
- Official-browser mouse drag on an isolated testing-MySQL Reader fixture at wide and narrow viewports, followed by protected-write snapshots and double cleanup.
- Five-axis scoped review.

## Acceptance

Accept only if real drag selection produces the same ordered phrase text, the component retains every effectful responsibility, protected learning tables remain unchanged, and no product or contract behavior changes.
