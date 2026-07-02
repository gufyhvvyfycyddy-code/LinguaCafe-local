# Reading Inline Review And Example Pool Plan

> **Status**: Multi-example pool / rotation / multi-source carousel implemented (2026-07-02). Lemma/surface binding display + lemma-prefer search + known-sense candidates front-only panel + known-sense-new-meaning hint front-only structure implemented (2026-07-03). Reading inline review scoring still frozen, not implemented.
> **Last updated**: 2026-07-03 (Trae-LemmaKnownSenseBridge-1).

This plan records the next route for reading-inline review and multi-example rotation. It is not implementation authorization. Future work still needs Architecture Gate, interface review, tests, and MCP Chrome page acceptance.

## 1. Scope

This document freezes four product routes:

1. Reading inline review must be WordSense-based. **(Still not implemented — scoring path frozen.)**
2. Review card examples must rotate from real source examples. **(Implemented 2026-07-02: `WordSenseExamplePoolService` + `SenseReviewCardSerializerService` rotation + supplementary example.)**
3. Known-sense-new-meaning confirmation must stay separate from ordinary AI recommendations. **(Front-only structure implemented 2026-07-03: `WordSenseKnownSenseService` + `GET /senses/known-sense-lookup` + `WordSensesList.vue` 「已学词义候选」 panel + 「熟词僻义」 info alert. AI judgment for "is this a known-sense-new-meaning case?" is still NOT implemented.)**
4. Surface/lemma binding must remain context-aware and user-correctable. **(Front-end display + lemma-prefer search + user-correction flow implemented 2026-07-03. Automatic context-aware binding (surface + lemma + pos + sentence meaning) and AI suggestions are still NOT implemented.)**

The 2026-07-02 Trae round implemented route #2 and the multi-source carousel portion of route #8 in §4.2. The 2026-07-03 Trae round implemented the front-end display and lemma-prefer search portions of route #4 plus the front-only structure of route #3. Neither round implemented reading-inline review scoring, real AI calls, WordSense generation, ReviewCard generation, FSRS write paths, AI-generated examples, automatic context-aware binding, or AI known-sense-new-meaning judgment.

## 4.1 Reading Inline Review Principles

1. Reading inline review must use `WordSense`, not word-level review.
2. Show an inline review card only when AI or system matching has already resolved the occurrence to a specific `WordSense`.
3. If the match is uncertain, do not show a direct review card.
4. If the occurrence looks like a known word with a different meaning, route it to known-sense-new-meaning confirmation.
5. If the user clicks "not this meaning", do not write FSRS.
6. Inline scoring must use the same `ReviewLog` and FSRS scheduling path as normal sense review.
7. If the same card already received a "remembered" or "easy/fluent" official review today, inline reading should not count another default official review.
8. If the user previously clicked "forgot" or "fuzzy" today, a later explicit inline action may show the card again as short-interval practice.
9. Future design needs Ctrl+Z or another undo for the current inline rating.
10. This round does not implement inline review.

## 4.2 Multi-Example Rotation Principles

1. Each `WordSense` should have an example pool. **(Implemented 2026-07-02: `WordSenseExamplePoolService::exampleCandidates()`.)**
2. The front-side review example must rotate. **(Implemented 2026-07-02: stable seed rotation in `SenseReviewCardSerializerService`.)**
3. Supplemental examples shown after answer reveal must differ from the front-side example. **(Implemented 2026-07-02: `pickSupplementaryIndex()` guaranteed-different.)**
4. If only one example exists, do not show it again as a duplicate supplemental example. **(Implemented 2026-07-02: supplementary is null when total < 2; `SenseReview.vue` defensive dedup.)**
5. Examples must come from real source text, not AI-generated text. **(Enforced 2026-07-02: sources are `WordSenseOccurrence` + card example fallback only; no AI.)**
6. Do not add the same sentence twice. **(Implemented 2026-07-02: dedupe by chapter + sentence; sentence-only dedupe for card fallback.)**
7. Different positions or different sentences in the same chapter may be separate sources. **(Implemented 2026-07-02: same-chapter same-sentence collapses; same-chapter different-sentence keeps.)**
8. Source lists must support multiple sources. **(Implemented 2026-07-02: `SenseSourceContextService::sourceContextList()` + `/senses/{id}/source-context-list` route + `SenseExampleDialog.vue` carousel.)**
9. Source switching should use rotation plus light shuffle, not fixed ABC order every time. **(Partial 2026-07-02: sources are ordered by manual-sense-add-first then id desc; light shuffle not implemented.)**
10. ~~This round does not implement multi-example rotation.~~ **Implemented 2026-07-02 (Trae-ExamplePool-ReviewRotation-SourceCarousel-1) for routes 1-8. Route 9 light shuffle still partial.**

