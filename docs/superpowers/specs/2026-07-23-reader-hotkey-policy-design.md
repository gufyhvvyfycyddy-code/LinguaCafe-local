# Reader Hotkey Policy Design

Date: 2026-07-23

Status: Accepted for Phase 6G implementation under goal-mode staged preauthorization

## Goal

Move the pure Reader key-to-intent decision table out of `TextBlockGroup.vue` without changing key codes, prevent-default behavior, editable-field/dialog suppression, stage values, selection navigation, scrolling, speech, Anki, or plain-text effects.

## Existing behavior to preserve

- Ignore all keys when Reader hotkeys are disabled.
- Ignore Ctrl/Meta/Alt combinations.
- Ignore keys originating from input, textarea, select, or content-editable targets.
- Ignore keys while a Vuetify dialog, menu, select overlay, or menuable surface is active.
- Preserve the established key-code mapping:
  - `V` → text to speech;
  - `C` → stage 2;
  - top-row `0`–`7` → stages `0` through `-7`;
  - numpad `0`–`7` → stages `0` through `-7`;
  - `X` → stage 1;
  - `I` → decrease font size, except Shift+I;
  - `O` → increase font size;
  - Up/W and Down/S → scroll with the existing Shift acceleration flag;
  - `F` → send selected word to Anki;
  - Escape → unselect;
  - Left/A and Right/D → previous/next selection with the existing Shift filter flag;
  - `P` → unselect, close hover, then toggle plain-text mode.
- Preserve exactly which mapped keys call `preventDefault`.
- Unmapped keys remain no-ops.

Phase 6G characterizes this table; it does not redesign shortcuts.

## Boundary

New pure owner:

- `ReaderHotkeyPolicy.js`
  - suppression from plain event/context facts;
  - key-code to intent mapping;
  - stage and Shift-derived action parameters;
  - prevent-default decision.

Existing component owner:

- DOM inspection for editable targets and active Vuetify surfaces;
- calling `event.preventDefault()`;
- speech, stage changes, scrolling, selection, Anki, hover, Vue events, and all other effects;
- listener registration and teardown.

The policy returns a plain intent such as `{ action, preventDefault, ...parameters }` or `null`. It has no DOM, Vue, Vuex, HTTP, persistence, timer, or learning-data capability.

## Scope

Allowed:

- `resources/js/services/ReaderHotkeyPolicy.js`
- `tests/js/ReaderHotkeyPolicy.test.mjs`
- `resources/js/components/Text/TextBlockGroup.vue`
- Phase 6G design, plan, acceptance, index, handoff, master-plan, and roadmap documents

Forbidden:

- template, gesture, visible shortcut, or hotkey-dialog changes;
- key-code, stage, Shift, prevent-default, scroll, selection, speech, Anki, or plain-text semantics;
- Vuex/store, endpoint, payload, backend, tokenizer, migration, or database changes;
- WordSense, ReviewCard, ReviewLog, FSRS, rating, lifecycle, source-context, or AI changes.

## Risk, seam, and architecture gate

Reader keyboard behavior is protected and therefore high risk. This slice crosses one seam only: browser event/context facts into a pure action intent. The component remains the sole effect executor.

Coupling is reduced because the action table no longer depends on component methods, while effect order remains explicit in the adapter. No public contract or accepted architecture decision changes, so no ADR is required.

Fresh-context adversarial review:

- Moving DOM checks into the policy would create a second browser owner: rejected.
- Generalizing shortcuts into a configurable registry would add an unrequested product surface: rejected.
- Normalizing `event.key` instead of preserving `event.which` could change legacy/numpad behavior: rejected.
- Combining navigation search or stage mutation into the policy would cross selection/learning seams: rejected.
- Returning executable callbacks would hide ownership and complicate tests: rejected.

The bounded intent object is the smallest seam that improves structure without changing behavior.

## Verification

- RED before module creation.
- Table tests for every key family, prevent-default flag, stage mapping, Shift parameters, suppression context, unmapped keys, and frozen inputs.
- Integration guard proving `hotkeyHandle` delegates the decision but retains every effect owner and effect order.
- Combined Reader Node loop, protected PHP suites, frontend build, and diff checks.
- Official-browser keyboard navigation plus editable-field suppression on an isolated testing-MySQL Reader fixture at wide and narrow viewports.
- Protected-write snapshots, double cleanup, and five-axis scoped review.

## Acceptance

Accept only if real keyboard navigation remains unchanged, focused dictionary input suppresses Reader navigation, protected learning tables remain unchanged, and no product, public interface, or effect boundary changes.
