# Reading Inline Review And Example Pool Plan

> **Status**: Route frozen, not implemented.
> **Last updated**: 2026-07-02 (Codex-LegacyEntry-FinishedReading-ExampleGuard-1).

This plan records the next route for reading-inline review and multi-example rotation. It is not implementation authorization. Future work still needs Architecture Gate, interface review, tests, and MCP Chrome page acceptance.

## 1. Scope

This document freezes four product routes:

1. Reading inline review must be WordSense-based.
2. Review card examples must rotate from real source examples.
3. Known-sense-new-meaning confirmation must stay separate from ordinary AI recommendations.
4. Surface/lemma binding must remain context-aware and user-correctable.

This round does not implement reading-inline review, multi-example rotation, real AI calls, WordSense generation, ReviewCard generation, or FSRS write paths.

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

1. Each `WordSense` should have an example pool.
2. The front-side review example must rotate.
3. Supplemental examples shown after answer reveal must differ from the front-side example.
4. If only one example exists, do not show it again as a duplicate supplemental example.
5. Examples must come from real source text, not AI-generated text.
6. Do not add the same sentence twice.
7. Different positions or different sentences in the same chapter may be separate sources.
8. Source lists must support multiple sources.
9. Source switching should use rotation plus light shuffle, not fixed ABC order every time.
10. This round does not implement multi-example rotation.

## 4.3 Known-Sense-New-Meaning And Inline Review Bridge

1. AI recommended words and known-sense-new-meaning candidates are separate areas.
2. On first reading, the user marks only genuinely unknown words.
3. During AI analysis, the system can also judge whether already-learned words match the current sentence meaning.
4. On a second reading, words already matched to a `WordSense` may enter inline review.
5. Suspected known-sense-new-meaning candidates enter confirmation, not direct legacy card review.
6. This round does not implement this bridge.

## 4.4 Surface And Lemma Binding

1. Surface display keeps the original word form.
2. Search and add-sense flows should prefer lemma.
3. After the user corrects lemma, later add-sense flows should use the corrected lemma.
4. Binding must consider surface, lemma, part of speech, and sentence meaning.
5. Do not bind every published-like surface unconditionally to a single lemma such as `publish`.
6. This round does not implement binding changes.

## 5. Required Future Harness

Future implementation should add tests or smoke checks for:

1. Inline review never writes FSRS when the meaning is rejected.
2. Inline review writes through the same sense review path when the meaning is accepted and a real review is intended.
3. Same-day repeat behavior respects prior official reviews.
4. Example rotation does not repeat the front-side example as the supplemental example.
5. Example pool insertion rejects duplicate source sentences.
6. AI output never creates source examples by itself.