## 4.3 Known-Sense-New-Meaning And Inline Review Bridge

1. AI recommended words and known-sense-new-meaning candidates are separate areas. **(Front-only separation implemented 2026-07-03: `WordSensesList.vue` 「已学词义候选」 panel is separate from ordinary AI recommendation area. AI judgment that decides whether a case is "known-sense-new-meaning" is still NOT implemented.)**
2. On first reading, the user marks only genuinely unknown words.
3. During AI analysis, the system can also judge whether already-learned words match the current sentence meaning. **(Still NOT implemented — no AI analysis step.)**
4. On a second reading, words already matched to a `WordSense` may enter inline review.
5. Suspected known-sense-new-meaning candidates enter confirmation, not direct legacy card review. **(Front-only hint implemented 2026-07-03: 「熟词僻义」 info alert + 「已学词义候选」 panel as candidates only. Confirmation flow that creates new WordSense is still the existing add-sense flow; no new confirmation path added.)**
6. ~~This round does not implement this bridge.~~ **Front-only structure implemented 2026-07-03 (Trae-LemmaKnownSenseBridge-1). AI judgment, inline review, and automatic known-sense-new-meaning detection are still NOT implemented.**

## 4.4 Surface And Lemma Binding

1. Surface display keeps the original word form. **(Implemented 2026-07-03: reading-page click on `geese` shows `geese → goose [修改]`; `WordSensesList.vue` `lemma-surface-card` shows surface + lemma.)**
2. Search and add-sense flows should prefer lemma. **(Implemented 2026-07-03: search box `value=lemma`; `WordSensesList.vue` `effectiveLemma` (studyBase → baseWord → lemma → surface → word) feeds search.)**
3. After the user corrects lemma, later add-sense flows should use the corrected lemma. **(Implemented 2026-07-03: `VocabularySideBox::saveLemma` → `commit setStudyBase` → `POST /vocabulary/word/update`; `effectiveLemma` prefers `studyBase` after correction. Test `test_add_new_sense_uses_corrected_lemma_after_user_edit` confirms lemma and surface_form stored independently.)**
4. Binding must consider surface, lemma, part of speech, and sentence meaning. **(Still NOT implemented — only front-end display + lemma-prefer search; no automatic context-aware binding.)**
5. Do not bind every published-like surface unconditionally to a single lemma such as `publish`. **(Honored 2026-07-03: no auto-binding logic added; user correction always overrides.)**
6. ~~This round does not implement binding changes.~~ **Principles 1-3 + 5 implemented 2026-07-03 (Trae-LemmaKnownSenseBridge-1). Principles 4 + 6 (automatic context-aware binding, AI suggestions) are still NOT implemented.**

## 5. Required Future Harness

Future implementation should add tests or smoke checks for:

1. Inline review never writes FSRS when the meaning is rejected.
2. Inline review writes through the same sense review path when the meaning is accepted and a real review is intended.
3. Same-day repeat behavior respects prior official reviews.
4. Example rotation does not repeat the front-side example as the supplemental example.
5. Example pool insertion rejects duplicate source sentences.
6. AI output never creates source examples by itself.
