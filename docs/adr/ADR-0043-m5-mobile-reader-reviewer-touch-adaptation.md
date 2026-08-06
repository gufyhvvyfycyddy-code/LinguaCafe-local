# ADR-0043: M5 Mobile Reader / Reviewer Touch Adaptation V1

## Status

Accepted under the current roadmap goal authorization

## Context

The Reader already owns token selection, dictionary lookup and an optional
Vuetify bottom sheet, but its touch path treats a short tap as cancellation and
only starts selection after a long press. Small-screen users can therefore
scroll, tap and drag through overlapping event paths, and coarse-pointer
devices may still inherit hover-oriented behavior.

Sense Review already preserves the Anki-aligned show-answer → four-rating
sequence and the single formal rating API. Its information density and flexible
button row work on desktop but do not yet provide a deliberate 360–430 px thumb
zone, safe-area padding or a consistently visible source action.

M5 is a responsive Web/cross-platform preparation slice. It must not create a
native application, change rating semantics, add a second dictionary/review
write path or alter Reader/Reviewer backend payloads.

## Decision

1. M5 reuses `TextBlockGroup.vue`, the existing vocabulary Vuex state and
   `VocabularyBottomSheet.vue`. It does not add a second Reader state module or
   duplicate lookup/save APIs.
2. At widths up to 768 px, an active vocabulary selection always uses the
   bottom sheet even when an old desktop preference disabled it. Desktop
   sidebar/popup behavior remains unchanged.
3. Touch selection has one explicit state machine:
   - a stationary short tap selects one token and opens the sheet;
   - a 450 ms long press starts selection and subsequent drag extends a phrase;
   - movement beyond 10 px before activation cancels the pending selection and
     leaves native scrolling untouched;
   - after long-press activation, movement is consumed only while extending the
     selection;
   - `touchcancel` removes an incomplete gesture without saving it.
4. Coarse/no-hover devices do not open `VocabularyHoverBox`. Translation and
   dictionary results remain available through the explicit tap-driven sheet.
   This is a LinguaCafe mobile adaptation; no visual hover convention is
   attributed to Anki.
5. While the mobile sheet is open, one same-URL history entry represents it.
   Browser/Android Back closes the sheet first. Explicit close consumes that
   entry; component destruction removes any marker so stale history entries do
   not remain.
6. The sheet has a visible drag handle, selection summary, explicit close
   target, bounded dynamic-viewport height, internal scrolling, 44 px minimum
   touch targets and bottom safe-area padding. Inputs remain normal form
   controls; the visual viewport may shrink for the keyboard without covering
   the close/action zone.
7. Sense Review keeps the existing `SenseStudyCard` and
   `SenseReviewRatingControls` responsibilities. On phones:
   - the summary and card header wrap without horizontal overflow;
   - Show Answer is a full-width 52 px target;
   - View Source remains visible after reveal;
   - ratings form a two-column thumb grid with 52 px targets and bottom
     safe-area padding;
   - hotkey-only help is hidden, while desktop keyboard behavior remains.
8. The existing rating labels, colors, interval previews, API call, loading
   lock, ReviewLog write and FSRS scheduler remain authoritative. M5 adds no
   client scheduling logic and no rating endpoint.
9. Breakpoints under acceptance are 360, 390 and 430 px phone widths plus one
   tablet width. Touch tap, scroll cancellation, long-press phrase drag, Back,
   keyboard focus, reveal, source and one formal rating require real rendered
   browser evidence on a server-bound testing database.

## Design direction

- Product/audience: one-handed English reading and daily Sense review.
- Tone: calm, tactile, focused.
- Layout: full-width reading surface with an anchored, bounded thumb-zone sheet.
- Typography: preserve project fonts; keep form and action text comfortably
  legible instead of shrinking desktop density.
- Color/surface: reuse the Vuetify theme; reserve primary and existing semantic
  rating colors for active decisions.
- Motion: existing sheet/expand transitions only, with reduced-motion support.
- Signature differentiator: the thumb-zone dock—sheet grip and selection
  summary in Reader, paired with a two-by-two rating dock in Reviewer.

No external component inspiration is needed: the project already contains the
correct domain components and visual language.

## Compatibility and exclusions

- No backend route, request/response payload, database schema, FSRS field,
  ReviewLog behavior, WordSense mutation or Vuex schema changes.
- No redesign of desktop Reader sidebar/popup or desktop rating layout.
- No promotion of legacy Word-card review into the sense-only product mainline.
- No native Android/iOS shell, local database, background sync, offline cache,
  push notification or app-store work.
- No global typography/theme redesign and no unrelated cleanup of the large
  Reader/Reviewer components.

## Verification

- pure touch-policy tests for tap, scroll cancellation, long press, drag and
  cancel;
- source guards for mobile bottom-sheet ownership, no-hover policy, Back marker,
  safe-area/keyboard CSS and responsive rating/source actions;
- existing Reader drag-selection, lookup, token-presentation, Review rating,
  FSRS and WordSense regressions;
- frontend build;
- real-browser 360/390/430 px and tablet acceptance with touch events, keyboard,
  Console/Network and testing-database deltas;
- fresh-context adversarial review and scoped diff checks.
