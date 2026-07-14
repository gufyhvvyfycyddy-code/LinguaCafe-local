# ADR-0020: EncounteredWord Stage Authority Boundary and FSRS Transition

**Status:** Accepted
**Date:** 2026-07-15

## Context

The vocabulary editors historically treated a non-empty legacy translation as an implicit request to call `setStage(-7)`. Editing content is not the same user intent as beginning formal study, and this coupling could move a word into the legacy SRS without an explicit stage action or confirmed sense.

`EncounteredWord::setStage()` still combines reader classification, `learn_words` goal updates, relearning, and legacy `reviewIntervals`, `next_review`, and `added_to_srs` compatibility. Removing that method or its data in this slice would cross reader, goal, legacy review, and finished-reading boundaries.

Formal sense study has a separate authority chain: a confirmed `WordSense` owns a sense-target `ReviewCard`; formal ratings write `ReviewLog` and advance FSRS. Manual sense creation currently also returns backend-confirmed `updated_word`, which the reader consumes to update the token, `uniqueWords`, and Vuex state immediately.

## Decision

1. Entering, pasting, or editing a legacy translation is content editing only. The three vocabulary editors continue to emit the unchanged `updateVocabBoxData` payload, but their `inputChanged()` methods no longer call or emit a stage change.
2. Explicit known, ignored, and legacy stage actions remain available and retain their current behavior. Phrase and Anki auto-add behavior are unchanged.
3. `EncounteredWord.stage` temporarily remains authoritative for new/known/ignored reader classification, reader compatibility state, and legacy compatibility.
4. Confirmed sense `ReviewCard`, formal `ReviewLog`, and FSRS fields remain authoritative for formal sense-review progress and scheduling.
5. Manual confirmed-sense creation keeps its current `updated_word` response and reader update path. This is an explicit user action and preserves immediate backend-confirmed UI state, including `keep_new=true` behavior.
6. The first safe slice is limited to removing the three translation-to-`setStage(-7)` side effects and adding an executable boundary guard.

## Product Gate for the remaining Mgmt-7-c migration

Full migration is not authorized by this ADR. Before changing stage ownership, the product must decide whether a confirmed sense should display the minimum “enrolled in learning” reader color before its first formal FSRS rating, or remain visually new until that rating. Any later slice must re-audit reader color, familiarity summaries, goals, finished reading, manual sense behavior, legacy compatibility, and migration/backfill needs.

## Forbidden changes in this slice

- Do not delete or rewrite `EncounteredWord::setStage()`, `next_review`, `reviewIntervals`, `added_to_srs`, `GoalService`, or legacy data.
- Do not relax the `ReaderDataService` `stage < 0` familiarity gate or change its FSRS familiarity calculation.
- Do not change `WordSenseService::createManualSense()`, `updated_word`, `WordSensesList.vue`, `TextBlockGroup.vue`, ReviewCard, ReviewLog, FSRS scheduling, lifecycle, routes, controllers, schemas, or migrations.
- Do not move the removed side effect into a watcher, computed property, blur/save handler, hidden request, service, repository, interface, DTO, or mixin.

## Consequences

Translation remains editable and persistable without silently starting legacy study. Explicit stage controls and confirmed-sense enrollment keep working. The legacy model remains coupled for now, but the coupling is no longer triggered by translation input. The remaining authority migration stays visible as a Product Gate instead of being implied complete.

## Verification

- `tests/js/EncounteredWordStageAuthorityGuard.test.mjs` locks the three translation boundaries and the manual-sense `updated_word` reader chain.
- Existing WordSense, reader highlight, finished-reading, legacy-entry, explicit-stage, Review FSRS, and scheduling tests protect retained behavior.
- Real browser acceptance covers translation-only editing and manual confirmed-sense creation at 1920×1080 and 900×900.
