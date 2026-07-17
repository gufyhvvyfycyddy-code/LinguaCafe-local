# ReviewCard Lifecycle Mutation Browser Acceptance

> **Status**: Passed / Production Closure Evidence
> **Date**: 2026-07-17
> **Scope**: Browser/ReviewCardManage Phase 3C-2 lifecycle mutation family only
> **Page**: `http://127.0.0.1:8000/review-cards/manage`

## 1. Acceptance boundary

This pass verifies the already-implemented `ReviewCardLifecycleMutationSurface.vue` through the real local page. It does not authorize Phase 3C-3, change lifecycle semantics, modify FSRS or ReviewLog behavior, or alter application code.

The default local browser context already had an authenticated LinguaCafe session. The task-provided fixture credentials did not authenticate in a separate isolated context, and registration confirmed that the email already existed. No account was created or replaced. Acceptance continued only in the existing authenticated localhost session.

## 2. Wide viewport — 1920×1080

Initial visible lifecycle baseline:

- total sense cards: 4;
- active/learning: 4;
- buried: 0;
- suspended: 0;
- archived: 0;
- due now: 4.

Verified actions:

1. Opening card 122 lifecycle menu produced one descriptor request: `GET /review-cards/122/lifecycle`.
2. Confirming `埋藏到明天` produced exactly one `POST /review-cards/122/lifecycle-actions`, followed by one list refresh and one stats refresh. The page showed buried 1 and active 3.
3. Filtering to buried cards and confirming `解除埋藏` produced exactly one lifecycle-action POST, followed by one list refresh and one stats refresh. The page returned to buried 0 and active 4.
4. Selecting cards 122 and 121 kept multi-row selection separate from current-card behavior and exposed the batch lifecycle menu.
5. Opening the batch suspend confirmation produced no write request before confirmation.
6. Confirming batch suspend produced exactly one `POST /review-cards/manage/bulk-lifecycle`, followed by one list refresh and one stats refresh. The page showed suspended 2 and active 2.
7. Selecting the same two cards and confirming batch restore produced exactly one bulk-lifecycle POST, followed by one list refresh and one stats refresh. Selection cleared and the page returned to suspended 0 and active 4.

Final visible lifecycle baseline matched the initial baseline: total 4, active 4, buried 0, suspended 0, archived 0 and due now 4. The visible FSRS state distribution also returned to one new, one learning, one review and one relearning card.

## 3. Narrow viewport — 900×900

- The lifecycle state-help dialog was centered and contained within the viewport; its visible content panel measured about 558×373 px.
- The single-card pause confirmation was centered and contained within the viewport; its visible content panel measured about 480×168 px.
- Opening either dialog did not create a write request.
- Cancelling the pause confirmation created no request.
- After dialogs closed, document width equaled client width; there was no page-level horizontal overflow.

## 4. Network and Console

- All observed resource hosts were `127.0.0.1:8000`.
- No external request was observed.
- Console inspection found no error, warning or issue.
- Browser actions were performed through the loaded page. No direct API/fetch substitute was used.

## 5. Result

**Passed.** Phase 3C-2 authenticated Chrome acceptance is complete and the lifecycle mutation family can be recorded as **Accepted / Production Closed**.

Phase 3C-3 Delete Mutation Family remains **Planned / Not Authorized** and requires a separate task before any implementation begins.
