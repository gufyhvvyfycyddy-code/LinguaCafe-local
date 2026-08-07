# ADR-0056: Reading Assist V2 Occurrence Owners And Passive Evidence Boundaries

## Status

Accepted

## Context

LinguaCafe's existing AI reading assist stores one overwriteable chapter-level payload in
`chapter_ai_reading_assists`. That payload is useful for lookup and translation projection,
but it is not a durable source of truth for user-owned occurrence decisions, and it is not a
formal review ledger.

The reading flow now needs three separate owners:

1. raw / validated AI assist data that may be replaced by a later AI import;
2. durable occurrence-to-sense evidence that survives AI re-import and gives user decisions
   higher authority than trusted AI matches;
3. formal FSRS ratings, which must continue to flow only through
   `ReviewCardService -> FsrsSchedulingService -> ReviewLog`.

The same chapter also needs a server-authoritative occurrence identity that becomes stale when
either `raw_text` or `processed_text` changes, so no old mark or evidence can silently rebind
to a reprocessed article.

## Decision

### 1. Source Revision

- Every reading-assist V2 chapter flow uses a server-owned `source_revision`.
- `source_revision` is derived from both the `raw_text` hash and the `processed_text` hash.
- If either source changes, the new `source_revision` invalidates previous unfamiliar targets,
  occurrence evidence, and reading sessions for settlement purposes.

### 2. Stable Occurrence Identity

- Every occurrence identity is server-generated.
- `occurrence_id` is derived from:
  `user + language + chapter + source_revision + kind + start_word_index + end_word_index`.
- `surface`, `lemma`, `pos`, and sentence text are snapshots, not identity fields.
- `reading_inline_sense_confirmations` and `word_sense_occurrences` are not repurposed as this
  owner.

### 3. Three Separate Owners

- `chapter_ai_reading_assists` remains the owner of raw / validated AI output and compatibility
  projections such as sentence translations, `vocabulary_items`, and `phrase_items`.
- `reading_occurrence_sense_evidence` is the durable owner of occurrence-to-sense evidence.
- `ReviewLog` remains the owner of formal ratings only.

### 4. Authority And Precedence

- User evidence outranks trusted AI evidence.
- Later AI imports may overwrite the saved AI assist payload, but they must never overwrite
  user-owned evidence.
- Trusted AI evidence is limited to `matched_existing + high confidence + current owned confirmed
  sense + validated manifest candidate`.
- Phrase targets are excluded from phrase FSRS. Phrase results never imply a phrase review card.

### 5. Phase A Safety Boundary

- Phase A unfamiliar targets, AI preview / confirm, and evidence services must create
  `0 ReviewLog` and `0 FSRS` mutations.
- Phase B settlement may read occurrence evidence, but the evidence service itself never scores.
- V1 AI assist compatibility remains supported, including the legacy tolerant parser.

### 6. Phase B Read Path

- Reading settlement may consume only persisted occurrence evidence and the existing formal
  rating kernel.
- Passive settlement must remain idempotent per reading session and per review card.
- Explicit reading ratings still reuse the existing sense-review endpoint and
  `ReviewCardService`.

## Consequences

- Server-owned occurrence identity becomes stable and auditable.
- AI assist imports stay replaceable without becoming the durable owner of user intent.
- Passive reading automation can reuse existing confirmed senses without introducing a second
  scoring path.
- Phrase FSRS stays excluded.

## Validation

- V2 source / preview / confirm must reject stale `source_revision`, tampered manifests, and
  wrong candidate sets.
- Phase A confirm paths must not create `ReviewLog`, `ReviewCard`, or `WordSense`.
- Phase B passive settlement must call `ReviewCardService::recordReviewWithLog(...)` when it
  creates a formal passive rating.
