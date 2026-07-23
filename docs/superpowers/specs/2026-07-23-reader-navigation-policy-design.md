# Reader Navigation Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6H implementation under goal-mode staged preauthorization

## Goal

Move the duplicated pure previous/next Reader candidate scan out of `TextBlockGroup.vue` without changing selection boundaries, rendered-token skipping, stage filters, keyboard behavior, or selection effects.

## Existing behavior to preserve

- Previous navigation starts before the first selected word.
- Next navigation starts after the last selected word.
- With no selection, previous navigation begins scanning before the final array position and next navigation begins scanning after position zero. Phase 6H characterizes this legacy boundary behavior; it does not redesign it.
- Tokens without a rendered `.word[wordindex]` element are skipped.
- Ordinary navigation selects the first rendered candidate in the requested direction.
- New-word-only navigation selects the first rendered candidate whose stage loosely equals `2`.
- Highlighted-word-only navigation selects the first rendered candidate whose stage is below `0`.
- When both filters are true, the first candidate satisfying either established filter is selected.
- Reaching a boundary or finding no candidate remains a no-op.
- A successful candidate still triggers, in order, component-owned unselection, next-tick scheduling, selection start, and selection finish.

## Boundary

New pure owner:

- `ReaderNavigationPolicy.js`
  - current anchor calculation from selection and direction;
  - bounded directional scan;
  - rendered-index and stage-filter decisions;
  - candidate index or `-1`.

Existing component owner:

- DOM queries that determine rendered word indexes;
- keyboard intent dispatch;
- `unselectAllWords`, `$nextTick`, `startSelection`, and `finishSelection`;
- selection, hover, lookup, scrolling, Vue events, and all other effects.

The policy accepts plain arrays, booleans, a direction, and a `Set`-like rendered-index collection. It has no DOM, Vue, Vuex, HTTP, persistence, timer, or learning-data capability.

## Scope

Allowed:

- `resources/js/services/ReaderNavigationPolicy.js`
- `tests/js/ReaderNavigationPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6H design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- template, visible shortcut, gesture, hover, lookup, or selection-effect changes;
- correcting or redesigning the legacy no-selection boundary behavior;
- Vuex/store, endpoint, payload, backend, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, or AI changes.

## Risk, seam, and architecture gate

Reader selection navigation is protected and therefore high risk. This slice crosses one seam only: component-observed render facts into a pure candidate index. The component remains the sole DOM and effect owner.

Coupling is reduced because previous and next navigation share one characterized scan instead of two near-identical loops. No public contract or accepted architecture decision changes, so no ADR is required.

Fresh-context adversarial review:

- Moving `document.querySelector` into the policy would create a browser-dependent service: rejected.
- Replacing the legacy boundary defaults with first/last selection would change behavior: rejected.
- Combining navigation with selection mutation would hide effect order: rejected.
- Generalizing selection into an iterator, registry, or strategy hierarchy would exceed the duplicated responsibility: rejected.
- Tightening loose stage comparison could change imported string-stage compatibility: rejected.

The plain candidate resolver is the smallest seam that removes duplication without changing behavior.

## Verification

- RED before module creation.
- Table tests for both directions, selection anchors, no-selection boundaries, rendered-token skipping, ordinary/new/highlighted filters, combined filters, exhaustion, string stage compatibility, and frozen inputs.
- Integration guard proving both component methods delegate while retaining DOM measurement and the exact selection effect sequence.
- Combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
- Official-browser arrow navigation plus editable-field suppression on an isolated testing-MySQL Reader fixture at wide and narrow viewports.
- Protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if real previous/next navigation remains unchanged, non-rendered tokens and filtered stages resolve identically, protected learning tables remain unchanged, and no product, public interface, DOM, or effect boundary changes.
