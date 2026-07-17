# ReviewCard Delete Mutation Browser Acceptance — 2026-07-17

**Status**: Passed / Production Closure Evidence

## Scope

This acceptance closes Browser / ReviewCardManage Phase 3C-3 — Delete Mutation Family. It verifies the existing single-card and selected-card bulk delete behavior after request and confirmation ownership moved into `ReviewCardDeleteMutationSurface.vue`.

No backend route, payload, access rule, database schema, ReviewLog rule, occurrence-retention rule, last-confirmed-sense rule or FSRS behavior changed.

## Environment

- Route: `/review-cards/manage`
- Authenticated local account: the task-provided local acceptance account
- Viewports: 1920×1080 and 900×900
- Browser: persistent local Chrome session through Playwright
- Network boundary: localhost only

## Acceptance fixtures

Three temporary confirmed sense cards were created for this acceptance:

- review card `123`: `phase3c3_single_20260717121338`
- review card `124`: `phase3c3_bulk_a_20260717121338`
- review card `125`: `phase3c3_bulk_b_20260717121338`

The product delete flow removed all three active review cards. Their source WordSense records followed the existing rejection/preservation contract rather than being bypassed by test-only cleanup.

## Single-card delete

1. The row action opened the existing single-delete confirmation.
2. The dialog stated that review history and reading-source records remain, other senses are not deleted, and a word returns to New when its last confirmed sense is removed.
3. Opening the dialog produced no DELETE or bulk-delete POST request.
4. At 900×900 the dialog remained inside the viewport and the document had no horizontal overflow.
5. Confirming produced exactly one `DELETE /review-cards/manage/123` request with HTTP 200.
6. The deleted row disappeared and the total count changed from 7 to 6.

## Selected-card bulk delete

1. Exactly review cards `124` and `125` were selected.
2. The bulk dialog listed both selected lemmas and stated that only the current selection would be processed, never all rows matching the filter.
3. The dialog preserved the existing review-history, reading-source and last-confirmed-sense explanations.
4. Opening the dialog produced no bulk-delete request.
5. At 900×900 the dialog remained inside the viewport and the document had no horizontal overflow.
6. Confirming produced exactly one `POST /review-cards/manage/bulk-delete` request with HTTP 200.
7. Both rows disappeared, the total count changed to 4, and selection returned to 0.

## Browser and network facts

- Every observed application request stayed on `127.0.0.1:8000`.
- No external provider or API request occurred.
- The only Console errors were the established local WebSocket fallback connection failures on ports 6001 / localhost; there was no Vue runtime or application error.
- Vuetify activator/checkbox overlays caused Playwright pointer interception and accessibility-ref drift during this pass. The acceptance invoked the already-loaded Vue components' existing event listeners inside the authenticated page, matching the established project workaround. No direct API, fetch or axios substitute was used.

## Result

Phase 3C-3 — Delete Mutation Family is **Accepted / Production Closed**.

`ReviewCardDeleteMutationSurface.vue` is now the ReviewCardManage-domain owner of the single delete request, bulk delete request, request locks and both confirmation dialogs. `ReviewCardTableSurface.vue` remains selection/current-row and intent-only owner. `ReviewCardManage.vue` only delegates intents and coordinates list/stat refresh and notifications.

Phase 3C-4 — Leech Governance Mutation Family remains **Planned / Not Authorized**. No later phase is entered automatically.
