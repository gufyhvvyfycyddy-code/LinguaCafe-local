# Reviewer Convergence Browser Acceptance — 2026-07-17

Status: **Accepted / Production Closed**

## Scope

- shared rating request coordination in Sense Review and legacy Review;
- Anki-aligned Sense Review shortcuts using existing edit, Card Info, lifecycle, and marker owners;
- authenticated local browser interaction on `http://127.0.0.1:8000`;
- no delete interaction and no endpoint/schema change.

## Executed browser evidence

1. Sense card 64 began with purple marker 6. `Ctrl+6` changed it to unmarked; the same shortcut restored purple, proving toggle semantics and the shared marker API path.
2. `I` navigated to `/review-cards/manage?review_card_id=64&from=sense-review`; the canonical Card Info drawer loaded the exact `technology` card and displayed its details.
3. `E` opened the existing `编辑词义卡片` dialog. It was closed without saving.
4. After showing the answer, `@` opened the existing `确认暂停复习` lifecycle dialog. It was cancelled, so lifecycle state did not change.
5. The legacy `/review` page loaded its queue successfully after the shared coordinator integration; no rating was performed there because Sense Review is the formal mainline.
6. Sense Review performed one real `good` rating. The session action count moved to one and `Ctrl+Z` restored the same card.
7. Console output contained only the known unavailable local Echo/WebSocket server connection errors; there were no Vue/runtime/request errors from Reviewer convergence.

## Database evidence

Before rating, card 64 had marker 6, `fsrs_state=review`, due `2026-07-14 09:46:09 UTC`, stability `5.09047`, difficulty `7.176636`, reps 2, lapses 1, lifecycle `active` version 0, three ReviewLogs, and zero lifecycle events.

After rating and undo, every listed ReviewCard field exactly matched baseline. ReviewLog count became four; the new `good` / `sense_review` row (id 169) had a non-null `undone_at`. Lifecycle event count remained zero. This is the expected append-only audit behavior, not an extra active rating.

## Automated evidence

- `ReviewRatingRequestCoordinator.test.mjs`: passed.
- Reviewer recovery, queue order, next-card, stack-undo, deep-link, and convergence guards: passed.
- Focused backend command: 1,183 passed / 4,321 assertions, 2 skipped, with one stale Browser ownership guard discovered; the guard was corrected to assert `bulkRewritePackages` in its actual Leech owner.
- Laravel Mix production compilation: passed; only pre-existing Sass deprecation warnings were emitted.

## Safety boundaries

- No new Reviewer abstraction, API client layer, endpoint, migration, or persisted state was added.
- No delete action was triggered.
- Marker and cancelled lifecycle actions did not change FSRS or ReviewLog.
- The formal rating was immediately undone and remains auditable by design.
- Local acceptance credentials remain governed by `AGENTS.md` and are not duplicated here.
