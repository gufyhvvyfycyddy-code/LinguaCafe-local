# Reader Hotkey Policy Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-reader-hotkey-policy-design.md`

## Steps

1. Add focused Node tests for every characterized key mapping, suppression rule, parameter, prevent-default flag, and component boundary.
2. Run RED because `ReaderHotkeyPolicy.js` does not exist.
3. Implement one pure resolver from event/context facts to a plain intent.
4. Run behavior tests GREEN while the component integration guard remains RED.
5. Adapt only `hotkeyHandle`:
   - derive editable-target and active-surface facts;
   - resolve one intent;
   - preserve `preventDefault` and the existing effect calls/order;
   - retain listener ownership in the component.
6. Run focused and combined Reader Node loops plus frontend build.
7. Run protected PHP suites.
8. Use the official OpenAI Chrome connection to verify keyboard navigation and focused-input suppression on an isolated Reader fixture at true wide and narrow viewports.
9. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and close only Phase 6G.

## Success

- Every established key, stage, Shift flag, prevent-default decision, and suppression rule is unchanged.
- No DOM, effect, API, store, backend, or learning-data ownership moves into the policy.
- `TextBlockGroup.vue` loses the inline decision table and retains a readable effect adapter.

## Failure

- Any changed keyboard behavior, browser/system shortcut interception, editable/dialog suppression, stage value, or effect order.
- Any new configuration, capability, dependency, or public contract.
- Any protected write, browser regression, or unexplained test failure.
