# Reader Sidebar Action Hierarchy and Focus Design

Date: 2026-07-23

Status: Accepted for Phase 6F implementation under goal-mode staged preauthorization

## Goal

Improve the Reader vocabulary sidebar's information density and keyboard focus order without changing any learning action, store contract, API, payload, or saved data.

## Design direction

1. Product goal: help desktop and half-screen English readers move from a selected word to the next deliberate learning action with less scanning.
2. Tone: quiet, information-dense, deliberate.
3. Layout: keep the compact word summary first, group ordinary learning-state actions together, and visually separate destructive recovery.
4. Typography: reuse the existing Vuetify hierarchy and project font; add only a small section label where it clarifies action ownership.
5. Color and surfaces: retain the current theme tokens; success/warning remain state cues, while destructive recovery becomes a lower-emphasis text action.
6. Motion: no decorative animation; opening “添加新释义” immediately transfers focus to the search field.
7. Signature differentiator: an Anki-like action hierarchy in which frequent study-state actions are adjacent and the destructive reset path is visibly secondary.

No external visual inspiration is needed; the accepted Anki-aligned roadmap and the existing LinguaCafe visual system are sufficient.

## Existing behavior to preserve

- “忽略”, “标为已知”, and “回归为新词” emit the same existing actions.
- `AiStudyCardDesktopWorkflow` remains mounted in the same word-only action area.
- The inline pronunciation control remains available when text-to-speech is available.
- “添加新释义” still toggles the same candidate panel and auto-expands AI + dictionary results.
- Search changes still use `searchFieldChanged` and the same Vuex-backed search value.
- Empty, word, stored-phrase, and new-phrase states keep their existing content and actions.

## Product changes

- Remove the duplicate header-level text-to-speech icon; keep the pronunciation action beside the selected word.
- Add a compact “学习状态” label above the ordinary state actions.
- Keep “忽略” and “标为已知” together as the frequent state actions.
- Move “回归为新词” into a separate, lower-emphasis destructive row with an explanatory title.
- Add a stable ref and accessible label to the candidate search field.
- When “添加新释义” opens, focus the candidate search field on the next Vue render.

## Scope

Allowed:

- `resources/js/components/Text/VocabularySideBox.vue`
- `tests/js/ReaderSidebarActionFocusGuard.test.mjs`
- Phase 6F design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- `TextBlockGroup.vue`, Vuex/store, routes, backend, endpoint, payload, migration, or database changes
- stage values, delete semantics, WordSense creation, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, or AI workflow changes
- mobile/BottomSheet work or broad sidebar redesign
- new dependency, global style, or component extraction

## Risk and seam

`VocabularySideBox.vue` is a protected Reader surface, so this is high risk. The slice crosses one seam only: template hierarchy into an already-existing local focus method. All mutations continue through the same emitted events and child feature island.

The focus transfer is a local accessibility improvement, not a public interface or architecture decision. No ADR is required.

## Verification

- RED source guard for the new hierarchy and focus contract.
- GREEN guard after the template/watcher adaptation.
- Existing sidebar Chinese-text, feature-island, inline-sense, morphology, legacy-entry, and stage-authority guards.
- Frontend build and diff checks.
- Official-browser pointer and keyboard checks at 1280×900 and 900×900:
  - one pronunciation action;
  - grouped ordinary actions and separated recovery;
  - opening “添加新释义” focuses “搜索词典...”;
  - no clipped controls or horizontal overflow.
- Protected-write snapshot and five-axis scoped review.

## Acceptance

Accept only if the hierarchy is visibly clearer, keyboard focus lands in the candidate search field, every existing action retains its owner and semantics, and protected learning data remains unchanged.
