# E-06 Mobile Reader / Reviewer Interaction — Implementation Plan

## Status

Accepted under current Goal authorization.

## Goal and non-goals

Complete the current Capacitor client's missing long-press phrase selection and
same-document Back/Forward behavior while retaining its existing lookup bottom
sheet, reviewer reveal/four-rating flow, haptics, safe areas and 44 px controls.

This slice does not create another Reader/Reviewer state owner, router, native
plugin, endpoint, store, rating path or scheduling rule. It does not change Web
Reader, FSRS, ReviewLog or WordSense authority.

## Architecture gate

Risk: high because the visible mobile Reader and formal Reviewer are touched.

- `LinguaCafeApp` remains the one UI orchestrator. Pointer events translate a
  450 ms long press and drag into one bounded source-sentence phrase, then reuse
  existing `openLookup()` and the existing server/package dictionary precedence.
- `readerTouchSelection.ts` is a pure phrase/threshold helper only; it owns no
  lifecycle or persistence.
- Browser history represents existing primary-screen navigation and one open
  lookup sheet. Back closes the sheet before changing screens; Forward follows
  the same screen history without a second router.
- Existing `styles.css` already owns safe-area, dynamic-height bottom-sheet,
  two-column rating and minimum-target rules and is read-only in this slice.

## Allowlist

- `mobile/src/ui.ts`
- `mobile/src/readerTouchSelection.ts`
- `mobile/src/readerTouchSelection.test.ts`
- `tests/js/E06MobileInteractionGuard.test.mjs`
- this plan and the Goal ledger

Forbidden: `mobile/src/styles.css`, backend/API/schema files, Web Reader/Reviewer,
native platform source, new dependencies/plugins, credentials and non-testing
data.

## Verification

1. pure tests cover scroll threshold, forward/reverse phrase normalization and
   sentence boundaries;
2. Mobile full tests, production build and E-03…E-06/M5/M7 guards pass;
3. testing-bound Android emulator runs the current synced APK and uses real UI
   events for tap lookup, long-press drag phrase, sheet Back, primary-screen
   Back/Forward, Reviewer reveal/rating and safe-area/overflow inspection;
4. target Network/Console/logcat findings are classified and task data, token,
   APK, server, emulator artifacts, ports and lease are cleaned exactly.
