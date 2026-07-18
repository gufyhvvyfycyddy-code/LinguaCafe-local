# Card Marker + Custom Study 1B Browser Acceptance — 2026-07-18

## Scope

This report closes roadmap Phase 4 only. It verifies ReviewCard Marker persistence and the preview-only `marked` Custom Study mode. It does not authorize or claim Reviewer, Reader, or real AI provider work.

## Automated evidence

- Marker persistence, database constraint/index, single and bulk API isolation, serializer exposure, and UI guards passed their focused PHP/Node suites.
- The complete Custom Study suite passed: 731 tests, 1,632 assertions.
- Protected regressions for Word FSRS, FSRS scheduling, WordSense/Sense review, ReviewCard management, and Card Info passed.
- `npm run development` passed. The emitted Sass deprecation notices are pre-existing build warnings.
- Three bounded fresh-context adversarial review cycles found and closed four edge cases: explicit-mode stale-token resume, non-list and numeric-key JSON-object bulk IDs, drawer-close reconciliation, and route-leave reconciliation during a pending Marker save. Their focused regressions passed after the final fixes.

## Authenticated browser evidence

The acceptance run used a disposable authenticated user against the isolated testing MySQL database and exercised both 1920×1080 and 900×900 viewports.

1. Review-card Browser displayed the shared Marker control and stable labels for none, red, orange, green, blue, pink, cyan, and purple.
2. A single card was changed through all seven colors and cleared; each canonical server result was reflected in the table.
3. Two cards were bulk-marked red, the page was reloaded, and both persisted.
4. Card Info displayed the persisted Marker and its “学习已标记卡片” entry opened `/custom-study?mode=marked`.
5. The Browser-level entry opened the same exact route and selected “已标记的词义”.
6. The marked session returned only the two eligible current-user cards. Suspended, temporarily buried, FSRS-disabled, unconfirmed-sense, and other-user marked fixtures were excluded.
7. The two-card preview completed with candidates 2, planned 2, completed 2, skipped 0.
8. With only excluded marked fixtures remaining, the page showed candidates 0, planned 0, completed 0, skipped 0.
9. At both viewports the setup, empty, answer, and completion states remained usable without horizontal overflow.
10. The console contained only the expected local Pusher/WebSocket fallback and development font notices; no Marker or Custom Study request failed.

## Protected-state delta

Before and after the preview loop, the seven isolated fixture cards retained their original FSRS state, due time, stability, difficulty, reps, lapses, last-reviewed time, enabled flag, lifecycle state/version, and burial time. The associated WordSense rows retained their original status and content. `review_logs` remained at 0.

The two active fixture cards were restored from red to Marker 0 through the public bulk Marker interface and remained cleared after reload. All disposable users, ReviewCards, and WordSenses were then deleted by exact ID from both the accidental local fixture set and the testing fixture set; post-cleanup counts were 0.

## Verdict

Card Marker + Custom Study 1B satisfies ADR-0029 and is Accepted / Production Closed. The next roadmap slice is Phase 5, Reviewer architecture convergence, which must preserve the formal rating and FSRS write boundaries.
