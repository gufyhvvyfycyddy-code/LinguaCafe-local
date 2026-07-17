# Review Card Leech Mutation Browser Acceptance — 2026-07-17

> **Status**: Accepted / Production Closed
> **Scope**: Phase 3C-4 Leech summary, rewrite-package generation, single suspend, and bulk suspend
> **Excluded**: delete, AI-provider calls, automatic card creation, ReviewLog creation, FSRS rating changes

## Browser evidence

- Signed in through the real local login page and opened `/review-cards/manage`.
- The initial browser run exposed a real summary defect: cards classified as `leech` by the rewrite-package flow were reported as `0` by the page summary.
- Root cause was the summary query selecting the legacy `review_cards.language` column instead of the authoritative `language_id` ownership column. A failing regression test reproduced the production fixture shape (`language=''`, `language_id='english'`) before the one-line query fix.
- After the fix and a real page reload, the summary displayed `高遗忘 2 · 需关注 1`, and the `高遗忘` filter returned cards `136` and `139` with their classification chips.
- Single-card rewrite generation for card `139` displayed the manual-copy package and the explicit “不会调用任何 AI” notice.
- Bulk rewrite generation for cards `136` and `139` produced exactly two packages and displayed “不调用 AI · 不创建学习卡 · 不写复习记录”.
- Opening the two-card bulk suspend confirmation and cancelling left both cards active at lifecycle version `0` for this acceptance pass, with zero `manage_bulk_leech_suspend` events.
- Confirming bulk Leech suspend applied exactly two cards. Both were restored through the ordinary bulk lifecycle UI.
- Confirming the single-card Leech suspend for card `139` produced the expected success status. The card was restored through its ordinary single-card lifecycle UI.
- At an explicit `900×900` viewport, the page had no document-level horizontal overflow (`innerWidth=900`, `scrollWidth=892`), and row lifecycle / more actions remained reachable.
- A fresh authenticated management tab reported no console warnings or errors after the complete acceptance flow.

## Database evidence

| Card | Accepted transitions | Final state | FSRS / ReviewLog evidence |
|---|---|---|---|
| 136 | bulk `suspend` (`manage_bulk_leech_suspend`), bulk `resume` (`review_card_manage_bulk`) | `active`, lifecycle version `2`, enabled | due `2026-07-14T13:08:42Z`; state `review`; reps/lapses `6/3`; stability/difficulty unchanged; ReviewLog count remained `7` |
| 139 | bulk `suspend`, bulk `resume`, single Leech `suspend` (`sense_review_leech`), single lifecycle `resume` (`review_card_manage`) | `active`, lifecycle version `4`, enabled | due `2026-07-15T19:21:52Z`; state `review`; reps/lapses `7/4`; stability/difficulty unchanged; ReviewLog count remained `7` |

Each confirmed transition created exactly one corresponding `ReviewCardStateEvent`. The cancelled bulk confirmation created no event. Both cards belonged to user `1`, language `english`; no other user or language was targeted.

## Automated evidence retained

- `ReviewCardManageLeechTest`: 9 passed / 49 assertions, including the authoritative `language_id` summary regression.
- `ReviewCardManageLeechGuard`: 19 passed.
- `ReviewCardLifecycleBulkGuard`: 32 passed.
- `ReviewCardManageArchitecturePlanGuard`: passed.
- `ReviewCardManageUiGuardTest`: 17 passed / 21 assertions.
- Frontend development build: compiled successfully.

Phase 3C-3 delete remains Planned / Not Authorized. This acceptance did not execute or authorize it.
