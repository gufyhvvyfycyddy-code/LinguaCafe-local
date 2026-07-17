# LinguaCafe Canonical Domain Context

> **Status**: Current stable glossary
> **Last updated**: 2026-07-17
> **Use**: Read when a task changes or depends on canonical terminology, data ownership, reading-familiarity versus formal-review boundaries, sense/card lifecycle, import/export semantics, or migration behavior. It is not universal task context. Current runtime facts still require code and test verification.

## 1. Product Model

LinguaCafe is an English-reading-centered learning system. Reading creates context and familiarity; formal review strengthens a specific meaning. These are related but separate processes.

The current mainline is sense-only formal review. Legacy word-card support may remain for compatibility, but new product work must not recreate it as a parallel review system.

## 2. Canonical Terms And Owners

### EncounteredWord

Represents a user's encountered word or phrase in the reading/familiarity layer.

Owns or supports:

- reading-page display and familiarity state;
- surface/lemma/study-base context used by reading features;
- occurrence-oriented vocabulary overview and legacy compatibility.

Does not own:

- the meaning being learned;
- formal FSRS scheduling for a sense;
- formal review history.

A reading-familiarity change is not automatically a formal review event.

### WordSense

Represents one concrete meaning being learned.

Owns or supports:

- confirmed semantic content;
- definition, part of speech, examples, aliases, collocations, and source linkage within accepted contracts;
- binding to reading occurrences;
- the semantic target of a sense ReviewCard.

A new formal learning/review feature should normally target a confirmed WordSense, not a bare word string.

### WordSenseOccurrence

Represents evidence that a WordSense appeared in a specific reading context.

Owns or supports:

- source chapter and sentence evidence;
- example provenance;
- multi-example/source reconstruction.

It is not a review score and does not own FSRS state.

### ReviewCard

Represents the schedulable review item and its lifecycle/governance state.

Current mainline:

- `target_type=sense` is the formal review-card mainline.
- `target_type=word` is legacy compatibility and must not be newly created without an explicit superseding decision.

Owns or supports:

- FSRS scheduling fields;
- lifecycle state such as active, buried, suspended, or archived;
- one finite Card Marker value (`0` unmarked, `1..7` colors) for user attention and ad-hoc study selection;
- association with the reviewed target.

It does not own the semantic definition itself and must not absorb WordSense content ownership.

### ReviewLog

Represents factual formal-review and audit history.

Owns or supports:

- ratings and review-source facts;
- before/after or undo audit data where accepted contracts define it;
- historical evidence retained by default.

Viewing, searching, opening help, reading a report, generating a prompt package, or changing reading familiarity must not create a ReviewLog. Existing ReviewLog lifecycle and preservation semantics require explicit authorization before change.

## 3. State Boundaries

### Reading familiarity versus formal review

- Reading familiarity describes what the user encountered or recognized while reading.
- Formal review state is governed by ReviewCard + FSRS + ReviewLog.
- Do not infer a formal rating from reading activity.
- Do not use an EncounteredWord stage as a replacement for ReviewCard lifecycle or FSRS state.

### Sense status versus card lifecycle

- WordSense confirmation/rejection/archive semantics describe the learning meaning.
- ReviewCard lifecycle describes whether and how the card participates in review.
- Leech/struggling is analysis or governance information, not a lifecycle state.

### Card Marker

- Card Marker is a reversible ReviewCard attribute used to visually flag one sense card and select marked cards for temporary study.
- Marker is independent of lifecycle, FSRS, Leech classification, WordSense status, and future WordSense tags.
- `flag:` is the Browser search spelling for Anki familiarity; `marker` is the canonical LinguaCafe domain/API field.
- Changing a marker is not a formal review and does not create ReviewLog or ReviewCardStateEvent rows.

### Create and bind

- Creating or confirming a WordSense does not authorize unrelated legacy card creation.
- Binding a WordSense to an EncounteredWord or occurrence requires user/language ownership checks and accepted product rules.
- Candidate data from AI, dictionary, import, or manual preview remains candidate data until the accepted confirmation boundary is crossed.

### Archive and delete

- Archive, suspend, bury, reject, and permanent delete are distinct operations.
- Review history is preserved by default unless an accepted decision explicitly changes that contract.
- Destructive changes require exact scope, reversibility, isolation, compatibility, and before/after evidence.

### Migration and compatibility

- Schema and persisted-format changes require an explicit migration/rollback strategy.
- Public payloads, import/export formats, route contracts, and persisted files are observable interfaces.
- Prefer additive and backward-compatible changes; breaking changes require a documented compatibility path and explicit authorization.

## 4. Source Of Truth

Use this file for terminology, not for current task status.

For current state and priority, follow `docs/DOCUMENTATION_INDEX.md`. For stable decisions, follow accepted ADRs and module specs. When code, tests, and old prose disagree, investigate the actual implementation and apply the rule priority from `AGENTS.md` and `docs/architecture/ai-development-rule-system.md`.
