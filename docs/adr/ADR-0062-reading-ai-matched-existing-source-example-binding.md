# ADR-0062 — Reading AI Matched-Existing Source Example Binding

- Status: Accepted product direction; implementation architecture pending
- Date: 2026-08-18
- Scope: AI Reading Assist / Reading occurrence identity → WordSenseOccurrence → Sense Review example pool

## Context

LinguaCafe already has a real-source example pool for WordSense review.

`WordSenseExamplePoolService` uses bound `WordSenseOccurrence` rows with non-empty real `sentence_en` as its primary candidates. Multiple distinct occurrences can therefore rotate across reviews. `WordSense.example_sentence_en` is only a fallback when no equivalent occurrence candidate exists.

The current review rotation is already the desired product behavior:

- multiple real examples can belong to one WordSense;
- duplicate source sentences are collapsed;
- consecutive formal reviews move through the pool using existing ReviewCard state;
- the supplementary example after answer reveal differs from the question example when possible;
- merely reading/serializing examples does not write ReviewLog or change FSRS.

The current AI Reading Assist V2 binding path does not fully connect to that pool.

A trusted high-confidence `matched_existing` result currently persists `ReadingOccurrenceSenseEvidence`. A user-confirmed `matched_existing` decision also persists reading evidence. Those rows say “this Reader occurrence is this existing WordSense”, but they do not automatically create/bind a corresponding real-source `WordSenseOccurrence` row. As a result, a real sentence B may be correctly identified as the same Sense learned from sentence A while still being absent from the Sense Review example rotation pool.

That is a product/data-owner gap. It does not justify a second example system.

## Decision

### 1. Authoritative matched-existing binding must also make the real Reader sentence a WordSense source occurrence

When a real Reader occurrence is authoritatively accepted as `matched_existing` for an existing confirmed WordSense, LinguaCafe must ensure that this real occurrence is represented as a bound `WordSenseOccurrence` for that WordSense.

Authoritative acceptance means either:

- the user explicitly confirms the exact existing WordSense; or
- the user has enabled Trust AI and the strict V2 path accepts a high-confidence `matched_existing` result under the existing candidate/identity/source-revision checks.

Preview-only AI output is not enough.

Once the bound source occurrence exists, the existing `WordSenseExamplePoolService` automatically makes the sentence available to Sense Review rotation. No second “AI example pool” is authorized.

### 2. The English example sentence must come from the real Reader source, never from AI prose

The example's `sentence_en` truth source is the current canonical Reader chapter text / sentence map identified by the validated occurrence and source revision.

Do not trust an AI-returned `source_sentence` string as a writable source-of-truth merely because it echoes the prompt.

AI may decide which existing WordSense the real occurrence matches; AI does not author the English source sentence.

This preserves the existing rule “examples come from real reading material, not AI-generated sentences” while allowing AI-assisted binding of those real sentences.

### 3. Accepted translation may enrich the real occurrence but does not replace English-source identity

If the same accepted AI Reading Assist package contains a saved translation for the exact source sentence and source revision, the bound occurrence may carry that `sentence_zh` as translation metadata for review display.

The English sentence, chapter, sentence index/identity, and source revision remain anchored to the Reader source.

If translation identity cannot be proven, the occurrence may still enter the pool with English only. Missing Chinese translation must not block the real-source example.

### 4. Retry/reimport must be idempotent

Pasting the same accepted AI result twice or retrying after an unknown outcome must not create duplicate example rows.

Implementation must identify the same real Reader occurrence deterministically and upsert/reuse one source occurrence.

The existing database does not currently expose a dedicated `reading_occurrence_id` column on `word_sense_occurrences`, so the implementation Architecture Gate must prove the smallest safe idempotency identity using existing source fields/evidence before adding schema.

A migration or new uniqueness column is not authorized by this ADR alone.

### 5. User correction must move the source association, not leave the sentence in two Sense pools

If the user later corrects an authoritative Reader occurrence from WordSense A to WordSense B:

- the same real source occurrence must no longer remain an active bound example for A;
- it must become the bound source occurrence for B;
- the correction must not duplicate the sentence into both Sense example pools;
- historical/audit evidence may remain readable where required, but the active example pool follows the current authoritative binding.

Likewise, if a binding is changed to `excluded` or another non-existing-Sense outcome, the former active source example must stop participating in that existing Sense's pool.

