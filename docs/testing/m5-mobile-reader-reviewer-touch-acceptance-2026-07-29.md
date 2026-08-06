# M5 Mobile Reader / Reviewer Touch Adaptation V1 Acceptance

## Status

Accepted / Closed (2026-07-29).

This report also clears the M1 deferred Web rating seam. The original Mobile
API, idempotency and contract evidence remains in
`docs/testing/mobile-api-foundation-acceptance-2026-07-28.md`; this report
supplies the missing real `/reviews/senses` page compatibility evidence.

## Scope and boundaries

M5 adapts the existing Web Reader and sense-only Reviewer for phone and touch:
tap, native scroll, long-press phrase selection, Back-to-close, no-hover
behavior, a bounded keyboard-safe vocabulary sheet, answer/source actions and
thumb-sized rating controls. It does not add a native application, a second
Reader/Reviewer state model, an API, a migration or a new scoring path.

The formal rating path remains:

`SenseReview.vue -> existing Web endpoint -> ReviewCardService::recordReview
-> FsrsSchedulingService::schedule`.

## Implemented slice

- A pure 450 ms / 10 px touch-selection policy distinguishes tap, native
  scroll and activated phrase drag.
- `TextBlockGroup.vue` reuses existing selection/store behavior, suppresses
  hover on coarse pointers and owns a same-URL history marker so Back closes
  the mobile sheet first.
- The existing vocabulary bottom sheet gained a grip/header, explicit close,
  selection summary, internal scrolling, safe-area padding and an 86dvh bound.
- Sense Study reveal/source presentation and the four existing rating events
  gained responsive layouts without moving API or scoring ownership.
- Source-context fallback remains valid existing behavior: M5 requires the
  visible source action and rendered result, not a newly guaranteed source.

## Automated verification

- Focused touch/presentation/contract scripts: 15 Node subtests plus the
  existing assertion-style guards passed, including
  `ReaderTouchSelectionPolicy.test.mjs` and
  `M5MobileTouchAdaptationGuard.test.mjs`.
- `npm run development` compiled successfully; output contained only existing
  Sass deprecation warnings.
- `ReviewFsrsTest`: 63 passed, 375 assertions.
- `FsrsSchedulingServiceTest`: 9 passed, 46 assertions.
- WordSense-filtered suite: 204 passed, 1 skipped, 879 assertions.
- Fresh adversarial review found one high-impact browser-only defect; after its
  fix and rerun, no remaining high-risk issue was found.

## Testing-server binding

The isolated acceptance server listened on `0.0.0.0:8775`, PID 50800.
`/__testing/acceptance-sentinel` returned:

- `environment=testing`;
- `database_is_testing=true`;
- `sentinel_present=true`.

All write-capable browser actions were performed only after this binding was
verified.

## Real browser evidence

The official OpenAI in-app Browser was attempted first and verified the local
rendered surface. Because that channel did not expose genuine touch injection
or a coarse pointer, ADR-0033 fallback used system Chrome through Playwright in
one real mobile context with `isMobile=true`, `hasTouch=true`.

Final isolated fixtures used testing user 135, ReviewCard 74 and chapter 14.
The browser reported a coarse pointer, no hover and one touch point.

- 360, 390, 430 and 768 px: `clientWidth == scrollWidth`; no horizontal
  overflow.
- A genuine tap on “friendly” opened the sheet. Its top/bottom/height were
  180/844/664 px; the close target was 48 x 48 px and the history marker was
  present.
- Browser Back kept the Reader URL, removed the marker and closed the sheet.
- Genuine touch scrolling moved the Reader from scrollTop 0 to 165 without
  opening the sheet.
- Long-press plus drag selected the two-token phrase `friendly reading`
  (indices 1 and 2).
- With the lookup input focused, the sheet stayed within the 844 px viewport,
  kept the close action visible and used internal `overflow-y: auto`.
- Reviewer reveal was full-width and 52 px at 360/390/430 px; the tablet layout
  retained the accepted 44 px desktop control size.
- The source action and source dialog were visible; the existing fallback
  message rendered.
- Mobile rating controls formed a zero-overflow 2 x 2 grid; every button was
  170 x 52 px.
- Exactly one `POST /reviews/senses/74/rate` was observed, with status 200.
- There were no server errors or unexpected console errors.

The genuine touch run originally exposed a synthetic mouse chain after
`touchend`, which cleared the newly opened selection. The fix calls
`preventDefault()` only after a resolved tap or phrase-finish action; native
scroll cancellation remains unblocked. A repeated genuine-touch run confirmed
the synthetic chain no longer occurred.

## Formal-rating and cleanup evidence

After the one rating action, testing user 135 had exactly one ReviewLog.
ReviewCard 74 had `fsrs_reps=3` and an updated `last_reviewed_at`.
`operation_count=0`, confirming the legacy Web seam remained separate from the
Mobile operation ledger as designed.

The protected regression run reset the isolated testing database. A final
query confirmed temporary users 127–135, settings 26/27 and the acceptance
sentinel migration were absent. PID 50800 was stopped, port 8775 was closed,
automation-owned browser pages were closed, and the temporary browser harness
was removed. No acceptance fixture or credential remains.

## Verdict

M5 is Accepted / Closed. The same server-bound real-page run proves the legacy
Web reviewer still performs one formal rating through its existing endpoint
and therefore clears the M1 deferred Web rating seam.
