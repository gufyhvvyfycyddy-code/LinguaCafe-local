# Review Card Marker + Custom Study 1B Browser Acceptance — 2026-07-17

Status: **Accepted / Production Closed**

## Scope

- authenticated local Browser acceptance on `http://127.0.0.1:8000`;
- ReviewCard marker single and bulk writes;
- `flag:` Browser search, Card Info visibility, Custom Study `marked`, and Sense Review shared picker;
- desktop and `900×900` responsive evidence;
- no delete flow, no AI call, no formal review rating.

## Executed evidence

1. Migrated `2026_07_17_000001_add_marker_to_review_cards_table`; status changed Pending → Ran and `php artisan db:doctor` remained healthy.
2. Browser card 155 changed from marker 0 to red (1); its Card Info drawer displayed the same marker.
3. Browser cards 153 and 154 were selected together and changed from marker 0 to blue (5); selection cleared after success.
4. Search `flag:5` returned exactly cards 153 and 154, both displaying blue.
5. `/custom-study?mode=marked` preselected “已标记的词义卡” and opened a three-card preview queue containing the marked cards.
6. `/reviews/senses` displayed the shared marker picker and changed the current card to purple (6) without rating it.
7. At `900×900`, `documentElement.scrollWidth === clientWidth === 900`; the table kept its intended internal horizontal scroll and the Marker column remained present.
8. A fresh authenticated validation tab had zero console warnings/errors.

## Data boundary evidence

Before the bulk write, cards 153 and 154 were marker 0, `fsrs_state=new`, reps/lapses 0, null stability/difficulty, and retained their due timestamps. After the write, only marker became 5. Their ReviewLog count stayed 0 and their combined ReviewCardStateEvent count stayed 6. Card 155 similarly changed only to marker 1.

The marked Custom Study session remained preview-only: it created only the existing encrypted browser session token and did not write ReviewLog, FSRS, lifecycle, or normal queue ownership.

## Automated evidence

- `ReviewCardMarkerTest`: 7 passed / 42 assertions.
- Marker + Custom Study focused suite: 164 passed / 322 assertions.
- `ReviewCardMarkerFrontendGuard.test.mjs`: passed.
- Laravel Mix production bundle compilation: passed (pre-existing Sass deprecation warnings only).
- `git diff --check`: passed.

## Safety boundaries

- Marker remains ReviewCard-owned and finite `0..7`.
- Marker is independent from lifecycle, Leech, WordSense status/tags, FSRS, ReviewLog, and ReviewCardStateEvent.
- Phase 3C-3 Delete Mutation Family remains Planned / Not Authorized and was not exercised.
- Local acceptance credentials are governed by `AGENTS.md`; this evidence file intentionally does not duplicate them.
