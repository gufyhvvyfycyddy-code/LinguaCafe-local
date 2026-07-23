# Reader Sidebar Action Hierarchy and Focus Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-sidebar-action-focus-design.md`

## Steps

1. Add a focused source guard for the sidebar action hierarchy, single pronunciation control, search ref/label, and focus watcher.
2. Run RED against the current duplicate action and missing focus contract.
3. Adapt only `VocabularySideBox.vue`:
   - remove the duplicate header text-to-speech action;
   - group ordinary study-state actions under a compact label;
   - separate and visually reduce the destructive recovery action;
   - add the candidate-search ref and accessible label;
   - focus that ref after the add-sense panel opens.
4. Run the focused guard GREEN.
5. Run the existing sidebar, feature-island, sense-preview, morphology, legacy-entry, and stage-authority guards.
6. Run the frontend build.
7. Use the official OpenAI Browser to verify hierarchy, pointer flow, keyboard focus, responsive layout, and protected-write facts at wide and half-screen viewports.
8. Apply the UI validation checklist, five-axis scoped review, update authority documents, and close only Phase 6F.

## Success

- One pronunciation action remains beside the selected word.
- Ordinary study-state actions are grouped and destructive recovery is visually secondary.
- Opening “添加新释义” focuses “搜索词典...”.
- Existing events, child workflow, store, API, and learning semantics are unchanged.

## Failure

- Duplicate or missing action, changed event owner, changed state/delete semantics, or lost feature-island mount.
- Focus remains outside the candidate search field.
- Clipped controls, horizontal overflow, protected write, or unexplained test/build/browser failure.
