# M5 Mobile Reader / Reviewer Touch Adaptation V1 — Implementation Plan

## Status

Accepted / Closed (2026-07-29)

Implementation, protected regressions, testing-server binding, genuine touch
browser interaction and cleanup are complete. Acceptance evidence:
`docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md`.
The same real Web rating run also supplies the `/reviews/senses` page evidence
previously deferred by M1.

## Goal and non-goals

Make the existing Web Reader and sense-only Reviewer deliberate and reliable on
phone/touch viewports. The slice completes tap/long-press/drag/Back behavior,
explicit no-hover lookup, a keyboard-safe vocabulary sheet and a thumb-friendly
show-answer/source/rating flow.

It does not build a native app, offline storage/background sync, a new API,
legacy Word-review parity, a new theme or a second Reader/Reviewer state model.

## Architecture gate

Risk: high. The slice touches `TextBlockGroup.vue` Reader state flow,
`VocabularyBottomSheet.vue`, visible Sense Review controls and real formal
rating acceptance.

Responsibilities:

- `ReaderTouchSelectionPolicy.js`: pure touch gesture transitions and movement
  threshold.
- `TextBlockGroup.vue`: translate DOM events into policy inputs, reuse existing
  selection/lookup state, select the mobile surface and own the temporary Back
  history marker.
- `VocabularyBottomSheet.vue` + its existing SCSS: present the selected
  word/phrase and existing actions inside a bounded touch surface.
- `SenseStudyCard.vue`: responsive question/answer/source presentation only.
- `SenseReviewRatingControls.vue`: responsive four-button presentation only.
- `SenseReview.vue`: page-level responsive framing only; existing orchestration
  and rating API remain unchanged.

Seams:

`touch DOM event -> pure gesture policy -> existing start/update/finishSelection
-> existing vocabulary store -> existing bottom sheet -> existing lookup/save
APIs`.

`SenseReview page -> existing SenseStudyCard reveal/source events -> existing
rating controls event -> existing parent rate() -> existing formal rating
endpoint`.

## Allowed files

- `resources/js/services/ReaderTouchSelectionPolicy.js`;
- `resources/js/components/Text/TextBlockGroup.vue`;
- `resources/js/components/Text/VocabularyBottomSheet.vue`;
- `resources/sass/Text/VocabularyBottomSheet.scss`;
- `resources/js/components/Senses/SenseStudyCard.vue`;
- `resources/js/components/Senses/SenseReviewRatingControls.vue`;
- `resources/js/components/Senses/SenseReview.vue`;
- focused JS/source-guard tests;
- ADR-0043, this plan, M5 acceptance, and current roadmap/index/handoff
  documents.

## Forbidden files

- backend controllers/services/routes/payloads;
- migrations/models/ReviewCard/ReviewLog/FSRS;
- Vuex store schema or unrelated Reader/Reviewer services;
- desktop sidebar/popup redesign;
- native mobile projects and M7/M8 responsibilities;
- `.env`, credentials, development/production data.

## Interaction contract

- Tap: stationary touch ends before long-press activation → select one token.
- Scroll: pre-activation movement over 10 px → cancel pending tap and do not
  prevent default.
- Phrase: 450 ms stationary press → start; drag across tokens → extend; release
  → finish.
- Cancel: clear timer/gesture and incomplete visual selection.
- Back: close an open mobile sheet before route navigation.
- Hover: only `(hover: hover) and (pointer: fine)` devices may open hover lookup.
- Phone sheet: max 86 dynamic viewport height, internal scroll, safe-area
  bottom, explicit 44 px close/actions.
- Review: full-width 52 px reveal, visible source action, two-column 52 px
  rating grid at phone widths, desktop behavior unchanged.

## Minimal verification

1. pure touch-policy tests and M5 source guard;
2. existing Reader selection/lookup/presentation JS tests;
3. Sense Review rating presentation/contract + Review FSRS/scheduler/WordSense;
4. `npm run development`;
5. server-bound testing browser at 360/390/430 px and tablet width for touch,
   Back, focused input/keyboard-safe bounds, answer/source/rating and cleanup;
6. PHP/JS syntax where applicable, documentation guards and scoped
   `git diff --check`;
7. up to three fresh-context adversarial review rounds, stopping when only
   wording or equivalent alternatives remain.
