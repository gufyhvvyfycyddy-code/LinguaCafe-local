# ADR-0021: EncounteredWord Learning Enrollment and Legacy Write Boundary

**Status:** Accepted
**Date:** 2026-07-15

## Context

ADR-0020 removed implicit stage writes from the three translation editors, but two backend couplings remained. Manual confirmed-sense creation called `EncounteredWord::setStage(-7)`, which also read legacy review intervals and wrote legacy scheduling fields. Separately, `VocabularyService::updateWord()` created or changed legacy word cards and bridged translations to suggested senses after every content save whenever the stored stage was negative.

Those effects made content editing, explicit legacy stage transitions, and confirmed-sense enrollment indistinguishable at the write boundary. They also made a newly confirmed sense appear as stage -7 immediately but stage -1 after a refresh, because the refreshed reader correctly derived a new sense card as the lowest FSRS familiarity.

## Decision

### 1. Content edit path

When an update request does not explicitly contain `stage`, `VocabularyService::updateWord()` may persist only the requested content fields. It must not call `setStage()`, create, enable, disable, or delete a ReviewCard, bridge a translation to a WordSense, create an occurrence or ReviewLog, change FSRS or legacy scheduling fields, or increment a goal. This remains true when the stored EncounteredWord stage is already negative and when a legacy word card already exists.

### 2. Explicit legacy stage transition path

When the request explicitly contains `stage`, the existing compatibility behavior remains authoritative: call `setStage()`, ensure a legacy word card for a negative stage, disable it for a non-negative stage, and run the existing translation bridge only when its existing negative-stage conditions are met. Legacy review routes, review intervals, phrase SRS, Anki auto-add, and occurrence idempotency remain unchanged.

### 3. Confirmed sense enrollment path

`EncounteredWordLearningEnrollmentService` owns only the reader enrollment transition caused by successful manual creation of a confirmed WordSense and its sense-target ReviewCard.

For a stage-2 word with `keep_new=false`, it writes:

- `stage = -1`
- `relearning = false`
- `next_review = null`
- `added_to_srs = null`
- one `learn_words` goal increment

It returns the existing `updated_word` shape with `stage_changed=true`. It does not call `setStage()`, read `reviewIntervals`, create a word-target ReviewCard, write ReviewLog, invoke FSRS, generate a review date, or set `added_to_srs`.

The EncounteredWord row is locked inside the existing manual-sense transaction before enrollment. Only the locked stage-2 to stage--1 transition increments the goal, so repeated or concurrent calls cannot count the same enrollment twice.

For an already negative stage, the service preserves stage and all legacy fields, does not increment the goal, and returns `stage_changed=false`. For Known stage 0 and Ignored stage 1, it changes nothing and returns `updated_word=null`.

## Lowest learning color and refresh consistency

A confirmed sense card in FSRS `new` state represents enrollment without a formal rating. The EncounteredWord reader classification is stage -1, while `ReaderDataService` continues to derive familiarity level 1 and 10% from the real sense ReviewCard. The existing backend-confirmed `updated_word` event chain therefore shows the same lowest green reader state immediately and after refresh. It must not be interpreted as a completed review.

## `keep_new` exception

For a stage-2 word with `keep_new=true`, the confirmed WordSense and sense ReviewCard are still created, but EncounteredWord remains stage 2, `updated_word.stage_changed` is false, no goal is incremented, and the reader remains yellow immediately and after refresh. The sense card alone must not override that reader classification.

## Formal study authority

Sense-target ReviewCard, ReviewLog, FSRS state, stability, difficulty, due time, repetitions, and lapses are the only authority for formal sense-review progress and scheduling. EncounteredWord stage remains a reader classification and legacy compatibility marker; enrollment does not fabricate formal review history.

## Migration and backfill

No migration, schema change, or backfill is required. Existing columns and the reader familiarity path already express the decision. Historical negative stages and legacy word cards are left untouched.

## Verification

- Focused Feature tests cover default enrollment, `keep_new`, already-enrolled, Known, Ignored, repeat enrollment, content-only edits at positive and negative stages, existing-card immutability, and explicit legacy stage compatibility.
- Reader tests cover a new sense card at stage -1 and 10%, `keep_new` stage 2, refresh-equivalent reads, and real post-rating familiarity.
- The architecture guard locks the three frontend content editors, service delegation, absence of direct `setStage(-7)` in manual-sense creation, content-only side-effect isolation, retained explicit-stage calls, and the existing `updated_word` event chain.
- Real Chrome acceptance exercises translation-only edits at 1920×1080 and 900×900, default and `keep_new` manual sense creation, a post-enrollment content edit, and one explicit legacy-stage smoke. Captured requests confirm one POST per action, content-only payloads without `stage`, explicit legacy payloads with `stage`, and HTTP 200 responses.

## Rollback impact

Reverting the production changes restores the former backend coupling and stage -7 immediate response. No data migration must be rolled back. Any stage -1 rows created under this decision remain valid reader-enrolled rows backed by confirmed sense cards.

## Out of scope

This decision does not change `EncounteredWord::setStage()`, FSRS algorithms or parameters, rating or undo APIs, ReviewLog or lifecycle semantics, queue order, daily limits, Phrase, Preset, Custom Study, AI study cards, source context, deletion/archive/restore, routes, schemas, or migrations.