### 6. New Sense source binding follows the same real-source principle

When a genuinely `new_sense` is confirmed/enrolled from a Reader occurrence, that real sentence should also be represented as a source occurrence for the new WordSense through the existing WordSenseOccurrence owner.

This is source provenance, not an automatic review rating.

The new Sense still receives zero same-reading Again and zero same-reading Good under the Reader review ADRs.

### 7. Example binding has zero rating side effect

Creating/rebinding the real-source `WordSenseOccurrence` for example provenance must not by itself:

- create ReviewLog;
- increment ReviewCard reps/lapses;
- change stability/difficulty/due;
- invoke `FsrsSchedulingService`;
- create a second ReviewCard for an already learned Sense.

Rating eligibility remains independently owned by the Reader review rules. Identity/provenance answers “which Sense and where did we encounter it?”; rating answers “did this encounter count as a review?”.

### 8. Reuse the existing WordSenseOccurrence and example-pool owners

The target architecture should reuse:

- `WordSenseOccurrenceService` as the owner of active occurrence-to-Sense binding;
- `WordSenseExamplePoolService` as the read-only example candidate/rotation owner;
- `ReadingOccurrenceSenseEvidenceService` as the Reader identity/evidence owner;
- `ReadingChapterTextService` / `ReadingTargetCatalogService` as source text and stable Reader occurrence evidence.

Do not add:

- a new AI example table;
- a second Sense Review example cache;
- AI-generated replacement sentences;
- a second rotation algorithm;
- rating writes inside AI parsing/preview code.

The implementation Architecture Gate must decide the narrow orchestration seam between reading evidence and `WordSenseOccurrenceService` without merging these responsibilities into one large service.

## Example

1. Sentence A contains word 1.
2. The learner confirms Sense X and the real A occurrence is bound to Sense X.
3. Sense Review example pool contains sentence A.
4. Later sentence B contains the same lemma.
5. AI Reading Assist compares the B occurrence with learned candidates and returns high-confidence `matched_existing` for Sense X, or the user confirms that match.
6. After authoritative confirmation, the real sentence B occurrence is upserted/bound to Sense X.
7. Sense Review's existing pool now contains A and B.
8. Existing rotation logic can show A on one review and B on a later review without any separate AI-example feature.

## Consequences

1. G-06C expands from semantic anti-duplicate + translation layout to also close the `matched_existing → real source occurrence → example pool` bridge.
2. Existing example rotation code should normally require no algorithm change; implementation should test that the new bound row is visible to it.
3. Current tests that assert trusted AI `matched_existing` writes only `ReadingOccurrenceSenseEvidence` must be rebased: the correct invariant is “no WordSense/ReviewCard/ReviewLog/FSRS rating writes, but one idempotent real-source occurrence provenance write is allowed/required after authoritative binding”.
4. Preview/ambiguous/medium/low results remain zero occurrence-binding writes.
5. User-confirmed matching and Trust-AI matching must converge on the same provenance owner rather than producing two different source formats.
6. Correction/reimport/idempotency and source-revision stale handling are high-risk acceptance cases.
7. Real browser acceptance must prove that a newly AI-bound sentence becomes available in the later Sense Review example rotation without fabricating an AI sentence.

## Superseded / clarified older wording

Older documents saying “AI output never creates source examples by itself” are retained only in this clarified sense:

> AI must never invent or persist an AI-generated sentence as a source example. But an authoritative AI-assisted `matched_existing` decision may and should bind the **real Reader sentence** to the existing WordSense, after which the normal real-source example pool includes it.

Any older rule that treats an accepted Reader `matched_existing` binding as permanently evidence-only with no source-provenance consequence is superseded where it conflicts with this ADR. The no-rating / no-FSRS-write boundary remains fully valid.

## Implementation gate

Before code changes, a dedicated Architecture Gate must prove:

- the current real Reader occurrence identity available at V2 confirm/user-confirm time;
- the smallest idempotent `WordSenseOccurrence` upsert identity with the current schema;
- exact source sentence ownership and source-revision stale behavior;
- optional accepted translation mapping;
- correction/rebind semantics;
- duplicate/reimport behavior;
- interaction with existing manual/new-Sense occurrence paths;
- zero ReviewLog/FSRS side effects;
- `WordSenseExamplePoolService` automatically consumes the new bound occurrence;
- focused Feature tests and real Reader → Sense Review browser acceptance.
