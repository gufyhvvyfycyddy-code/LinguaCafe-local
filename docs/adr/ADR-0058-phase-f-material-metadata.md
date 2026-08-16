# ADR-0058: Phase F Material Metadata

## Status

Accepted under current goal authorization

## Context

Phase F must classify user-uploaded English material as personal, CET-4, CET-6,
or postgraduate-exam content, with exam year, set number, and chapter question
type. Books and chapters already own imported content, user/language isolation,
and the Web library API. A second material catalog would duplicate those owners.

## Decision

- `books.material_type` is the classification owner. Its canonical values are
  `personal`, `cet4`, `cet6`, and `postgraduate_exam`; existing and legacy-created
  books default to `personal`.
- `books.exam_year` and `books.exam_set` are nullable for personal material and
  required when an exam classification is supplied through the write API.
- `chapters.question_type` is nullable. Canonical values are
  `reading_comprehension`, `cloze`, `translation`, `writing`, `listening`, and
  `other`.
- Existing Book/Chapter FormRequest → Controller → Service → Model paths remain
  the only writers. Existing callers may omit all new fields; legacy update
  requests then preserve stored metadata.
- F-01 exposes the fields through existing Book/Chapter list payloads. Import UI,
  library filtering, package download metadata, versioning, and deletion behavior
  remain owned by later Phase F milestones.

## Consequences

The additive columns provide one durable source for Web and Mobile consumers
without a new table, endpoint, service, or compatibility path. The fixed values
prevent category spelling drift. Changing the taxonomy later requires an
explicit contract and data migration rather than silent aliases.

## Validation

- Focused Feature tests cover legacy defaults, exam validation and persistence,
  additive list payloads, metadata-preserving legacy updates, and chapter type
  persistence.
- Testing MySQL receives the additive migration only through the machine-global
  testing database lease; no reset, refresh, wipe, drop, or truncate is allowed.
