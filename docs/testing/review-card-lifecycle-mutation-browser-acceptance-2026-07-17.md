# Review Card Lifecycle Mutation Browser Acceptance — 2026-07-17

> **Status**: Stage-wise Accept; authority-ledger promotion remains pending
> **Scope**: Authenticated ReviewCardManage single-card and bulk lifecycle writes
> **Excluded**: delete, reset, due-now, FSRS rating, Leech policy changes

## Browser evidence

- Signed in through the real local login page and opened `/review-cards/manage`.
- Verified the lifecycle entry at the normal desktop viewport and at explicit `1920×1080` and `900×900` viewports.
- At `900×900`, the table remained usable through its horizontal scroll and the row lifecycle menu remained reachable.
- Console warning/error capture returned an empty list.
- Opening the single-card lifecycle menu, opening the suspend confirmation, and cancelling left card `155` at `active`, version `0`, with zero lifecycle events and zero ReviewLog rows.
- Opening the bulk lifecycle menu, opening the suspend confirmation for cards `153` and `154`, and cancelling left both cards at `active`, version `0`, with zero lifecycle events and zero ReviewLog rows.
- Confirmed one single-card suspend for card `155`; the row left the active result set and the page summary changed from learning `80` / suspended `5` to learning `79` / suspended `6`.
- Confirmed one bulk suspend for cards `153` and `154`; both rows left the active result set.
- Restored card `155` through its single-card lifecycle entry and restored cards `153` and `154` through the bulk lifecycle entry. All three returned to `active`.

## Database evidence

| Card | Before | After restore | FSRS / ReviewLog evidence |
|---|---|---|---|
| 153 | `active`, version `0`, enabled | `active`, version `2`, enabled; events `suspend`, `resume` | due unchanged at `2026-07-15T05:57:20Z`; state `new`; reps/lapses `0/0`; stability/difficulty unchanged; ReviewLog `0` |
| 154 | `active`, version `0`, enabled | `active`, version `2`, enabled; events `suspend`, `resume` | due unchanged at `2026-07-15T06:00:11Z`; state `new`; reps/lapses `0/0`; stability/difficulty unchanged; ReviewLog `0` |
| 155 | `active`, version `0`, enabled | `active`, version `2`, enabled; events `suspend`, `resume` | due unchanged at `2026-07-15T07:35:17Z`; state `new`; reps/lapses `0/0`; stability/difficulty unchanged; ReviewLog `0` |

Each confirmed transition created exactly one expected audit event. Cancelled confirmations created none. The cards belonged to user `1` and language `english`; no other user or language was targeted.

## Network tooling note

The standalone Chrome DevTools connector was unavailable (`SSE probe returned 404`), and neither active browser runtime exposed the optional raw-CDP capability. Direct Network-panel export was therefore unavailable. Request singularity is instead evidenced by the real-page one-click flows, exactly one state transition/audit event per confirmation, zero events on cancellation, and the executable single-owner/idempotency guards and grouped lifecycle tests. No API-only result is presented as browser acceptance.

## Automated evidence retained

- `ReviewCardLifecycleMutationSurfaceGuard.test.mjs`: passed.
- `ReviewCardLifecycleBulkGuard.test.mjs`: 31 passed.
- Lifecycle/compatibility/concurrency/queue/danger/UI/Leech grouped Feature set: 108 passed / 244 assertions.
- `php artisan db:doctor`: healthy.

Phase 3C-3 delete remains Planned / Not Authorized. This acceptance does not authorize or execute it.
